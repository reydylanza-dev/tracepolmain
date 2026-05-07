<?php
ob_start(); // buffer output agar warning/notice tidak merusak JSON response
session_start();
date_default_timezone_set('Asia/Jakarta');

// ── Guard: harus login & harus admin/pimpinan ────────────────────────────────
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}
if (!in_array($_SESSION["position"] ?? '', ['admin', 'pimpinan'])) {
    header("location: home/index.php");
    exit;
}

require_once "koneksi.php";
mysqli_query($link, "SET time_zone = '+07:00'");

// ── AJAX: filter tabel realtime (ditangani lebih awal agar tidak ada output sebelum JSON) ──
if (isset($_GET['ajax_filter'])) {
    $af_tipe   = $_GET['tipe']   ?? 'semua';
    $af_bulan  = max(1, min(12, (int)($_GET['bulan'] ?? date('n'))));
    $af_tahun  = max(2020, min((int)date('Y'), (int)($_GET['tahun'] ?? date('Y'))));
    $af_cari   = trim($_GET['cari'] ?? '');
    $af_hal    = max(1, (int)($_GET['hal'] ?? 1));
    $af_per    = 15;

    if (!in_array($af_tipe, ['semua','staff','dosen'])) $af_tipe = 'semua';

    // Helper
    $mk_where = fn($a) => $af_cari ? "AND {$a}.nama LIKE ?" : "";
    $mk_vals  = fn() => $af_cari ? ["%{$af_cari}%"] : [];

    // Count
    $cnt_staff = 0; $cnt_dosen = 0;
    if ($af_tipe !== 'dosen') {
        $r = mysqli_prepare($link, "SELECT COUNT(*) FROM data_staff s WHERE s.status_aktif=1 " . $mk_where('s'));
        if ($r) {
            $v = $mk_vals(); if ($v) mysqli_stmt_bind_param($r,"s",...$v);
            mysqli_stmt_execute($r); mysqli_stmt_bind_result($r,$cnt_staff); mysqli_stmt_fetch($r); mysqli_stmt_close($r);
        }
    }
    if ($af_tipe !== 'staff') {
        $r = mysqli_prepare($link, "SELECT COUNT(*) FROM data_dosen d WHERE d.status_aktif=1 " . $mk_where('d'));
        if ($r) {
            $v = $mk_vals(); if ($v) mysqli_stmt_bind_param($r,"s",...$v);
            mysqli_stmt_execute($r); mysqli_stmt_bind_result($r,$cnt_dosen); mysqli_stmt_fetch($r); mysqli_stmt_close($r);
        }
    }
    $total = $cnt_staff + $cnt_dosen;
    $total_hal = max(1, (int)ceil($total / $af_per));
    $af_hal = min($af_hal, $total_hal);
    $off = ($af_hal - 1) * $af_per;

    // Ambil data
    $res_rows = [];

    $fetch = function(string $tbl_p, string $col_nik, string $tbl_a, string $jam1, string $jam2,
                       string $lok_col, string $extra_sel, string $alias,
                       int $lim, int $ofs) use ($link, $af_bulan, $af_tahun, $af_cari, $mk_where, $mk_vals) {

        $sql = "SELECT
                    {$alias}.nik, {$alias}.nama, {$alias}.foto_profil,
                    " . ($alias==='s' ? "{$alias}.nip" : "{$alias}.nidn AS nip") . ",
                    j.nama_jabatan, {$extra_sel},
                    COALESCE(SUM(a.status_kehadiran='hadir'),0)      AS hadir,
                    COALESCE(SUM(a.status_kehadiran='izin'),0)       AS izin,
                    COALESCE(SUM(a.status_kehadiran='sakit'),0)      AS sakit,
                    COALESCE(SUM(a.status_kehadiran='alfa'),0)       AS alfa,
                    COALESCE(SUM(a.status_kehadiran='cuti'),0)       AS cuti,
                    COALESCE(SUM(a.status_kehadiran IN('dinas_luar','tugas_luar')),0) AS dinas,
                    COUNT(a.id)     AS total_record,
                    MAX(a.tanggal)  AS terakhir_absen
                FROM {$tbl_p} {$alias}
                LEFT JOIN data_jabatan j ON j.kode_jabatan = {$alias}.kode_jabatan
                " . ($alias==='d' ? "LEFT JOIN data_kuliah k ON k.kode_prodi = {$alias}.kode_prodi" : "") . "
                LEFT JOIN {$tbl_a} a ON a.{$col_nik} = {$alias}.nik
                    AND MONTH(a.tanggal)=? AND YEAR(a.tanggal)=?
                WHERE {$alias}.status_aktif=1 " . ($af_cari ? "AND {$alias}.nama LIKE ?" : "") . "
                GROUP BY {$alias}.nik, {$alias}.nama, {$alias}.foto_profil,
                         " . ($alias==='s' ? "{$alias}.nip" : "{$alias}.nidn") . ",
                         j.nama_jabatan, " . preg_replace('/\s+AS\s+\w+/i', '', $extra_sel) . "
                ORDER BY {$alias}.nama ASC
                LIMIT ? OFFSET ?";

        $types = "ii" . ($af_cari ? "s" : "") . "ii";
        $vals  = [$af_bulan, $af_tahun, ...$mk_vals(), $lim, $ofs];
        $out = [];
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, $types, ...$vals);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
            mysqli_stmt_close($stmt);
        }
        return $out;
    };

    if ($af_tipe === 'staff') {
        $rows = $fetch('data_staff','staff_nik','absensi_staff','jam_masuk','jam_keluar','lokasi_masuk',
                       's.unit_kerja','s', $af_per, $off);
        foreach ($rows as &$r) $r['tipe'] = 'staff';
        $res_rows = $rows;
    } elseif ($af_tipe === 'dosen') {
        $rows = $fetch('data_dosen','dosen_nik','absensi_dosen','jam_mulai','jam_selesai','lokasi',
                       'k.nama_prodi AS unit_kerja','d', $af_per, $off);
        foreach ($rows as &$r) $r['tipe'] = 'dosen';
        $res_rows = $rows;
    } else {
        // Gabung: ambil semua, sort, slice
        $rs = $fetch('data_staff','staff_nik','absensi_staff','jam_masuk','jam_keluar','lokasi_masuk',
                     's.unit_kerja','s', $cnt_staff, 0);
        $rd = $fetch('data_dosen','dosen_nik','absensi_dosen','jam_mulai','jam_selesai','lokasi',
                     'k.nama_prodi AS unit_kerja','d', $cnt_dosen, 0);
        foreach ($rs as &$r) $r['tipe'] = 'staff';
        foreach ($rd as &$r) $r['tipe'] = 'dosen';
        $all = array_merge($rs, $rd);
        usort($all, fn($a,$b) => strcmp($a['nama'], $b['nama']));
        $res_rows = array_slice($all, $off, $af_per);
    }

    mysqli_close($link);
    ob_end_clean(); // buang output apapun sebelum JSON
    header('Content-Type: application/json');
    echo json_encode([
        'rows'        => $res_rows,
        'total'       => $total,
        'halaman'     => $af_hal,
        'total_hal'   => $total_hal,
        'bulan'       => $af_bulan,
        'tahun'       => $af_tahun,
        'today'       => date('Y-m-d'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AJAX: detail absensi per pegawai (ditangani lebih awal) ──────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $nik_a   = trim($_GET['nik']   ?? '');
    $tipe_a  = trim($_GET['tipe']  ?? 'staff');
    $bulan_a = (int)($_GET['bulan'] ?? date('n'));
    $tahun_a = (int)($_GET['tahun'] ?? date('Y'));

    if (!$nik_a) { header('Content-Type: application/json'); echo json_encode([]); exit; }

    if ($tipe_a === 'dosen') {
        $sql_det = "SELECT tanggal, jenis_kegiatan AS kegiatan, jam_mulai AS jam_masuk,
                           jam_selesai AS jam_keluar, lokasi, status_kehadiran, keterangan, metode_absensi
                    FROM absensi_dosen
                    WHERE dosen_nik=? AND MONTH(tanggal)=? AND YEAR(tanggal)=?
                    ORDER BY tanggal ASC";
    } else {
        $sql_det = "SELECT tanggal, NULL AS kegiatan, jam_masuk, jam_keluar,
                           lokasi_masuk AS lokasi, status_kehadiran, keterangan, metode_absensi
                    FROM absensi_staff
                    WHERE staff_nik=? AND MONTH(tanggal)=? AND YEAR(tanggal)=?
                    ORDER BY tanggal ASC";
    }

    $out_det = [];
    if ($stmt = mysqli_prepare($link, $sql_det)) {
        mysqli_stmt_bind_param($stmt, "sii", $nik_a, $bulan_a, $tahun_a);
        mysqli_stmt_execute($stmt);
        $res_det = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res_det)) $out_det[] = $row;
        mysqli_stmt_close($stmt);
    }

    mysqli_close($link);
    ob_end_clean(); // buang output apapun sebelum JSON
    header('Content-Type: application/json');
    echo json_encode($out_det, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Parameter filter ─────────────────────────────────────────────────────────
$filter_tipe   = $_GET["tipe"]   ?? "semua";
$filter_bulan  = isset($_GET["bulan"])  ? (int)$_GET["bulan"]  : (int)date('n');
$filter_tahun  = isset($_GET["tahun"])  ? (int)$_GET["tahun"]  : (int)date('Y');
$filter_status = $_GET["status"] ?? "semua";
$filter_cari   = trim($_GET["cari"] ?? "");
$halaman       = isset($_GET["hal"]) ? max(1, (int)$_GET["hal"]) : 1;
$per_halaman   = 15;

$filter_bulan = max(1, min(12, $filter_bulan));
$filter_tahun = max(2020, min((int)date('Y'), $filter_tahun));
if (!in_array($filter_tipe, ['semua','staff','dosen'])) $filter_tipe = 'semua';

$nama_bulan = ['','Januari','Februari','Maret','April','Mei','Juni',
               'Juli','Agustus','September','Oktober','November','Desember'];
$nama_hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

// ── Ringkasan global bulan ini ────────────────────────────────────────────────
$sql_summary = "SELECT
    (SELECT COUNT(DISTINCT staff_nik) FROM absensi_staff
     WHERE MONTH(tanggal)=? AND YEAR(tanggal)=?) +
    (SELECT COUNT(DISTINCT dosen_nik) FROM absensi_dosen
     WHERE MONTH(tanggal)=? AND YEAR(tanggal)=?)  AS total_aktif,

    (SELECT COUNT(*) FROM absensi_staff
     WHERE MONTH(tanggal)=? AND YEAR(tanggal)=? AND status_kehadiran='hadir') +
    (SELECT COUNT(*) FROM absensi_dosen
     WHERE MONTH(tanggal)=? AND YEAR(tanggal)=? AND status_kehadiran='hadir') AS total_hadir,

    (SELECT COUNT(*) FROM absensi_staff
     WHERE MONTH(tanggal)=? AND YEAR(tanggal)=? AND status_kehadiran='alfa') +
    (SELECT COUNT(*) FROM absensi_dosen
     WHERE MONTH(tanggal)=? AND YEAR(tanggal)=? AND status_kehadiran='alfa')  AS total_alfa,

    (SELECT COUNT(*) FROM absensi_staff  WHERE tanggal=CURDATE()) +
    (SELECT COUNT(*) FROM absensi_dosen  WHERE tanggal=CURDATE())              AS hadir_hari_ini";

$summary = [];
if ($stmt = mysqli_prepare($link, $sql_summary)) {
    $b = $filter_bulan; $y = $filter_tahun;
    mysqli_stmt_bind_param($stmt, "iiiiiiiiiiii", $b,$y,$b,$y,$b,$y,$b,$y,$b,$y,$b,$y);
    mysqli_stmt_execute($stmt);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

// ── Ambil data pegawai + rekap absensi mereka ─────────────────────────────────
// Helper bangun WHERE + bind untuk pencarian nama
function cari_where(string $alias, string $cari): string {
    return $cari ? "AND {$alias}.nama LIKE ?" : "";
}
function cari_val(string $cari): array {
    return $cari ? ["%{$cari}%"] : [];
}

// ── Hitung total pegawai (untuk pagination) ───────────────────────────────────
$total_staff = 0;
$total_dosen = 0;

if ($filter_tipe !== 'dosen') {
    $sql_cs = "SELECT COUNT(*) FROM data_staff s WHERE s.status_aktif = 1 " . cari_where('s', $filter_cari);
    $tv = cari_val($filter_cari);
    if ($stmt = mysqli_prepare($link, $sql_cs)) {
        if ($tv) mysqli_stmt_bind_param($stmt, "s", ...$tv);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $total_staff);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }
}
if ($filter_tipe !== 'staff') {
    $sql_cd = "SELECT COUNT(*) FROM data_dosen d WHERE d.status_aktif = 1 " . cari_where('d', $filter_cari);
    $tv = cari_val($filter_cari);
    if ($stmt = mysqli_prepare($link, $sql_cd)) {
        if ($tv) mysqli_stmt_bind_param($stmt, "s", ...$tv);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $total_dosen);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }
}

$total_rows    = $total_staff + $total_dosen;
$total_halaman = max(1, (int)ceil($total_rows / $per_halaman));
$halaman       = min($halaman, $total_halaman);
$offset        = ($halaman - 1) * $per_halaman;

// ── Ambil data dengan LIMIT/OFFSET ──────────────────────────────────────────
// Untuk mode "semua": ambil staff + dosen lalu sort+slice di PHP
// Untuk mode spesifik: LIMIT langsung di SQL

$rows_staff = [];
if ($filter_tipe !== 'dosen') {
    $limit_staff  = $filter_tipe === 'staff' ? $per_halaman : $total_staff; // ambil semua jika mode gabungan
    $offset_staff = $filter_tipe === 'staff' ? $offset       : 0;

    $sql_staff = "SELECT
                    s.nik, s.nama, s.foto_profil, s.nip,
                    j.nama_jabatan, s.unit_kerja,
                    COALESCE(SUM(a.status_kehadiran='hadir'), 0)      AS hadir,
                    COALESCE(SUM(a.status_kehadiran='izin'),  0)      AS izin,
                    COALESCE(SUM(a.status_kehadiran='sakit'), 0)      AS sakit,
                    COALESCE(SUM(a.status_kehadiran='alfa'),  0)      AS alfa,
                    COALESCE(SUM(a.status_kehadiran='cuti'),  0)      AS cuti,
                    COALESCE(SUM(a.status_kehadiran='dinas_luar'), 0) AS dinas,
                    COUNT(a.id)                                       AS total_record,
                    MAX(a.tanggal)                                    AS terakhir_absen
                  FROM data_staff s
                  LEFT JOIN data_jabatan j ON j.kode_jabatan = s.kode_jabatan
                  LEFT JOIN absensi_staff a
                         ON a.staff_nik    = s.nik
                        AND MONTH(a.tanggal) = ?
                        AND YEAR(a.tanggal)  = ?
                  WHERE s.status_aktif = 1
                  " . cari_where('s', $filter_cari) . "
                  GROUP BY s.nik, s.nama, s.foto_profil, s.nip, j.nama_jabatan, s.unit_kerja
                  ORDER BY s.nama ASC
                  LIMIT ? OFFSET ?";

    $types = "ii" . ($filter_cari ? "s" : "") . "ii";
    $vals  = [$filter_bulan, $filter_tahun, ...cari_val($filter_cari), $limit_staff, $offset_staff];

    if ($stmt = mysqli_prepare($link, $sql_staff)) {
        mysqli_stmt_bind_param($stmt, $types, ...$vals);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) { $r['tipe'] = 'staff'; $rows_staff[] = $r; }
        mysqli_stmt_close($stmt);
    }
}

$rows_dosen = [];
if ($filter_tipe !== 'staff') {
    $limit_dosen  = $filter_tipe === 'dosen' ? $per_halaman : $total_dosen;
    $offset_dosen = $filter_tipe === 'dosen' ? $offset       : 0;

    $sql_dosen = "SELECT
                    d.nik, d.nama, d.foto_profil, d.nidn AS nip,
                    j.nama_jabatan, k.nama_prodi AS unit_kerja,
                    COALESCE(SUM(a.status_kehadiran='hadir'),      0) AS hadir,
                    COALESCE(SUM(a.status_kehadiran='izin'),       0) AS izin,
                    COALESCE(SUM(a.status_kehadiran='sakit'),      0) AS sakit,
                    COALESCE(SUM(a.status_kehadiran='alfa'),       0) AS alfa,
                    COALESCE(SUM(a.status_kehadiran='cuti'),       0) AS cuti,
                    COALESCE(SUM(a.status_kehadiran='tugas_luar'), 0) AS dinas,
                    COUNT(a.id)                                       AS total_record,
                    MAX(a.tanggal)                                    AS terakhir_absen
                  FROM data_dosen d
                  LEFT JOIN data_jabatan j ON j.kode_jabatan = d.kode_jabatan
                  LEFT JOIN data_kuliah  k ON k.kode_prodi   = d.kode_prodi
                  LEFT JOIN absensi_dosen a
                         ON a.dosen_nik    = d.nik
                        AND MONTH(a.tanggal) = ?
                        AND YEAR(a.tanggal)  = ?
                  WHERE d.status_aktif = 1
                  " . cari_where('d', $filter_cari) . "
                  GROUP BY d.nik, d.nama, d.foto_profil, d.nidn, j.nama_jabatan, k.nama_prodi
                  ORDER BY d.nama ASC
                  LIMIT ? OFFSET ?";

    $types = "ii" . ($filter_cari ? "s" : "") . "ii";
    $vals  = [$filter_bulan, $filter_tahun, ...cari_val($filter_cari), $limit_dosen, $offset_dosen];

    if ($stmt = mysqli_prepare($link, $sql_dosen)) {
        mysqli_stmt_bind_param($stmt, $types, ...$vals);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) { $r['tipe'] = 'dosen'; $rows_dosen[] = $r; }
        mysqli_stmt_close($stmt);
    }
}

mysqli_close($link);

// Helper persen
function pct($hadir, $total) {
    return $total > 0 ? min(100, round($hadir / $total * 100)) : 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Absensi | Pimpinan</title>
    <link rel="icon" href="img/ico.png" type="image/png">
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
            --gray-700:   #334155;
            --gray-800:   #1e293b;
            --green:      #16a34a;
            --red:        #dc2626;
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
            min-height:100vh;
            transition:background .35s, color .35s;
        }

        /* ── TOP BAR ── */
        .top-bar {
            position:sticky; top:0; z-index:100;
            background:var(--white); border-bottom:1px solid var(--gray-200);
            height:60px; padding:0 24px;
            display:flex; align-items:center; justify-content:space-between;
            box-shadow:0 1px 8px rgba(0,0,0,.06);
            transition:background .35s, border-color .35s;
        }
        .brand { display:flex; align-items:center; gap:10px; text-decoration:none; }
        .brand-icon {
            width:36px; height:36px; border-radius:10px;
            background:linear-gradient(135deg,var(--blue),var(--blue-mid));
            display:flex; align-items:center; justify-content:center;
        }
        .brand-icon i { color:var(--yellow); font-size:15px; }
        .brand-text .t1 { font-size:14px; font-weight:800; color:var(--blue); line-height:1.2; transition:color .35s; }
        .brand-text .t2 { font-size:9.5px; color:var(--gray-400); text-transform:uppercase; letter-spacing:.5px; }
        .topbar-right { display:flex; align-items:center; gap:10px; }
        .dark-toggle {
            width:40px; height:22px; background:var(--gray-200);
            border:1.5px solid var(--gray-300); border-radius:999px;
            cursor:pointer; position:relative; transition:all .3s;
        }
        .dark-toggle::after {
            content:''; position:absolute; top:2px; right:2px;
            width:14px; height:14px; background:var(--yellow); border-radius:50%;
            transition:all .3s; box-shadow:0 1px 3px rgba(0,0,0,.2);
        }
        .btn-logout {
            height:34px; padding:0 14px; border-radius:9px;
            background:var(--gray-100); border:1px solid var(--gray-200);
            display:flex; align-items:center; gap:6px;
            color:var(--gray-600); font-size:12.5px; font-weight:700;
            font-family:"Plus Jakarta Sans",sans-serif;
            cursor:pointer; text-decoration:none; transition:all .2s;
        }
        .btn-logout:hover { background:#fef2f2; color:var(--red); border-color:#fecaca; }
        .user-chip {
            display:flex; align-items:center; gap:7px;
            background:var(--gray-100); border:1px solid var(--gray-200);
            border-radius:999px; padding:4px 12px 4px 4px;
        }
        .user-avatar {
            width:26px; height:26px; border-radius:50%;
            background:var(--blue-mid);
            display:flex; align-items:center; justify-content:center;
            color:var(--white); font-size:11px; font-weight:700;
        }
        .user-name { font-size:12px; font-weight:700; color:var(--gray-700); }

        /* ── LAYOUT ── */
        .page-wrap { max-width:1280px; margin:0 auto; padding:24px 24px 48px; }

        /* ── HERO STRIP ── */
        .hero-strip {
            background:linear-gradient(145deg,var(--blue) 0%,var(--blue-mid) 55%,var(--blue-light) 100%);
            border-radius:20px; padding:24px 28px;
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:24px; position:relative; overflow:hidden;
            flex-wrap:wrap; gap:16px;
        }
        .hero-strip::before {
            content:''; position:absolute; top:-60px; right:-60px;
            width:200px; height:200px; border-radius:50%;
            background:rgba(255,215,0,.10);
        }
        .hero-left .hl-badge {
            display:inline-flex; align-items:center; gap:5px;
            background:rgba(255,215,0,.15); border:1px solid rgba(255,215,0,.3);
            border-radius:999px; padding:3px 10px; margin-bottom:8px;
        }
        .hero-left .hl-badge span { font-size:10px; font-weight:700; color:var(--yellow); letter-spacing:1px; text-transform:uppercase; }
        .hero-left h1 { font-size:22px; font-weight:800; color:var(--white); letter-spacing:-.3px; }
        .hero-left p  { font-size:13px; color:rgba(255,255,255,.6); margin-top:3px; }

        .hero-stats { display:flex; gap:12px; position:relative; z-index:1; flex-wrap:wrap; }
        .hs-box {
            background:rgba(0,0,0,.22); border:1px solid rgba(255,255,255,.08);
            border-radius:14px; padding:12px 20px; text-align:center; min-width:90px;
        }
        .hs-box .hv { font-size:26px; font-weight:800; color:var(--yellow); line-height:1; }
        .hs-box .hl { font-size:10px; font-weight:600; color:rgba(255,255,255,.55); text-transform:uppercase; letter-spacing:.5px; margin-top:3px; }

        /* ── FILTER BAR ── */
        .filter-wrap {
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:16px; padding:16px 20px;
            display:flex; align-items:center; gap:12px; flex-wrap:wrap;
            margin-bottom:20px;
            transition:background .35s, border-color .35s;
        }
        .filter-group { display:flex; align-items:center; gap:8px; }
        .filter-label { font-size:11px; font-weight:700; color:var(--gray-400); text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
        .filter-select {
            height:36px; padding:0 12px;
            font-size:13px; font-weight:600; font-family:"Plus Jakarta Sans",sans-serif;
            color:var(--gray-800); background:var(--gray-50);
            border:1.5px solid var(--gray-200); border-radius:9px;
            outline:none; -webkit-appearance:none; cursor:pointer; transition:all .2s;
        }
        .filter-select:focus { border-color:var(--blue-mid); background:var(--white); }

        .search-wrap {
            flex:1; min-width:200px; position:relative;
        }
        .search-input {
            width:100%; height:36px; padding:0 36px 0 12px;
            font-size:13px; font-family:"Plus Jakarta Sans",sans-serif;
            color:var(--gray-800); background:var(--gray-50);
            border:1.5px solid var(--gray-200); border-radius:9px;
            outline:none; transition:all .2s;
        }
        .search-input:focus { border-color:var(--blue-mid); background:var(--white); }
        .search-icon { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:var(--gray-400); font-size:13px; pointer-events:none; }

        .tipe-pills { display:flex; gap:6px; }
        .tp {
            height:34px; padding:0 14px; border-radius:9px;
            font-size:12px; font-weight:700; border:1.5px solid var(--gray-200);
            background:var(--gray-50); color:var(--gray-600);
            text-decoration:none; display:flex; align-items:center; gap:5px;
            transition:all .2s; cursor:pointer;
        }
        .tp:hover, .tp.on { background:var(--blue); border-color:var(--blue); color:var(--white); }
        .tp.on-staff  { background:#0369a1; border-color:#0369a1; }
        .tp.on-dosen  { background:#6d28d9; border-color:#6d28d9; }

        .btn-export {
            height:36px; padding:0 16px; border-radius:9px;
            background:linear-gradient(135deg,var(--blue),var(--blue-mid));
            color:var(--white); border:none; cursor:pointer;
            font-size:12px; font-weight:700; font-family:"Plus Jakarta Sans",sans-serif;
            display:flex; align-items:center; gap:6px; transition:all .2s;
            text-decoration:none; white-space:nowrap;
        }
        .btn-export:hover { box-shadow:0 4px 14px rgba(0,51,102,.3); transform:translateY(-1px); }

        /* ── INFO BAR ── */
        .info-bar {
            display:flex; align-items:center; justify-content:space-between;
            margin-bottom:12px; font-size:12px; color:var(--gray-500);
        }
        .info-bar b { color:var(--blue-mid); font-weight:800; }

        /* ── TABEL PEGAWAI ── */
        .table-card {
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:18px; overflow:hidden;
            transition:background .35s, border-color .35s;
        }

        table { width:100%; border-collapse:collapse; }
        thead th {
            background:var(--gray-50); padding:11px 16px;
            font-size:10.5px; font-weight:700; color:var(--gray-500);
            text-transform:uppercase; letter-spacing:.6px;
            border-bottom:1px solid var(--gray-200); text-align:left;
            white-space:nowrap;
            transition:background .35s, border-color .35s, color .35s;
        }
        thead th:first-child { border-radius:18px 0 0 0; }
        thead th:last-child  { border-radius:0 18px 0 0; }

        tbody tr {
            border-bottom:1px solid var(--gray-100);
            transition:background .15s;
        }
        tbody tr:last-child { border-bottom:none; }
        tbody tr:hover { background:var(--gray-50); }

        td { padding:13px 16px; vertical-align:middle; font-size:13px; }

        /* kolom pegawai */
        .col-pegawai { display:flex; align-items:center; gap:11px; }
        .peg-avatar {
            width:40px; height:40px; border-radius:11px;
            background:var(--gray-200); flex-shrink:0; overflow:hidden;
            display:flex; align-items:center; justify-content:center;
            font-size:15px; font-weight:800; color:var(--white);
        }
        .peg-avatar img { width:100%; height:100%; object-fit:cover; }
        .peg-info .pn { font-size:13px; font-weight:700; color:var(--gray-800); transition:color .35s; }
        .peg-info .ps { font-size:11px; color:var(--gray-400); margin-top:1px; }

        /* badge tipe */
        .badge-tipe {
            font-size:10px; font-weight:700; padding:2px 8px; border-radius:999px;
            letter-spacing:.3px; white-space:nowrap;
        }
        .bt-staff { background:#dbeafe; color:#1e40af; }
        .bt-dosen { background:#ede9fe; color:#5b21b6; }

        /* mini stat bar */
        .mini-stat { display:flex; align-items:center; gap:5px; }
        .ms-bar { flex:1; height:5px; background:var(--gray-200); border-radius:999px; overflow:hidden; min-width:50px; }
        .ms-fill { height:100%; border-radius:999px; background:linear-gradient(90deg,var(--green),#4ade80); }
        .ms-pct  { font-size:11px; font-weight:700; color:var(--green); min-width:32px; text-align:right; }

        /* angka rekap kecil */
        .stat-grid { display:flex; gap:6px; flex-wrap:wrap; }
        .sg-item { text-align:center; min-width:28px; }
        .sg-val  { font-size:14px; font-weight:800; line-height:1; }
        .sg-lbl  { font-size:9px; font-weight:600; color:var(--gray-400); letter-spacing:.3px; text-transform:uppercase; margin-top:1px; }
        .sv-hadir { color:var(--green); }
        .sv-izin  { color:#3b82f6; }
        .sv-sakit { color:#ea580c; }
        .sv-alfa  { color:var(--red); }
        .sv-cuti  { color:#7c3aed; }
        .sv-dinas { color:#059669; }

        /* terakhir absen */
        .last-absen { font-size:11.5px; color:var(--gray-500); white-space:nowrap; }
        .last-absen.today { color:var(--green); font-weight:700; }
        .last-absen.never { color:var(--gray-300); font-style:italic; }

        /* tombol detail */
        .btn-detail {
            height:30px; padding:0 12px; border-radius:8px;
            background:var(--gray-100); border:1px solid var(--gray-200);
            color:var(--gray-600); font-size:11.5px; font-weight:700;
            font-family:"Plus Jakarta Sans",sans-serif;
            cursor:pointer; display:flex; align-items:center; gap:5px;
            transition:all .2s; white-space:nowrap;
        }
        .btn-detail:hover { background:var(--blue); color:var(--white); border-color:var(--blue); }

        /* empty */
        .empty-row td {
            text-align:center; padding:48px 20px;
            color:var(--gray-400);
        }
        .empty-row i { font-size:32px; display:block; margin-bottom:10px; color:var(--gray-300); }

        /* ── MODAL DETAIL ── */
        .modal-overlay {
            position:fixed; inset:0;
            background:rgba(0,0,0,.55); backdrop-filter:blur(4px);
            z-index:200; display:flex; align-items:flex-end; justify-content:center;
            opacity:0; pointer-events:none; transition:opacity .3s;
        }
        .modal-overlay.open { opacity:1; pointer-events:all; }

        .modal-sheet {
            background:var(--white); border-radius:24px 24px 0 0;
            width:100%; max-width:680px;
            max-height:85vh; overflow-y:auto;
            transform:translateY(100%);
            transition:transform .35s cubic-bezier(.32,0,.67,0);
        }
        .modal-overlay.open .modal-sheet { transform:translateY(0); }

        .modal-handle { width:40px; height:4px; border-radius:999px; background:var(--gray-300); margin:12px auto 4px; }

        .modal-header {
            padding:16px 20px 14px; border-bottom:1px solid var(--gray-200);
            display:flex; align-items:center; justify-content:space-between; gap:12px;
            transition:border-color .35s;
        }
        .modal-header-left { display:flex; align-items:center; gap:12px; }
        .mh-avatar {
            width:44px; height:44px; border-radius:12px;
            background:var(--blue-mid); flex-shrink:0; overflow:hidden;
            display:flex; align-items:center; justify-content:center;
            font-size:17px; font-weight:800; color:var(--white);
        }
        .mh-avatar img { width:100%; height:100%; object-fit:cover; }
        .mh-name  { font-size:15px; font-weight:800; color:var(--blue); transition:color .35s; }
        .mh-sub   { font-size:11.5px; color:var(--gray-400); margin-top:2px; }

        .modal-close {
            width:32px; height:32px; border-radius:8px;
            background:var(--gray-100); border:none; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            color:var(--gray-500); font-size:14px; transition:all .2s; flex-shrink:0;
        }
        .modal-close:hover { background:#fef2f2; color:var(--red); }

        .modal-body { padding:16px 20px 24px; }

        /* detail list */
        .detail-list { display:flex; flex-direction:column; gap:8px; }
        .det-item {
            background:var(--gray-50); border:1px solid var(--gray-200);
            border-radius:12px; padding:12px 14px;
            display:flex; align-items:center; gap:12px;
            transition:background .35s, border-color .35s;
        }
        .det-date {
            width:42px; flex-shrink:0; text-align:center;
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:9px; padding:6px 4px;
        }
        .det-date .dd { font-size:9px; font-weight:700; color:var(--gray-400); text-transform:uppercase; letter-spacing:.3px; }
        .det-date .dt { font-size:18px; font-weight:800; color:var(--blue); line-height:1.1; }
        .det-date .dm { font-size:9px; font-weight:700; color:var(--gray-400); text-transform:uppercase; letter-spacing:.3px; }

        .det-info { flex:1; min-width:0; }
        .det-top  { display:flex; align-items:center; gap:5px; flex-wrap:wrap; margin-bottom:3px; }
        .det-jam  { font-size:11.5px; color:var(--gray-500); }
        .det-jam i { font-size:9px; color:var(--gray-300); margin-right:2px; }
        .det-lok  { font-size:11px; color:var(--gray-400); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .det-lok i { font-size:9px; margin-right:2px; }

        .badge-status {
            font-size:10.5px; font-weight:700; padding:2px 8px;
            border-radius:999px; white-space:nowrap;
        }
        .badge-kg {
            font-size:10.5px; font-weight:700; color:var(--blue-mid);
            background:#eff6ff; padding:2px 7px; border-radius:999px;
            text-transform:capitalize;
        }

        .det-empty { text-align:center; padding:32px; color:var(--gray-400); }
        .det-empty i { font-size:28px; display:block; margin-bottom:8px; color:var(--gray-300); }

        .modal-loading { text-align:center; padding:40px; color:var(--gray-400); }
        .modal-loading i { font-size:24px; display:block; margin-bottom:8px; color:var(--blue-light); }

        /* ── PAGINATION ── */
        .pg {
            min-width:34px; height:34px; padding:0 10px;
            border-radius:9px; font-size:12.5px; font-weight:700;
            font-family:"Plus Jakarta Sans",sans-serif;
            border:1.5px solid var(--gray-200); background:var(--white);
            color:var(--gray-600); text-decoration:none;
            display:inline-flex; align-items:center; justify-content:center;
            transition:all .2s;
        }
        .pg:hover    { border-color:var(--blue); color:var(--blue); }
        .pg.on       { background:var(--blue); border-color:var(--blue); color:var(--white); }
        .pg.off      { opacity:.4; pointer-events:none; }
        .pg-dot      { font-size:13px; color:var(--gray-400); padding:0 2px; line-height:34px; }
        body.dark .pg { background:var(--dm-card); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .pg.on { background:var(--blue-mid); border-color:var(--blue-mid); color:var(--white); }

        /* ── DARK MODE ── */
        body.dark { background:var(--dm-bg); color:var(--dm-text); }
        body.dark .top-bar      { background:var(--dm-nav); border-color:var(--dm-border); }
        body.dark .brand-text .t1 { color:var(--yellow); }
        body.dark .dark-toggle  { background:#1e3a5f; border-color:#2a4a73; }
        body.dark .dark-toggle::after { right:auto; left:2px; background:#c7d2fe; }
        body.dark .btn-logout   { background:rgba(255,255,255,.05); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .user-chip    { background:var(--dm-card2); border-color:var(--dm-border); }
        body.dark .user-name    { color:var(--dm-text); }
        body.dark .filter-wrap  { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .filter-select { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text); }
        body.dark .search-input { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text); }
        body.dark .tp           { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .table-card   { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark thead th      { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark tbody tr      { border-color:var(--dm-border); }
        body.dark tbody tr:hover { background:var(--dm-card2); }
        body.dark .peg-info .pn { color:var(--dm-text); }
        body.dark .btn-detail   { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .ms-bar       { background:rgba(255,255,255,.08); }
        body.dark .modal-sheet  { background:var(--dm-card); }
        body.dark .modal-handle { background:var(--dm-border); }
        body.dark .modal-header { border-color:var(--dm-border); }
        body.dark .mh-name      { color:var(--yellow); }
        body.dark .modal-close  { background:var(--dm-card2); color:var(--dm-muted); }
        body.dark .det-item     { background:var(--dm-card2); border-color:var(--dm-border); }
        body.dark .det-date     { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .det-date .dt { color:var(--yellow); }
        body.dark .info-bar     { color:var(--dm-muted); }
        body.dark .info-bar b   { color:var(--yellow); }
    </style>
</head>
<body>

<!-- TOP BAR -->
<header class="top-bar">
    <a href="#" class="brand">
        <div class="brand-icon"><i class="fas fa-chart-bar"></i></div>
        <div class="brand-text">
            <div class="t1">Dashboard Absensi</div>
            <div class="t2">Politeknik Masamy Internasional</div>
        </div>
    </a>
    <div class="topbar-right">
        <div class="user-chip">
            <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
        </div>
        <button class="dark-toggle" id="darkToggle" aria-label="Toggle dark mode"></button>
        <a href="../logout.php" class="btn-logout"><i class="fas fa-right-from-bracket"></i> Keluar</a>
    </div>
</header>

<div class="page-wrap">

    <!-- HERO -->
    <div class="hero-strip">
        <div class="hero-left" style="position:relative;z-index:1;">
            <div class="hl-badge"><i class="fas fa-chart-pie" style="color:var(--yellow);font-size:10px;"></i><span>Laporan Kehadiran</span></div>
            <h1><?php echo $nama_bulan[$filter_bulan] . ' ' . $filter_tahun; ?></h1>
            <p>Rekap kehadiran seluruh pegawai &mdash; <?php echo date('l, d F Y'); ?></p>
        </div>
        <div class="hero-stats">
            <div class="hs-box">
                <div class="hv"><?php echo $summary['hadir_hari_ini'] ?? 0; ?></div>
                <div class="hl">Hadir Hari Ini</div>
            </div>
            <div class="hs-box">
                <div class="hv"><?php echo $summary['total_aktif'] ?? 0; ?></div>
                <div class="hl">Pegawai Aktif</div>
            </div>
            <div class="hs-box">
                <div class="hv"><?php echo $summary['total_hadir'] ?? 0; ?></div>
                <div class="hl">Total Hadir</div>
            </div>
            <div class="hs-box" style="--hv-color:#fca5a5;">
                <div class="hv" style="color:#fca5a5;"><?php echo $summary['total_alfa'] ?? 0; ?></div>
                <div class="hl">Total Alfa</div>
            </div>
        </div>
    </div>

    <!-- FILTER -->
    <div class="filter-wrap">
        <!-- Periode -->
        <div class="filter-group">
            <span class="filter-label">Periode</span>
            <select id="fBulan" class="filter-select">
                <?php for ($b=1;$b<=12;$b++): ?>
                <option value="<?php echo $b; ?>" <?php echo $b===$filter_bulan?'selected':'';?>>
                    <?php echo $nama_bulan[$b]; ?>
                </option>
                <?php endfor; ?>
            </select>
            <select id="fTahun" class="filter-select" style="max-width:90px;">
                <?php for ($y=(int)date('Y');$y>=2020;$y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $y===$filter_tahun?'selected':'';?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <!-- Tipe -->
        <div class="filter-group">
            <span class="filter-label">Tipe</span>
            <div class="tipe-pills" id="tipePills">
                <button class="tp <?php echo $filter_tipe==='semua'?'on':''; ?>" data-val="semua">Semua</button>
                <button class="tp <?php echo $filter_tipe==='staff'?'on on-staff':''; ?>" data-val="staff">Staff</button>
                <button class="tp <?php echo $filter_tipe==='dosen'?'on on-dosen':''; ?>" data-val="dosen">Dosen</button>
            </div>
        </div>

        <!-- Cari -->
        <div class="search-wrap">
            <input type="text" id="fCari" class="search-input"
                   placeholder="Cari nama pegawai..."
                   value="<?php echo htmlspecialchars($filter_cari); ?>">
            <i class="fas fa-search search-icon"></i>
        </div>

        <!-- Export -->
        <a id="btnExport" href="export_absensi.php?bulan=<?php echo $filter_bulan; ?>&tahun=<?php echo $filter_tahun; ?>&tipe=<?php echo $filter_tipe; ?>"
           class="btn-export">
            <i class="fas fa-file-excel"></i> Export
        </a>
    </div>

    <!-- INFO BAR -->
    <div class="info-bar" id="infoBar">
        <span id="infoCount">Memuat...</span>
        <span id="infoPager"></span>
    </div>

    <!-- TABEL -->
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th style="width:32%">Pegawai</th>
                    <th>Tipe</th>
                    <th>Kehadiran</th>
                    <th>Rekap</th>
                    <th>Terakhir Absen</th>
                    <th style="text-align:center;">Detail</th>
                </tr>
            </thead>
            <tbody id="tabelBody">
                <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--gray-400);">
                    <i class="fas fa-spinner fa-spin" style="font-size:20px;display:block;margin-bottom:8px;"></i>
                    Memuat data...
                </td></tr>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div id="paginationWrap" style="display:flex;align-items:center;justify-content:center;gap:5px;margin-top:18px;flex-wrap:wrap;"></div>

</div><!-- /page-wrap -->

<!-- MODAL DETAIL -->
<div class="modal-overlay" id="modalDetail">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="mh-avatar" id="mhAvatar"></div>
                <div>
                    <div class="mh-name" id="mhNama">—</div>
                    <div class="mh-sub"  id="mhSub">—</div>
                </div>
            </div>
            <button class="modal-close" onclick="tutupDetail()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="modal-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Memuat data...</p>
            </div>
        </div>
    </div>
</div>

<script>
// ── State filter ──────────────────────────────────────────────────────────────
const state = {
    bulan : <?php echo $filter_bulan; ?>,
    tahun : <?php echo $filter_tahun; ?>,
    tipe  : '<?php echo $filter_tipe; ?>',
    cari  : '<?php echo addslashes($filter_cari); ?>',
    hal   : 1,
};

const namaBulanJS = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const namaHariJS  = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];

// ── Dark mode ─────────────────────────────────────────────────────────────────
(function(){
    const body = document.body;
    if (localStorage.getItem('absensiDark') === '1') body.classList.add('dark');
    document.getElementById('darkToggle').addEventListener('click', () => {
        body.classList.toggle('dark');
        localStorage.setItem('absensiDark', body.classList.contains('dark') ? '1' : '0');
    });
})();

// ── Badge helpers ─────────────────────────────────────────────────────────────
const badgeStatus = {
    hadir:['Hadir','#dcfce7','#166534','#16a34a'],izin:['Izin','#eff6ff','#1e40af','#3b82f6'],
    sakit:['Sakit','#fff7ed','#9a3412','#ea580c'],alfa:['Alfa','#fef2f2','#991b1b','#dc2626'],
    cuti:['Cuti','#f5f3ff','#5b21b6','#7c3aed'],dinas_luar:['Dinas Luar','#ecfdf5','#065f46','#059669'],
    tugas_luar:['Tugas Luar','#ecfdf5','#065f46','#059669'],
};
function badge(s){const d=badgeStatus[s]||[s,'#f1f5f9','#334155','#64748b'];return`<span class="badge-status" style="background:${d[1]};color:${d[2]};border:1px solid ${d[3]}44">${d[0]}</span>`;}

// ── Fetch tabel ───────────────────────────────────────────────────────────────
let debounceTimer = null;

function fetchTabel(resetHal = true) {
    if (resetHal) state.hal = 1;
    // params untuk fetch (dengan ajax_filter=1)
    const fetchParams = new URLSearchParams({ajax_filter:1,tipe:state.tipe,bulan:state.bulan,tahun:state.tahun,cari:state.cari,hal:state.hal});
    // params untuk URL browser (tanpa ajax_filter agar reload tidak menampilkan JSON)
    const urlParams = new URLSearchParams({tipe:state.tipe,bulan:state.bulan,tahun:state.tahun,cari:state.cari,hal:state.hal});
    history.replaceState(null, '', 'dashboard.php?' + urlParams.toString());
    document.getElementById('btnExport').href = `export_absensi.php?bulan=${state.bulan}&tahun=${state.tahun}&tipe=${state.tipe}`;
    document.getElementById('tabelBody').innerHTML = `<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--gray-400);"><i class="fas fa-spinner fa-spin" style="font-size:20px;display:block;margin-bottom:8px;color:var(--blue-light);"></i>Memuat data...</td></tr>`;
    document.getElementById('paginationWrap').innerHTML = '';
    document.getElementById('infoCount').innerHTML = 'Memuat...';
    document.getElementById('infoPager').innerHTML  = '';
    fetch('dashboard.php?' + fetchParams.toString())
        .then(r => r.json())
        .then(data => { renderTabel(data); renderPagination(data); renderInfoBar(data); })
        .catch(() => { document.getElementById('tabelBody').innerHTML = `<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--red);"><i class="fas fa-circle-exclamation"></i> Gagal memuat data.</td></tr>`; });
}

// ── Render tabel ──────────────────────────────────────────────────────────────
function renderTabel(data) {
    const today = data.today;
    if (!data.rows.length) {
        document.getElementById('tabelBody').innerHTML = `<tr class="empty-row"><td colspan="6"><i class="fas fa-inbox"></i><p>Tidak ada pegawai ditemukan</p></td></tr>`;
        return;
    }
    let html = '';
    data.rows.forEach(p => {
        const pct      = p.total_record>0 ? Math.min(100,Math.round(p.hadir/p.total_record*100)) : 0;
        const lowPct   = pct<60;
        const barColor = lowPct?'background:linear-gradient(90deg,#dc2626,#f87171)':'';
        const pctColor = lowPct?'color:var(--red)':'';
        const avColor  = p.tipe==='dosen'?'#6d28d9':'#0369a1';
        const inisial  = p.nama.split(' ').slice(0,2).map(w=>w[0]||'').join('').toUpperCase();
        const avatar   = p.foto_profil?`<img src="${p.foto_profil}" alt="">`:`<span style="font-size:14px;font-weight:800;color:#fff;">${inisial}</span>`;
        const isToday  = p.terakhir_absen===today;
        let terakhir   = '<span class="last-absen never">Belum pernah</span>';
        if (p.terakhir_absen) {
            if (isToday) { terakhir=`<span class="last-absen today"><i class="fas fa-circle-check"></i> Hari ini</span>`; }
            else { const dt=new Date(p.terakhir_absen);const bln=namaBulanJS[dt.getMonth()+1].substring(0,3);terakhir=`<span class="last-absen">${dt.getDate()} ${bln} ${dt.getFullYear()}</span>`; }
        }
        const jabatan = p.nama_jabatan?`<div style="font-size:10.5px;color:var(--gray-400);margin-top:3px;">${p.nama_jabatan}</div>`:'';
        const safeName = p.nama.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
        const safeJab  = (p.nama_jabatan||'').replace(/'/g,"\\'");
        html += `<tr>
            <td><div class="col-pegawai"><div class="peg-avatar" style="background:${avColor}">${avatar}</div><div class="peg-info"><div class="pn">${p.nama}</div><div class="ps">${p.nip||'—'}${p.unit_kerja?' &middot; '+p.unit_kerja:''}</div></div></div></td>
            <td><span class="badge-tipe ${p.tipe==='staff'?'bt-staff':'bt-dosen'}">${p.tipe.charAt(0).toUpperCase()+p.tipe.slice(1)}</span>${jabatan}</td>
            <td style="min-width:130px;"><div class="mini-stat"><div class="ms-bar"><div class="ms-fill" style="width:${pct}%;${barColor}"></div></div><span class="ms-pct" style="${pctColor}">${pct}%</span></div><div style="font-size:10px;color:var(--gray-400);margin-top:3px;">${p.hadir} dari ${p.total_record} catatan</div></td>
            <td><div class="stat-grid"><div class="sg-item"><div class="sg-val sv-hadir">${p.hadir}</div><div class="sg-lbl">Hadir</div></div><div class="sg-item"><div class="sg-val sv-izin">${p.izin}</div><div class="sg-lbl">Izin</div></div><div class="sg-item"><div class="sg-val sv-sakit">${p.sakit}</div><div class="sg-lbl">Sakit</div></div><div class="sg-item"><div class="sg-val sv-alfa">${p.alfa}</div><div class="sg-lbl">Alfa</div></div><div class="sg-item"><div class="sg-val sv-cuti">${p.cuti}</div><div class="sg-lbl">Cuti</div></div><div class="sg-item"><div class="sg-val sv-dinas">${p.dinas}</div><div class="sg-lbl">Dinas</div></div></div></td>
            <td>${terakhir}</td>
            <td style="text-align:center;"><button class="btn-detail" onclick="bukaDetail('${p.nik}','${safeName}','${p.tipe}','${p.foto_profil||''}','${safeJab}')"><i class="fas fa-eye"></i> Lihat</button></td>
        </tr>`;
    });
    document.getElementById('tabelBody').innerHTML = html;
}

// ── Render pagination ─────────────────────────────────────────────────────────
function renderPagination(data) {
    const { halaman: hal, total_hal } = data;
    if (total_hal<=1) { document.getElementById('paginationWrap').innerHTML=''; return; }
    let ps=Math.max(1,hal-2), pe=Math.min(total_hal,ps+4);
    ps=Math.max(1,pe-4);
    const pg=(h,lbl,cls='')=>`<a class="pg ${cls}" style="cursor:pointer;" onclick="goHal(${h})">${lbl}</a>`;
    let html=pg(Math.max(1,hal-1),'<i class="fas fa-chevron-left"></i>',hal<=1?'off':'');
    if(ps>1){html+=pg(1,'1');if(ps>2)html+=`<span class="pg-dot">…</span>`;}
    for(let p=ps;p<=pe;p++)html+=pg(p,p,p===hal?'on':'');
    if(pe<total_hal){if(pe<total_hal-1)html+=`<span class="pg-dot">…</span>`;html+=pg(total_hal,total_hal);}
    html+=pg(Math.min(total_hal,hal+1),'<i class="fas fa-chevron-right"></i>',hal>=total_hal?'off':'');
    document.getElementById('paginationWrap').innerHTML=html;
}

// ── Render info bar ───────────────────────────────────────────────────────────
function renderInfoBar(data) {
    const cari=state.cari?` &mdash; "<b>${state.cari}</b>"`:'';
    document.getElementById('infoCount').innerHTML=`Menampilkan <b>${data.rows.length}</b> dari <b>${data.total}</b> pegawai${cari}`;
    document.getElementById('infoPager').innerHTML=`Hal <b>${data.halaman}</b> / <b>${data.total_hal}</b>`;
}

function goHal(h){state.hal=h;fetchTabel(false);}

// ── Event listeners filter ────────────────────────────────────────────────────
document.getElementById('fBulan').addEventListener('change',function(){state.bulan=+this.value;fetchTabel();});
document.getElementById('fTahun').addEventListener('change',function(){state.tahun=+this.value;fetchTabel();});
document.querySelectorAll('#tipePills .tp').forEach(btn=>{
    btn.addEventListener('click',function(){
        document.querySelectorAll('#tipePills .tp').forEach(b=>b.className='tp');
        const val=this.dataset.val;
        this.className='tp on'+(val==='staff'?' on-staff':val==='dosen'?' on-dosen':'');
        state.tipe=val;fetchTabel();
    });
});
document.getElementById('fCari').addEventListener('input',function(){
    clearTimeout(debounceTimer);
    debounceTimer=setTimeout(()=>{state.cari=this.value.trim();fetchTabel();},350);
});

// ── Modal detail absensi ──────────────────────────────────────────────────────
function bukaDetail(nik,nama,tipe,foto,jabatan){
    const av=document.getElementById('mhAvatar');
    if(foto){av.innerHTML=`<img src="${foto}" alt="">`;}
    else{av.style.background=tipe==='dosen'?'#6d28d9':'#0369a1';av.textContent=nama.split(' ').slice(0,2).map(w=>w[0]||'').join('').toUpperCase();}
    document.getElementById('mhNama').textContent=nama;
    document.getElementById('mhSub').textContent=jabatan||(tipe==='dosen'?'Dosen':'Staff');
    document.getElementById('modalBody').innerHTML=`<div class="modal-loading"><i class="fas fa-spinner fa-spin"></i><p>Memuat data absensi...</p></div>`;
    document.getElementById('modalDetail').classList.add('open');
    document.body.style.overflow='hidden';
    fetch(`dashboard.php?ajax=1&nik=${encodeURIComponent(nik)}&tipe=${tipe}&bulan=${state.bulan}&tahun=${state.tahun}`)
        .then(r=>r.json()).then(renderDetail)
        .catch(()=>{document.getElementById('modalBody').innerHTML=`<div class="det-empty"><i class="fas fa-circle-exclamation"></i><p>Gagal memuat data.</p></div>`;});
}

function renderDetail(rows){
    if(!rows.length){document.getElementById('modalBody').innerHTML=`<div class="det-empty"><i class="fas fa-inbox"></i><p>Tidak ada catatan absensi untuk ${namaBulanJS[state.bulan]} ${state.tahun}</p></div>`;return;}
    let html=`<p style="font-size:12px;color:var(--gray-400);margin-bottom:12px;">${rows.length} catatan &mdash; ${namaBulanJS[state.bulan]} ${state.tahun}</p><div class="detail-list">`;
    rows.forEach(r=>{
        const dt=new Date(r.tanggal),day=dt.getDate(),mon=namaBulanJS[dt.getMonth()+1].substring(0,3).toUpperCase(),hari=namaHariJS[dt.getDay()];
        const jamM=r.jam_masuk?r.jam_masuk.substring(0,5):'--:--',jamK=r.jam_keluar?r.jam_keluar.substring(0,5):'--:--';
        const kg=r.kegiatan?`<span class="badge-kg">${r.kegiatan.replace(/_/g,' ')}</span>`:'';
        const lok=r.lokasi?`<div class="det-lok"><i class="fas fa-location-dot"></i>${r.lokasi}</div>`:'';
        const ket=r.keterangan?`<div class="det-lok" style="font-style:italic;color:var(--gray-400);">"${r.keterangan}"</div>`:'';
        html+=`<div class="det-item"><div class="det-date"><div class="dd">${hari}</div><div class="dt">${day}</div><div class="dm">${mon}</div></div><div class="det-info"><div class="det-top">${badge(r.status_kehadiran)} ${kg}</div><div class="det-jam"><i class="fas fa-right-to-bracket"></i> ${jamM} &nbsp;—&nbsp; <i class="fas fa-right-from-bracket"></i> ${jamK}</div>${lok}${ket}</div></div>`;
    });
    html+=`</div>`;
    document.getElementById('modalBody').innerHTML=html;
}

function tutupDetail(){document.getElementById('modalDetail').classList.remove('open');document.body.style.overflow='';}
document.getElementById('modalDetail').addEventListener('click',function(e){if(e.target===this)tutupDetail();});

// ── Load awal ─────────────────────────────────────────────────────────────────
fetchTabel(false);
</script>
</body>
</html>