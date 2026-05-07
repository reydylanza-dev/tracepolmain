<?php
session_start();
date_default_timezone_set('Asia/Jakarta'); // WIB UTC+7

// Guard: harus login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../login.php");
    exit;
}

require_once "../koneksi.php";
mysqli_query($link, "SET time_zone = '+07:00'"); // Sync timezone MySQL ke WIB

// ── Flash message dari proses_absensi.php ───────────────────────────────────
$flash_tipe  = $_SESSION["flash_tipe"]  ?? null;
$flash_pesan = $_SESSION["flash_pesan"] ?? null;
unset($_SESSION["flash_tipe"], $_SESSION["flash_pesan"]);

// ── Ambil data pegawai berdasarkan position di session ──────────────────────
$username    = $_SESSION["username"];
$position    = $_SESSION["position"] ?? "staff"; // 'staff' | 'dosen' | 'admin'
$nik_pegawai = $_SESSION["nik"]      ?? null;

$pegawai = null;
$rekap   = null;

if ($nik_pegawai) {
    if ($position === "dosen") {
        $sql = "SELECT d.nama, d.nidn AS nip_nidn, d.foto_profil, d.kode_jabatan,
                       d.email AS email_kerja, d.status_kepegawaian,
                       j.nama_jabatan,
                       k.nama_prodi
                FROM   data_dosen d
                LEFT JOIN data_jabatan j ON j.kode_jabatan = d.kode_jabatan
                LEFT JOIN data_kuliah  k ON k.kode_prodi   = d.kode_prodi
                WHERE  d.nik = ?
                LIMIT 1";
    } else {
        $sql = "SELECT s.nama, s.nip AS nip_nidn, s.foto_profil, s.kode_jabatan,
                       s.unit_kerja, s.email AS email_kerja, s.status_kepegawaian,
                       j.nama_jabatan,
                       NULL AS nama_prodi
                FROM   data_staff s
                LEFT JOIN data_jabatan j ON j.kode_jabatan = s.kode_jabatan
                WHERE  s.nik = ?
                LIMIT 1";
    }

    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "s", $nik_pegawai);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) $pegawai = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
}

// ── Rekap bulan ini ─────────────────────────────────────────────────────────
$bulan_ini = (int) date('n');
$tahun_ini = (int) date('Y');
$tipe      = ($position === 'dosen') ? 'dosen' : 'staff';

// Cek absensi hari ini
$tabel_absensi = ($position === 'dosen') ? 'absensi_dosen' : 'absensi_staff';
$kolom_nik     = ($position === 'dosen') ? 'dosen_nik'     : 'staff_nik';

$sql_hari_ini = "SELECT * FROM {$tabel_absensi}
                 WHERE {$kolom_nik} = ?
                   AND tanggal = CURDATE()
                 ORDER BY id DESC";

$absensi_hari_ini  = null;  // record pertama (untuk status chip hero)
$absensi_hari_map  = [];    // map shift/kegiatan → record (untuk cek duplikat di JS)

if ($stmt = mysqli_prepare($link, $sql_hari_ini)) {
    mysqli_stmt_bind_param($stmt, "s", $nik_pegawai);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        if (!$absensi_hari_ini) $absensi_hari_ini = $row;
        $key = $position === 'dosen'
            ? ($row['jenis_kegiatan'] ?? '')
            : ($row['shift'] ?? '');
        $absensi_hari_map[$key] = [
            'status'    => $row['status_kehadiran'],
            'jam_masuk' => $position === 'dosen'
                ? substr($row['jam_mulai']  ?? '--:--', 0, 5)
                : substr($row['jam_masuk']  ?? '--:--', 0, 5),
            'jam_keluar' => $position === 'dosen'
                ? substr($row['jam_selesai'] ?? '--:--', 0, 5)
                : substr($row['jam_keluar']  ?? '--:--', 0, 5),
        ];
    }
    mysqli_stmt_close($stmt);
}

// Rekap bulan ini
$sql_rekap = "SELECT total_hadir, total_izin, total_sakit, total_alfa, total_cuti,
                     total_dinas, total_hari_kerja, persentase_kehadiran
              FROM   rekap_absensi_bulanan
              WHERE  tipe_pegawai = ? AND pegawai_nik = ?
                AND  bulan = ? AND tahun = ?
              LIMIT 1";

if ($stmt2 = mysqli_prepare($link, $sql_rekap)) {
    mysqli_stmt_bind_param($stmt2, "ssii", $tipe, $nik_pegawai, $bulan_ini, $tahun_ini);
    mysqli_stmt_execute($stmt2);
    $r2 = mysqli_stmt_get_result($stmt2);
    if ($r2) $rekap = mysqli_fetch_assoc($r2);
    mysqli_stmt_close($stmt2);
}

// ── Fallback: hitung langsung dari tabel absensi jika rekap belum tersedia ──
if (!$rekap && $nik_pegawai) {
    $tabel_rekap  = ($position === 'dosen') ? 'absensi_dosen' : 'absensi_staff';
    $kolom_rekap  = ($position === 'dosen') ? 'dosen_nik'     : 'staff_nik';
    $sql_fallback = "SELECT
                        SUM(status_kehadiran = 'hadir')                      AS total_hadir,
                        SUM(status_kehadiran = 'izin')                       AS total_izin,
                        SUM(status_kehadiran = 'sakit')                      AS total_sakit,
                        SUM(status_kehadiran = 'alfa')                       AS total_alfa,
                        SUM(status_kehadiran = 'cuti')                       AS total_cuti,
                        SUM(status_kehadiran IN ('dinas_luar','tugas_luar')) AS total_dinas,
                        COUNT(*)                                             AS total_hari_kerja
                     FROM {$tabel_rekap}
                     WHERE {$kolom_rekap} = ?
                       AND MONTH(tanggal)  = ?
                       AND YEAR(tanggal)   = ?";
    if ($stmtF = mysqli_prepare($link, $sql_fallback)) {
        mysqli_stmt_bind_param($stmtF, "sii", $nik_pegawai, $bulan_ini, $tahun_ini);
        mysqli_stmt_execute($stmtF);
        $rF = mysqli_stmt_get_result($stmtF);
        $raw = mysqli_fetch_assoc($rF);
        mysqli_stmt_close($stmtF);
        if ($raw && (int)$raw['total_hari_kerja'] > 0) {
            $rekap = $raw;
            $rekap['persentase_kehadiran'] = round(
                ($rekap['total_hadir'] / $rekap['total_hari_kerja']) * 100, 1
            );
        }
    }
}

// ── History 5 absensi terakhir ───────────────────────────────────────────────
$history = [];
if ($position === 'dosen') {
    $sql_hist = "SELECT tanggal, jenis_kegiatan AS shift_atau_kegiatan,
                        jam_mulai AS jam_masuk, jam_selesai AS jam_keluar,
                        lokasi, status_kehadiran, keterangan
                 FROM   absensi_dosen
                 WHERE  dosen_nik = ?
                 ORDER BY tanggal DESC, id DESC
                 LIMIT 10";
} else {
    $sql_hist = "SELECT tanggal, shift AS shift_atau_kegiatan,
                        jam_masuk, jam_keluar,
                        lokasi_masuk AS lokasi, status_kehadiran, keterangan
                 FROM   absensi_staff
                 WHERE  staff_nik = ?
                 ORDER BY tanggal DESC, id DESC
                 LIMIT 10";
}

if ($stmt3 = mysqli_prepare($link, $sql_hist)) {
    mysqli_stmt_bind_param($stmt3, "s", $nik_pegawai);
    mysqli_stmt_execute($stmt3);
    $r3 = mysqli_stmt_get_result($stmt3);
    while ($row = mysqli_fetch_assoc($r3)) $history[] = $row;
    mysqli_stmt_close($stmt3);
}

mysqli_close($link);

// Tanggal & waktu
$hari_id   = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
               'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$nama_bulan = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',
    5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',
    9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];
$hari_nama       = $hari_id[date('l')] ?? date('l');
$bln_nama        = $nama_bulan[$bulan_ini];          // nama bulan rekap (angka → Indonesia)
$tanggal_panjang = "{$hari_nama}, " . date('j') . " {$nama_bulan[(int)date('n')]} " . date('Y');

// Label status kehadiran
function badge_status($s) {
    $map = [
        'hadir'      => ['Hadir',        '#dcfce7','#166534','#16a34a'],
        'izin'       => ['Izin',         '#eff6ff','#1e40af','#3b82f6'],
        'sakit'      => ['Sakit',        '#fff7ed','#9a3412','#ea580c'],
        'alfa'       => ['Alfa',         '#fef2f2','#991b1b','#dc2626'],
        'cuti'       => ['Cuti',         '#f5f3ff','#5b21b6','#7c3aed'],
        'dinas_luar' => ['Dinas Luar',   '#ecfdf5','#065f46','#059669'],
        'tugas_luar' => ['Tugas Luar',   '#ecfdf5','#065f46','#059669'],
    ];
    $d = $map[$s] ?? [$s, '#f1f5f9','#334155','#64748b'];
    return "<span style='background:{$d[1]};color:{$d[2]};border:1px solid {$d[3]}33;
                 font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;
                 letter-spacing:0.4px;white-space:nowrap;'>{$d[0]}</span>";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Beranda | TRACE Polmain</title>
    <link rel="icon" href="../img/ico.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --blue:        #003366;
            --blue-mid:    #004d99;
            --blue-light:  #0066cc;
            --yellow:      #FFD700;
            --yellow-dark: #E6BF00;
            --white:       #ffffff;
            --gray-50:     #f8fafc;
            --gray-100:    #f1f5f9;
            --gray-200:    #e2e8f0;
            --gray-300:    #cbd5e1;
            --gray-400:    #94a3b8;
            --gray-500:    #64748b;
            --gray-600:    #475569;
            --gray-700:    #334155;
            --gray-800:    #1e293b;
            --green:       #16a34a;
            --green-bg:    #dcfce7;
            --red:         #dc2626;
            --red-bg:      #fef2f2;

            /* dark */
            --dm-bg:       #060f1e;
            --dm-nav:      #0a1a30;
            --dm-card:     #0d1f3c;
            --dm-card2:    #112244;
            --dm-border:   rgba(255,255,255,0.07);
            --dm-text:     #e2e8f0;
            --dm-muted:    rgba(255,255,255,0.38);
        }

        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

        html { height:100%; scroll-behavior:smooth; }

        body {
            font-family: "Plus Jakarta Sans", sans-serif;
            background: var(--gray-100);
            min-height: 100vh;
            padding-bottom: 80px; /* space for bottom nav */
            transition: background .35s, color .35s;
            color: var(--gray-800);
        }

        /* ─── TOP BAR ────────────────────────────────────── */
        .top-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 0 16px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 8px rgba(0,0,0,0.06);
            transition: background .35s, border-color .35s;
        }

        .brand { display:flex; align-items:center; gap:9px; text-decoration:none; }

        .brand-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--blue), var(--blue-mid));
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .brand-icon i { color: var(--yellow); font-size: 14px; }

        .brand-text .t1 {
            font-size: 13px; font-weight: 800;
            color: var(--blue); line-height: 1.2;
            transition: color .35s;
        }
        .brand-text .t2 {
            font-size: 9.5px; font-weight: 500;
            color: var(--gray-400); letter-spacing: .5px; text-transform: uppercase;
        }

        .topbar-right { display:flex; align-items:center; gap:10px; }

        .dark-toggle {
            width: 40px; height: 22px;
            background: var(--gray-200);
            border: 1.5px solid var(--gray-300);
            border-radius: 999px; cursor: pointer;
            position: relative; transition: all .3s;
        }
        .dark-toggle::after {
            content:'';
            position: absolute; top:2px; right:2px;
            width:14px; height:14px;
            background: var(--yellow); border-radius:50%;
            transition: all .3s; box-shadow:0 1px 3px rgba(0,0,0,.2);
        }

        .btn-logout {
            width: 32px; height: 32px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            display:flex; align-items:center; justify-content:center;
            color: var(--gray-500); font-size: 13px;
            cursor:pointer; text-decoration:none;
            transition: all .2s;
        }
        .btn-logout:hover { background:var(--red-bg); color:var(--red); border-color:#fecaca; }

        /* ─── BODY SCROLL CONTAINER ──────────────────────── */
        .page-content {
            max-width: 540px;
            margin: 0 auto;
            padding: 0 14px;
        }

        /* ─── HERO / GREETING CARD ───────────────────────── */
        .hero-card {
            background: linear-gradient(145deg, var(--blue) 0%, var(--blue-mid) 55%, var(--blue-light) 100%);
            border-radius: 0 0 24px 24px;
            padding: 20px 20px 24px;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .hero-card::before {
            content:''; position:absolute;
            top:-50px; right:-50px;
            width:160px; height:160px; border-radius:50%;
            background:rgba(255,215,0,.10);
        }
        .hero-card::after {
            content:''; position:absolute;
            bottom:-40px; left:-30px;
            width:120px; height:120px; border-radius:50%;
            background:rgba(255,255,255,.05);
        }

        .hero-top {
            display:flex; align-items:flex-start;
            justify-content:space-between;
            margin-bottom:16px;
            position:relative; z-index:1;
        }

        .greeting-text .g-time {
            font-size:11px; font-weight:600;
            color:rgba(255,255,255,.55);
            letter-spacing:.8px; text-transform:uppercase;
            margin-bottom:4px;
        }
        .greeting-text .g-name {
            font-size:20px; font-weight:800;
            color:var(--white); line-height:1.2;
        }
        .greeting-text .g-name span { color:var(--yellow); }
        .greeting-text .g-role {
            font-size:12px; color:rgba(255,255,255,.6);
            margin-top:3px;
        }

        .avatar {
            width:46px; height:46px; border-radius:12px;
            background:rgba(255,215,0,.2);
            border:2px solid rgba(255,215,0,.4);
            display:flex; align-items:center; justify-content:center;
            flex-shrink:0; overflow:hidden;
        }
        .avatar img { width:100%; height:100%; object-fit:cover; }
        .avatar i { font-size:20px; color:var(--yellow); }

        /* Clock & date inside hero */
        .hero-clock {
            background:rgba(0,0,0,.25);
            border:1px solid rgba(255,255,255,.08);
            border-radius:14px;
            padding:12px 16px;
            display:flex; align-items:center; justify-content:space-between;
            position:relative; z-index:1;
        }

        .clock-left .c-time {
            font-size:28px; font-weight:800;
            color:var(--yellow); letter-spacing:1.5px;
            font-variant-numeric:tabular-nums;
        }
        .clock-left .c-date {
            font-size:11.5px; color:rgba(255,255,255,.6);
            margin-top:2px;
        }

        .clock-right { text-align:right; }

        /* Status chip hari ini */
        .status-today {
            display:inline-flex; align-items:center; gap:5px;
            padding:5px 12px; border-radius:999px;
            font-size:11px; font-weight:700;
            letter-spacing:.4px;
        }
        .status-today.belum {
            background:rgba(220,38,38,.2); color:#fca5a5;
            border:1px solid rgba(220,38,38,.3);
        }
        .status-today.hadir {
            background:rgba(22,163,74,.2); color:#86efac;
            border:1px solid rgba(22,163,74,.3);
        }
        .status-today.izin, .status-today.sakit, .status-today.cuti {
            background:rgba(99,102,241,.2); color:#a5b4fc;
            border:1px solid rgba(99,102,241,.3);
        }
        .clock-right .c-label {
            font-size:9px; color:rgba(255,255,255,.4);
            text-transform:uppercase; letter-spacing:.6px; margin-top:4px;
        }

        /* ─── SECTION LABEL ──────────────────────────────── */
        .section-label {
            font-size:11.5px; font-weight:700;
            color:var(--gray-500); text-transform:uppercase;
            letter-spacing:.8px; margin:0 0 10px;
            transition:color .35s;
        }

        /* ─── REKAP STRIP ─────────────────────────────────── */
        .rekap-strip {
            display:grid; grid-template-columns:repeat(4,1fr);
            gap:8px; margin-bottom:20px;
        }

        .rekap-item {
            background:var(--white);
            border-radius:14px;
            padding:12px 8px;
            text-align:center;
            border:1px solid var(--gray-200);
            transition:background .35s, border-color .35s;
        }

        .rekap-item .ri-val {
            font-size:22px; font-weight:800;
            line-height:1.1; margin-bottom:3px;
        }
        .rekap-item .ri-label {
            font-size:10px; font-weight:600;
            color:var(--gray-400); text-transform:uppercase; letter-spacing:.4px;
        }

        .ri-hadir .ri-val  { color:var(--green); }
        .ri-izin  .ri-val  { color:#3b82f6; }
        .ri-sakit .ri-val  { color:#ea580c; }
        .ri-alfa  .ri-val  { color:var(--red); }

        /* ─── MAIN MENU CARDS ────────────────────────────── */
        .menu-grid {
            display:grid; grid-template-columns:1fr 1fr;
            gap:12px; margin-bottom:20px;
        }

        .menu-card {
            background:var(--white);
            border:1px solid var(--gray-200);
            border-radius:18px;
            padding:20px 16px;
            text-decoration:none; color:inherit;
            display:flex; flex-direction:column;
            gap:12px; position:relative; overflow:hidden;
            transition:all .25s; cursor:pointer;
            -webkit-tap-highlight-color:transparent;
        }

        .menu-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,30,80,.12); }
        .menu-card:active { transform:translateY(0); box-shadow:none; }

        /* wide card spans 2 cols */
        .menu-card.wide { grid-column:span 2; flex-direction:row; align-items:center; gap:16px; padding:18px 20px; }

        .menu-icon {
            width:48px; height:48px; border-radius:14px;
            display:flex; align-items:center; justify-content:center;
            flex-shrink:0;
        }
        .menu-icon i { font-size:20px; }

        .menu-card.wide .menu-icon { width:52px; height:52px; border-radius:16px; }
        .menu-card.wide .menu-icon i { font-size:22px; }

        .ic-absensi  { background:#dbeafe; } .ic-absensi i  { color:#1d4ed8; }
        .ic-history  { background:#d1fae5; } .ic-history i  { color:#065f46; }
        .ic-helpdesk { background:#fef3c7; } .ic-helpdesk i { color:#92400e; }

        .menu-body { flex:1; }
        .menu-title { font-size:14px; font-weight:800; color:var(--gray-800); margin-bottom:3px; transition:color .35s; }
        .menu-desc  { font-size:11.5px; color:var(--gray-400); line-height:1.5; transition:color .35s; }

        .menu-arrow {
            width:28px; height:28px; border-radius:8px;
            background:var(--gray-100);
            display:flex; align-items:center; justify-content:center;
            color:var(--gray-400); font-size:11px;
            flex-shrink:0; transition:all .25s;
        }
        .menu-card:hover .menu-arrow { background:var(--blue); color:var(--white); }

        /* absensi card accent */
        .menu-card.accent-absensi {
            background:linear-gradient(135deg, var(--blue) 0%, var(--blue-mid) 100%);
            border-color:transparent;
        }
        .menu-card.accent-absensi .ic-absensi  { background:rgba(255,255,255,.15); }
        .menu-card.accent-absensi .ic-absensi i { color:var(--yellow); }
        .menu-card.accent-absensi .menu-title  { color:var(--white); }
        .menu-card.accent-absensi .menu-desc   { color:rgba(255,255,255,.65); }
        .menu-card.accent-absensi .menu-arrow  { background:rgba(255,255,255,.15); color:var(--white); }
        .menu-card.accent-absensi:hover .menu-arrow { background:var(--yellow); color:var(--blue); }

        /* badge (e.g. "Belum absen") */
        .menu-badge {
            position:absolute; top:12px; right:12px;
            background:var(--red); color:var(--white);
            font-size:9.5px; font-weight:700;
            padding:2px 8px; border-radius:999px;
            letter-spacing:.4px;
        }

        /* ─── HISTORY SECTION ────────────────────────────── */
        .history-list { display:flex; flex-direction:column; gap:8px; margin-bottom:20px; }

        .history-item {
            background:var(--white);
            border:1px solid var(--gray-200);
            border-radius:14px;
            padding:13px 14px;
            display:flex; align-items:center; gap:12px;
            transition:background .35s, border-color .35s;
        }

        .hi-date {
            width:44px; flex-shrink:0; text-align:center;
            background:var(--gray-100);
            border-radius:10px; padding:7px 4px;
        }
        .hi-date .hd-day  { font-size:18px; font-weight:800; color:var(--blue); line-height:1; }
        .hi-date .hd-mon  { font-size:9px;  font-weight:700; color:var(--gray-400);
                             text-transform:uppercase; letter-spacing:.5px; }

        .hi-info { flex:1; min-width:0; }
        .hi-top  { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:4px; }
        .hi-kegiatan {
            font-size:11.5px; font-weight:700; color:var(--blue-mid);
            background:#eff6ff; padding:1px 7px; border-radius:999px;
            text-transform:capitalize;
        }
        .hi-jam  { font-size:11px; color:var(--gray-400); }
        .hi-lokasi {
            font-size:11.5px; color:var(--gray-500);
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .hi-lokasi i { font-size:10px; margin-right:3px; color:var(--gray-400); }

        .history-empty {
            background:var(--white);
            border:1px dashed var(--gray-300);
            border-radius:14px; padding:28px;
            text-align:center; color:var(--gray-400);
        }
        .history-empty i { font-size:28px; margin-bottom:8px; display:block; }
        .history-empty p { font-size:13px; }

        .btn-lihat-semua {
            display:flex; align-items:center; justify-content:center; gap:6px;
            width:100%; height:42px;
            background:var(--white);
            border:1px solid var(--gray-200);
            border-radius:12px;
            font-size:13px; font-weight:700;
            color:var(--blue-mid); cursor:pointer;
            text-decoration:none;
            transition:all .2s; margin-bottom:20px;
        }
        .btn-lihat-semua:hover { background:var(--blue); color:var(--white); border-color:var(--blue); }

        /* ─── BOTTOM NAV ─────────────────────────────────── */
        .bottom-nav {
            position:fixed; bottom:0; left:0; right:0;
            background:var(--white);
            border-top:1px solid var(--gray-200);
            height:68px;
            display:flex; align-items:stretch;
            z-index:100;
            box-shadow:0 -4px 20px rgba(0,0,0,0.06);
            transition:background .35s, border-color .35s;
        }

        .nav-item {
            flex:1; display:flex; flex-direction:column;
            align-items:center; justify-content:center;
            gap:4px; text-decoration:none; cursor:pointer;
            -webkit-tap-highlight-color:transparent;
            color:var(--gray-400); transition:color .2s;
            position:relative;
        }
        .nav-item.active { color:var(--blue); }
        .nav-item.active::before {
            content:'';
            position:absolute; top:0; left:50%; transform:translateX(-50%);
            width:32px; height:2.5px;
            background:var(--blue); border-radius:0 0 4px 4px;
        }
        .nav-item i  { font-size:18px; }
        .nav-item span { font-size:10px; font-weight:600; letter-spacing:.3px; }

        /* ─── MODAL ABSENSI ───────────────────────────────── */
        .modal-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.55);
            backdrop-filter:blur(4px);
            z-index:200;
            display:flex; align-items:flex-end; justify-content:center;
            opacity:0; pointer-events:none;
            transition:opacity .3s;
        }
        .modal-overlay.open { opacity:1; pointer-events:all; }

        .modal-sheet {
            background:var(--white);
            border-radius:24px 24px 0 0;
            width:100%; max-width:540px;
            padding:0 0 32px;
            transform:translateY(100%);
            transition:transform .35s cubic-bezier(.32,0,.67,0);
            max-height:90vh; overflow-y:auto;
        }
        .modal-overlay.open .modal-sheet { transform:translateY(0); }

        .modal-handle {
            width:40px; height:4px; border-radius:999px;
            background:var(--gray-300); margin:12px auto 4px;
        }

        .modal-header {
            padding:16px 20px 14px;
            border-bottom:1px solid var(--gray-200);
            display:flex; align-items:center; justify-content:space-between;
        }
        .modal-header h3 { font-size:16px; font-weight:800; color:var(--blue); }
        .modal-close {
            width:32px; height:32px; border-radius:8px;
            background:var(--gray-100); border:none; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            color:var(--gray-500); font-size:14px; transition:all .2s;
        }
        .modal-close:hover { background:var(--red-bg); color:var(--red); }

        .modal-body { padding:20px; }

        .absensi-form .form-group { margin-bottom:16px; }

        .form-label {
            display:flex; align-items:center; gap:5px;
            font-size:11px; font-weight:700;
            color:var(--gray-600); text-transform:uppercase;
            letter-spacing:.6px; margin-bottom:7px;
            transition:color .35s;
        }
        .form-label i { font-size:10px; color:var(--blue-light); }

        .form-control, .form-select {
            width:100%; height:46px;
            padding:0 14px;
            font-size:14px; font-family:"Plus Jakarta Sans",sans-serif;
            color:var(--gray-800);
            background:var(--gray-50);
            border:1.5px solid var(--gray-200);
            border-radius:12px; outline:none;
            transition:all .25s; -webkit-appearance:none;
        }
        textarea.form-control {
            height:90px; padding:12px 14px;
            resize:none; line-height:1.5;
        }
        .input-readonly {
            background:var(--gray-100) !important;
            color:var(--gray-500);
            cursor:default;
        }
        .input-readonly:focus {
            border-color:var(--gray-200);
            box-shadow:none;
        }
        .form-control:focus, .form-select:focus {
            background:var(--white);
            border-color:var(--blue-mid);
            box-shadow:0 0 0 3px rgba(0,77,153,.10);
        }

        /* Status pills dalam form */
        .status-pills { display:flex; flex-wrap:wrap; gap:8px; }
        .status-pill {
            flex:1; min-width:calc(33% - 6px);
            height:38px; border-radius:10px;
            border:1.5px solid var(--gray-200);
            background:var(--gray-50);
            display:flex; align-items:center; justify-content:center; gap:5px;
            font-size:12px; font-weight:700;
            color:var(--gray-600); cursor:pointer;
            transition:all .2s; -webkit-tap-highlight-color:transparent;
        }
        .status-pill.selected-hadir  { background:#dcfce7; border-color:#16a34a; color:#166534; }
        .status-pill.selected-izin   { background:#dbeafe; border-color:#3b82f6; color:#1e40af; }
        .status-pill.selected-sakit  { background:#ffedd5; border-color:#ea580c; color:#9a3412; }
        .status-pill.selected-alfa   { background:#fef2f2; border-color:#dc2626; color:#991b1b; }
        .status-pill.selected-cuti   { background:#f5f3ff; border-color:#7c3aed; color:#5b21b6; }
        .status-pill.selected-dinas  { background:#ecfdf5; border-color:#059669; color:#065f46; }

        .btn-absen-submit {
            width:100%; height:50px;
            background:linear-gradient(135deg, var(--blue), var(--blue-mid));
            color:var(--white); border:none; border-radius:14px;
            font-size:14px; font-weight:800;
            font-family:"Plus Jakarta Sans",sans-serif;
            letter-spacing:.6px; text-transform:uppercase;
            cursor:pointer; display:flex; align-items:center;
            justify-content:center; gap:8px;
            transition:all .3s; margin-top:4px;
        }
        .btn-absen-submit:hover {
            background:linear-gradient(135deg, var(--blue-mid), var(--blue-light));
            box-shadow:0 8px 24px rgba(0,51,102,.3);
        }

        /* ─── HELPDESK SECTION ────────────────────────────── */
        .helpdesk-section {
            background:var(--white);
            border:1px solid var(--gray-200);
            border-radius:18px; padding:18px 16px;
            margin-bottom:20px;
            transition:background .35s, border-color .35s;
        }
        .helpdesk-header {
            display:flex; align-items:center; gap:10px; margin-bottom:14px;
        }
        .helpdesk-icon {
            width:40px; height:40px; border-radius:11px;
            background:#fef3c7;
            display:flex; align-items:center; justify-content:center;
            flex-shrink:0;
        }
        .helpdesk-icon i { color:#92400e; font-size:17px; }
        .helpdesk-header h3 { font-size:14px; font-weight:800; color:var(--gray-800); }
        .helpdesk-header p  { font-size:11.5px; color:var(--gray-400); }

        .helpdesk-contacts { display:flex; flex-direction:column; gap:8px; }
        .contact-btn {
            display:flex; align-items:center; gap:12px;
            padding:11px 14px; border-radius:12px;
            border:1.5px solid var(--gray-200);
            text-decoration:none; color:var(--gray-800);
            background:var(--gray-50);
            transition:all .2s; -webkit-tap-highlight-color:transparent;
        }
        .contact-btn:hover { border-color:var(--blue); background:#eff6ff; }

        .contact-icon {
            width:36px; height:36px; border-radius:10px;
            display:flex; align-items:center; justify-content:center;
            flex-shrink:0; font-size:15px;
        }
        .ci-wa  { background:#dcfce7; color:#16a34a; }
        .ci-tel { background:#dbeafe; color:#1d4ed8; }
        .ci-mail{ background:#fef3c7; color:#92400e; }

        .contact-info .ci-name  { font-size:12.5px; font-weight:700; }
        .contact-info .ci-value { font-size:11.5px; color:var(--gray-400); }

        .contact-btn .cb-arrow { margin-left:auto; color:var(--gray-300); font-size:12px; }

        /* ─── DARK MODE ───────────────────────────────────── */
        body.dark { background:var(--dm-bg); color:var(--dm-text); }
        body.dark .top-bar { background:var(--dm-nav); border-color:var(--dm-border); }
        body.dark .brand-text .t1 { color:var(--yellow); }
        body.dark .dark-toggle { background:#1e3a5f; border-color:#2a4a73; }
        body.dark .dark-toggle::after { right:auto; left:2px; background:#c7d2fe; }
        body.dark .btn-logout { background:rgba(255,255,255,.05); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .rekap-item { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .menu-card  { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .menu-title { color:var(--dm-text); }
        body.dark .menu-desc  { color:var(--dm-muted); }
        body.dark .menu-arrow { background:var(--dm-card2); color:var(--dm-muted); }
        body.dark .hi-date    { background:var(--dm-card2); }
        body.dark .hi-date .hd-day { color:var(--yellow); }
        body.dark .history-item { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .history-empty { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .btn-lihat-semua { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .bottom-nav { background:var(--dm-nav); border-color:var(--dm-border); }
        body.dark .nav-item.active { color:var(--yellow); }
        body.dark .nav-item.active::before { background:var(--yellow); }
        body.dark .modal-sheet { background:var(--dm-card); }
        body.dark .modal-handle { background:var(--dm-border); }
        body.dark .modal-header { border-color:var(--dm-border); }
        body.dark .modal-header h3 { color:var(--yellow); }
        body.dark .modal-close { background:var(--dm-card2); color:var(--dm-muted); }
        body.dark .form-label { color:var(--dm-muted); }
        body.dark .form-control, body.dark .form-select {
            background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text);
        }
        body.dark .input-readonly {
            background:var(--dm-bg) !important; color:var(--dm-muted); cursor:default;
        }
        body.dark .status-pill { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .status-pill.selected-hadir { background:rgba(22,163,74,.25);  border-color:#16a34a; color:#86efac; }
        body.dark .status-pill.selected-izin  { background:rgba(59,130,246,.25); border-color:#3b82f6; color:#93c5fd; }
        body.dark .status-pill.selected-sakit { background:rgba(234,88,12,.25);  border-color:#ea580c; color:#fdba74; }
        body.dark .status-pill.selected-alfa  { background:rgba(220,38,38,.25);  border-color:#dc2626; color:#fca5a5; }
        body.dark .status-pill.selected-cuti  { background:rgba(124,58,237,.25); border-color:#7c3aed; color:#c4b5fd; }
        body.dark .status-pill.selected-dinas { background:rgba(5,150,105,.25);  border-color:#059669; color:#6ee7b7; }
        body.dark .helpdesk-section { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .helpdesk-header h3 { color:var(--dm-text); }
        body.dark .contact-btn { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text); }
        body.dark .section-label { color:var(--dm-muted); }

        /* ─── POPUP KONFIRMASI DUPLIKAT ──────────────────── */
        .popup-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.6);
            backdrop-filter:blur(6px);
            z-index:300;
            display:flex; align-items:center; justify-content:center;
            padding:20px;
            opacity:0; pointer-events:none;
            transition:opacity .25s;
        }
        .popup-overlay.open { opacity:1; pointer-events:all; }

        .popup-box {
            background:var(--white);
            border-radius:24px;
            width:100%; max-width:360px;
            padding:28px 24px 22px;
            transform:scale(.92) translateY(12px);
            transition:transform .3s cubic-bezier(.34,1.56,.64,1);
            box-shadow:0 24px 60px rgba(0,0,0,.25);
        }
        .popup-overlay.open .popup-box { transform:scale(1) translateY(0); }

        .popup-icon {
            width:56px; height:56px; border-radius:16px;
            background:#fff7ed;
            border:2px solid #fed7aa;
            display:flex; align-items:center; justify-content:center;
            margin:0 auto 16px;
        }
        .popup-icon i { font-size:24px; color:#ea580c; }

        .popup-box h4 {
            font-size:16px; font-weight:800;
            color:var(--gray-800); text-align:center;
            margin-bottom:8px;
        }
        .popup-box p {
            font-size:13px; color:var(--gray-500);
            text-align:center; line-height:1.6;
            margin-bottom:6px;
        }

        .popup-info {
            background:var(--gray-50);
            border:1px solid var(--gray-200);
            border-radius:12px; padding:11px 14px;
            margin:14px 0 18px;
            display:flex; flex-direction:column; gap:5px;
        }
        .popup-info-row {
            display:flex; align-items:center;
            justify-content:space-between; gap:8px;
        }
        .popup-info-row .pir-label {
            font-size:11px; font-weight:600;
            color:var(--gray-400); text-transform:uppercase; letter-spacing:.4px;
        }
        .popup-info-row .pir-val {
            font-size:12.5px; font-weight:700; color:var(--gray-700);
        }

        .popup-actions { display:flex; gap:8px; }

        .popup-btn {
            flex:1; height:44px; border-radius:12px;
            font-size:13px; font-weight:700;
            font-family:"Plus Jakarta Sans",sans-serif;
            cursor:pointer; border:none;
            display:flex; align-items:center; justify-content:center; gap:6px;
            transition:all .2s;
        }
        .popup-btn-cancel {
            background:var(--gray-100);
            border:1.5px solid var(--gray-200);
            color:var(--gray-600);
        }
        .popup-btn-cancel:hover { background:var(--gray-200); }

        .popup-btn-lanjut {
            background:linear-gradient(135deg, #ea580c, #c2410c);
            color:var(--white);
            box-shadow:0 4px 14px rgba(234,88,12,.3);
        }
        .popup-btn-lanjut:hover { box-shadow:0 6px 20px rgba(234,88,12,.45); transform:translateY(-1px); }

        /* Dark mode popup */
        body.dark .popup-box      { background:var(--dm-card); }
        body.dark .popup-box h4   { color:var(--dm-text); }
        body.dark .popup-box p    { color:var(--dm-muted); }
        body.dark .popup-info     { background:var(--dm-card2); border-color:var(--dm-border); }
        body.dark .pir-val        { color:var(--dm-text); }
        body.dark .popup-btn-cancel { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }

        /* ─── FLASH ALERT ────────────────────────────────── */
        .flash-alert {
            display:flex; align-items:center; gap:10px;
            padding:12px 14px; border-radius:14px;
            margin:14px 0 4px;
            font-size:13px; font-weight:600; line-height:1.4;
            animation:slideDown .3s ease;
        }
        .flash-sukses {
            background:#dcfce7; border:1px solid #86efac; color:#166534;
        }
        .flash-error {
            background:#fef2f2; border:1px solid #fecaca; color:#991b1b;
        }
        .flash-alert i  { font-size:15px; flex-shrink:0; }
        .flash-alert span { flex:1; }
        .flash-close {
            background:none; border:none; cursor:pointer;
            color:inherit; opacity:.5; font-size:13px; padding:2px 4px;
        }
        .flash-close:hover { opacity:1; }
        @keyframes slideDown {
            from { opacity:0; transform:translateY(-8px); }
            to   { opacity:1; transform:translateY(0); }
        }

        /* ─── POPUP DUPLIKAT ──────────────────────────────── */
        .popup-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.6);
            backdrop-filter:blur(6px);
            z-index:300;
            display:flex; align-items:center; justify-content:center;
            padding:20px;
            opacity:0; pointer-events:none;
            transition:opacity .25s;
        }
        .popup-overlay.open { opacity:1; pointer-events:all; }

        .popup-box {
            background:var(--white);
            border-radius:24px;
            padding:28px 24px 24px;
            width:100%; max-width:340px;
            text-align:center;
            box-shadow:0 24px 60px rgba(0,0,0,.25);
            transform:scale(.92) translateY(12px);
            transition:transform .3s cubic-bezier(.34,1.56,.64,1);
        }
        .popup-overlay.open .popup-box { transform:scale(1) translateY(0); }

        .popup-icon {
            width:60px; height:60px; border-radius:18px;
            background:#fff7ed; margin:0 auto 14px;
            display:flex; align-items:center; justify-content:center;
        }
        .popup-icon i { font-size:26px; color:#f97316; }

        .popup-title {
            font-size:18px; font-weight:800;
            color:var(--gray-800); margin-bottom:8px;
            transition:color .35s;
        }
        .popup-desc {
            font-size:13px; color:var(--gray-500);
            line-height:1.6; margin-bottom:16px;
            transition:color .35s;
        }
        .popup-desc strong { color:var(--blue-mid); }

        .popup-detail {
            background:var(--gray-50);
            border:1px solid var(--gray-200);
            border-radius:14px; padding:12px 14px;
            margin-bottom:14px; text-align:left;
            display:flex; flex-direction:column; gap:8px;
            transition:background .35s, border-color .35s;
        }
        .pd-row {
            display:flex; align-items:center;
            justify-content:space-between; gap:8px;
        }
        .pd-label {
            font-size:11.5px; font-weight:600; color:var(--gray-400);
            display:flex; align-items:center; gap:5px;
            transition:color .35s;
        }
        .pd-label i { font-size:10px; }
        .pd-val {
            font-size:12.5px; font-weight:700; color:var(--gray-800);
            transition:color .35s;
        }

        .popup-hint {
            font-size:11.5px; color:var(--gray-400);
            margin-bottom:18px; line-height:1.5;
            transition:color .35s;
        }
        .popup-hint strong { color:var(--gray-600); }

        .popup-actions {
            display:flex; gap:10px;
        }
        .popup-btn-batal, .popup-btn-lanjut {
            flex:1; height:46px; border-radius:12px;
            font-size:13px; font-weight:700;
            font-family:"Plus Jakarta Sans",sans-serif;
            cursor:pointer; border:none;
            display:flex; align-items:center; justify-content:center; gap:6px;
            transition:all .2s;
        }
        .popup-btn-batal {
            background:var(--gray-100);
            border:1.5px solid var(--gray-200);
            color:var(--gray-600);
        }
        .popup-btn-batal:hover { background:var(--gray-200); }
        .popup-btn-lanjut {
            background:linear-gradient(135deg, var(--blue), var(--blue-mid));
            color:var(--white);
            box-shadow:0 4px 14px rgba(0,51,102,.25);
        }
        .popup-btn-lanjut:hover {
            background:linear-gradient(135deg, var(--blue-mid), var(--blue-light));
            box-shadow:0 6px 20px rgba(0,51,102,.35);
        }

        /* dark mode popup */
        body.dark .popup-box    { background:var(--dm-card); }
        body.dark .popup-title  { color:var(--dm-text); }
        body.dark .popup-desc   { color:var(--dm-muted); }
        body.dark .popup-detail { background:var(--dm-card2); border-color:var(--dm-border); }
        body.dark .pd-label     { color:var(--dm-muted); }
        body.dark .pd-val       { color:var(--dm-text); }
        body.dark .popup-hint   { color:var(--dm-muted); }
        body.dark .popup-hint strong { color:rgba(255,255,255,.55); }
        body.dark .popup-btn-batal {
            background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted);
        }
        body.dark .popup-btn-batal:hover { background:rgba(255,255,255,.08); }

        /* ─── RESPONSIVE DESKTOP ─────────────────────────── */
        @media (min-width:600px) {
            .page-content { padding:0 0; }
            .hero-card { border-radius:20px; margin:16px 0 20px; }
        }
    </style>
</head>
<body>

<!-- TOP BAR -->
<header class="top-bar">
    <a href="#" class="brand">
        <div class="brand-icon"><i class="fas fa-fingerprint"></i></div>
        <div class="brand-text">
            <div class="t1">TRACE (Time & Record Academic Connection Environment)</div>
            <div class="t2">Politeknik Masamy Internasional</div>
        </div>
    </a>
    <div class="topbar-right">
        <button class="dark-toggle" id="darkToggle" aria-label="Toggle dark mode"></button>
        <a href="../logout.php" class="btn-logout" title="Keluar">
            <i class="fas fa-right-from-bracket"></i>
        </a>
    </div>
</header>

<!-- PAGE CONTENT -->
<div class="page-content">

    <!-- FLASH MESSAGE -->
    <?php if ($flash_pesan): ?>
    <div class="flash-alert flash-<?php echo $flash_tipe === 'sukses' ? 'sukses' : 'error'; ?>" id="flashAlert">
        <i class="fas <?php echo $flash_tipe === 'sukses' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
        <span><?php echo htmlspecialchars($flash_pesan); ?></span>
        <button onclick="this.parentElement.remove()" class="flash-close"><i class="fas fa-xmark"></i></button>
    </div>
    <?php endif; ?>

    <!-- HERO / GREETING -->
    <div class="hero-card">
        <div class="hero-top">
            <div class="greeting-text">
                <div class="g-time" id="greetingTime">Selamat Pagi</div>
                <div class="g-name">Halo, <span id="namaDisplay"><?php echo htmlspecialchars($pegawai['nama'] ?? $username); ?></span></div>
                <div class="g-role">
                    <?php
                    $label_role = $pegawai['nama_jabatan'] ?? ucfirst($position);
                    echo htmlspecialchars($label_role);
                    if (!empty($pegawai['nama_prodi'])) echo ' &mdash; ' . htmlspecialchars($pegawai['nama_prodi']);
                    ?>
                </div>
            </div>
            <div class="avatar">
                <?php if (!empty($pegawai['foto_profil'])): ?>
                    <img src="../<?php echo htmlspecialchars($pegawai['foto_profil']); ?>" alt="Foto">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
        </div>

        <div class="hero-clock">
            <div class="clock-left">
                <div class="c-time" id="clockTime">--:--:--</div>
                <div class="c-date"><?php echo $tanggal_panjang; ?></div>
            </div>
            <div class="clock-right">
                <?php
                $status_chip_class = 'belum';
                $status_chip_label = '● Belum Absen';
                $status_chip_icon  = 'fa-circle-xmark';
                if ($absensi_hari_ini) {
                    $s = $absensi_hari_ini['status_kehadiran'];
                    $status_chip_class = $s;
                    $label_map = [
                        'hadir'=>'✓ Hadir','izin'=>'Izin','sakit'=>'Sakit',
                        'alfa'=>'Alfa','cuti'=>'Cuti',
                        'dinas_luar'=>'Dinas','tugas_luar'=>'Tugas Luar'
                    ];
                    $status_chip_label = $label_map[$s] ?? ucfirst($s);
                }
                ?>
                <div class="status-today <?php echo $status_chip_class; ?>">
                    <i class="fas <?php echo ($status_chip_class === 'hadir') ? 'fa-circle-check' : (($status_chip_class === 'belum') ? 'fa-circle-xmark' : 'fa-circle-info'); ?>"></i>
                    <?php echo $status_chip_label; ?>
                </div>
                <div class="c-label">Status Hari Ini</div>
            </div>
        </div>
    </div>

    <!-- REKAP BULAN INI -->
    <p class="section-label">Rekap <?php echo $bln_nama . ' ' . $tahun_ini; ?></p>
    <div class="rekap-strip">
        <div class="rekap-item ri-hadir">
            <div class="ri-val"><?php echo $rekap['total_hadir'] ?? 0; ?></div>
            <div class="ri-label">Hadir</div>
        </div>
        <div class="rekap-item ri-izin">
            <div class="ri-val"><?php echo $rekap['total_izin'] ?? 0; ?></div>
            <div class="ri-label">Izin</div>
        </div>
        <div class="rekap-item ri-sakit">
            <div class="ri-val"><?php echo $rekap['total_sakit'] ?? 0; ?></div>
            <div class="ri-label">Sakit</div>
        </div>
        <div class="rekap-item ri-alfa">
            <div class="ri-val"><?php echo $rekap['total_alfa'] ?? 0; ?></div>
            <div class="ri-label">Alfa</div>
        </div>
    </div>

    <!-- MAIN MENU -->
    <p class="section-label">Menu Utama</p>
    <div class="menu-grid">

        <!-- Absensi -->
        <a href="#" class="menu-card accent-absensi" id="btnAbsensi" onclick="openModal();return false;">
            <?php if ($absensi_hari_ini === null): ?>
                <div class="menu-badge">Belum</div>
            <?php endif; ?>
            <div class="menu-icon ic-absensi"><i class="fas fa-fingerprint"></i></div>
            <div class="menu-body">
                <div class="menu-title">Absensi</div>
                <div class="menu-desc">Catat kehadiran hari ini</div>
            </div>
        </a>

        <!-- History -->
        <a href="#historySection" class="menu-card" onclick="scrollToHistory();return false;">
            <div class="menu-icon ic-history"><i class="fas fa-clock-rotate-left"></i></div>
            <div class="menu-body">
                <div class="menu-title">Riwayat</div>
                <div class="menu-desc">Lihat history absensi</div>
            </div>
            <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
        </a>

        <!-- Helpdesk — wide -->
        <a href="#helpdeskSection" class="menu-card wide" onclick="scrollToHelpdesk();return false;">
            <div class="menu-icon ic-helpdesk"><i class="fas fa-headset"></i></div>
            <div class="menu-body">
                <div class="menu-title">Hubungi Helpdesk</div>
                <div class="menu-desc">Butuh bantuan? Hubungi tim IT atau administrasi kami</div>
            </div>
            <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
        </a>

    </div>

    <!-- HISTORY -->
    <p class="section-label" id="historySection">Riwayat Absensi</p>
    <div class="history-list">
        <?php if (empty($history)): ?>
        <div class="history-empty">
            <i class="fas fa-inbox"></i>
            <p>Belum ada riwayat absensi.</p>
        </div>
        <?php else: foreach ($history as $h):
            $tgl   = new DateTime($h['tanggal']);
            $day   = $tgl->format('j');
            $month = strtoupper(substr($bln_id[$tgl->format('F')] ?? $tgl->format('M'), 0, 3));
            $jam_m = $h['jam_masuk']  ? substr($h['jam_masuk'], 0, 5)  : '--:--';
            $jam_k = $h['jam_keluar'] ? substr($h['jam_keluar'], 0, 5) : '--:--';
        ?>
        <div class="history-item">
            <div class="hi-date">
                <div class="hd-day"><?php echo $day; ?></div>
                <div class="hd-mon"><?php echo $month; ?></div>
            </div>
            <div class="hi-info">
                <div class="hi-top">
                    <span class="hi-kegiatan"><?php echo htmlspecialchars(str_replace('_',' ', $h['shift_atau_kegiatan'] ?? '-')); ?></span>
                    <?php echo badge_status($h['status_kehadiran']); ?>
                </div>
                <div class="hi-jam"><i class="fas fa-clock" style="font-size:10px;color:var(--gray-300);margin-right:3px;"></i><?php echo $jam_m; ?> &mdash; <?php echo $jam_k; ?></div>
                <?php if (!empty($h['lokasi'])): ?>
                <div class="hi-lokasi"><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($h['lokasi']); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if (!empty($history)): ?>
    <a href="history.php" class="btn-lihat-semua">
        <i class="fas fa-list-ul"></i> Lihat Semua Riwayat
    </a>
    <?php endif; ?>

    <!-- HELPDESK -->
    <p class="section-label" id="helpdeskSection">Bantuan &amp; Helpdesk</p>
    <div class="helpdesk-section">
        <div class="helpdesk-header">
            <div class="helpdesk-icon"><i class="fas fa-headset"></i></div>
            <div>
                <h3>Tim Helpdesk Polmain</h3>
                <p>Layanan Senin–Jumat, 08.00–16.00 WIB</p>
            </div>
        </div>
        <div class="helpdesk-contacts">
            <a href="https://wa.me/6281234567890?text=Halo+Helpdesk+Polmain,+saya+butuh+bantuan+terkait+sistem+absensi." target="_blank" class="contact-btn">
                <div class="contact-icon ci-wa"><i class="fab fa-whatsapp"></i></div>
                <div class="contact-info">
                    <div class="ci-name">WhatsApp Helpdesk</div>
                    <div class="ci-value">+62 812-3456-7890</div>
                </div>
                <i class="fas fa-chevron-right cb-arrow"></i>
            </a>
            <a href="tel:+62341123456" class="contact-btn">
                <div class="contact-icon ci-tel"><i class="fas fa-phone"></i></div>
                <div class="contact-info">
                    <div class="ci-name">Telepon Langsung</div>
                    <div class="ci-value">(0341) 123-456 ext. 101</div>
                </div>
                <i class="fas fa-chevron-right cb-arrow"></i>
            </a>
            <a href="mailto:helpdesk@polmain.ac.id?subject=Bantuan%20Sistem%20Absensi" class="contact-btn">
                <div class="contact-icon ci-mail"><i class="fas fa-envelope"></i></div>
                <div class="contact-info">
                    <div class="ci-name">Email Helpdesk</div>
                    <div class="ci-value">helpdesk@polmain.ac.id</div>
                </div>
                <i class="fas fa-chevron-right cb-arrow"></i>
            </a>
        </div>
    </div>

</div><!-- /page-content -->

<!-- BOTTOM NAV -->
<nav class="bottom-nav">
    <a class="nav-item active" href="#" onclick="scrollToTop();return false;">
        <i class="fas fa-house"></i>
        <span>Beranda</span>
    </a>
    <a class="nav-item" href="#" onclick="openModal();return false;">
        <i class="fas fa-fingerprint"></i>
        <span>Absensi</span>
    </a>
    <a class="nav-item" href="#" onclick="scrollToHistory();return false;">
        <i class="fas fa-clock-rotate-left"></i>
        <span>Riwayat</span>
    </a>
    <a class="nav-item" href="#" onclick="scrollToHelpdesk();return false;">
        <i class="fas fa-headset"></i>
        <span>Helpdesk</span>
    </a>
</nav>

<!-- ── MODAL ABSENSI ────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalAbsensi">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-header">
            <h3><i class="fas fa-fingerprint" style="color:var(--yellow);margin-right:6px;"></i>Catat Absensi</h3>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form class="absensi-form" action="proses_absensi.php" method="POST" id="formAbsensi">

                <!-- Tanggal — readonly, otomatis -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-calendar"></i> Tanggal</label>
                    <input type="text" class="form-control"
                           value="<?php echo $tanggal_panjang; ?>" readonly
                           class="form-control input-readonly">
                    <input type="hidden" name="tanggal" value="<?php echo date('Y-m-d'); ?>">
                </div>

                <?php if ($position === 'staff'): ?>
                <!-- Shift — hanya untuk staff -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-business-time"></i> Shift</label>
                    <select name="shift" class="form-select" required>
                        <option value="pagi">Pagi (07.00–15.00)</option>
                        <option value="siang">Siang (13.00–21.00)</option>
                        <option value="malam">Malam (21.00–07.00)</option>
                        <option value="full">Full Day</option>
                    </select>
                </div>
                <?php else: ?>
                <!-- Jenis Kegiatan — hanya untuk dosen -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-chalkboard-teacher"></i> Jenis Kegiatan</label>
                    <select name="jenis_kegiatan" class="form-select" required>
                        <option value="mengajar">Mengajar</option>
                        <option value="rapat">Rapat</option>
                        <option value="administratif">Administratif</option>
                        <option value="penelitian">Penelitian</option>
                        <option value="pengabdian">Pengabdian</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Jam Masuk -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-clock"></i> Jam Masuk</label>
                    <input type="time" name="jam_masuk" class="form-control"
                           value="<?php echo date('H:i'); ?>" required>
                </div>

                <!-- Jam Keluar (opsional) -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-clock"></i> Jam Keluar <span style="font-weight:400;font-style:italic;text-transform:none;">(opsional)</span></label>
                    <input type="time" name="jam_keluar" class="form-control">
                </div>

                <!-- Lokasi -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-location-dot"></i> Lokasi</label>
                    <div class="input-wrap" style="position:relative;">
                        <input type="text" name="lokasi" class="form-control"
                               placeholder="Gedung / Ruang / Keterangan lokasi"
                               id="inputLokasi" style="padding-right:42px;">
                        <span id="gpsSpinner" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);
                              color:var(--gray-400);font-size:13px;display:none;">
                            <i class="fas fa-spinner fa-spin"></i>
                        </span>
                        <span id="gpsOk" style="position:absolute;right:13px;top:50%;transform:translateY(-50%);
                              color:#16a34a;font-size:13px;display:none;">
                            <i class="fas fa-circle-check"></i>
                        </span>
                    </div>
                    <input type="hidden" name="koordinat" id="inputKoordinat">

                    <!-- Notifikasi GPS -->
                    <div id="gpsNotif" style="display:none;margin-top:7px;padding:9px 12px;border-radius:10px;
                         font-size:12px;font-weight:600;line-height:1.5;
                         display:none;align-items:flex-start;gap:8px;">
                    </div>
                </div>

                <!-- Status Kehadiran -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-circle-dot"></i> Status Kehadiran</label>
                    <div class="status-pills">
                        <button type="button" class="status-pill selected-hadir" data-val="hadir" onclick="pilihStatus(this)">✓ Hadir</button>
                        <button type="button" class="status-pill" data-val="izin"  onclick="pilihStatus(this)">Izin</button>
                        <button type="button" class="status-pill" data-val="sakit" onclick="pilihStatus(this)">Sakit</button>
                        <button type="button" class="status-pill" data-val="alfa"  onclick="pilihStatus(this)">Alfa</button>
                        <button type="button" class="status-pill" data-val="cuti"  onclick="pilihStatus(this)">Cuti</button>
                        <?php if ($position === 'staff'): ?>
                        <button type="button" class="status-pill" data-val="dinas_luar" onclick="pilihStatus(this)">Dinas</button>
                        <?php else: ?>
                        <button type="button" class="status-pill" data-val="tugas_luar" onclick="pilihStatus(this)">Tugas Luar</button>
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="status_kehadiran" id="inputStatus" value="hadir">
                </div>

                <!-- Keterangan -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-pen-to-square"></i> Keterangan <span style="font-weight:400;font-style:italic;text-transform:none;">(opsional)</span></label>
                    <textarea name="keterangan" class="form-control"
                              placeholder="Tuliskan keterangan jika diperlukan..."></textarea>
                </div>

                <input type="hidden" name="position" value="<?php echo htmlspecialchars($position); ?>">
                <input type="hidden" name="metode_absensi" value="manual">

                <button type="submit" class="btn-absen-submit">
                    <i class="fas fa-check-circle"></i> Simpan Absensi
                </button>
            </form>
        </div>
    </div>
</div>

<!-- ── POPUP KONFIRMASI DUPLIKAT ABSENSI ─────────────────────────── -->
<div class="popup-overlay" id="popupDuplikat">
    <div class="popup-box">
        <div class="popup-icon"><i class="fas fa-triangle-exclamation"></i></div>
        <h4>Sudah Pernah Absen</h4>
        <p>Kamu sudah tercatat absen pada shift/kegiatan ini hari ini.</p>

        <div class="popup-info">
            <div class="popup-info-row">
                <span class="pir-label">Shift / Kegiatan</span>
                <span class="pir-val" id="popupShift">—</span>
            </div>
            <div class="popup-info-row">
                <span class="pir-label">Status</span>
                <span class="pir-val" id="popupStatus">—</span>
            </div>
            <div class="popup-info-row">
                <span class="pir-label">Jam Masuk</span>
                <span class="pir-val" id="popupJamMasuk">—</span>
            </div>
            <div class="popup-info-row">
                <span class="pir-label">Jam Keluar</span>
                <span class="pir-val" id="popupJamKeluar">—</span>
            </div>
        </div>

        <p style="font-size:12px;color:var(--gray-400);text-align:center;">
            Silakan pilih shift lain atau hubungi admin jika ada kesalahan.
        </p>

        <div class="popup-actions" style="margin-top:14px;">
            <button class="popup-btn popup-btn-cancel" onclick="tutupPopupDuplikat()" style="flex:1;">
                <i class="fas fa-check"></i> Mengerti
            </button>
        </div>
    </div>
</div>

<!-- ── POPUP DUPLIKAT ABSENSI ───────────────────────────────────────────── -->
<div class="popup-overlay" id="popupDuplikat">
    <div class="popup-box">
        <div class="popup-icon">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 class="popup-title">Sudah Absen</h3>
        <p class="popup-desc">
            Kamu sudah tercatat absen untuk
            <strong id="popupShift">—</strong>
            hari ini.
        </p>
        <div class="popup-detail">
            <div class="pd-row">
                <span class="pd-label"><i class="fas fa-circle-dot"></i> Status</span>
                <span class="pd-val" id="popupStatus">—</span>
            </div>
            <div class="pd-row">
                <span class="pd-label"><i class="fas fa-right-to-bracket"></i> Masuk</span>
                <span class="pd-val" id="popupJamMasuk">—</span>
            </div>
            <div class="pd-row">
                <span class="pd-label"><i class="fas fa-right-from-bracket"></i> Keluar</span>
                <span class="pd-val" id="popupJamKeluar">—</span>
            </div>
        </div>
        <p class="popup-hint">Anda sudah tercatat hadir untuk shift ini. Silakan pilih shift lain atau hubungi admin jika ada kesalahan.</p>
        <div class="popup-actions">
            <button class="popup-btn-batal" onclick="tutupPopupDuplikat()" style="flex:1;">
                <i class="fas fa-check"></i> Mengerti
            </button>
        </div>
    </div>
</div>

<script>
// ── Data absensi hari ini dari PHP (map shift/kegiatan → detail) ──────────
const absensiHariIni = <?php echo json_encode($absensi_hari_map, JSON_UNESCAPED_UNICODE); ?>;
const positionUser   = '<?php echo $position; ?>';

// Label untuk tampilan
const labelShift = {
    pagi:'Pagi (07.00–15.00)', siang:'Siang (13.00–21.00)',
    malam:'Malam (21.00–07.00)', full:'Full Day',
    mengajar:'Mengajar', rapat:'Rapat', administratif:'Administratif',
    penelitian:'Penelitian', pengabdian:'Pengabdian', lainnya:'Lainnya'
};
const labelStatus = {
    hadir:'Hadir', izin:'Izin', sakit:'Sakit',
    alfa:'Alfa', cuti:'Cuti', dinas_luar:'Dinas Luar', tugas_luar:'Tugas Luar'
};

(function () {
    /* ── Data absensi hari ini dari PHP (untuk cek duplikat) ── */
    const absensiHariIni = <?php echo json_encode($absensi_hari_map, JSON_UNESCAPED_UNICODE); ?>;

    const labelShift = {
        pagi: 'Shift Pagi', siang: 'Shift Siang',
        malam: 'Shift Malam', full: 'Full Day',
        mengajar: 'Mengajar', rapat: 'Rapat',
        administratif: 'Administratif', penelitian: 'Penelitian',
        pengabdian: 'Pengabdian', lainnya: 'Lainnya',
    };
    const labelStatus = {
        hadir: 'Hadir', izin: 'Izin', sakit: 'Sakit',
        alfa: 'Alfa', cuti: 'Cuti',
        dinas_luar: 'Dinas Luar', tugas_luar: 'Tugas Luar',
    };

    // Expose ke scope global agar fungsi luar bisa akses
    window._absensiHariIni = absensiHariIni;
    window._labelShift     = labelShift;
    window._labelStatus    = labelStatus;
    /* ── Clock & greeting ── */
    const greets = [
        [0,  'Selamat Malam'],
        [5,  'Selamat Pagi'],
        [11, 'Selamat Siang'],
        [15, 'Selamat Sore'],
        [18, 'Selamat Malam'],
    ];

    function getGreet(h) {
        let g = greets[0][1];
        for (const [hour, label] of greets) { if (h >= hour) g = label; }
        return g;
    }

    function tick() {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2, '0');
        const mm = String(now.getMinutes()).padStart(2, '0');
        const ss = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('clockTime').textContent = `${hh}:${mm}:${ss}`;
        document.getElementById('greetingTime').textContent = getGreet(now.getHours());
    }
    tick(); setInterval(tick, 1000);

    /* ── Dark mode ── */
    const body = document.body;
    const toggle = document.getElementById('darkToggle');
    if (localStorage.getItem('absensiDark') === '1') body.classList.add('dark');
    toggle.addEventListener('click', () => {
        body.classList.toggle('dark');
        localStorage.setItem('absensiDark', body.classList.contains('dark') ? '1' : '0');
    });

    /* ── Bottom nav active state ── */
    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(el => {
        el.addEventListener('click', function () {
            navItems.forEach(n => n.classList.remove('active'));
            this.classList.add('active');
        });
    });

})();

/* ── Modal ── */
let gpsRequested = false;

function showGpsNotif(tipe, pesan) {
    const el = document.getElementById('gpsNotif');
    const s  = {
        warning: { bg:'#fffbeb', border:'#fde68a', color:'#92400e', icon:'fa-triangle-exclamation' },
        error:   { bg:'#fef2f2', border:'#fecaca', color:'#991b1b', icon:'fa-circle-xmark'         },
        info:    { bg:'#eff6ff', border:'#bfdbfe', color:'#1e40af', icon:'fa-circle-info'           },
    }[tipe] || { bg:'#eff6ff', border:'#bfdbfe', color:'#1e40af', icon:'fa-circle-info' };
    el.style.cssText  = `display:flex;margin-top:7px;padding:9px 12px;border-radius:10px;
                          font-size:12px;font-weight:600;line-height:1.5;
                          align-items:flex-start;gap:8px;
                          background:${s.bg};border:1px solid ${s.border};color:${s.color}`;
    el.innerHTML      = `<i class="fas ${s.icon}" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i><span>${pesan}</span>`;
}

function requestGPS() {
    const spinner = document.getElementById('gpsSpinner');
    const ok      = document.getElementById('gpsOk');

    if (!navigator.geolocation) {
        showGpsNotif('info', 'Browser Anda tidak mendukung GPS. Silakan isi lokasi secara manual.');
        return;
    }

    spinner.style.display = 'inline';

    navigator.geolocation.getCurrentPosition(
        pos => {
            spinner.style.display = 'none';
            const { latitude: lat, longitude: lng } = pos.coords;
            document.getElementById('inputKoordinat').value = `${lat},${lng}`;

            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
                .then(r => r.json())
                .then(d => {
                    const lok = document.getElementById('inputLokasi');
                    if (!lok.value && d.display_name) {
                        lok.value = d.display_name.split(',').slice(0, 3).join(', ');
                    }
                    ok.style.display = 'inline';
                })
                .catch(() => { ok.style.display = 'inline'; });
        },
        err => {
            spinner.style.display = 'none';
            const pesan = {
                1: 'Akses lokasi <strong>ditolak</strong>. Aktifkan izin lokasi di pengaturan browser, lalu muat ulang halaman.',
                2: 'Lokasi tidak dapat ditentukan. Pastikan GPS aktif dan perangkat terhubung jaringan.',
                3: 'Permintaan lokasi <strong>habis waktu</strong>. Periksa sinyal GPS dan coba lagi.',
            }[err.code] || 'Gagal mendapatkan lokasi. Isi lokasi secara manual.';
            showGpsNotif(err.code === 1 ? 'warning' : 'error', pesan);
        },
        { timeout: 10000, maximumAge: 60000 }
    );
}

function openModal() {
    document.getElementById('modalAbsensi').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.querySelectorAll('.nav-item')[1].classList.add('active');

    // Minta GPS sekali saja saat modal pertama dibuka
    if (!gpsRequested) {
        gpsRequested = true;
        requestGPS();
    }
}
function closeModal() {
    document.getElementById('modalAbsensi').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('modalAbsensi').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

/* ── Status pills ── */
function pilihStatus(el) {
    document.querySelectorAll('.status-pill').forEach(p => {
        p.className = 'status-pill';
    });
    const val = el.dataset.val;
    const cls = val === 'dinas_luar' || val === 'tugas_luar' ? 'dinas' : val;
    el.classList.add('selected-' + cls);
    document.getElementById('inputStatus').value = val;
}

/* ── Cek duplikat sebelum submit ── */
document.getElementById('formAbsensi').addEventListener('submit', function (e) {
    e.preventDefault();
    cekDuplikatLaluSubmit();
});

function getShiftDipilih() {
    const sel = document.querySelector('[name="shift"], [name="jenis_kegiatan"]');
    return sel ? sel.value : null;
}

function cekDuplikatLaluSubmit() {
    const shiftDipilih = getShiftDipilih();
    const absensiHariIni = window._absensiHariIni || {};
    const labelShift     = window._labelShift     || {};
    const labelStatus    = window._labelStatus    || {};

    if (shiftDipilih && absensiHariIni[shiftDipilih]) {
        const data = absensiHariIni[shiftDipilih];
        document.getElementById('popupShift').textContent     = labelShift[shiftDipilih] ?? shiftDipilih;
        document.getElementById('popupStatus').textContent    = labelStatus[data.status]  ?? data.status;
        document.getElementById('popupJamMasuk').textContent  = data.jam_masuk  || '--:--';
        document.getElementById('popupJamKeluar').textContent = data.jam_keluar || '--:--';
        document.getElementById('popupDuplikat').classList.add('open');
    } else {
        submitAbsensi();
    }
}

function tutupPopupDuplikat() {
    document.getElementById('popupDuplikat').classList.remove('open');
}

function lanjutkanAbsensi() {
    tutupPopupDuplikat();
    submitAbsensi();
}

function submitAbsensi() {
    const btn = document.querySelector('.btn-absen-submit');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    btn.disabled  = true;
    document.getElementById('formAbsensi').submit();
}

// Tutup popup jika klik di luar box
document.getElementById('popupDuplikat').addEventListener('click', function (e) {
    if (e.target === this) tutupPopupDuplikat();
});

/* ── Scroll helpers ── */
function scrollToTop()      { window.scrollTo({top:0, behavior:'smooth'}); setActive(0); }
function scrollToHistory()  { document.getElementById('historySection').scrollIntoView({behavior:'smooth', block:'start'}); setActive(2); }
function scrollToHelpdesk() { document.getElementById('helpdeskSection').scrollIntoView({behavior:'smooth', block:'start'}); setActive(3); }

function setActive(idx) {
    document.querySelectorAll('.nav-item').forEach((n, i) => n.classList.toggle('active', i === idx));
}
</script>
</body>
</html>