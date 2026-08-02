<?php
/**
 * export_absensi_dosen.php
 * Export & filter absensi DOSEN berdasarkan jenis kegiatan ke Excel (.xls).
 * Tanpa Composer, tanpa library eksternal — HTML table trick dengan header ms-excel.
 *
 * Parameter GET:
 *   bulan          = 1-12              (default: bulan berjalan)
 *   tahun          = 2020-sekarang     (default: tahun berjalan)
 *   jenis_kegiatan = mengajar|rapat|administratif|penelitian|pengabdian|lainnya|semua
 *   format         = rekap|detail      (default: rekap)
 *   cari           = nama / nidn / nik (opsional)
 *   kode_prodi     = kode prodi        (opsional)
 *   status         = hadir|izin|sakit|alfa|cuti|tugas_luar|semua (opsional, hanya detail)
 *   mode           = preview|export    (default: preview — tampilkan halaman HTML; export — unduh xls)
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

// Cek role dari DB
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

// ── Konstanta jenis kegiatan ──────────────────────────────────────────────────
$JENIS_KEGIATAN_LIST = [
    'semua'          => 'Semua Kegiatan',
    'mengajar'       => 'Mengajar',
    'rapat'          => 'Rapat',
    'administratif'  => 'Administratif',
    'penelitian'     => 'Penelitian',
    'pengabdian'     => 'Pengabdian',
    'lainnya'        => 'Lainnya',
];

$JENIS_WARNA = [
    'mengajar'      => ['bg' => '#DBEAFE', 'fc' => '#1D4ED8', 'icon' => '📚'],
    'rapat'         => ['bg' => '#F3E8FF', 'fc' => '#7C3AED', 'icon' => '🤝'],
    'administratif' => ['bg' => '#FEF3C7', 'fc' => '#D97706', 'icon' => '📋'],
    'penelitian'    => ['bg' => '#DCFCE7', 'fc' => '#15803D', 'icon' => '🔬'],
    'pengabdian'    => ['bg' => '#FCE7F3', 'fc' => '#BE185D', 'icon' => '🌱'],
    'lainnya'       => ['bg' => '#F1F5F9', 'fc' => '#475569', 'icon' => '📌'],
];

// ── Parameter ─────────────────────────────────────────────────────────────────
$bulan          = isset($_GET['bulan'])  ? max(1, min(12, (int)$_GET['bulan']))                : (int)date('n');
$tahun          = isset($_GET['tahun'])  ? max(2020, min((int)date('Y'), (int)$_GET['tahun'])) : (int)date('Y');
$jenis_kegiatan = $_GET['jenis_kegiatan'] ?? 'semua';
$format         = ($_GET['format'] ?? 'rekap') === 'detail' ? 'detail' : 'rekap';
$cari           = trim($_GET['cari'] ?? '');
$kode_prodi     = trim($_GET['kode_prodi'] ?? '');
$filter_status  = $_GET['status'] ?? 'semua';
$mode           = ($_GET['mode'] ?? 'preview') === 'export' ? 'export' : 'preview';

if (!array_key_exists($jenis_kegiatan, $JENIS_KEGIATAN_LIST)) $jenis_kegiatan = 'semua';

$nama_bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
               'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// ── Ambil daftar prodi untuk dropdown ─────────────────────────────────────────
$prodi_list = [];
$r_prodi = mysqli_query($link, "SELECT kode_prodi, nama_prodi FROM data_kuliah ORDER BY nama_prodi ASC");
while ($rp = mysqli_fetch_assoc($r_prodi)) {
    $prodi_list[$rp['kode_prodi']] = $rp['nama_prodi'];
}

// ── Helper escape ─────────────────────────────────────────────────────────────
function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function labelStatus(string $s): string {
    return match($s) {
        'hadir'      => 'Hadir',
        'izin'       => 'Izin',
        'sakit'      => 'Sakit',
        'alfa'       => 'Alfa',
        'cuti'       => 'Cuti',
        'tugas_luar' => 'Tugas Luar',
        default      => ucfirst(str_replace('_', ' ', $s)),
    };
}

function statusBg(string $s): string {
    return match($s) {
        'Hadir'      => '#DCFCE7',
        'Izin'       => '#FEF9C3',
        'Sakit'      => '#FEF3C7',
        'Alfa'       => '#FEE2E2',
        'Cuti'       => '#EDE9FE',
        'Tugas Luar' => '#E0F2FE',
        default      => '#FFFFFF',
    };
}

function statusFc(string $s): string {
    return match($s) {
        'Hadir'      => '#16A34A',
        'Izin'       => '#CA8A04',
        'Sakit'      => '#D97706',
        'Alfa'       => '#DC2626',
        'Cuti'       => '#6D28D9',
        'Tugas Luar' => '#0369A1',
        default      => '#1E293B',
    };
}

// ── Query: Rekap per dosen ─────────────────────────────────────────────────────
function buildRekapQuery(
    object $link,
    int $bulan, int $tahun,
    string $jenis_kegiatan,
    string $cari,
    string $kode_prodi
): array {

    // Kondisi jenis kegiatan
    $jk_cond  = $jenis_kegiatan !== 'semua'
        ? "AND a.jenis_kegiatan = ?"
        : "";

    // Kondisi pencarian
    $cari_cond = '';
    if ($cari !== '') {
        $cari_cond = "AND (d.nama LIKE ? OR d.nidn LIKE ? OR d.nik LIKE ?)";
    }

    // Kondisi prodi
    $prodi_cond = '';
    if ($kode_prodi !== '') {
        $prodi_cond = "AND d.kode_prodi = ?";
    }

    $sql = "
        SELECT
            d.nik,
            CONCAT_WS(' ', d.gelar_depan, d.nama, d.gelar_belakang) AS nama_lengkap,
            d.nama,
            d.nidn,
            d.nip,
            j.nama_jabatan,
            k.nama_prodi,
            d.kode_prodi,
            d.pendidikan_terakhir,
            d.status_kepegawaian,
            -- Rekap per jenis kegiatan (di-filter)
            COALESCE(SUM(a.jenis_kegiatan = 'mengajar'),0)      AS jml_mengajar,
            COALESCE(SUM(a.jenis_kegiatan = 'rapat'),0)         AS jml_rapat,
            COALESCE(SUM(a.jenis_kegiatan = 'administratif'),0) AS jml_administratif,
            COALESCE(SUM(a.jenis_kegiatan = 'penelitian'),0)    AS jml_penelitian,
            COALESCE(SUM(a.jenis_kegiatan = 'pengabdian'),0)    AS jml_pengabdian,
            COALESCE(SUM(a.jenis_kegiatan = 'lainnya'),0)       AS jml_lainnya,
            -- Rekap status kehadiran
            COALESCE(SUM(a.status_kehadiran = 'hadir'),0)                          AS hadir,
            COALESCE(SUM(a.status_kehadiran = 'izin'),0)                           AS izin,
            COALESCE(SUM(a.status_kehadiran = 'sakit'),0)                          AS sakit,
            COALESCE(SUM(a.status_kehadiran = 'alfa'),0)                           AS alfa,
            COALESCE(SUM(a.status_kehadiran = 'cuti'),0)                           AS cuti,
            COALESCE(SUM(a.status_kehadiran = 'tugas_luar'),0)                     AS tugas_luar,
            COUNT(a.id)                                                             AS total_record,
            MAX(a.tanggal)                                                          AS terakhir_absen
        FROM data_dosen d
        LEFT JOIN data_jabatan j   ON j.kode_jabatan = d.kode_jabatan
        LEFT JOIN data_kuliah  k   ON k.kode_prodi   = d.kode_prodi
        LEFT JOIN absensi_dosen a  ON a.dosen_nik    = d.nik
                                   AND MONTH(a.tanggal) = ?
                                   AND YEAR(a.tanggal)  = ?
                                   $jk_cond
        WHERE d.status_aktif = 1
              $cari_cond
              $prodi_cond
        GROUP BY d.nik, d.nidn, d.nip, d.nama, d.gelar_depan, d.gelar_belakang,
                 j.nama_jabatan, k.nama_prodi, d.kode_prodi,
                 d.pendidikan_terakhir, d.status_kepegawaian
        ORDER BY d.nama ASC
    ";

    // Susun tipe dan nilai bind
    $types = 'ii';
    $vals  = [$bulan, $tahun];

    if ($jenis_kegiatan !== 'semua') {
        $types .= 's';
        $vals[] = $jenis_kegiatan;
    }

    if ($cari !== '') {
        $types .= 'sss';
        $like   = "%$cari%";
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $like;
    }

    if ($kode_prodi !== '') {
        $types .= 's';
        $vals[] = $kode_prodi;
    }

    $rows = [];
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, $types, ...$vals);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        mysqli_stmt_close($stmt);
    }

    return $rows;
}

// ── Query: Detail harian per dosen ────────────────────────────────────────────
function fetchDetailDosen(
    object $link,
    string $nik,
    int $bulan,
    int $tahun,
    string $jenis_kegiatan,
    string $filter_status
): array {
    $jk_cond     = $jenis_kegiatan !== 'semua' ? "AND jenis_kegiatan = ?" : '';
    $status_cond = $filter_status  !== 'semua' ? "AND status_kehadiran = ?" : '';

    $sql = "SELECT
                tanggal, jenis_kegiatan, mata_kuliah,
                jam_mulai, jam_selesai, lokasi,
                status_kehadiran, keterangan, metode_absensi, foto_evidence
            FROM absensi_dosen
            WHERE dosen_nik = ?
              AND MONTH(tanggal) = ?
              AND YEAR(tanggal)  = ?
              $jk_cond
              $status_cond
            ORDER BY tanggal ASC, jenis_kegiatan ASC";

    $types = 'sii';
    $vals  = [$nik, $bulan, $tahun];

    if ($jenis_kegiatan !== 'semua') { $types .= 's'; $vals[] = $jenis_kegiatan; }
    if ($filter_status  !== 'semua') { $types .= 's'; $vals[] = $filter_status; }

    $rows = [];
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, $types, ...$vals);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        mysqli_stmt_close($stmt);
    }

    return $rows;
}

// ── Ringkasan statistik jenis kegiatan ───────────────────────────────────────
function fetchStatistikJenis(object $link, int $bulan, int $tahun): array {
    $sql = "SELECT
                jenis_kegiatan,
                COUNT(*) AS total,
                SUM(status_kehadiran = 'hadir') AS hadir
            FROM absensi_dosen
            WHERE MONTH(tanggal) = ? AND YEAR(tanggal) = ?
            GROUP BY jenis_kegiatan
            ORDER BY total DESC";

    $stats = [];
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, 'ii', $bulan, $tahun);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($r = mysqli_fetch_assoc($res)) {
            $stats[$r['jenis_kegiatan']] = $r;
        }
        mysqli_stmt_close($stmt);
    }
    return $stats;
}

// ── Ambil data ────────────────────────────────────────────────────────────────
$semua_rows  = buildRekapQuery($link, $bulan, $tahun, $jenis_kegiatan, $cari, $kode_prodi);
$statistik   = fetchStatistikJenis($link, $bulan, $tahun);

// Kumpulkan detail jika format=detail
$detail_map = [];
if ($format === 'detail') {
    foreach ($semua_rows as $p) {
        $detail_map[$p['nik']] = fetchDetailDosen(
            $link, $p['nik'], $bulan, $tahun, $jenis_kegiatan, $filter_status
        );
    }
}

// Ringkasan numerik
$sum_dosen   = count($semua_rows);
$sum_hadir   = (int)array_sum(array_column($semua_rows, 'hadir'));
$sum_alfa    = (int)array_sum(array_column($semua_rows, 'alfa'));
$sum_total   = (int)array_sum(array_column($semua_rows, 'total_record'));
$pct_global  = $sum_total > 0 ? round($sum_hadir / $sum_total * 100, 1) : 0;
$today       = date('Y-m-d');
$hadir_today = count(array_filter($semua_rows, fn($p) => ($p['terakhir_absen'] ?? '') === $today));

mysqli_close($link);

// ── Jika mode=export → kirim file Excel ───────────────────────────────────────
if ($mode === 'export') {
    ob_end_clean();

    $label_jenis = $JENIS_KEGIATAN_LIST[$jenis_kegiatan] ?? 'Semua';
    $filename    = "Absensi_Dosen_{$label_jenis}_{$nama_bulan[$bulan]}_{$tahun}";
    if ($format === 'detail') $filename .= '_Detail';
    $filename   .= '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    // ── CSS untuk Excel ───────────────────────────────────────────────────────
    echo '<style>
      body   { font-family: Arial, sans-serif; font-size: 10pt; }
      table  { border-collapse: collapse; width: 100%; }
      th, td { border: 1px solid #CBD5E1; padding: 4px 6px; font-size: 10pt; }
      .judul    { background:#003366; color:#FFD700; font-size:16pt; font-weight:bold; text-align:center; }
      .sub      { background:#004D99; color:#BFD7FF; font-size:11pt; text-align:center; }
      .meta     { background:#002244; color:#94A3B8; font-size:9pt; font-style:italic; text-align:center; }
      .hdr      { background:#003366; color:#FFD700; font-weight:bold; text-align:center; }
      .hdr2     { background:#004D99; color:#BFD7FF; font-weight:bold; text-align:center; }
      .total-row{ background:#FFF7CD; color:#78350F; font-weight:bold; text-align:center; }
      .center   { text-align:center; }
      .sum-card { font-weight:bold; text-align:center; font-size:10pt; }
      .badge-jk { font-weight:bold; font-size:9pt; text-align:center; }
    </style>';

    // ════════════════════════════════════════════════════════════════════════
    // SHEET 1 – REKAP
    // ════════════════════════════════════════════════════════════════════════
    echo '<table>';

    // Judul
    $label_jenis = $JENIS_KEGIATAN_LIST[$jenis_kegiatan] ?? 'Semua';
    echo '<tr><td colspan="18" class="judul">REKAP ABSENSI DOSEN</td></tr>';
    echo '<tr><td colspan="18" class="sub">Periode: ' . e($nama_bulan[$bulan]) . ' ' . e($tahun)
        . ' &nbsp;|&nbsp; Kegiatan: ' . e($label_jenis)
        . ($kode_prodi ? ' &nbsp;|&nbsp; Prodi: ' . e($kode_prodi) : '') . '</td></tr>';
    echo '<tr><td colspan="18" class="meta">Dicetak: ' . date('d/m/Y H:i:s')
        . ' &nbsp;|&nbsp; Sistem Absensi &mdash; Hak akses: Admin/Pimpinan</td></tr>';

    // Baris kosong
    echo '<tr><td colspan="18" style="height:6px;background:#fff;border:none;"></td></tr>';

    // Ringkasan kartu
    echo '<tr>';
    $cards = [
        ['Total Dosen',   $sum_dosen,           '#DBEAFE', '#001F54'],
        ['Total Kegiatan',$sum_total,            '#EDE9FE', '#3B0764'],
        ['Total Hadir',   $sum_hadir,            '#DCFCE7', '#14532D'],
        ['Total Alfa',    $sum_alfa,             '#FEE2E2', '#7F1D1D'],
        ['% Kehadiran',   $pct_global . '%',     '#FEF9C3', '#78350F'],
        ['Hadir Hari Ini',$hadir_today,          '#E0F2FE', '#1E3A5F'],
    ];
    foreach ($cards as [$lbl, $val, $bg, $fc]) {
        echo '<td colspan="3" class="sum-card" style="background:' . $bg . ';color:' . $fc . ';border:1px solid #CBD5E1;">'
           . e($lbl) . '<br>' . e($val) . '</td>';
    }
    echo '</tr>';

    // Statistik jenis kegiatan
    echo '<tr><td colspan="18" style="height:6px;background:#fff;border:none;"></td></tr>';
    echo '<tr><td colspan="18" class="hdr2">STATISTIK PER JENIS KEGIATAN (Bulan Berjalan)</td></tr>';
    echo '<tr>';
    foreach (['mengajar','rapat','administratif','penelitian','pengabdian','lainnya'] as $jk) {
        $stat   = $statistik[$jk] ?? ['total' => 0, 'hadir' => 0];
        $warna  = $JENIS_KEGIATAN_LIST[$jk] ?? $jk;
        $bgJk   = ['mengajar'=>'#DBEAFE','rapat'=>'#F3E8FF','administratif'=>'#FEF3C7',
                   'penelitian'=>'#DCFCE7','pengabdian'=>'#FCE7F3','lainnya'=>'#F1F5F9'][$jk] ?? '#FFF';
        $fcJk   = ['mengajar'=>'#1D4ED8','rapat'=>'#7C3AED','administratif'=>'#D97706',
                   'penelitian'=>'#15803D','pengabdian'=>'#BE185D','lainnya'=>'#475569'][$jk] ?? '#000';
        echo '<td colspan="3" class="badge-jk" style="background:' . $bgJk . ';color:' . $fcJk . ';border:1px solid #CBD5E1;">'
           . e(ucfirst($jk)) . '<br>' . (int)$stat['total'] . ' kegiatan</td>';
    }
    echo '</tr>';

    echo '<tr><td colspan="18" style="height:6px;background:#fff;border:none;"></td></tr>';

    // Header tabel rekap
    $hdrs = ['No', 'NIDN', 'NIP', 'Nama Dosen', 'Jabatan', 'Prodi',
             'Pend.', 'Status Kepeg.',
             'Mengajar', 'Rapat', 'Administratif', 'Penelitian', 'Pengabdian', 'Lainnya',
             'Hadir', 'Alfa', 'Total', '% Hadir'];
    echo '<tr>';
    foreach ($hdrs as $h) echo '<th class="hdr">' . e($h) . '</th>';
    echo '</tr>';

    // Data
    $sg = $sa = $st = $smg = $srp = $sadm = $spen = $spbm = $slain = 0;
    foreach ($semua_rows as $i => $p) {
        $total   = (int)$p['total_record'];
        $pct     = $total > 0 ? round((int)$p['hadir'] / $total * 100, 1) . '%' : '0%';
        $bgRow   = $i % 2 === 1 ? '#EFF6FF' : '#FFFFFF';
        $alfaBg  = ((int)$p['alfa'] > 0) ? '#FEE2E2' : $bgRow;
        $alfaFc  = ((int)$p['alfa'] > 0) ? '#DC2626' : '#1E293B';

        $sg   += (int)$p['hadir'];
        $sa   += (int)$p['alfa'];
        $st   += $total;
        $smg  += (int)$p['jml_mengajar'];
        $srp  += (int)$p['jml_rapat'];
        $sadm += (int)$p['jml_administratif'];
        $spen += (int)$p['jml_penelitian'];
        $spbm += (int)$p['jml_pengabdian'];
        $slain+= (int)$p['jml_lainnya'];

        echo '<tr style="background:' . $bgRow . ';">';
        echo '<td class="center">' . ($i + 1) . '</td>';
        echo '<td style="mso-number-format:\'@\';">' . e($p['nidn'] ?? '-') . '</td>';
        echo '<td style="mso-number-format:\'@\';">' . e($p['nip'] ?? '-') . '</td>';
        echo '<td>' . e($p['nama_lengkap'] ?? $p['nama'] ?? '-') . '</td>';
        echo '<td>' . e($p['nama_jabatan'] ?? '-') . '</td>';
        echo '<td>' . e($p['nama_prodi'] ?? '-') . '</td>';
        echo '<td class="center">' . e($p['pendidikan_terakhir'] ?? '-') . '</td>';
        echo '<td class="center">' . e(ucfirst($p['status_kepegawaian'] ?? '-')) . '</td>';
        echo '<td class="center" style="background:#DBEAFE;color:#1D4ED8;">' . (int)$p['jml_mengajar'] . '</td>';
        echo '<td class="center" style="background:#F3E8FF;color:#7C3AED;">' . (int)$p['jml_rapat'] . '</td>';
        echo '<td class="center" style="background:#FEF3C7;color:#D97706;">' . (int)$p['jml_administratif'] . '</td>';
        echo '<td class="center" style="background:#DCFCE7;color:#15803D;">' . (int)$p['jml_penelitian'] . '</td>';
        echo '<td class="center" style="background:#FCE7F3;color:#BE185D;">' . (int)$p['jml_pengabdian'] . '</td>';
        echo '<td class="center" style="background:#F1F5F9;color:#475569;">' . (int)$p['jml_lainnya'] . '</td>';
        echo '<td class="center">' . (int)$p['hadir'] . '</td>';
        echo '<td class="center" style="background:' . $alfaBg . ';color:' . $alfaFc . ';font-weight:bold;">' . (int)$p['alfa'] . '</td>';
        echo '<td class="center">' . $total . '</td>';
        echo '<td class="center">' . $pct . '</td>';
        echo '</tr>';
    }

    // Total
    $pct_t = $st > 0 ? round($sg / $st * 100, 1) . '%' : '0%';
    echo '<tr class="total-row">';
    echo '<td colspan="8" class="center">TOTAL</td>';
    echo '<td class="center">' . $smg . '</td>';
    echo '<td class="center">' . $srp . '</td>';
    echo '<td class="center">' . $sadm . '</td>';
    echo '<td class="center">' . $spen . '</td>';
    echo '<td class="center">' . $spbm . '</td>';
    echo '<td class="center">' . $slain . '</td>';
    echo '<td class="center">' . $sg . '</td>';
    echo '<td class="center">' . $sa . '</td>';
    echo '<td class="center">' . $st . '</td>';
    echo '<td class="center">' . $pct_t . '</td>';
    echo '</tr>';

    echo '</table>';

    // ════════════════════════════════════════════════════════════════════════
    // SHEET 2 – DETAIL HARIAN
    // ════════════════════════════════════════════════════════════════════════
    if ($format === 'detail') {
        echo '<br><br><table>';

        echo '<tr><td colspan="14" class="judul">DETAIL HARIAN ABSENSI DOSEN</td></tr>';
        echo '<tr><td colspan="14" class="sub">Periode: ' . e($nama_bulan[$bulan]) . ' ' . e($tahun)
            . ' &nbsp;|&nbsp; Kegiatan: ' . e($label_jenis) . '</td></tr>';
        echo '<tr><td colspan="14" class="meta">Dicetak: ' . date('d/m/Y H:i:s') . '</td></tr>';

        echo '<tr><td colspan="14" style="height:6px;background:#fff;border:none;"></td></tr>';

        $hdrs2 = ['No', 'NIDN', 'Nama Dosen', 'Jabatan', 'Prodi',
                  'Tanggal', 'Hari', 'Jenis Kegiatan', 'Mata Kuliah',
                  'Jam Mulai', 'Jam Selesai', 'Status', 'Metode', 'Keterangan'];
        echo '<tr>';
        foreach ($hdrs2 as $h) echo '<th class="hdr">' . e($h) . '</th>';
        echo '</tr>';

        $no_peg  = 0;
        $tot_cat = 0;
        foreach ($semua_rows as $p) {
            $no_peg++;
            $det = $detail_map[$p['nik']] ?? [];

            if (empty($det)) {
                $tot_cat++;
                echo '<tr style="background:#F8FAFC;">';
                echo '<td class="center" style="color:#94A3B8;">' . $no_peg . '</td>';
                echo '<td style="color:#94A3B8;">' . e($p['nidn'] ?? '-') . '</td>';
                echo '<td style="color:#94A3B8;">' . e($p['nama'] ?? '-') . '</td>';
                echo '<td style="color:#94A3B8;">' . e($p['nama_jabatan'] ?? '-') . '</td>';
                echo '<td style="color:#94A3B8;">' . e($p['nama_prodi'] ?? '-') . '</td>';
                echo '<td colspan="9" class="center" style="color:#94A3B8;font-style:italic;">Tidak ada catatan</td>';
                echo '</tr>';
                continue;
            }

            $first = true;
            foreach ($det as $d) {
                $tot_cat++;
                $dt     = new DateTime($d['tanggal']);
                $hari   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int)$dt->format('w')];
                $tgl    = $dt->format('d/m/Y');
                $jm     = $d['jam_mulai']   ? substr($d['jam_mulai'],  0, 5) : '--:--';
                $js     = $d['jam_selesai'] ? substr($d['jam_selesai'],0, 5) : '--:--';
                $status = labelStatus($d['status_kehadiran'] ?? '');
                $jkLabel= ucfirst(str_replace('_', ' ', $d['jenis_kegiatan'] ?? ''));
                $bgJk   = ['mengajar'=>'#DBEAFE','rapat'=>'#F3E8FF','administratif'=>'#FEF3C7',
                           'penelitian'=>'#DCFCE7','pengabdian'=>'#FCE7F3','lainnya'=>'#F1F5F9'][$d['jenis_kegiatan'] ?? ''] ?? '#FFF';
                $fcJk   = ['mengajar'=>'#1D4ED8','rapat'=>'#7C3AED','administratif'=>'#D97706',
                           'penelitian'=>'#15803D','pengabdian'=>'#BE185D','lainnya'=>'#475569'][$d['jenis_kegiatan'] ?? ''] ?? '#000';
                $bgRow  = $first ? '#FFFFFF' : '#F8FAFC';

                echo '<tr style="background:' . $bgRow . ';">';
                echo '<td class="center">' . ($first ? $no_peg               : '') . '</td>';
                echo '<td style="mso-number-format:\'@\';">' . ($first ? e($p['nidn'] ?? '-')        : '') . '</td>';
                echo '<td>'                 . ($first ? e($p['nama'] ?? '-')        : '') . '</td>';
                echo '<td>'                 . ($first ? e($p['nama_jabatan'] ?? '-'): '') . '</td>';
                echo '<td>'                 . ($first ? e($p['nama_prodi'] ?? '-')  : '') . '</td>';
                echo '<td class="center">'  . e($tgl) . '</td>';
                echo '<td class="center">'  . e($hari) . '</td>';
                echo '<td class="center" style="background:' . $bgJk . ';color:' . $fcJk . ';font-weight:bold;">'
                   . e($jkLabel) . '</td>';
                echo '<td>' . e($d['mata_kuliah'] ?? '-') . '</td>';
                echo '<td class="center">'  . e($jm) . '</td>';
                echo '<td class="center">'  . e($js) . '</td>';
                echo '<td class="center" style="background:' . statusBg($status) . ';color:' . statusFc($status) . ';font-weight:bold;">'
                   . e($status) . '</td>';
                echo '<td class="center">'  . e($d['metode_absensi'] ?? '-') . '</td>';
                echo '<td>'                 . e($d['keterangan'] ?? '') . '</td>';
                echo '</tr>';

                $first = false;
            }
        }

        echo '<tr class="total-row"><td colspan="14" class="center">Total: ' . $tot_cat . ' catatan absensi</td></tr>';
        echo '</table>';
    }

    // ════════════════════════════════════════════════════════════════════════
    // SHEET 3 – PETUNJUK
    // ════════════════════════════════════════════════════════════════════════
    echo '<br><br><table>';
    echo '<tr><td colspan="3" class="judul">PETUNJUK PENGGUNAAN EKSPOR ABSENSI DOSEN</td></tr>';

    $petunjuk = [
        [null, 'PARAMETER URL', null, '#004D99', '#FFD700'],
        ['bulan',          'Nomor bulan 1–12. Default: bulan berjalan.',          '#F1F5F9', '#1E293B'],
        ['tahun',          'Tahun 2020–sekarang. Default: tahun berjalan.',        '#F1F5F9', '#1E293B'],
        ['jenis_kegiatan', 'semua | mengajar | rapat | administratif | penelitian | pengabdian | lainnya. Default: semua.', '#F1F5F9', '#1E293B'],
        ['format',         'rekap (default) → Rekap bulanan | detail → Detail harian.', '#F1F5F9', '#1E293B'],
        ['kode_prodi',     'Filter berdasarkan kode prodi (opsional).',           '#F1F5F9', '#1E293B'],
        ['cari',           'Cari berdasarkan nama, NIDN, atau NIK dosen.',         '#F1F5F9', '#1E293B'],
        ['status',         'Filter status (hanya berlaku untuk detail): semua | hadir | izin | sakit | alfa | cuti | tugas_luar.', '#F1F5F9', '#1E293B'],
        ['mode',           'preview (default) → tampilkan halaman; export → unduh file Excel.', '#F1F5F9', '#1E293B'],
        [null, '', null, null, null],
        [null, 'JENIS KEGIATAN', null, '#004D99', '#FFD700'],
        ['mengajar',       'Kegiatan mengajar / tatap muka di kelas.',             '#DBEAFE', '#1D4ED8'],
        ['rapat',          'Rapat koordinasi, rapat jurusan, rapat pimpinan, dsb.','#F3E8FF', '#7C3AED'],
        ['administratif',  'Pekerjaan administrasi, pengisian DUPAK, SKP, dsb.',  '#FEF3C7', '#D97706'],
        ['penelitian',     'Kegiatan penelitian / riset / penulisan karya ilmiah.','#DCFCE7', '#15803D'],
        ['pengabdian',     'Pengabdian kepada masyarakat / KKN / penyuluhan.',    '#FCE7F3', '#BE185D'],
        ['lainnya',        'Kegiatan lain di luar kategori di atas.',              '#F1F5F9', '#475569'],
        [null, '', null, null, null],
        [null, 'CONTOH URL', null, '#004D99', '#FFD700'],
        ['Rekap semua kegiatan', 'export_absensi_dosen.php?bulan=5&tahun=2025&mode=export', '#F1F5F9', '#004D99'],
        ['Rekap kegiatan mengajar', 'export_absensi_dosen.php?bulan=5&tahun=2025&jenis_kegiatan=mengajar&mode=export', '#F1F5F9', '#004D99'],
        ['Detail per dosen', 'export_absensi_dosen.php?bulan=5&tahun=2025&format=detail&mode=export', '#F1F5F9', '#004D99'],
        ['Filter prodi+kegiatan', 'export_absensi_dosen.php?bulan=5&tahun=2025&kode_prodi=FH-S1-01&jenis_kegiatan=mengajar&mode=export', '#F1F5F9', '#004D99'],
    ];

    foreach ($petunjuk as $row) {
        if ($row[1] === '') {
            echo '<tr><td colspan="3" style="height:6px;background:#fff;border:none;"></td></tr>';
            continue;
        }
        if ($row[0] === null) {
            echo '<tr><td colspan="3" style="background:' . $row[3] . ';color:' . $row[4] . ';font-weight:bold;padding:6px;">'
               . e($row[1]) . '</td></tr>';
            continue;
        }
        [$lbl, $ket, $bgUse, $fc] = $row;
        echo '<tr>';
        echo '<td style="background:' . $bgUse . ';color:' . $fc . ';font-weight:bold;width:200px;">' . e($lbl) . '</td>';
        echo '<td colspan="2" style="background:' . $bgUse . ';color:' . $fc . ';">' . e($ket) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

// ════════════════════════════════════════════════════════════════════════════════
// MODE PREVIEW — Halaman HTML dengan filter interaktif
// ════════════════════════════════════════════════════════════════════════════════
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Absensi Dosen – Filter Jenis Kegiatan</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --navy: #003366; --navy-med: #004D99; --navy-lite: #E8F0FB;
    --gold: #FFD700; --gold-lite: #FFF7CD;
    --green: #16A34A; --red: #DC2626; --purple: #6D28D9; --blue: #1D4ED8;
    --orange: #D97706; --pink: #BE185D;
    --gray-50: #F8FAFC; --gray-100: #F1F5F9; --gray-200: #E2E8F0;
    --gray-400: #94A3B8; --gray-600: #475569; --gray-800: #1E293B;
    --radius: 10px; --shadow: 0 2px 8px rgba(0,0,0,.08);
  }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--gray-100); color: var(--gray-800); font-size: 14px; }

  /* ── Navbar ── */
  .navbar { background: var(--navy); padding: 0 24px; height: 56px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 12px rgba(0,0,0,.2); position: sticky; top: 0; z-index: 100; }
  .navbar-brand { color: var(--gold); font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; text-decoration: none; }
  .navbar-back  { color: #94A3B8; font-size: 13px; text-decoration: none; display: flex; align-items: center; gap: 5px; transition: color .2s; }
  .navbar-back:hover { color: #fff; }

  /* ── Container ── */
  .page { max-width: 1400px; margin: 0 auto; padding: 24px 16px; }

  /* ── Panel filter ── */
  .filter-card { background: #fff; border-radius: var(--radius); box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; border: 1px solid var(--gray-200); }
  .filter-title { font-size: 14px; font-weight: 700; color: var(--navy); margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
  .filter-grid  { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
  .filter-group label { display: block; font-size: 11px; font-weight: 600; color: var(--gray-600); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
  .filter-group select,
  .filter-group input  { width: 100%; border: 1.5px solid var(--gray-200); border-radius: 7px; padding: 7px 10px; font-size: 13px; color: var(--gray-800); outline: none; transition: border-color .2s; background: #fff; }
  .filter-group select:focus,
  .filter-group input:focus  { border-color: var(--navy-med); }
  .btn-filter   { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 7px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: opacity .2s, transform .15s; text-decoration: none; }
  .btn-filter:hover { opacity: .88; transform: translateY(-1px); }
  .btn-primary  { background: var(--navy); color: var(--gold); }
  .btn-export   { background: #16A34A; color: #fff; }
  .btn-export-detail { background: #0369A1; color: #fff; }
  .btn-reset    { background: var(--gray-200); color: var(--gray-600); }
  .btn-row      { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; align-items: center; }

  /* ── Badge jenis kegiatan ── */
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }

  /* ── Stat cards ── */
  .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 20px; }
  .stat-card { background: #fff; border-radius: var(--radius); box-shadow: var(--shadow); padding: 14px 16px; border: 1px solid var(--gray-200); }
  .stat-card .lbl { font-size: 11px; color: var(--gray-400); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
  .stat-card .val { font-size: 22px; font-weight: 800; color: var(--navy); }
  .stat-card .sub { font-size: 11px; color: var(--gray-400); margin-top: 2px; }

  /* ── Jenis kegiatan pills ── */
  .jk-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
  .jk-pill { padding: 6px 14px; border-radius: 999px; font-size: 12px; font-weight: 600; cursor: pointer; border: 2px solid transparent; text-decoration: none; transition: all .2s; }
  .jk-pill:hover, .jk-pill.active { border-color: currentColor; box-shadow: 0 2px 8px rgba(0,0,0,.12); }

  /* ── Tabel ── */
  .tbl-wrap  { background: #fff; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; border: 1px solid var(--gray-200); }
  .tbl-head  { background: var(--navy); padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .tbl-head-title { color: var(--gold); font-size: 14px; font-weight: 700; }
  .tbl-head-sub   { color: #94A3B8; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead th { background: var(--navy-med); color: var(--gold); padding: 10px 10px; text-align: center; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
  tbody tr:hover { background: #F0F7FF !important; }
  tbody td { padding: 9px 10px; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
  tbody tr:last-child td { border-bottom: none; }
  .tr-alt   { background: #F8FAFC; }
  .tr-total { background: var(--gold-lite); font-weight: 700; }
  .num-cell { text-align: center; }
  .alfa-cell { color: var(--red); font-weight: 700; }

  /* ── Dosen name chip ── */
  .dosen-chip { display: flex; align-items: center; gap: 8px; }
  .dosen-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--navy-lite); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: var(--navy); flex-shrink: 0; }

  /* ── Empty state ── */
  .empty { text-align: center; padding: 48px 24px; color: var(--gray-400); }
  .empty i { font-size: 40px; margin-bottom: 12px; }
  .empty p { font-size: 14px; }

  /* ── Responsive ── */
  @media (max-width: 768px) {
    .filter-grid { grid-template-columns: 1fr 1fr; }
    thead th, tbody td { font-size: 11px; padding: 7px 6px; }
  }
</style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <a href="dashboard.php" class="navbar-brand">
    <i class="fas fa-chalkboard-teacher"></i>
    Absensi Dosen
  </a>
  <a href="dashboard.php" class="navbar-back"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>
</nav>

<div class="page">

  <!-- ── Panel Filter ─────────────────────────────────────────────────────── -->
  <div class="filter-card">
    <div class="filter-title"><i class="fas fa-filter"></i> Filter Absensi Dosen</div>
    <form method="GET" action="">
      <input type="hidden" name="mode" value="preview">
      <div class="filter-grid">
        <!-- Bulan -->
        <div class="filter-group">
          <label for="bulan">Bulan</label>
          <select name="bulan" id="bulan">
            <?php foreach ($nama_bulan as $n => $nm):
                  if ($n === 0) continue; ?>
              <option value="<?= $n ?>" <?= $n === $bulan ? 'selected' : '' ?>><?= e($nm) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Tahun -->
        <div class="filter-group">
          <label for="tahun">Tahun</label>
          <select name="tahun" id="tahun">
            <?php for ($y = (int)date('Y'); $y >= 2020; $y--): ?>
              <option value="<?= $y ?>" <?= $y === $tahun ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <!-- Jenis Kegiatan -->
        <div class="filter-group">
          <label for="jenis_kegiatan">Jenis Kegiatan</label>
          <select name="jenis_kegiatan" id="jenis_kegiatan">
            <?php foreach ($JENIS_KEGIATAN_LIST as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $k === $jenis_kegiatan ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Format -->
        <div class="filter-group">
          <label for="format">Format</label>
          <select name="format" id="format">
            <option value="rekap"  <?= $format === 'rekap'  ? 'selected' : '' ?>>Rekap Bulanan</option>
            <option value="detail" <?= $format === 'detail' ? 'selected' : '' ?>>Detail Harian</option>
          </select>
        </div>
        <!-- Prodi -->
        <div class="filter-group">
          <label for="kode_prodi">Program Studi</label>
          <select name="kode_prodi" id="kode_prodi">
            <option value="">Semua Prodi</option>
            <?php foreach ($prodi_list as $kp => $np): ?>
              <option value="<?= e($kp) ?>" <?= $kp === $kode_prodi ? 'selected' : '' ?>><?= e($np) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Status (hanya aktif jika detail) -->
        <div class="filter-group">
          <label for="status">Status Kehadiran <small style="color:#94A3B8;">(detail)</small></label>
          <select name="status" id="status">
            <?php foreach (['semua'=>'Semua Status','hadir'=>'Hadir','izin'=>'Izin','sakit'=>'Sakit','alfa'=>'Alfa','cuti'=>'Cuti','tugas_luar'=>'Tugas Luar'] as $k=>$v): ?>
              <option value="<?= e($k) ?>" <?= $k === $filter_status ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Cari -->
        <div class="filter-group" style="grid-column: span 2;">
          <label for="cari">Cari Dosen (Nama / NIDN / NIK)</label>
          <input type="text" name="cari" id="cari" value="<?= e($cari) ?>" placeholder="Ketik nama, NIDN, atau NIK...">
        </div>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn-filter btn-primary"><i class="fas fa-search"></i> Terapkan Filter</button>
        <a href="<?= e('export_absensi_dosen.php?' . http_build_query(array_merge($_GET, ['mode'=>'export','format'=>'rekap']))) ?>"
           class="btn-filter btn-export">
          <i class="fas fa-file-excel"></i> Export Rekap (.xls)
        </a>
        <a href="<?= e('export_absensi_dosen.php?' . http_build_query(array_merge($_GET, ['mode'=>'export','format'=>'detail']))) ?>"
           class="btn-filter btn-export-detail">
          <i class="fas fa-file-excel"></i> Export Detail (.xls)
        </a>
        <a href="export_absensi_dosen.php" class="btn-filter btn-reset"><i class="fas fa-undo"></i> Reset</a>
      </div>
    </form>
  </div>

  <!-- ── Pill Filter Cepat Jenis Kegiatan ─────────────────────────────────── -->
  <div class="jk-pills">
    <?php
    $pill_colors = [
        'semua'         => ['#E2E8F0','#1E293B'],
        'mengajar'      => ['#DBEAFE','#1D4ED8'],
        'rapat'         => ['#F3E8FF','#7C3AED'],
        'administratif' => ['#FEF3C7','#D97706'],
        'penelitian'    => ['#DCFCE7','#15803D'],
        'pengabdian'    => ['#FCE7F3','#BE185D'],
        'lainnya'       => ['#F1F5F9','#475569'],
    ];
    $pill_icons = [
        'semua'=>'fas fa-list','mengajar'=>'fas fa-chalkboard-teacher','rapat'=>'fas fa-handshake',
        'administratif'=>'fas fa-clipboard','penelitian'=>'fas fa-flask','pengabdian'=>'fas fa-leaf','lainnya'=>'fas fa-tag'
    ];
    $stat_total_all = array_sum(array_column($statistik, 'total'));
    foreach ($JENIS_KEGIATAN_LIST as $k => $v):
        $bgP  = $pill_colors[$k][0];
        $fcP  = $pill_colors[$k][1];
        $stat = $k === 'semua' ? $stat_total_all : ($statistik[$k]['total'] ?? 0);
        $qs   = http_build_query(array_merge($_GET, ['jenis_kegiatan' => $k, 'mode' => 'preview']));
        $active = ($k === $jenis_kegiatan) ? 'active' : '';
    ?>
    <a href="?<?= e($qs) ?>" class="jk-pill <?= $active ?>"
       style="background:<?= $bgP ?>;color:<?= $fcP ?>;">
      <i class="<?= $pill_icons[$k] ?>"></i>
      <?= e($v) ?>
      <span style="opacity:.7;font-size:11px;">(<?= $stat ?>)</span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── Stat Cards ─────────────────────────────────────────────────────────── -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="lbl">Total Dosen</div>
      <div class="val"><?= $sum_dosen ?></div>
      <div class="sub">dosen aktif</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Total Kegiatan</div>
      <div class="val"><?= $sum_total ?></div>
      <div class="sub">catatan bulan ini</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Total Hadir</div>
      <div class="val" style="color:var(--green);"><?= $sum_hadir ?></div>
      <div class="sub">dari <?= $sum_total ?> catatan</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Alfa / Absen</div>
      <div class="val" style="color:var(--red);"><?= $sum_alfa ?></div>
      <div class="sub">tanpa keterangan</div>
    </div>
    <div class="stat-card">
      <div class="lbl">% Kehadiran</div>
      <div class="val" style="color:var(--blue);"><?= $pct_global ?>%</div>
      <div class="sub">global bulan ini</div>
    </div>
    <div class="stat-card">
      <div class="lbl">Hadir Hari Ini</div>
      <div class="val"><?= $hadir_today ?></div>
      <div class="sub"><?= date('d M Y') ?></div>
    </div>
    <!-- Mini statistik per jenis -->
    <?php foreach (['mengajar','rapat','penelitian','pengabdian'] as $jk):
          $stat  = $statistik[$jk] ?? ['total'=>0,'hadir'=>0];
          $bgSt  = $pill_colors[$jk][0];
          $fcSt  = $pill_colors[$jk][1];
    ?>
    <div class="stat-card" style="border-left: 4px solid <?= $fcSt ?>;">
      <div class="lbl" style="color:<?= $fcSt ?>;"><?= ucfirst($jk) ?></div>
      <div class="val" style="color:<?= $fcSt ?>;"><?= (int)$stat['total'] ?></div>
      <div class="sub">hadir: <?= (int)$stat['hadir'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── Tabel Rekap ────────────────────────────────────────────────────────── -->
  <div class="tbl-wrap">
    <div class="tbl-head">
      <div>
        <div class="tbl-head-title">
          <i class="fas fa-table"></i>
          <?= $format === 'rekap' ? 'Rekap Bulanan' : 'Detail Harian' ?> — <?= e($JENIS_KEGIATAN_LIST[$jenis_kegiatan]) ?>
        </div>
        <div class="tbl-head-sub">
          <?= e($nama_bulan[$bulan]) . ' ' . $tahun ?>
          <?= $kode_prodi ? ' &nbsp;·&nbsp; Prodi: ' . e($kode_prodi) : '' ?>
          <?= $cari       ? ' &nbsp;·&nbsp; Cari: "' . e($cari) . '"' : '' ?>
          &nbsp;·&nbsp; <?= $sum_dosen ?> dosen
        </div>
      </div>
    </div>

    <?php if ($format === 'rekap'): ?>
    <!-- ── Rekap Bulanan ── -->
    <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>No</th><th>NIDN</th><th>Nama Dosen</th><th>Jabatan</th><th>Prodi</th><th>Pend.</th>
          <th style="background:#1D4ED8;">📚 Mengajar</th>
          <th style="background:#7C3AED;">🤝 Rapat</th>
          <th style="background:#D97706;">📋 Administratif</th>
          <th style="background:#15803D;">🔬 Penelitian</th>
          <th style="background:#BE185D;">🌱 Pengabdian</th>
          <th style="background:#475569;">📌 Lainnya</th>
          <th>Hadir</th><th>Izin</th><th>Sakit</th>
          <th style="background:#7F1D1D;">Alfa</th>
          <th>Cuti</th><th>Tugas Luar</th><th>Total</th><th>% Hadir</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($semua_rows)): ?>
        <tr>
          <td colspan="20">
            <div class="empty">
              <i class="fas fa-search"></i>
              <p>Tidak ada data dosen yang sesuai filter.</p>
            </div>
          </td>
        </tr>
        <?php else: ?>

        <?php
        $sg=$si=$sk=$sa=$scu=$stu=$st=0;
        $smg=$srp=$sadm=$spen=$spbm=$slain=0;
        foreach ($semua_rows as $i => $p):
            $total  = (int)$p['total_record'];
            $pct    = $total > 0 ? round((int)$p['hadir'] / $total * 100, 1) . '%' : '0%';
            $bgRow  = $i % 2 === 1 ? '#F8FAFC' : '#FFFFFF';
            $inisial= mb_strtoupper(mb_substr($p['nama'], 0, 1, 'UTF-8'));
            $sg   += (int)$p['hadir']; $si += (int)$p['izin']; $sk += (int)$p['sakit'];
            $sa   += (int)$p['alfa'];  $scu+= (int)$p['cuti']; $stu+= (int)$p['tugas_luar'];
            $st   += $total;
            $smg  += (int)$p['jml_mengajar'];    $srp  += (int)$p['jml_rapat'];
            $sadm += (int)$p['jml_administratif'];$spen += (int)$p['jml_penelitian'];
            $spbm += (int)$p['jml_pengabdian'];   $slain+= (int)$p['jml_lainnya'];
        ?>
        <tr style="background:<?= $bgRow ?>;">
          <td class="num-cell"><?= $i + 1 ?></td>
          <td style="font-size:12px;color:#475569;"><?= e($p['nidn'] ?? '-') ?></td>
          <td>
            <div class="dosen-chip">
              <div class="dosen-avatar"><?= e($inisial) ?></div>
              <div>
                <div style="font-weight:600;"><?= e($p['nama'] ?? '-') ?></div>
                <?php if (!empty($p['gelar_belakang']) || !empty($p['gelar_depan'])): ?>
                <div style="font-size:11px;color:#94A3B8;"><?= e(trim(($p['gelar_depan'] ?? '') . ' ' . ($p['gelar_belakang'] ?? ''))) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td style="font-size:12px;"><?= e($p['nama_jabatan'] ?? '-') ?></td>
          <td style="font-size:12px;"><?= e($p['nama_prodi'] ?? '-') ?></td>
          <td class="num-cell"><span class="badge" style="background:#E0F2FE;color:#0369A1;"><?= e($p['pendidikan_terakhir'] ?? '-') ?></span></td>
          <!-- Jenis kegiatan -->
          <td class="num-cell" style="background:#EFF6FF;color:#1D4ED8;font-weight:700;"><?= (int)$p['jml_mengajar'] ?></td>
          <td class="num-cell" style="background:#F5F3FF;color:#7C3AED;font-weight:700;"><?= (int)$p['jml_rapat'] ?></td>
          <td class="num-cell" style="background:#FFFBEB;color:#D97706;font-weight:700;"><?= (int)$p['jml_administratif'] ?></td>
          <td class="num-cell" style="background:#F0FDF4;color:#15803D;font-weight:700;"><?= (int)$p['jml_penelitian'] ?></td>
          <td class="num-cell" style="background:#FDF2F8;color:#BE185D;font-weight:700;"><?= (int)$p['jml_pengabdian'] ?></td>
          <td class="num-cell" style="background:#F8FAFC;color:#475569;"><?= (int)$p['jml_lainnya'] ?></td>
          <!-- Status kehadiran -->
          <td class="num-cell" style="color:var(--green);font-weight:600;"><?= (int)$p['hadir'] ?></td>
          <td class="num-cell" style="color:#CA8A04;"><?= (int)$p['izin'] ?></td>
          <td class="num-cell" style="color:#D97706;"><?= (int)$p['sakit'] ?></td>
          <td class="num-cell <?= (int)$p['alfa'] > 0 ? 'alfa-cell' : '' ?>"><?= (int)$p['alfa'] ?></td>
          <td class="num-cell" style="color:var(--purple);"><?= (int)$p['cuti'] ?></td>
          <td class="num-cell" style="color:#0369A1;"><?= (int)$p['tugas_luar'] ?></td>
          <td class="num-cell" style="font-weight:700;"><?= $total ?></td>
          <td class="num-cell">
            <span class="badge" style="background:<?= $total>0 && (int)$p['hadir']/$total>=.8 ? '#DCFCE7' : '#FEE2E2' ?>;color:<?= $total>0 && (int)$p['hadir']/$total>=.8 ? '#16A34A' : '#DC2626' ?>;">
              <?= $pct ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>

        <!-- Total row -->
        <?php $pct_t = $st > 0 ? round($sg/$st*100,1).'%' : '0%'; ?>
        <tr class="tr-total">
          <td colspan="6" class="num-cell">TOTAL (<?= $sum_dosen ?> Dosen)</td>
          <td class="num-cell"><?= $smg ?></td>
          <td class="num-cell"><?= $srp ?></td>
          <td class="num-cell"><?= $sadm ?></td>
          <td class="num-cell"><?= $spen ?></td>
          <td class="num-cell"><?= $spbm ?></td>
          <td class="num-cell"><?= $slain ?></td>
          <td class="num-cell"><?= $sg ?></td>
          <td class="num-cell"><?= $si ?></td>
          <td class="num-cell"><?= $sk ?></td>
          <td class="num-cell alfa-cell"><?= $sa ?></td>
          <td class="num-cell"><?= $scu ?></td>
          <td class="num-cell"><?= $stu ?></td>
          <td class="num-cell"><?= $st ?></td>
          <td class="num-cell"><?= $pct_t ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>

    <?php else: ?>
    <!-- ── Detail Harian ── -->
    <div style="overflow-x:auto;">
    <table>
      <thead>
        <tr>
          <th>No</th><th>NIDN</th><th>Nama Dosen</th><th>Jabatan</th><th>Prodi</th>
          <th>Tanggal</th><th>Hari</th><th>Jenis Kegiatan</th><th>Mata Kuliah</th>
          <th>Jam Mulai</th><th>Jam Selesai</th><th>Status</th><th>Metode</th><th>Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($semua_rows)): ?>
        <tr><td colspan="14"><div class="empty"><i class="fas fa-search"></i><p>Tidak ada data.</p></div></td></tr>
        <?php else: ?>
        <?php $no_peg = 0; foreach ($semua_rows as $p):
              $no_peg++;
              $det     = $detail_map[$p['nik']] ?? [];
              $inisial = mb_strtoupper(mb_substr($p['nama'], 0, 1, 'UTF-8'));
        ?>
          <?php if (empty($det)): ?>
          <tr style="background:#F8FAFC;">
            <td class="num-cell" style="color:#94A3B8;"><?= $no_peg ?></td>
            <td style="color:#94A3B8;font-size:12px;"><?= e($p['nidn'] ?? '-') ?></td>
            <td style="color:#94A3B8;">
              <div class="dosen-chip">
                <div class="dosen-avatar" style="opacity:.5;"><?= e($inisial) ?></div>
                <span><?= e($p['nama'] ?? '-') ?></span>
              </div>
            </td>
            <td style="color:#94A3B8;font-size:12px;"><?= e($p['nama_jabatan'] ?? '-') ?></td>
            <td style="color:#94A3B8;font-size:12px;"><?= e($p['nama_prodi'] ?? '-') ?></td>
            <td colspan="9" style="color:#94A3B8;text-align:center;font-style:italic;">Tidak ada catatan</td>
          </tr>
          <?php else: ?>
            <?php $first = true; foreach ($det as $d):
                  $dt     = new DateTime($d['tanggal']);
                  $hari   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'][(int)$dt->format('w')];
                  $tgl    = $dt->format('d M Y');
                  $jm     = $d['jam_mulai']   ? substr($d['jam_mulai'],  0, 5) : '--:--';
                  $js     = $d['jam_selesai'] ? substr($d['jam_selesai'],0, 5) : '--:--';
                  $status = labelStatus($d['status_kehadiran'] ?? '');
                  $jkKey  = $d['jenis_kegiatan'] ?? '';
                  $jkLbl  = ucfirst(str_replace('_',' ',$jkKey));
                  $bgJk   = $pill_colors[$jkKey][0] ?? '#F1F5F9';
                  $fcJk   = $pill_colors[$jkKey][1] ?? '#475569';
                  $bgRow  = $first ? '#FFFFFF' : '#F8FAFC';
            ?>
            <tr style="background:<?= $bgRow ?>;">
              <td class="num-cell"><?= $first ? $no_peg : '' ?></td>
              <td style="font-size:12px;color:#475569;"><?= $first ? e($p['nidn'] ?? '-') : '' ?></td>
              <td>
                <?php if ($first): ?>
                <div class="dosen-chip">
                  <div class="dosen-avatar"><?= e($inisial) ?></div>
                  <div>
                    <div style="font-weight:600;"><?= e($p['nama'] ?? '-') ?></div>
                    <div style="font-size:11px;color:#94A3B8;"><?= e($p['status_kepegawaian'] ?? '') ?></div>
                  </div>
                </div>
                <?php endif; ?>
              </td>
              <td style="font-size:12px;"><?= $first ? e($p['nama_jabatan'] ?? '-') : '' ?></td>
              <td style="font-size:12px;"><?= $first ? e($p['nama_prodi'] ?? '-') : '' ?></td>
              <td class="num-cell" style="white-space:nowrap;font-size:12px;"><?= e($tgl) ?></td>
              <td class="num-cell" style="font-size:12px;"><?= e($hari) ?></td>
              <td class="num-cell">
                <span class="badge" style="background:<?= $bgJk ?>;color:<?= $fcJk ?>;"><?= e($jkLbl) ?></span>
              </td>
              <td style="font-size:12px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($d['mata_kuliah'] ?? '') ?>">
                <?= e($d['mata_kuliah'] ?? '-') ?>
              </td>
              <td class="num-cell" style="font-size:12px;"><?= e($jm) ?></td>
              <td class="num-cell" style="font-size:12px;"><?= e($js) ?></td>
              <td class="num-cell">
                <span class="badge" style="background:<?= statusBg($status) ?>;color:<?= statusFc($status) ?>;">
                  <?= e($status) ?>
                </span>
              </td>
              <td class="num-cell" style="font-size:12px;"><?= e($d['metode_absensi'] ?? '-') ?></td>
              <td style="font-size:12px;color:#475569;max-width:200px;"><?= e($d['keterangan'] ?? '') ?></td>
            </tr>
            <?php $first = false; endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div><!-- /.tbl-wrap -->

</div><!-- /.page -->

<script>
  // Auto-disable status filter jika format rekap
  (function () {
    const fmtSel    = document.getElementById('format');
    const statusSel = document.getElementById('status');
    function toggleStatus() {
      statusSel.disabled = fmtSel.value !== 'detail';
      statusSel.style.opacity = fmtSel.value !== 'detail' ? '.4' : '1';
    }
    fmtSel.addEventListener('change', toggleStatus);
    toggleStatus();
  })();
</script>
</body>
</html>