<?php
/**
 * export_absensi.php
 * Export data absensi ke file Excel (.xls) — tanpa Composer, tanpa library eksternal.
 * Menggunakan teknik HTML table dengan header application/vnd.ms-excel.
 *
 * Parameter GET:
 *   bulan  = 1-12          (default: bulan berjalan)
 *   tahun  = 2020-sekarang (default: tahun berjalan)
 *   tipe   = semua|staff|dosen (default: semua)
 *   format = rekap|detail  (default: rekap)
 */

ob_start();
session_start();
date_default_timezone_set('Asia/Jakarta');

// ── Guard ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once 'koneksi.php';
mysqli_query($link, "SET time_zone = '+07:00'");

// Cek role dari DB (sama seperti dashboard.php)
$_db_role = null;
if (!empty($_SESSION['id'])) {
    $stmt_role = mysqli_prepare($link, "SELECT role FROM credentials WHERE id = ? LIMIT 1");
    if ($stmt_role) {
        mysqli_stmt_bind_param($stmt_role, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt_role);
        mysqli_stmt_bind_result($stmt_role, $_db_role);
        mysqli_stmt_fetch($stmt_role);
        mysqli_stmt_close($stmt_role);
    }
}

$_akses_diizinkan = in_array($_SESSION['position'] ?? '', ['admin', 'pimpinan'])
                 || in_array($_db_role ?? '', ['admin', 'pimpinan']);

if (!$_akses_diizinkan) {
    header('location: http://localhost/absensi/home/index.php');
    exit;
}

// ── Parameter ─────────────────────────────────────────────────────────────────
$bulan  = isset($_GET['bulan'])  ? max(1, min(12, (int)$_GET['bulan']))                : (int)date('n');
$tahun  = isset($_GET['tahun'])  ? max(2020, min((int)date('Y'), (int)$_GET['tahun'])) : (int)date('Y');
$tipe   = $_GET['tipe']   ?? 'semua';
$format = ($_GET['format'] ?? 'rekap') === 'detail' ? 'detail' : 'rekap';
if (!in_array($tipe, ['semua', 'staff', 'dosen'])) $tipe = 'semua';

$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$nama_hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

// ── Query helpers ─────────────────────────────────────────────────────────────
function fetchRekap($link, string $tbl_p, string $col_nik, string $tbl_a,
                    string $alias, string $nip_col, string $extra_sel,
                    int $bulan, int $tahun, string $tipe_label): array {
    $join_kuliah = ($alias === 'd')
        ? "LEFT JOIN data_kuliah k ON k.kode_prodi = {$alias}.kode_prodi" : '';
    $extra_group = preg_replace('/\s+AS\s+\w+/i', '', $extra_sel);
    $sql = "SELECT {$alias}.nik, {$alias}.{$nip_col} AS nip, {$alias}.nama,
                   j.nama_jabatan, {$extra_sel},
                   COALESCE(SUM(a.status_kehadiran='hadir'),0)                       AS hadir,
                   COALESCE(SUM(a.status_kehadiran='izin'),0)                        AS izin,
                   COALESCE(SUM(a.status_kehadiran='sakit'),0)                       AS sakit,
                   COALESCE(SUM(a.status_kehadiran='alfa'),0)                        AS alfa,
                   COALESCE(SUM(a.status_kehadiran='cuti'),0)                        AS cuti,
                   COALESCE(SUM(a.status_kehadiran IN('dinas_luar','tugas_luar')),0) AS dinas,
                   COUNT(a.id)     AS total_record,
                   MAX(a.tanggal) AS terakhir_absen
            FROM {$tbl_p} {$alias}
            LEFT JOIN data_jabatan j ON j.kode_jabatan = {$alias}.kode_jabatan
            {$join_kuliah}
            LEFT JOIN {$tbl_a} a ON a.{$col_nik} = {$alias}.nik
                AND MONTH(a.tanggal)=? AND YEAR(a.tanggal)=?
            WHERE {$alias}.status_aktif = 1
            GROUP BY {$alias}.nik, {$alias}.{$nip_col}, {$alias}.nama,
                     j.nama_jabatan, {$extra_group}
            ORDER BY {$alias}.nama ASC";
    $rows = [];
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) { $r['tipe'] = $tipe_label; $rows[] = $r; }
        mysqli_stmt_close($stmt);
    }
    return $rows;
}

function fetchDetail($link, string $nik, string $tipeL, int $bulan, int $tahun): array {
    if ($tipeL === 'dosen') {
        $sql = "SELECT tanggal, jam_mulai AS jam_masuk, jam_selesai AS jam_keluar,
                       lokasi, jenis_kegiatan AS kegiatan,
                       status_kehadiran, keterangan, metode_absensi
                FROM absensi_dosen
                WHERE dosen_nik=? AND MONTH(tanggal)=? AND YEAR(tanggal)=?
                ORDER BY tanggal ASC";
    } else {
        $sql = "SELECT tanggal, jam_masuk, jam_keluar, lokasi_masuk AS lokasi,
                       NULL AS kegiatan, status_kehadiran, keterangan, metode_absensi
                FROM absensi_staff
                WHERE staff_nik=? AND MONTH(tanggal)=? AND YEAR(tanggal)=?
                ORDER BY tanggal ASC";
    }
    $rows = [];
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, 'sii', $nik, $bulan, $tahun);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        mysqli_stmt_close($stmt);
    }
    return $rows;
}

function labelStatus(string $s): string {
    return match($s) {
        'hadir'                   => 'Hadir',
        'izin'                    => 'Izin',
        'sakit'                   => 'Sakit',
        'alfa'                    => 'Alfa',
        'cuti'                    => 'Cuti',
        'dinas_luar','tugas_luar' => 'Dinas / Tugas Luar',
        default                   => ucfirst(str_replace('_', ' ', $s)),
    };
}

function statusBg(string $s): string {
    return match($s) {
        'Hadir'              => '#DCFCE7',
        'Izin'               => '#FEF9C3',
        'Sakit'              => '#FEF3C7',
        'Alfa'               => '#FEE2E2',
        'Cuti'               => '#EDE9FE',
        'Dinas / Tugas Luar' => '#E0F2FE',
        default              => '#FFFFFF',
    };
}

function statusFc(string $s): string {
    return match($s) {
        'Hadir'              => '#16A34A',
        'Izin'               => '#CA8A04',
        'Sakit'              => '#D97706',
        'Alfa'               => '#DC2626',
        'Cuti'               => '#6D28D9',
        'Dinas / Tugas Luar' => '#0369A1',
        default              => '#1E293B',
    };
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ── Ambil data ────────────────────────────────────────────────────────────────
$rows_staff = $tipe !== 'dosen'
    ? fetchRekap($link, 'data_staff', 'staff_nik', 'absensi_staff', 's', 'nip',
                 's.unit_kerja', $bulan, $tahun, 'Staff') : [];
$rows_dosen = $tipe !== 'staff'
    ? fetchRekap($link, 'data_dosen', 'dosen_nik', 'absensi_dosen', 'd', 'nidn',
                 'k.nama_prodi AS unit_kerja', $bulan, $tahun, 'Dosen') : [];

$semua_rows = match($tipe) {
    'staff'  => $rows_staff,
    'dosen'  => $rows_dosen,
    default  => (function () use ($rows_staff, $rows_dosen) {
                    $m = array_merge($rows_staff, $rows_dosen);
                    usort($m, fn($a, $b) => strcmp($a['nama'], $b['nama']));
                    return $m;
                })(),
};

// Ringkasan
$sum_aktif   = count($semua_rows);
$sum_hadir   = (int)array_sum(array_column($semua_rows, 'hadir'));
$sum_alfa    = (int)array_sum(array_column($semua_rows, 'alfa'));
$sum_total   = (int)array_sum(array_column($semua_rows, 'total_record'));
$today       = date('Y-m-d');
$hadir_today = count(array_filter($semua_rows, fn($p) => ($p['terakhir_absen'] ?? '') === $today));
$pct_global  = $sum_total > 0 ? round($sum_hadir / $sum_total * 100, 1) : 0;

// Detail harian
$detail_map = [];
if ($format === 'detail') {
    foreach ($semua_rows as $p) {
        $detail_map[$p['nik']] = fetchDetail($link, $p['nik'], strtolower($p['tipe']), $bulan, $tahun);
    }
}

mysqli_close($link);

// ── Output headers ────────────────────────────────────────────────────────────
ob_end_clean();

$label_tipe = $tipe === 'semua' ? 'Semua Pegawai' : ucfirst($tipe);
$fTipe      = $tipe === 'semua' ? 'Semua' : ucfirst($tipe);
$filename   = "Absensi_{$fTipe}_{$nama_bulan[$bulan]}_{$tahun}";
if ($format === 'detail') $filename .= '_Detail';
$filename .= '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

// ── CSS bersama ───────────────────────────────────────────────────────────────
$css = '
<style>
  body { font-family: Arial, sans-serif; font-size: 10pt; }
  table { border-collapse: collapse; width: 100%; }
  th, td { border: 1px solid #CBD5E1; padding: 4px 6px; font-size: 10pt; }
  .judul    { background:#003366; color:#FFD700; font-size:16pt; font-weight:bold; text-align:center; }
  .sub      { background:#004D99; color:#BFD7FF; font-size:11pt; text-align:center; }
  .meta     { background:#002244; color:#94A3B8; font-size:9pt; font-style:italic; text-align:center; }
  .hdr      { background:#003366; color:#FFD700; font-weight:bold; text-align:center; }
  .total-row{ background:#FFF7CD; color:#78350F; font-weight:bold; text-align:center; }
  .alt      { background:#EFF6FF; }
  .center   { text-align:center; }
  .left     { text-align:left; }
  .sum-card { font-weight:bold; text-align:center; font-size:10pt; }
</style>';

// ════════════════════════════════════════════════════════════════════════════
// SHEET 1 – REKAP BULANAN
// ════════════════════════════════════════════════════════════════════════════
echo $css;
echo '<table>';

// Judul
echo '<tr><td colspan="15" class="judul">REKAP ABSENSI BULANAN</td></tr>';
echo '<tr><td colspan="15" class="sub">Periode: ' . e($nama_bulan[$bulan]) . ' ' . e($tahun) . '&nbsp;&nbsp;|&nbsp;&nbsp;Tipe: ' . e($label_tipe) . '</td></tr>';
echo '<tr><td colspan="15" class="meta">Dicetak: ' . date('d/m/Y H:i:s') . '&nbsp;&nbsp;|&nbsp;&nbsp;Sistem Absensi &mdash; Hak akses: Admin/Pimpinan</td></tr>';

// Baris kosong
echo '<tr><td colspan="15" style="height:6px;background:#fff;border:none;"></td></tr>';

// Ringkasan
echo '<tr>';
$sumItems = [
    ['Total Pegawai',  $sum_aktif,       '#DBEAFE', '#001F54'],
    ['Total Hadir',    $sum_hadir,       '#DCFCE7', '#14532D'],
    ['Total Alfa',     $sum_alfa,        '#FEE2E2', '#7F1D1D'],
    ['Hadir Hari Ini', $hadir_today,     '#E0F2FE', '#1E3A5F'],
    ['% Kehadiran',    $pct_global . '%','#EDE9FE', '#3B0764'],
];
foreach ($sumItems as [$lbl, $val, $bg, $fc]) {
    echo '<td colspan="3" class="sum-card" style="background:' . $bg . ';color:' . $fc . ';border:1px solid #CBD5E1;">'
       . e($lbl) . '<br>' . e($val) . '</td>';
}
echo '</tr>';

// Baris kosong
echo '<tr><td colspan="15" style="height:6px;background:#fff;border:none;"></td></tr>';

// Header kolom
$hdrs1 = ['No', 'NIP / NIK / NIDN', 'Nama Pegawai', 'Jabatan', 'Unit Kerja / Prodi', 'Tipe',
          'Hadir', 'Izin', 'Sakit', 'Alfa', 'Cuti', 'Dinas / Tugas Luar', 'Total Catatan', '% Kehadiran', 'Terakhir Absen'];
echo '<tr>';
foreach ($hdrs1 as $h) {
    echo '<th class="hdr">' . e($h) . '</th>';
}
echo '</tr>';

// Data rows
$sum_g = $sum_h = $sum_i = $sum_j = $sum_k = $sum_l = $sum_m = 0;
foreach ($semua_rows as $i => $p) {
    $isAlt    = ($i % 2 === 1);
    $bgRow    = $isAlt ? '#EFF6FF' : '#FFFFFF';
    $total    = (int)$p['total_record'];
    $terakhir = $p['terakhir_absen'] ? date('d/m/Y', strtotime($p['terakhir_absen'])) : '-';
    $pct      = $total > 0 ? round((int)$p['hadir'] / $total * 100, 1) . '%' : '0%';
    $tipeFc   = ($p['tipe'] === 'Dosen') ? '#6D28D9' : '#0369A1';
    $alfaBg   = ((int)$p['alfa'] > 0) ? '#FEE2E2' : $bgRow;
    $alfaFc   = ((int)$p['alfa'] > 0) ? '#DC2626' : '#1E293B';

    $sum_g += (int)$p['hadir'];
    $sum_h += (int)$p['izin'];
    $sum_i += (int)$p['sakit'];
    $sum_j += (int)$p['alfa'];
    $sum_k += (int)$p['cuti'];
    $sum_l += (int)$p['dinas'];
    $sum_m += $total;

    echo '<tr style="background:' . $bgRow . ';">';
    echo '<td class="center">' . ($i + 1) . '</td>';
    echo '<td style="mso-number-format:\'@\';">' . e($p['nip'] ?? '-') . '</td>';
    echo '<td>' . e($p['nama'] ?? '-') . '</td>';
    echo '<td>' . e($p['nama_jabatan'] ?? '-') . '</td>';
    echo '<td>' . e($p['unit_kerja'] ?? '-') . '</td>';
    echo '<td class="center" style="color:' . $tipeFc . ';font-weight:bold;">' . e($p['tipe'] ?? '-') . '</td>';
    echo '<td class="center">' . (int)$p['hadir'] . '</td>';
    echo '<td class="center">' . (int)$p['izin'] . '</td>';
    echo '<td class="center">' . (int)$p['sakit'] . '</td>';
    echo '<td class="center" style="background:' . $alfaBg . ';color:' . $alfaFc . ';font-weight:bold;">' . (int)$p['alfa'] . '</td>';
    echo '<td class="center">' . (int)$p['cuti'] . '</td>';
    echo '<td class="center">' . (int)$p['dinas'] . '</td>';
    echo '<td class="center">' . $total . '</td>';
    echo '<td class="center">' . $pct . '</td>';
    echo '<td class="center">' . e($terakhir) . '</td>';
    echo '</tr>';
}

// Total row
$pct_total = $sum_m > 0 ? round($sum_g / $sum_m * 100, 1) . '%' : '0%';
echo '<tr class="total-row">';
echo '<td colspan="6" class="center">TOTAL</td>';
echo '<td class="center">' . $sum_g . '</td>';
echo '<td class="center">' . $sum_h . '</td>';
echo '<td class="center">' . $sum_i . '</td>';
echo '<td class="center">' . $sum_j . '</td>';
echo '<td class="center">' . $sum_k . '</td>';
echo '<td class="center">' . $sum_l . '</td>';
echo '<td class="center">' . $sum_m . '</td>';
echo '<td class="center">' . $pct_total . '</td>';
echo '<td class="center">-</td>';
echo '</tr>';

echo '</table>';

// ════════════════════════════════════════════════════════════════════════════
// SHEET 2 – DETAIL HARIAN (hanya jika format=detail)
// ════════════════════════════════════════════════════════════════════════════
if ($format === 'detail') {
    echo '<br><br>';
    echo '<table>';

    // Judul
    echo '<tr><td colspan="15" class="judul">DETAIL HARIAN ABSENSI</td></tr>';
    echo '<tr><td colspan="15" class="sub">Periode: ' . e($nama_bulan[$bulan]) . ' ' . e($tahun) . '&nbsp;&nbsp;|&nbsp;&nbsp;Tipe: ' . e($label_tipe) . '</td></tr>';
    echo '<tr><td colspan="15" class="meta">Dicetak: ' . date('d/m/Y H:i:s') . '&nbsp;&nbsp;|&nbsp;&nbsp;Sistem Absensi &mdash; Hak akses: Admin/Pimpinan</td></tr>';

    // Legend
    echo '<tr>';
    $legends = [
        ['Hadir',       '#DCFCE7', '#16A34A'],
        ['Izin',        '#FEF9C3', '#CA8A04'],
        ['Sakit',       '#FEF3C7', '#D97706'],
        ['Alfa',        '#FEE2E2', '#DC2626'],
        ['Cuti',        '#EDE9FE', '#6D28D9'],
        ['Dinas / Tugas Luar', '#E0F2FE', '#0369A1'],
    ];
    foreach ($legends as [$lbl, $bg, $fc]) {
        echo '<td colspan="2" class="center" style="background:' . $bg . ';color:' . $fc . ';font-weight:bold;font-size:9pt;">' . e($lbl) . '</td>';
    }
    echo '<td colspan="3" class="center" style="background:#F8FAFC;color:#475569;font-size:9pt;">Warna baris = status kehadiran</td>';
    echo '</tr>';

    // Baris kosong
    echo '<tr><td colspan="15" style="height:6px;background:#fff;border:none;"></td></tr>';

    // Header
    $hdrs2 = ['No', 'NIP / NIK / NIDN', 'Nama Pegawai', 'Jabatan', 'Unit Kerja / Prodi', 'Tipe',
              'Tanggal', 'Hari', 'Jam Masuk', 'Jam Keluar', 'Status Kehadiran',
              'Kegiatan', 'Lokasi', 'Metode', 'Keterangan'];
    echo '<tr>';
    foreach ($hdrs2 as $h) {
        echo '<th class="hdr">' . e($h) . '</th>';
    }
    echo '</tr>';

    // Data detail
    $no_peg    = 0;
    $total_cat = 0;
    $centerCols2 = [0, 5, 6, 7, 8, 9, 10, 13]; // indeks kolom yg di-center

    foreach ($semua_rows as $p) {
        $no_peg++;
        $nik    = $p['nik'] ?? '';
        $tipeL  = strtolower($p['tipe'] ?? 'staff');
        $detail = $detail_map[$nik] ?? [];
        $tipeFc = ($p['tipe'] === 'Dosen') ? '#6D28D9' : '#0369A1';

        if (empty($detail)) {
            $total_cat++;
            echo '<tr style="background:#F8FAFC;">';
            echo '<td class="center" style="color:#94A3B8;">' . $no_peg . '</td>';
            echo '<td style="color:#94A3B8;mso-number-format:\'@\';">' . e($p['nip'] ?? '-') . '</td>';
            echo '<td style="color:#94A3B8;">' . e($p['nama'] ?? '-') . '</td>';
            echo '<td style="color:#94A3B8;">' . e($p['nama_jabatan'] ?? '-') . '</td>';
            echo '<td style="color:#94A3B8;">' . e($p['unit_kerja'] ?? '-') . '</td>';
            echo '<td class="center" style="color:#94A3B8;">' . e($p['tipe'] ?? '-') . '</td>';
            echo '<td colspan="9" class="center" style="color:#94A3B8;font-style:italic;">Tidak ada catatan</td>';
            echo '</tr>';
            continue;
        }

        $first = true;
        foreach ($detail as $d) {
            $total_cat++;
            $dt      = new DateTime($d['tanggal']);
            $hari    = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int)$dt->format('w')];
            $tgl     = $dt->format('d/m/Y');
            $jm      = $d['jam_masuk']  ? substr($d['jam_masuk'],  0, 5) : '--:--';
            $jk      = $d['jam_keluar'] ? substr($d['jam_keluar'], 0, 5) : '--:--';
            $status  = labelStatus($d['status_kehadiran'] ?? '');
            $kegiatan = isset($d['kegiatan']) ? str_replace('_', ' ', $d['kegiatan'] ?? '') : '-';
            $bgRow   = $first ? '#FFFFFF' : '#F8FAFC';

            echo '<tr style="background:' . $bgRow . ';">';

            // Kolom pegawai — hanya tampil di baris pertama
            echo '<td class="center">'  . ($first ? $no_peg                     : '') . '</td>';
            echo '<td style="mso-number-format:\'@\';">' . ($first ? e($p['nip'] ?? '-') : '') . '</td>';
            echo '<td>'                 . ($first ? e($p['nama'] ?? '-')        : '') . '</td>';
            echo '<td>'                 . ($first ? e($p['nama_jabatan'] ?? '-'): '') . '</td>';
            echo '<td>'                 . ($first ? e($p['unit_kerja'] ?? '-')  : '') . '</td>';
            echo '<td class="center" style="color:' . $tipeFc . ';font-weight:bold;">'
                                        . ($first ? e($p['tipe'] ?? '-')        : '') . '</td>';

            // Kolom detail harian
            echo '<td class="center">' . e($tgl) . '</td>';
            echo '<td class="center">' . e($hari) . '</td>';
            echo '<td class="center">' . e($jm) . '</td>';
            echo '<td class="center">' . e($jk) . '</td>';
            echo '<td class="center" style="background:' . statusBg($status) . ';color:' . statusFc($status) . ';font-weight:bold;">'
               . e($status) . '</td>';
            echo '<td>' . e($kegiatan ?: '-') . '</td>';
            echo '<td>' . e($d['lokasi'] ?? '-') . '</td>';
            echo '<td class="center">' . e($d['metode_absensi'] ?? '-') . '</td>';
            echo '<td>' . e($d['keterangan'] ?? '') . '</td>';
            echo '</tr>';

            $first = false;
        }
    }

    // Total row detail
    echo '<tr class="total-row">';
    echo '<td colspan="15" class="center">Total: ' . $total_cat . ' catatan absensi</td>';
    echo '</tr>';

    echo '</table>';
}

// ════════════════════════════════════════════════════════════════════════════
// SHEET 3 – PETUNJUK
// ════════════════════════════════════════════════════════════════════════════
echo '<br><br>';
echo '<table>';
echo '<tr><td colspan="3" class="judul">PETUNJUK PENGGUNAAN FILE EKSPOR ABSENSI</td></tr>';

$petunjuk = [
    // [label, keterangan, bg, fc]
    [null,          'PARAMETER URL',               null,       '#004D99', '#FFD700'],
    ['bulan',       'Nomor bulan 1–12. Default: bulan berjalan.',         '#F1F5F9', '#1E293B'],
    ['tahun',       'Tahun 2020–sekarang. Default: tahun berjalan.',      '#F1F5F9', '#1E293B'],
    ['tipe',        'semua | staff | dosen. Default: semua.',             '#F1F5F9', '#1E293B'],
    ['format',      'rekap (default) → Rekap  |  detail → Detail Harian.','#F1F5F9','#1E293B'],
    [null,          '',                                                    null,      null,     null],
    [null,          'KODE STATUS KEHADIRAN',        null,       '#004D99', '#FFD700'],
    ['hadir',       'Pegawai hadir dan tercatat masuk.',                  '#DCFCE7', '#16A34A'],
    ['izin',        'Pegawai izin (ada keterangan).',                     '#FEF9C3', '#CA8A04'],
    ['sakit',       'Pegawai sakit (umumnya disertai surat dokter).',     '#FEF3C7', '#D97706'],
    ['alfa',        'Tidak hadir tanpa keterangan.',                      '#FEE2E2', '#DC2626'],
    ['cuti',        'Cuti resmi yang telah disetujui.',                   '#EDE9FE', '#6D28D9'],
    ['dinas_luar / tugas_luar','Perjalanan dinas atau penugasan luar.',   '#E0F2FE', '#0369A1'],
    [null,          '',                                                    null,      null,     null],
    [null,          'CONTOH URL EKSPOR',            null,       '#004D99', '#FFD700'],
    ['Rekap semua pegawai', 'export_absensi.php?bulan=5&tahun=2025&tipe=semua',          '#F1F5F9', '#004D99'],
    ['Detail harian dosen', 'export_absensi.php?bulan=5&tahun=2025&tipe=dosen&format=detail','#F1F5F9','#004D99'],
    ['Rekap staff saja',    'export_absensi.php?bulan=5&tahun=2025&tipe=staff',          '#F1F5F9', '#004D99'],
];

foreach ($petunjuk as $row) {
    // Baris kosong
    if ($row[1] === '') {
        echo '<tr><td colspan="3" style="height:6px;background:#fff;border:none;"></td></tr>';
        continue;
    }
    // Header section: [null, 'JUDUL', null, $bg_header, $fc_header]
    if ($row[0] === null) {
        $bg_h = $row[3]; $fc_h = $row[4];
        echo '<tr><td colspan="3" style="background:' . $bg_h . ';color:' . $fc_h . ';font-weight:bold;padding:6px;">'
           . e($row[1]) . '</td></tr>';
        continue;
    }
    // Baris data: [$lbl, $ket, $bg, $fc]
    [$lbl, $ket, $bgUse, $fc] = $row;
    echo '<tr>';
    echo '<td style="background:' . $bgUse . ';color:' . $fc . ';font-weight:bold;width:180px;">' . e($lbl) . '</td>';
    echo '<td colspan="2" style="background:' . $bgUse . ';color:' . $fc . ';">' . e($ket) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
?>