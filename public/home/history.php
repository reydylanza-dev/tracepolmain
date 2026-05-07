<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../login.php");
    exit;
}

require_once "../koneksi.php";
mysqli_query($link, "SET time_zone = '+07:00'"); // Sync timezone MySQL ke WIB

$position    = $_SESSION["position"] ?? "staff";
$nik_pegawai = $_SESSION["nik"]      ?? null;

if (!$nik_pegawai) {
    header("location: index.php");
    exit;
}

// ── Parameter filter ─────────────────────────────────────────────────────────
$filter_bulan  = isset($_GET["bulan"])  ? (int) $_GET["bulan"]  : (int) date('n');
$filter_tahun  = isset($_GET["tahun"])  ? (int) $_GET["tahun"]  : (int) date('Y');
$filter_status = isset($_GET["status"]) ? trim($_GET["status"]) : "semua";
$halaman       = isset($_GET["hal"])    ? max(1, (int) $_GET["hal"]) : 1;
$per_halaman   = 15;
$offset        = ($halaman - 1) * $per_halaman;

$filter_bulan = max(1, min(12, $filter_bulan));
$filter_tahun = max(2020, min((int) date('Y'), $filter_tahun));

// ── Nama bulan & hari ────────────────────────────────────────────────────────
$nama_bulan = ['','Januari','Februari','Maret','April','Mei','Juni',
               'Juli','Agustus','September','Oktober','November','Desember'];
$nama_hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

// ── Status valid per tipe ────────────────────────────────────────────────────
$status_list = ($position === 'dosen')
    ? ['hadir','izin','sakit','alfa','cuti','tugas_luar']
    : ['hadir','izin','sakit','alfa','cuti','dinas_luar'];

if ($filter_status !== 'semua' && !in_array($filter_status, $status_list)) {
    $filter_status = 'semua';
}

// ── Konfigurasi tabel per tipe ───────────────────────────────────────────────
if ($position === 'dosen') {
    $tabel    = 'absensi_dosen';
    $col_nik  = 'dosen_nik';
    $col_jam1 = 'jam_mulai';
    $col_jam2 = 'jam_selesai';
    $col_lok  = 'lokasi';
    $extra    = ", jenis_kegiatan AS kegiatan, mata_kuliah";
} else {
    $tabel    = 'absensi_staff';
    $col_nik  = 'staff_nik';
    $col_jam1 = 'jam_masuk';
    $col_jam2 = 'jam_keluar';
    $col_lok  = 'lokasi_masuk';
    $extra    = ", shift AS kegiatan, lokasi_keluar";
}

// ── WHERE dinamis ─────────────────────────────────────────────────────────────
$where_parts = ["{$col_nik} = ?", "MONTH(tanggal) = ?", "YEAR(tanggal) = ?"];
$bind_types  = "sii";
$bind_vals   = [$nik_pegawai, $filter_bulan, $filter_tahun];

if ($filter_status !== 'semua') {
    $where_parts[] = "status_kehadiran = ?";
    $bind_types   .= "s";
    $bind_vals[]   = $filter_status;
}

$where_sql = implode(" AND ", $where_parts);

// ── Total untuk pagination ────────────────────────────────────────────────────
$total_data = 0;
if ($stmt = mysqli_prepare($link, "SELECT COUNT(*) FROM {$tabel} WHERE {$where_sql}")) {
    mysqli_stmt_bind_param($stmt, $bind_types, ...$bind_vals);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $total_data);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
}
$total_halaman = max(1, (int) ceil($total_data / $per_halaman));
$halaman = min($halaman, $total_halaman);
$offset  = ($halaman - 1) * $per_halaman;

// ── Data absensi ──────────────────────────────────────────────────────────────
$rows = [];
$sql_data = "SELECT id, tanggal,
                    {$col_jam1} AS jam_masuk, {$col_jam2} AS jam_keluar,
                    {$col_lok} AS lokasi, status_kehadiran,
                    keterangan, metode_absensi {$extra}
             FROM   {$tabel}
             WHERE  {$where_sql}
             ORDER BY tanggal DESC, id DESC
             LIMIT  ? OFFSET ?";

if ($stmt = mysqli_prepare($link, $sql_data)) {
    $bt = $bind_types . "ii";
    $bv = array_merge($bind_vals, [$per_halaman, $offset]);
    mysqli_stmt_bind_param($stmt, $bt, ...$bv);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $rows[] = $row;
    mysqli_stmt_close($stmt);
}

// ── Rekap bulanan ─────────────────────────────────────────────────────────────
$rekap = null;
$tipe_rekap = ($position === 'dosen') ? 'dosen' : 'staff';

$sql_rekap = "SELECT total_hadir, total_izin, total_sakit, total_alfa,
                     total_cuti, total_dinas, total_hari_kerja, persentase_kehadiran
              FROM   rekap_absensi_bulanan
              WHERE  tipe_pegawai = ? AND pegawai_nik = ? AND bulan = ? AND tahun = ?
              LIMIT 1";
if ($stmt = mysqli_prepare($link, $sql_rekap)) {
    mysqli_stmt_bind_param($stmt, "ssii", $tipe_rekap, $nik_pegawai, $filter_bulan, $filter_tahun);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    if ($r) $rekap = mysqli_fetch_assoc($r);
    mysqli_stmt_close($stmt);
}

// Hitung langsung dari raw jika rekap belum ada di tabel
if (!$rekap) {
    $sql_raw = "SELECT
                    SUM(status_kehadiran='hadir')  AS total_hadir,
                    SUM(status_kehadiran='izin')   AS total_izin,
                    SUM(status_kehadiran='sakit')  AS total_sakit,
                    SUM(status_kehadiran='alfa')   AS total_alfa,
                    SUM(status_kehadiran='cuti')   AS total_cuti,
                    SUM(status_kehadiran IN('dinas_luar','tugas_luar')) AS total_dinas,
                    COUNT(*) AS total_hari_kerja
                FROM {$tabel}
                WHERE {$col_nik}=? AND MONTH(tanggal)=? AND YEAR(tanggal)=?";
    if ($stmt = mysqli_prepare($link, $sql_raw)) {
        mysqli_stmt_bind_param($stmt, "sii", $nik_pegawai, $filter_bulan, $filter_tahun);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $raw = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
        if ($raw && $raw['total_hari_kerja'] > 0) {
            $rekap = $raw;
            $rekap['persentase_kehadiran'] = round(
                ($rekap['total_hadir'] / $rekap['total_hari_kerja']) * 100, 1
            );
        }
    }
}

mysqli_close($link);

// ── Helpers ───────────────────────────────────────────────────────────────────
function badge(string $s): string {
    $m = [
        'hadir'      => ['Hadir',      '#dcfce7','#166534','#16a34a'],
        'izin'       => ['Izin',       '#eff6ff','#1e40af','#3b82f6'],
        'sakit'      => ['Sakit',      '#fff7ed','#9a3412','#ea580c'],
        'alfa'       => ['Alfa',       '#fef2f2','#991b1b','#dc2626'],
        'cuti'       => ['Cuti',       '#f5f3ff','#5b21b6','#7c3aed'],
        'dinas_luar' => ['Dinas Luar', '#ecfdf5','#065f46','#059669'],
        'tugas_luar' => ['Tugas Luar', '#ecfdf5','#065f46','#059669'],
    ];
    $d = $m[$s] ?? [ucfirst($s),'#f1f5f9','#334155','#64748b'];
    return "<span class='badge-status' style='background:{$d[1]};color:{$d[2]};border:1px solid {$d[3]}44'>{$d[0]}</span>";
}

function metode_icon(string $m): string {
    $map = [
        'manual'      => ['fa-pen',         '#94a3b8','Manual'],
        'fingerprint' => ['fa-fingerprint', '#3b82f6','Fingerprint'],
        'qr'          => ['fa-qrcode',      '#8b5cf6','QR Code'],
        'face'        => ['fa-face-smile',  '#f59e0b','Face Recog.'],
    ];
    $d = $map[$m] ?? ['fa-circle','#94a3b8',ucfirst($m)];
    return "<i class='fas {$d[0]}' style='color:{$d[1]};font-size:11px' title='{$d[2]}'></i>";
}

function build_url(array $ov = []): string {
    return 'history.php?' . http_build_query(array_merge([
        'bulan'  => $GLOBALS['filter_bulan'],
        'tahun'  => $GLOBALS['filter_tahun'],
        'status' => $GLOBALS['filter_status'],
        'hal'    => $GLOBALS['halaman'],
    ], $ov));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Riwayat Absensi | Sistem Absensi</title>
    <link rel="icon" href="../../img/ico.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --blue:       #003366;
            --blue-mid:   #004d99;
            --blue-light: #0066cc;
            --yellow:     #FFD700;
            --white:      #ffffff;
            --gray-50:    #f8fafc;
            --gray-100:   #f1f5f9;
            --gray-200:   #e2e8f0;
            --gray-300:   #cbd5e1;
            --gray-400:   #94a3b8;
            --gray-500:   #64748b;
            --gray-600:   #475569;
            --gray-800:   #1e293b;
            --dm-bg:      #060f1e;
            --dm-nav:     #0a1a30;
            --dm-card:    #0d1f3c;
            --dm-card2:   #112244;
            --dm-border:  rgba(255,255,255,0.07);
            --dm-text:    #e2e8f0;
            --dm-muted:   rgba(255,255,255,0.38);
        }

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:"Plus Jakarta Sans",sans-serif;
            background:var(--gray-100); color:var(--gray-800);
            min-height:100vh; padding-bottom:32px;
            transition:background .35s, color .35s;
        }

        /* ── TOP BAR ── */
        .top-bar {
            position:sticky; top:0; z-index:100;
            background:var(--white); border-bottom:1px solid var(--gray-200);
            height:56px; padding:0 16px;
            display:flex; align-items:center; gap:12px;
            box-shadow:0 1px 8px rgba(0,0,0,.06);
            transition:background .35s, border-color .35s;
        }
        .btn-back {
            width:34px; height:34px; border-radius:9px;
            background:var(--gray-100); border:1px solid var(--gray-200);
            display:flex; align-items:center; justify-content:center;
            color:var(--gray-600); font-size:13px;
            text-decoration:none; flex-shrink:0; transition:all .2s;
        }
        .btn-back:hover { background:var(--blue); color:var(--white); border-color:var(--blue); }
        .topbar-title { flex:1; font-size:15px; font-weight:800; color:var(--blue); transition:color .35s; }
        .topbar-title span {
            display:block; font-size:10px; font-weight:500;
            color:var(--gray-400); letter-spacing:.4px; text-transform:uppercase;
        }
        .dark-toggle {
            width:40px; height:22px; background:var(--gray-200);
            border:1.5px solid var(--gray-300); border-radius:999px;
            cursor:pointer; position:relative; transition:all .3s; flex-shrink:0;
        }
        .dark-toggle::after {
            content:''; position:absolute; top:2px; right:2px;
            width:14px; height:14px; background:var(--yellow);
            border-radius:50%; transition:all .3s; box-shadow:0 1px 3px rgba(0,0,0,.2);
        }

        /* ── WRAP ── */
        .page-wrap { max-width:600px; margin:0 auto; padding:16px 14px; }

        /* ── REKAP CARD ── */
        .rekap-card {
            background:linear-gradient(145deg, var(--blue) 0%, var(--blue-mid) 55%, var(--blue-light) 100%);
            border-radius:20px; padding:18px 18px 20px;
            margin-bottom:16px; position:relative; overflow:hidden;
        }
        .rekap-card::before {
            content:''; position:absolute; top:-50px; right:-50px;
            width:140px; height:140px; border-radius:50%;
            background:rgba(255,215,0,.10);
        }
        .rekap-card::after {
            content:''; position:absolute; bottom:-35px; left:-30px;
            width:110px; height:110px; border-radius:50%;
            background:rgba(255,255,255,.05);
        }
        .rekap-head {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:12px; position:relative; z-index:1;
        }
        .rekap-head h3 { font-size:13.5px; font-weight:800; color:var(--white); }
        .rekap-pct { text-align:right; }
        .rekap-pct .big { font-size:24px; font-weight:800; color:var(--yellow); letter-spacing:-.5px; }
        .rekap-pct .lbl { font-size:10px; color:rgba(255,255,255,.55); }
        .progress-wrap {
            background:rgba(0,0,0,.25); border-radius:999px;
            height:5px; margin-bottom:14px; overflow:hidden; position:relative; z-index:1;
        }
        .progress-fill {
            height:100%; border-radius:999px;
            background:linear-gradient(90deg,var(--yellow),#ffe44d);
            transition:width .6s ease;
        }
        .rekap-grid {
            display:grid; grid-template-columns:repeat(6,1fr);
            gap:6px; position:relative; z-index:1;
        }
        .rekap-box {
            background:rgba(0,0,0,.2); border:1px solid rgba(255,255,255,.08);
            border-radius:10px; padding:8px 4px; text-align:center;
        }
        .rekap-box .rv { font-size:18px; font-weight:800; line-height:1; }
        .rekap-box .rl { font-size:9px; font-weight:600; letter-spacing:.4px; text-transform:uppercase; margin-top:2px; }
        .rb-hadir .rv{color:#86efac} .rb-hadir .rl{color:rgba(134,239,172,.65)}
        .rb-izin  .rv{color:#93c5fd} .rb-izin  .rl{color:rgba(147,197,253,.65)}
        .rb-sakit .rv{color:#fdba74} .rb-sakit .rl{color:rgba(253,186,116,.65)}
        .rb-alfa  .rv{color:#fca5a5} .rb-alfa  .rl{color:rgba(252,165,165,.65)}
        .rb-cuti  .rv{color:#c4b5fd} .rb-cuti  .rl{color:rgba(196,181,253,.65)}
        .rb-dinas .rv{color:#6ee7b7} .rb-dinas .rl{color:rgba(110,231,183,.65)}
        @media(max-width:380px){
            .rekap-grid { grid-template-columns:repeat(3,1fr); }
        }

        /* ── FILTER ── */
        .filter-bar {
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:16px; padding:14px;
            margin-bottom:12px; display:flex; flex-direction:column; gap:10px;
            transition:background .35s, border-color .35s;
        }
        .filter-row { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .filter-label {
            font-size:10px; font-weight:700; color:var(--gray-400);
            text-transform:uppercase; letter-spacing:.5px; white-space:nowrap;
        }
        .filter-select {
            flex:1; height:36px; padding:0 10px;
            font-size:13px; font-weight:600;
            font-family:"Plus Jakarta Sans",sans-serif;
            color:var(--gray-800); background:var(--gray-50);
            border:1.5px solid var(--gray-200); border-radius:9px;
            outline:none; -webkit-appearance:none; transition:all .2s;
        }
        .filter-select:focus { border-color:var(--blue-mid); background:var(--white); }
        .status-pills { display:flex; gap:5px; flex-wrap:wrap; }
        .sp {
            height:28px; padding:0 10px; border-radius:999px;
            font-size:11px; font-weight:700;
            border:1.5px solid var(--gray-200); background:var(--gray-50);
            color:var(--gray-500); text-decoration:none;
            display:flex; align-items:center; transition:all .2s;
        }
        .sp:hover, .sp.on { border-color:var(--blue); background:var(--blue); color:var(--white); }
        .sp.on-hadir  { background:#16a34a; border-color:#16a34a; }
        .sp.on-izin   { background:#3b82f6; border-color:#3b82f6; }
        .sp.on-sakit  { background:#ea580c; border-color:#ea580c; }
        .sp.on-alfa   { background:#dc2626; border-color:#dc2626; }
        .sp.on-cuti   { background:#7c3aed; border-color:#7c3aed; }
        .sp.on-dinas  { background:#059669; border-color:#059669; }

        /* ── INFO BAR ── */
        .info-bar {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:10px; font-size:11.5px; font-weight:600; color:var(--gray-500);
            transition:color .35s;
        }
        .info-bar b { color:var(--blue-mid); }

        /* ── LIST ── */
        .hist-list { display:flex; flex-direction:column; gap:8px; }

        .hi {
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:16px; padding:13px 14px;
            display:flex; align-items:stretch; gap:12px;
            transition:background .35s, border-color .35s, box-shadow .2s;
        }
        .hi:hover { box-shadow:0 4px 16px rgba(0,30,80,.08); }

        .hi-date {
            width:46px; flex-shrink:0;
            background:var(--gray-100); border-radius:11px;
            padding:8px 4px; text-align:center;
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            transition:background .35s;
        }
        .hi-date .dd { font-size:9px; font-weight:700; color:var(--gray-400); letter-spacing:.3px; text-transform:uppercase; }
        .hi-date .dt { font-size:21px; font-weight:800; color:var(--blue); line-height:1.1; }
        .hi-date .dm { font-size:9px; font-weight:700; color:var(--gray-400); letter-spacing:.3px; text-transform:uppercase; }

        .hi-body { flex:1; min-width:0; display:flex; flex-direction:column; gap:4px; }

        .hi-top { display:flex; align-items:center; flex-wrap:wrap; gap:5px; }

        .badge-status {
            font-size:11px; font-weight:700;
            padding:2px 8px; border-radius:999px; white-space:nowrap;
        }
        .badge-kg {
            font-size:11px; font-weight:700;
            color:var(--blue-mid); background:#eff6ff;
            padding:2px 8px; border-radius:999px;
            text-transform:capitalize;
        }
        .hi-jam {
            font-size:12px; color:var(--gray-500);
            display:flex; align-items:center; gap:4px;
        }
        .hi-jam i { font-size:10px; color:var(--gray-300); }
        .hi-lok {
            font-size:11.5px; color:var(--gray-500);
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
            display:flex; align-items:center; gap:4px;
        }
        .hi-lok i { font-size:10px; color:var(--gray-300); flex-shrink:0; }
        .hi-ket {
            font-size:11.5px; color:var(--gray-400); font-style:italic;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }

        /* ── EMPTY ── */
        .empty {
            background:var(--white); border:1.5px dashed var(--gray-300);
            border-radius:16px; padding:40px 20px;
            text-align:center; color:var(--gray-400);
            transition:background .35s, border-color .35s;
        }
        .empty i { font-size:36px; color:var(--gray-300); margin-bottom:10px; display:block; }
        .empty p { font-size:13.5px; }
        .empty small { font-size:12px; }

        /* ── PAGINATION ── */
        .pagination {
            display:flex; align-items:center; justify-content:center;
            gap:5px; margin-top:18px; flex-wrap:wrap;
        }
        .pg {
            min-width:34px; height:34px; padding:0 10px;
            border-radius:9px; font-size:12.5px; font-weight:700;
            font-family:"Plus Jakarta Sans",sans-serif;
            border:1.5px solid var(--gray-200); background:var(--white);
            color:var(--gray-600); text-decoration:none;
            display:flex; align-items:center; justify-content:center;
            transition:all .2s;
        }
        .pg:hover    { border-color:var(--blue); color:var(--blue); }
        .pg.on       { background:var(--blue); border-color:var(--blue); color:var(--white); }
        .pg.off      { opacity:.4; pointer-events:none; }
        .pg-dot      { font-size:12px; color:var(--gray-400); padding:0 2px; }

        /* ── DARK MODE ── */
        body.dark { background:var(--dm-bg); color:var(--dm-text); }
        body.dark .top-bar      { background:var(--dm-nav); border-color:var(--dm-border); }
        body.dark .topbar-title { color:var(--yellow); }
        body.dark .btn-back     { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .dark-toggle  { background:#1e3a5f; border-color:#2a4a73; }
        body.dark .dark-toggle::after { right:auto; left:2px; background:#c7d2fe; }
        body.dark .filter-bar   { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .filter-select{ background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text); }
        body.dark .sp           { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .hi           { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .hi-date      { background:var(--dm-card2); }
        body.dark .hi-date .dt  { color:var(--yellow); }
        body.dark .empty        { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .pg           { background:var(--dm-card); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .info-bar     { color:var(--dm-muted); }
        body.dark .info-bar b   { color:var(--yellow); }
        body.dark .badge-kg     { background:rgba(59,130,246,.15); }
    </style>
</head>
<body>

<header class="top-bar">
    <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i></a>
    <div class="topbar-title">
        Riwayat Absensi
        <span><?php echo $nama_bulan[$filter_bulan] . ' ' . $filter_tahun; ?></span>
    </div>
    <button class="dark-toggle" id="darkToggle" aria-label="Toggle dark mode"></button>
</header>

<div class="page-wrap">

    <!-- REKAP -->
    <?php
    $pct   = $rekap ? (float)$rekap['persentase_kehadiran'] : 0;
    $stats = [
        'hadir' => $rekap['total_hadir']     ?? 0,
        'izin'  => $rekap['total_izin']      ?? 0,
        'sakit' => $rekap['total_sakit']     ?? 0,
        'alfa'  => $rekap['total_alfa']      ?? 0,
        'cuti'  => $rekap['total_cuti']      ?? 0,
        'dinas' => $rekap['total_dinas']     ?? 0,
    ];
    ?>
    <div class="rekap-card">
        <div class="rekap-head">
            <h3>Rekap <?php echo $nama_bulan[$filter_bulan] . ' ' . $filter_tahun; ?></h3>
            <div class="rekap-pct">
                <div class="big"><?php echo number_format($pct, 1); ?>%</div>
                <div class="lbl">kehadiran</div>
            </div>
        </div>
        <div class="progress-wrap">
            <div class="progress-fill" style="width:<?php echo min(100, $pct); ?>%"></div>
        </div>
        <div class="rekap-grid">
            <?php foreach (['hadir'=>'Hadir','izin'=>'Izin','sakit'=>'Sakit','alfa'=>'Alfa','cuti'=>'Cuti','dinas'=>'Dinas'] as $k=>$l): ?>
            <div class="rekap-box rb-<?php echo $k; ?>">
                <div class="rv"><?php echo $stats[$k]; ?></div>
                <div class="rl"><?php echo $l; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- FILTER -->
    <div class="filter-bar">
        <div class="filter-row">
            <span class="filter-label">Periode</span>
            <select class="filter-select" id="selBulan" onchange="applyFilter()">
                <?php for ($b = 1; $b <= 12; $b++): ?>
                <option value="<?php echo $b; ?>" <?php echo $b===$filter_bulan?'selected':''; ?>>
                    <?php echo $nama_bulan[$b]; ?>
                </option>
                <?php endfor; ?>
            </select>
            <select class="filter-select" id="selTahun" onchange="applyFilter()" style="max-width:88px;">
                <?php for ($y=(int)date('Y'); $y>=2020; $y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y===$filter_tahun?'selected':''; ?>>
                    <?php echo $y; ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="filter-row">
            <span class="filter-label">Status</span>
            <div class="status-pills">
                <?php
                $pills = ['semua'=>'Semua'] + array_combine($status_list, array_map(function($s){
                    $label = ['hadir'=>'Hadir','izin'=>'Izin','sakit'=>'Sakit',
                              'alfa'=>'Alfa','cuti'=>'Cuti',
                              'dinas_luar'=>'Dinas','tugas_luar'=>'Tugas Luar'];
                    return $label[$s] ?? ucfirst($s);
                }, $status_list));
                foreach ($pills as $val=>$lbl):
                    $active = $filter_status === $val;
                    $cls    = $active ? 'on on-' . ($val==='semua'?'':($val==='dinas_luar'||$val==='tugas_luar'?'dinas':$val)) : '';
                ?>
                <a href="<?php echo build_url(['status'=>$val,'hal'=>1]); ?>"
                   class="sp <?php echo trim($cls); ?>"><?php echo $lbl; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- INFO BAR -->
    <div class="info-bar">
        <span>Menampilkan <b><?php echo count($rows); ?></b> dari <b><?php echo $total_data; ?></b> catatan</span>
        <span>Hal <b><?php echo $halaman; ?></b> / <b><?php echo $total_halaman; ?></b></span>
    </div>

    <!-- LIST -->
    <div class="hist-list">
        <?php if (empty($rows)): ?>
        <div class="empty">
            <i class="fas fa-inbox"></i>
            <p>Tidak ada data absensi</p>
            <small>
                <?php
                echo $nama_bulan[$filter_bulan] . ' ' . $filter_tahun;
                if ($filter_status !== 'semua') echo ' &mdash; status: ' . htmlspecialchars($filter_status);
                ?>
            </small>
        </div>
        <?php else: foreach ($rows as $r):
            $dt      = new DateTime($r['tanggal']);
            $hari    = substr($nama_hari[(int)$dt->format('w')], 0, 3);
            $tgl     = $dt->format('j');
            $bln     = strtoupper(substr($nama_bulan[(int)$dt->format('n')], 0, 3));
            $jam_m   = $r['jam_masuk']  ? substr($r['jam_masuk'],  0, 5) : '--:--';
            $jam_k   = $r['jam_keluar'] ? substr($r['jam_keluar'], 0, 5) : '--:--';
            $kegiatan = $r['kegiatan']  ?? '';
        ?>
        <div class="hi">
            <div class="hi-date">
                <div class="dd"><?php echo $hari; ?></div>
                <div class="dt"><?php echo $tgl; ?></div>
                <div class="dm"><?php echo $bln; ?></div>
            </div>
            <div class="hi-body">
                <div class="hi-top">
                    <?php echo badge($r['status_kehadiran']); ?>
                    <?php if ($kegiatan): ?>
                    <span class="badge-kg"><?php echo htmlspecialchars(str_replace('_',' ',$kegiatan)); ?></span>
                    <?php endif; ?>
                    <?php echo metode_icon($r['metode_absensi']); ?>
                </div>
                <div class="hi-jam">
                    <i class="fas fa-right-to-bracket"></i> <?php echo $jam_m; ?>
                    &nbsp;—&nbsp;
                    <i class="fas fa-right-from-bracket"></i> <?php echo $jam_k; ?>
                </div>
                <?php if (!empty($r['lokasi'])): ?>
                <div class="hi-lok">
                    <i class="fas fa-location-dot"></i>
                    <?php echo htmlspecialchars($r['lokasi']); ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($r['keterangan'])): ?>
                <div class="hi-ket">"<?php echo htmlspecialchars($r['keterangan']); ?>"</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- PAGINATION -->
    <?php if ($total_halaman > 1):
        $ps = max(1, $halaman - 2);
        $pe = min($total_halaman, $ps + 4);
        $ps = max(1, $pe - 4);
    ?>
    <div class="pagination">
        <a href="<?php echo build_url(['hal'=>max(1,$halaman-1)]); ?>"
           class="pg <?php echo $halaman<=1?'off':''; ?>"><i class="fas fa-chevron-left"></i></a>

        <?php if ($ps > 1): ?>
            <a href="<?php echo build_url(['hal'=>1]); ?>" class="pg">1</a>
            <?php if ($ps > 2): ?><span class="pg-dot">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($p=$ps; $p<=$pe; $p++): ?>
        <a href="<?php echo build_url(['hal'=>$p]); ?>"
           class="pg <?php echo $p===$halaman?'on':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>

        <?php if ($pe < $total_halaman): ?>
            <?php if ($pe < $total_halaman-1): ?><span class="pg-dot">…</span><?php endif; ?>
            <a href="<?php echo build_url(['hal'=>$total_halaman]); ?>" class="pg"><?php echo $total_halaman; ?></a>
        <?php endif; ?>

        <a href="<?php echo build_url(['hal'=>min($total_halaman,$halaman+1)]); ?>"
           class="pg <?php echo $halaman>=$total_halaman?'off':''; ?>"><i class="fas fa-chevron-right"></i></a>
    </div>
    <?php endif; ?>

</div>

<script>
(function(){
    const body = document.body;
    if (localStorage.getItem('absensiDark') === '1') body.classList.add('dark');
    document.getElementById('darkToggle').addEventListener('click', () => {
        body.classList.toggle('dark');
        localStorage.setItem('absensiDark', body.classList.contains('dark') ? '1' : '0');
    });
})();

function applyFilter() {
    const b = document.getElementById('selBulan').value;
    const y = document.getElementById('selTahun').value;
    const s = '<?php echo urlencode($filter_status); ?>';
    window.location.href = `history.php?bulan=${b}&tahun=${y}&status=${s}&hal=1`;
}
</script>
</body>
</html>