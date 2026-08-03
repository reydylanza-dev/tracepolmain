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

// ── Proses ganti password (inline form) ─────────────────────────────────────
$pw_error  = null;
$pw_sukses = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'ganti_password') {
    $pw_baru   = $_POST['pw_baru']   ?? '';
    $pw_ulang  = $_POST['pw_ulang']  ?? '';
    $user_id   = $_SESSION['id']     ?? null;

    if (strlen($pw_baru) < 6) {
        $pw_error = 'Password baru minimal 6 karakter.';
    } elseif ($pw_baru !== $pw_ulang) {
        $pw_error = 'Konfirmasi password tidak cocok.';
    } elseif (stripos($pw_baru, 'demo') !== false) {
        $pw_error = 'Password tidak boleh mengandung kata "demo".';
    } elseif ($user_id) {
        $hash = password_hash($pw_baru, PASSWORD_DEFAULT);
        $stmt_upd = mysqli_prepare($link, "UPDATE credentials SET password = ? WHERE id = ?");
        if ($stmt_upd) {
            mysqli_stmt_bind_param($stmt_upd, "si", $hash, $user_id);
            $pw_sukses = mysqli_stmt_execute($stmt_upd);
            mysqli_stmt_close($stmt_upd);
        }
        if (!$pw_sukses) $pw_error = 'Gagal menyimpan password. Coba lagi.';
    } else {
        $pw_error = 'Sesi tidak valid. Silakan login ulang.';
    }
}

// ── Flash message dari proses_absensi.php ───────────────────────────────────
$flash_tipe  = $_SESSION["flash_tipe"]  ?? null;
$flash_pesan = $_SESSION["flash_pesan"] ?? null;
unset($_SESSION["flash_tipe"], $_SESSION["flash_pesan"]);

// ── Ambil data pegawai berdasarkan position di session ──────────────────────
$username    = $_SESSION["username"];
$position    = $_SESSION["position"] ?? "staff"; // 'staff' | 'dosen' | 'admin'
$nik_pegawai = $_SESSION["nik"]      ?? null;

// Cek kolom role dari DB (sebagai alternatif selain session position)
$_db_role = null;
if (!empty($_SESSION["id"])) {
    $stmt_role = mysqli_prepare($link, "SELECT role FROM credentials WHERE id = ? LIMIT 1");
    if ($stmt_role) {
        mysqli_stmt_bind_param($stmt_role, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt_role);
        mysqli_stmt_bind_result($stmt_role, $_db_role);
        mysqli_stmt_fetch($stmt_role);
        mysqli_stmt_close($stmt_role);
    }
}
$is_admin = in_array($position, ['admin', 'pimpinan'])
         || in_array($_db_role ?? '', ['admin', 'pimpinan']);

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
$mengajar_sesi_terpakai = []; // sesi mengajar yang sudah terpakai hari ini

if ($stmt = mysqli_prepare($link, $sql_hari_ini)) {
    mysqli_stmt_bind_param($stmt, "s", $nik_pegawai);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        if (!$absensi_hari_ini) $absensi_hari_ini = $row;
        $kegiatan = $row['jenis_kegiatan'] ?? '';

        // Untuk mengajar: key dibedakan per sesi (mengajar_1, mengajar_2, dst)
        if ($position === 'dosen' && $kegiatan === 'mengajar') {
            // Ekstrak nomor sesi dari keterangan: prefix "[Sesi N]"
            $sesi_num = 1;
            if (!empty($row['keterangan']) && preg_match('/^\[Sesi (\d+)\]/', $row['keterangan'], $m)) {
                $sesi_num = (int)$m[1];
            } else {
                // Record lama tanpa prefix sesi → anggap sesi 1
                while (isset($absensi_hari_map["mengajar_{$sesi_num}"])) $sesi_num++;
            }
            $key = "mengajar_{$sesi_num}";
            $mengajar_sesi_terpakai[] = $sesi_num;
        } else {
            $key = $position === 'dosen' ? $kegiatan : 'absensi';
        }

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

// ── Auto INSERT alfa jika belum ada record hari ini (hanya staff) ────────────
if (!$absensi_hari_ini && $nik_pegawai && $position !== 'dosen') {
    $hari_ini = date('Y-m-d');
    $dow      = (int) date('N'); // 1=Senin … 7=Minggu

    if ($dow >= 1 && $dow <= 5) {
        $sql_auto = "INSERT IGNORE INTO absensi_staff
                        (staff_nik, tanggal, status_kehadiran, metode_absensi)
                     VALUES (?, ?, 'alfa', 'sistem')";

        if ($stmt_auto = mysqli_prepare($link, $sql_auto)) {
            mysqli_stmt_bind_param($stmt_auto, "ss", $nik_pegawai, $hari_ini);
            mysqli_stmt_execute($stmt_auto);
            mysqli_stmt_close($stmt_auto);
        }

        if ($stmt = mysqli_prepare($link, $sql_hari_ini)) {
            mysqli_stmt_bind_param($stmt, "s", $nik_pegawai);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($r)) {
                if (!$absensi_hari_ini) $absensi_hari_ini = $row;
                $absensi_hari_map['absensi'] = [
                    'status'     => $row['status_kehadiran'],
                    'jam_masuk'  => substr($row['jam_masuk']  ?? '--:--', 0, 5),
                    'jam_keluar' => substr($row['jam_keluar'] ?? '--:--', 0, 5),
                ];
            }
            mysqli_stmt_close($stmt);
        }
    }
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

// ── Fallback rekap ──
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
    $sql_hist = "SELECT id, tanggal, jenis_kegiatan AS shift_atau_kegiatan,
                        jam_mulai AS jam_masuk, jam_selesai AS jam_keluar,
                        lokasi, status_kehadiran, keterangan
                 FROM   absensi_dosen
                 WHERE  dosen_nik = ?
                 ORDER BY tanggal DESC, id DESC
                 LIMIT 10";
} else {
    $sql_hist = "SELECT id, tanggal, NULL AS shift_atau_kegiatan,
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

// ── Proses update jam keluar (AJAX) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'update_jam_keluar') {
    header('Content-Type: application/json');
    $absensi_id  = (int) ($_POST['absensi_id'] ?? 0);
    $jam_keluar_baru = date('H:i:s'); // Gunakan jam server saat ini
    $tanggal_hari_ini = date('Y-m-d');

    if (!$absensi_id || !$nik_pegawai) {
        echo json_encode(['ok' => false, 'pesan' => 'Data tidak valid.']);
        exit;
    }

    if ($position === 'dosen') {
        $tabel_upd  = 'absensi_dosen';
        $kolom_nik_upd = 'dosen_nik';
        $kolom_jam  = 'jam_selesai';
    } else {
        $tabel_upd  = 'absensi_staff';
        $kolom_nik_upd = 'staff_nik';
        $kolom_jam  = 'jam_keluar';
    }

    $sql_cek = "SELECT id FROM {$tabel_upd}
                WHERE id = ? AND {$kolom_nik_upd} = ?
                  AND tanggal = ? AND ({$kolom_jam} IS NULL OR {$kolom_jam} = '')
                LIMIT 1";
    $id_valid = null;
    if ($stmtC = mysqli_prepare($link, $sql_cek)) {
        mysqli_stmt_bind_param($stmtC, "iss", $absensi_id, $nik_pegawai, $tanggal_hari_ini);
        mysqli_stmt_execute($stmtC);
        mysqli_stmt_bind_result($stmtC, $id_valid);
        mysqli_stmt_fetch($stmtC);
        mysqli_stmt_close($stmtC);
    }

    if (!$id_valid) {
        echo json_encode(['ok' => false, 'pesan' => 'Record tidak ditemukan, bukan hari ini, atau jam keluar sudah terisi.']);
        exit;
    }

    $sql_upd2 = "UPDATE {$tabel_upd} SET {$kolom_jam} = ? WHERE id = ?";
    $berhasil = false;
    if ($stmtU = mysqli_prepare($link, $sql_upd2)) {
        mysqli_stmt_bind_param($stmtU, "si", $jam_keluar_baru, $absensi_id);
        $berhasil = mysqli_stmt_execute($stmtU);
        mysqli_stmt_close($stmtU);
    }

    echo json_encode([
        'ok'          => $berhasil,
        'jam_keluar'  => $berhasil ? substr($jam_keluar_baru, 0, 5) : null,
        'pesan'       => $berhasil ? 'Jam keluar berhasil diperbarui.' : 'Gagal menyimpan. Coba lagi.',
    ]);
    exit;
}

// Cek apakah password user mengandung/sama dengan kata "demo"
$pakai_password_demo = false;
if (!$pw_sukses && !empty($_SESSION["id"])) {
    require_once "../koneksi.php";
    $stmt_pw = mysqli_prepare($link, "SELECT password FROM credentials WHERE id = ? LIMIT 1");
    if ($stmt_pw) {
        mysqli_stmt_bind_param($stmt_pw, "i", $_SESSION["id"]);
        mysqli_stmt_execute($stmt_pw);
        mysqli_stmt_bind_result($stmt_pw, $_hashed_pw);
        if (mysqli_stmt_fetch($stmt_pw)) {
            $demo_variants = ['demo', 'Demo', 'DEMO', 'demo123', 'Demo123', 'DEMO123',
                              'passworddemo', 'demopassword', 'demo1234', 'demo2024', 'demo2025', 'demo2026'];
            foreach ($demo_variants as $variant) {
                if (password_verify($variant, $_hashed_pw)) {
                    $pakai_password_demo = true;
                    break;
                }
            }
        }
        mysqli_stmt_close($stmt_pw);
    }
    mysqli_close($link);
} else {
    mysqli_close($link);
}

// Tanggal & waktu
$hari_id   = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa',
               'Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
$nama_bulan = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',
    5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',
    9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];
$hari_nama       = $hari_id[date('l')] ?? date('l');
$bln_nama        = $nama_bulan[$bulan_ini];
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
            padding-bottom: 80px;
            transition: background .35s, color .35s;
            color: var(--gray-800);
        }

        /* ─── TOP BAR ────────────────────────────────────── */
        .top-bar {
            position: sticky; top: 0; z-index: 100;
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 0 16px; height: 56px;
            display: flex; align-items: center; justify-content: space-between;
            box-shadow: 0 1px 8px rgba(0,0,0,0.06);
            transition: background .35s, border-color .35s;
        }

        .brand { display:flex; align-items:center; gap:9px; text-decoration:none; }
        .brand-icon {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, var(--blue), var(--blue-mid));
            border-radius: 9px; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .brand-icon i { color: var(--yellow); font-size: 14px; }
        .brand-text .t1 { font-size: 13px; font-weight: 800; color: var(--blue); line-height: 1.2; transition: color .35s; }
        .brand-text .t2 { font-size: 9.5px; font-weight: 500; color: var(--gray-400); letter-spacing: .5px; text-transform: uppercase; }

        .topbar-right { display:flex; align-items:center; gap:10px; }
        .dark-toggle {
            width: 40px; height: 22px; background: var(--gray-200);
            border: 1.5px solid var(--gray-300); border-radius: 999px; cursor: pointer;
            position: relative; transition: all .3s;
        }
        .dark-toggle::after {
            content:''; position: absolute; top:2px; right:2px; width:14px; height:14px;
            background: var(--yellow); border-radius:50%; transition: all .3s; box-shadow:0 1px 3px rgba(0,0,0,.2);
        }

        .btn-logout {
            width: 32px; height: 32px; background: var(--gray-100);
            border: 1px solid var(--gray-200); border-radius: 8px;
            display:flex; align-items:center; justify-content:center;
            color: var(--gray-500); font-size: 13px; cursor:pointer; text-decoration:none; transition: all .2s;
        }
        .btn-logout:hover { background:var(--red-bg); color:var(--red); border-color:#fecaca; }

        .page-content { max-width: 540px; margin: 0 auto; padding: 0 14px; }

        /* ─── HERO / GREETING CARD ───────────────────────── */
        .hero-card {
            background: linear-gradient(145deg, var(--blue) 0%, var(--blue-mid) 55%, var(--blue-light) 100%);
            border-radius: 0 0 24px 24px; padding: 20px 20px 24px;
            position: relative; overflow: hidden; margin-bottom: 20px;
        }
        .hero-card::before {
            content:''; position:absolute; top:-50px; right:-50px; width:160px; height:160px; border-radius:50%; background:rgba(255,215,0,.10);
        }
        .hero-card::after {
            content:''; position:absolute; bottom:-40px; left:-30px; width:120px; height:120px; border-radius:50%; background:rgba(255,255,255,.05);
        }
        .hero-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:16px; position:relative; z-index:1; }
        .greeting-text .g-time { font-size:11px; font-weight:600; color:rgba(255,255,255,.55); letter-spacing:.8px; text-transform:uppercase; margin-bottom:4px; }
        .greeting-text .g-name { font-size:20px; font-weight:800; color:var(--white); line-height:1.2; }
        .greeting-text .g-name span { color:var(--yellow); }
        .greeting-text .g-role { font-size:12px; color:rgba(255,255,255,.6); margin-top:3px; }
        .avatar {
            width:46px; height:46px; border-radius:12px; background:rgba(255,215,0,.2); border:2px solid rgba(255,215,0,.4);
            display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden;
        }
        .avatar img { width:100%; height:100%; object-fit:cover; }
        .avatar i { font-size:20px; color:var(--yellow); }
        .hero-clock { background:rgba(0,0,0,.25); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; position:relative; z-index:1; }
        .clock-left .c-time { font-size:28px; font-weight:800; color:var(--yellow); letter-spacing:1.5px; font-variant-numeric:tabular-nums; }
        .clock-left .c-date { font-size:11.5px; color:rgba(255,255,255,.6); margin-top:2px; }
        .clock-right { text-align:right; }
        .status-today { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.4px; }
        .status-today.belum { background:rgba(220,38,38,.2); color:#fca5a5; border:1px solid rgba(220,38,38,.3); }
        .status-today.hadir { background:rgba(22,163,74,.2); color:#86efac; border:1px solid rgba(22,163,74,.3); }
        .status-today.izin, .status-today.sakit, .status-today.cuti { background:rgba(99,102,241,.2); color:#a5b4fc; border:1px solid rgba(99,102,241,.3); }
        .clock-right .c-label { font-size:9px; color:rgba(255,255,255,.4); text-transform:uppercase; letter-spacing:.6px; margin-top:4px; }

        /* ─── SECTION LABEL ──────────────────────────────── */
        .section-label { font-size:11.5px; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.8px; margin:0 0 10px; transition:color .35s; }

        /* ─── REKAP STRIP ─────────────────────────────────── */
        .rekap-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:20px; }
        .rekap-item { background:var(--white); border-radius:14px; padding:12px 8px; text-align:center; border:1px solid var(--gray-200); transition:background .35s, border-color .35s; }
        .rekap-item .ri-val { font-size:22px; font-weight:800; line-height:1.1; margin-bottom:3px; }
        .rekap-item .ri-label { font-size:10px; font-weight:600; color:var(--gray-400); text-transform:uppercase; letter-spacing:.4px; }
        .ri-hadir .ri-val  { color:var(--green); }
        .ri-izin  .ri-val  { color:#3b82f6; }
        .ri-sakit .ri-val  { color:#ea580c; }
        .ri-alfa  .ri-val  { color:var(--red); }

        /* ─── MAIN MENU CARDS ────────────────────────────── */
        .menu-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px; }
        .menu-card {
            background:var(--white); border:1px solid var(--gray-200); border-radius:18px; padding:20px 16px; text-decoration:none; color:inherit;
            display:flex; flex-direction:column; gap:12px; position:relative; overflow:hidden; transition:all .25s; cursor:pointer; -webkit-tap-highlight-color:transparent;
        }
        .menu-card:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(0,30,80,.12); }
        .menu-card:active { transform:translateY(0); box-shadow:none; }
        .menu-card.wide { grid-column:span 2; flex-direction:row; align-items:center; gap:16px; padding:18px 20px; }
        .menu-icon { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
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
            width:28px; height:28px; border-radius:8px; background:var(--gray-100); display:flex; align-items:center; justify-content:center;
            color:var(--gray-400); font-size:11px; flex-shrink:0; transition:all .25s;
        }
        .menu-card:hover .menu-arrow { background:var(--blue); color:var(--white); }
        .menu-card.accent-absensi { background:linear-gradient(135deg, var(--blue) 0%, var(--blue-mid) 100%); border-color:transparent; }
        .menu-card.accent-absensi .ic-absensi  { background:rgba(255,255,255,.15); }
        .menu-card.accent-absensi .ic-absensi i { color:var(--yellow); }
        .menu-card.accent-absensi .menu-title  { color:var(--white); }
        .menu-card.accent-absensi .menu-desc   { color:rgba(255,255,255,.65); }
        .menu-card.accent-absensi .menu-arrow  { background:rgba(255,255,255,.15); color:var(--white); }
        .menu-card.accent-absensi:hover .menu-arrow { background:var(--yellow); color:var(--blue); }
        .menu-badge { position:absolute; top:12px; right:12px; background:var(--red); color:var(--white); font-size:9.5px; font-weight:700; padding:2px 8px; border-radius:999px; letter-spacing:.4px; }

        /* ─── HISTORY SECTION ────────────────────────────── */
        .history-list { display:flex; flex-direction:column; gap:10px; margin-bottom:20px; }
        .history-item { background:var(--white); border:1px solid var(--gray-200); border-radius:16px; padding:14px; display:flex; align-items:flex-start; gap:14px; transition:all .25s; box-shadow:0 2px 8px rgba(0,0,0,0.02); position:relative; overflow:hidden; }
        .history-item:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,30,80,.06); border-color:var(--gray-300); }
        .hi-date { width:48px; flex-shrink:0; text-align:center; background:linear-gradient(135deg, var(--gray-50), var(--gray-100)); border: 1px solid var(--gray-200); border-radius:12px; padding:8px 4px; box-shadow: inset 0 2px 4px rgba(255,255,255,0.5); }
        .hi-date .hd-day  { font-size:19px; font-weight:800; color:var(--blue-mid); line-height:1.1; }
        .hi-date .hd-mon  { font-size:9.5px; font-weight:700; color:var(--gray-500); text-transform:uppercase; letter-spacing:.5px; margin-top:2px; }
        .hi-info { flex:1; min-width:0; display:flex; flex-direction:column; gap:4px; padding-top:2px; }
        .hi-top  { display:flex; align-items:center; justify-content:space-between; gap:6px; flex-wrap:wrap; }
        .hi-kegiatan { font-size:12.5px; font-weight:800; color:var(--gray-800); text-transform:capitalize; letter-spacing:0.2px; }
        .hi-jam  { font-size:11.5px; font-weight:600; color:var(--gray-500); display:flex; align-items:center; gap:5px; }
        .hi-jam i { color:var(--gray-400); font-size:11px; }
        .hi-lokasi { font-size:11px; color:var(--gray-400); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:flex; align-items:center; gap:5px; }
        .hi-lokasi i { font-size:10px; color:var(--gray-300); }
        .btn-update-jam { display: inline-flex; align-items: center; gap: 5px; margin-top: 6px; padding: 6px 12px; background: #eff6ff; border: 1.5px solid #93c5fd; border-radius: 10px; font-size: 11.5px; font-weight: 700; color: #1d4ed8; cursor: pointer; font-family: "Plus Jakarta Sans", sans-serif; transition: all .2s; white-space: nowrap; -webkit-tap-highlight-color: transparent; width: fit-content; }
        .btn-update-jam:hover { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
        .btn-update-jam:disabled { opacity: .5; cursor: not-allowed; }
        .btn-update-jam i { font-size: 10px; }
        body.dark .btn-update-jam { background: rgba(59,130,246,.12); border-color: rgba(59,130,246,.35); color: #93c5fd; }
        body.dark .btn-update-jam:hover { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
        .history-empty { background:var(--white); border:1px dashed var(--gray-300); border-radius:14px; padding:28px; text-align:center; color:var(--gray-400); }
        .history-empty i { font-size:28px; margin-bottom:8px; display:block; }
        .history-empty p { font-size:13px; }
        .btn-lihat-semua { display:flex; align-items:center; justify-content:center; gap:6px; width:100%; height:42px; background:var(--white); border:1px solid var(--gray-200); border-radius:12px; font-size:13px; font-weight:700; color:var(--blue-mid); cursor:pointer; text-decoration:none; transition:all .2s; margin-bottom:20px; }
        .btn-lihat-semua:hover { background:var(--blue); color:var(--white); border-color:var(--blue); }

        /* ─── HELPDESK SECTION ────────────────────────────── */
        .helpdesk-section { background:var(--white); border:1px solid var(--gray-200); border-radius:18px; padding:18px 16px; margin-bottom:20px; transition:background .35s, border-color .35s; }
        .helpdesk-header { display:flex; align-items:center; gap:10px; margin-bottom:14px; }
        .helpdesk-icon { width:40px; height:40px; border-radius:11px; background:#fef3c7; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .helpdesk-icon i { color:#92400e; font-size:17px; }
        .helpdesk-header h3 { font-size:14px; font-weight:800; color:var(--gray-800); }
        .helpdesk-header p  { font-size:11.5px; color:var(--gray-400); }
        .helpdesk-contacts { display:flex; flex-direction:column; gap:8px; }
        .contact-btn { display:flex; align-items:center; gap:12px; padding:11px 14px; border-radius:12px; border:1.5px solid var(--gray-200); text-decoration:none; color:var(--gray-800); background:var(--gray-50); transition:all .2s; -webkit-tap-highlight-color:transparent; }
        .contact-btn:hover { border-color:var(--blue); background:#eff6ff; }
        .contact-icon { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:15px; }
        .ci-wa  { background:#dcfce7; color:#16a34a; }
        .ci-tel { background:#dbeafe; color:#1d4ed8; }
        .ci-mail{ background:#fef3c7; color:#92400e; }
        .contact-info .ci-name  { font-size:12.5px; font-weight:700; }
        .contact-info .ci-value { font-size:11.5px; color:var(--gray-400); }
        .contact-btn .cb-arrow { margin-left:auto; color:var(--gray-300); font-size:12px; }

        /* ─── BOTTOM NAV ─────────────────────────────────── */
        .bottom-nav { position:fixed; bottom:0; left:0; right:0; background:var(--white); border-top:1px solid var(--gray-200); height:68px; display:flex; align-items:stretch; z-index:100; box-shadow:0 -4px 20px rgba(0,0,0,0.06); transition:background .35s, border-color .35s; }
        .nav-item { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; text-decoration:none; cursor:pointer; -webkit-tap-highlight-color:transparent; color:var(--gray-400); transition:color .2s; position:relative; }
        .nav-item.active { color:var(--blue); }
        .nav-item.active::before { content:''; position:absolute; top:0; left:50%; transform:translateX(-50%); width:32px; height:2.5px; background:var(--blue); border-radius:0 0 4px 4px; }
        .nav-item i  { font-size:18px; }
        .nav-item span { font-size:10px; font-weight:600; letter-spacing:.3px; }

        /* ─── MODAL ABSENSI ───────────────────────────────── */
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:200; display:flex; align-items:flex-end; justify-content:center; opacity:0; pointer-events:none; transition:opacity .3s; }
        .modal-overlay.open { opacity:1; pointer-events:all; }
        .modal-sheet { background:var(--white); border-radius:24px 24px 0 0; width:100%; max-width:540px; padding:0 0 32px; transform:translateY(100%); transition:transform .35s cubic-bezier(.32,0,.67,0); max-height:90vh; overflow-y:auto; }
        .modal-overlay.open .modal-sheet { transform:translateY(0); }
        .modal-handle { width:40px; height:4px; border-radius:999px; background:var(--gray-300); margin:12px auto 4px; }
        .modal-header { padding:16px 20px 14px; border-bottom:1px solid var(--gray-200); display:flex; align-items:center; justify-content:space-between; }
        .modal-header h3 { font-size:16px; font-weight:800; color:var(--blue); }
        .modal-close { width:32px; height:32px; border-radius:8px; background:var(--gray-100); border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; color:var(--gray-500); font-size:14px; transition:all .2s; }
        .modal-close:hover { background:var(--red-bg); color:var(--red); }
        .modal-body { padding:20px; }
        .absensi-form .form-group { margin-bottom:16px; }
        .form-label { display:flex; align-items:center; gap:5px; font-size:11px; font-weight:700; color:var(--gray-600); text-transform:uppercase; letter-spacing:.6px; margin-bottom:7px; transition:color .35s; }
        .form-label i { font-size:10px; color:var(--blue-light); }
        
        .form-control { width:100%; height:46px; padding:0 14px; font-size:14px; font-family:"Plus Jakarta Sans",sans-serif; color:var(--gray-800); background:var(--gray-50); border:1.5px solid var(--gray-200); border-radius:12px; outline:none; transition:all .25s; -webkit-appearance:none; }
        
        /* Dropdown Berwarna untuk menarik perhatian */
        .form-select {
            width: 100%; height: 46px; padding: 0 40px 0 16px; font-size: 14px; font-weight: 700;
            font-family: "Plus Jakarta Sans", sans-serif; color: var(--blue-mid);
            background-color: #eff6ff; /* Background biru muda */
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230066cc' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 14px center; background-size: 16px;
            border: 2px solid #93c5fd; border-radius: 12px; outline: none; transition: all .25s;
            -webkit-appearance: none; -moz-appearance: none; appearance: none; cursor: pointer;
            box-shadow: 0 2px 6px rgba(59, 130, 246, 0.1);
        }
        .form-select:hover { background-color: #dbeafe; border-color: var(--blue-light); }
        .form-select:focus { background-color: var(--white); border-color: var(--blue); box-shadow: 0 0 0 4px rgba(0,77,153,.15); }

        textarea.form-control { height:90px; padding:12px 14px; resize:none; line-height:1.5; }
        .input-readonly { background:var(--gray-100) !important; color:var(--gray-500); cursor:default; }
        .input-readonly:focus { border-color:var(--gray-200); box-shadow:none; }
        .form-control:focus { background:var(--white); border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(0,77,153,.10); }

        .sesi-pills { display: flex; flex-wrap: wrap; gap: 8px; }
        .sesi-pill { padding: 7px 18px; border-radius: 999px; border: 1.5px solid var(--gray-200); background: var(--gray-50); font-size: 12.5px; font-weight: 600; color: var(--gray-600); cursor: pointer; transition: all .2s; font-family: "Plus Jakarta Sans", sans-serif; -webkit-tap-highlight-color: transparent; }
        .sesi-pill:hover { border-color: var(--blue-light); color: var(--blue); }
        .sesi-pill.selected { background: #dbeafe; border-color: #3b82f6; color: #1e40af; }
        .sesi-pill.sudah-terpakai { background: var(--gray-100); color: var(--gray-300); border-color: var(--gray-200); cursor: not-allowed; text-decoration: line-through; opacity: 0.6; }

        .btn-absen-submit { width:100%; height:50px; background:linear-gradient(135deg, var(--blue), var(--blue-mid)); color:var(--white); border:none; border-radius:14px; font-size:14px; font-weight:800; font-family:"Plus Jakarta Sans",sans-serif; letter-spacing:.6px; text-transform:uppercase; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:all .3s; margin-top:4px; }
        .btn-absen-submit:hover { background:linear-gradient(135deg, var(--blue-mid), var(--blue-light)); box-shadow:0 8px 24px rgba(0,51,102,.3); }
        .btn-absen-submit:disabled { background: linear-gradient(135deg, var(--gray-300), var(--gray-400)); box-shadow: none; cursor: not-allowed; opacity: 0.75; }

        .upload-evidence-wrap { position: relative; }
        .evidence-method-btns { display: flex; gap: 8px; margin-bottom: 10px; }
        .evidence-method-btn { flex: 1; height: 44px; border-radius: 11px; border: 1.5px solid var(--gray-200); background: var(--gray-50); display: flex; align-items: center; justify-content: center; gap: 7px; font-size: 12.5px; font-weight: 700; color: var(--gray-600); cursor: pointer; transition: all .2s; font-family: "Plus Jakarta Sans", sans-serif; -webkit-tap-highlight-color: transparent; }
        .evidence-method-btn:hover { border-color: var(--blue-light); color: var(--blue); background: #eff6ff; }
        .evidence-method-btn.active { background: #dbeafe; border-color: #3b82f6; color: #1d4ed8; }
        .evidence-method-btn i { font-size: 15px; }

        .upload-evidence-box { width: 100%; min-height: 110px; border: 2px dashed var(--gray-300); border-radius: 14px; background: var(--gray-50); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; cursor: pointer; transition: all .25s; padding: 18px 16px; position: relative; overflow: hidden; -webkit-tap-highlight-color: transparent; }
        .upload-evidence-box:hover, .upload-evidence-box.drag-over { border-color: var(--blue-light); background: #eff6ff; }
        .upload-evidence-box input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
        .upload-evidence-icon { width: 44px; height: 44px; background: #dbeafe; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .upload-evidence-icon i { font-size: 20px; color: #1d4ed8; }
        .upload-evidence-label { font-size: 13px; font-weight: 700; color: var(--gray-700); text-align: center; line-height: 1.4; }
        .upload-evidence-sub   { font-size: 11px; color: var(--gray-400); text-align: center; }

        .camera-wrap { display: none; border-radius: 14px; overflow: hidden; border: 2px solid var(--blue-light); background: #000; position: relative; }
        .camera-wrap.show { display: block; }
        .camera-video { width: 100%; max-height: 280px; display: block; object-fit: cover; background: #000; }
        .camera-controls { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 12px 16px; background: rgba(0,0,0,.7); }
        .btn-capture { width: 62px; height: 62px; border-radius: 50%; background: var(--white); border: 4px solid rgba(255,255,255,.3); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; flex-shrink: 0; box-shadow: 0 0 0 3px rgba(255,255,255,.15); }
        .btn-capture:hover { background: #dbeafe; transform: scale(1.06); }
        .btn-capture:active { transform: scale(.96); }
        .btn-capture i { font-size: 26px; color: var(--blue); }
        .btn-flip-camera, .btn-close-camera { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,.15); border: none; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; cursor: pointer; transition: background .2s; }
        .btn-flip-camera:hover, .btn-close-camera:hover { background: rgba(255,255,255,.28); }
        .camera-hint { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,.55); color: #fff; font-size: 11px; font-weight: 600; padding: 4px 12px; border-radius: 999px; white-space: nowrap; pointer-events: none; }

        .evidence-preview-wrap { display: none; border-radius: 14px; overflow: hidden; border: 2px solid var(--blue-light); background: var(--gray-100); position: relative; }
        .evidence-preview-wrap.show { display: block; }
        .evidence-preview-img { width: 100%; max-height: 200px; object-fit: cover; display: block; }
        .evidence-preview-remove { position: absolute; top: 8px; right: 8px; width: 30px; height: 30px; background: rgba(220,38,38,.85); border: none; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; cursor: pointer; transition: background .2s; }
        .evidence-preview-remove:hover { background: var(--red); }
        .evidence-preview-name { padding: 7px 12px; font-size: 11.5px; font-weight: 600; color: var(--gray-600); display: flex; align-items: center; gap: 6px; background: var(--gray-50); border-top: 1px solid var(--gray-200); }
        .evidence-preview-name i { font-size: 12px; color: var(--blue-light); flex-shrink: 0; }

        .flash-alert { display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; margin:14px 0 4px; font-size:13px; font-weight:600; line-height:1.4; animation:slideDown .3s ease; }
        .flash-sukses { background:#dcfce7; border:1px solid #86efac; color:#166534; }
        .flash-error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
        .flash-alert i  { font-size:15px; flex-shrink:0; }
        .flash-alert span { flex:1; }
        .flash-close { background:none; border:none; cursor:pointer; color:inherit; opacity:.5; font-size:13px; padding:2px 4px; }
        .flash-close:hover { opacity:1; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }

        .popup-overlay { position:fixed; inset:0; background:rgba(0,0,0,.6); backdrop-filter:blur(6px); z-index:300; display:flex; align-items:center; justify-content:center; padding:20px; opacity:0; pointer-events:none; transition:opacity .25s; }
        .popup-overlay.open { opacity:1; pointer-events:all; }
        .popup-box { background:var(--white); border-radius:24px; padding:28px 24px 24px; width:100%; max-width:340px; text-align:center; box-shadow:0 24px 60px rgba(0,0,0,.25); transform:scale(.92) translateY(12px); transition:transform .3s cubic-bezier(.34,1.56,.64,1); }
        .popup-overlay.open .popup-box { transform:scale(1) translateY(0); }
        .popup-icon { width:60px; height:60px; border-radius:18px; background:#fff7ed; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; }
        .popup-icon i { font-size:26px; color:#f97316; }
        .popup-title { font-size:18px; font-weight:800; color:var(--gray-800); margin-bottom:8px; transition:color .35s; }
        .popup-desc { font-size:13px; color:var(--gray-500); line-height:1.6; margin-bottom:16px; transition:color .35s; }
        .popup-detail { background:var(--gray-50); border:1px solid var(--gray-200); border-radius:14px; padding:12px 14px; margin-bottom:14px; text-align:left; display:flex; flex-direction:column; gap:8px; transition:background .35s, border-color .35s; }
        .pd-row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .pd-label { font-size:11.5px; font-weight:600; color:var(--gray-400); display:flex; align-items:center; gap:5px; transition:color .35s; }
        .pd-label i { font-size:10px; }
        .pd-val { font-size:12.5px; font-weight:700; color:var(--gray-800); transition:color .35s; }
        .popup-hint { font-size:11.5px; color:var(--gray-400); margin-bottom:18px; line-height:1.5; transition:color .35s; }
        .popup-actions { display:flex; gap:10px; }
        .popup-btn-batal, .popup-btn-lanjut { flex:1; height:46px; border-radius:12px; font-size:13px; font-weight:700; font-family:"Plus Jakarta Sans",sans-serif; cursor:pointer; border:none; display:flex; align-items:center; justify-content:center; gap:6px; transition:all .2s; }
        .popup-btn-batal { background:var(--gray-100); border:1.5px solid var(--gray-200); color:var(--gray-600); }
        .popup-btn-batal:hover { background:var(--gray-200); }

        .tp-trigger { width:100%; height:46px; display:flex; align-items:center; gap:10px; padding:0 14px; background:var(--gray-50); border:1.5px solid var(--gray-200); border-radius:12px; cursor:pointer; transition:all .25s; user-select:none; -webkit-tap-highlight-color:transparent; }
        .tp-trigger:hover, .tp-trigger:active { background:var(--white); border-color:var(--blue-mid); box-shadow:0 0 0 3px rgba(0,77,153,.10); }
        .tp-trigger-icon { color:var(--blue-light); font-size:13px; flex-shrink:0; }
        .tp-trigger-val  { flex:1; font-size:14px; font-weight:600; color:var(--gray-800); }
        .tp-trigger-val.placeholder { color:var(--gray-400); font-weight:400; }
        .tp-trigger-arrow { color:var(--gray-300); font-size:11px; flex-shrink:0; }

        .tp-overlay { position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(4px); z-index:400; display:flex; align-items:flex-end; justify-content:center; opacity:0; pointer-events:none; transition:opacity .28s; }
        .tp-overlay.open { opacity:1; pointer-events:all; }
        .tp-sheet { background:var(--white); border-radius:28px 28px 0 0; width:100%; max-width:540px; padding-bottom:env(safe-area-inset-bottom, 12px); transform:translateY(100%); transition:transform .35s cubic-bezier(.32,0,.67,0); }
        .tp-overlay.open .tp-sheet { transform:translateY(0); }
        .tp-handle { width:40px; height:4px; border-radius:999px; background:var(--gray-300); margin:12px auto 0; }
        .tp-header { display:flex; align-items:center; justify-content:space-between; padding:14px 20px 10px; border-bottom:1px solid var(--gray-200); }
        .tp-header-title { font-size:15px; font-weight:800; color:var(--blue); }
        .tp-header-actions { display:flex; gap:8px; }
        .tp-btn-batal { height:34px; padding:0 14px; border-radius:10px; border:1.5px solid var(--gray-200); background:var(--gray-100); color:var(--gray-600); font-size:12.5px; font-weight:700; font-family:"Plus Jakarta Sans",sans-serif; cursor:pointer; transition:all .2s; }
        .tp-btn-ok { height:34px; padding:0 18px; border-radius:10px; border:none; background:linear-gradient(135deg, var(--blue), var(--blue-mid)); color:var(--white); font-size:12.5px; font-weight:800; font-family:"Plus Jakarta Sans",sans-serif; cursor:pointer; transition:all .2s; box-shadow:0 3px 10px rgba(0,51,102,.25); }
        .tp-preview { font-size:28px; font-weight:800; letter-spacing:2px; color:var(--blue); text-align:center; padding:10px 0 4px; }
        .tp-preview span { color:var(--gray-300); }
        .tp-drums { display:flex; align-items:center; justify-content:center; gap:0; padding:0 20px 20px; position:relative; }
        .tp-separator { font-size:26px; font-weight:800; color:var(--blue); margin:0 6px; padding-bottom:2px; flex-shrink:0; position:relative; z-index:2; }
        .tp-drum-wrap { flex:1; max-width:100px; position:relative; height:210px; overflow:hidden; }
        .tp-drum-wrap::before, .tp-drum-wrap::after { content:''; position:absolute; left:6px; right:6px; height:2px; background:var(--blue-mid); opacity:.25; z-index:3; pointer-events:none; }
        .tp-drum-wrap::before { top:84px; } .tp-drum-wrap::after  { top:126px; }
        .tp-drum-highlight { position:absolute; left:4px; right:4px; top:88px; height:34px; background:rgba(0,77,153,.07); border-radius:10px; z-index:2; pointer-events:none; border:1.5px solid rgba(0,77,153,.13); }
        .tp-drum { position:absolute; top:0; left:0; right:0; display:flex; flex-direction:column; align-items:center; cursor:grab; touch-action:none; }
        .tp-drum-item { height:42px; width:100%; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:700; color:var(--gray-300); transition:color .15s, font-size .15s, font-weight .15s; flex-shrink:0; user-select:none; }
        .tp-drum-item.active { color:var(--blue); font-size:22px; font-weight:800; }
        .tp-drum-item.near { color:var(--gray-500); font-size:18px; }
        .tp-drum-wrap .tp-mask-top, .tp-drum-wrap .tp-mask-bottom { position:absolute; left:0; right:0; height:84px; z-index:4; pointer-events:none; }
        .tp-drum-wrap .tp-mask-top { top:0; background:linear-gradient(to bottom, var(--white) 30%, rgba(255,255,255,0)); }
        .tp-drum-wrap .tp-mask-bottom { bottom:0; background:linear-gradient(to top, var(--white) 30%, rgba(255,255,255,0)); }

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
        body.dark .history-item { background:var(--dm-card); border-color:var(--dm-border); box-shadow:0 2px 8px rgba(0,0,0,0.2); }
        body.dark .history-item:hover { box-shadow:0 6px 16px rgba(0,0,0,.4); border-color:rgba(255,255,255,0.15); }
        body.dark .hi-date    { background:var(--dm-card2); border-color:var(--dm-border); box-shadow: inset 0 2px 4px rgba(255,255,255,0.02); }
        body.dark .hi-date .hd-day { color:var(--yellow); }
        body.dark .hi-date .hd-mon { color:var(--dm-muted); }
        body.dark .hi-kegiatan { color:var(--dm-text); }
        body.dark .hi-jam { color:var(--dm-muted); }
        body.dark .hi-jam i { color:var(--dm-muted); opacity: 0.7;}
        body.dark .hi-lokasi { color:var(--dm-muted); }
        body.dark .hi-lokasi i { color:var(--dm-muted); opacity: 0.5; }
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
        
        body.dark .form-control { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text); }
        body.dark .form-select { background-color: rgba(29, 78, 216, 0.15); border-color: rgba(59, 130, 246, 0.4); color: #93c5fd; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2393c5fd' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); box-shadow: none; }
        body.dark .form-select:hover { background-color: rgba(29, 78, 216, 0.25); border-color: rgba(59, 130, 246, 0.6); }
        body.dark .form-select:focus { background-color: var(--dm-card); border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2); }

        body.dark .input-readonly { background:var(--dm-bg) !important; color:var(--dm-muted); cursor:default; }
        body.dark .helpdesk-section { background:var(--dm-card); border-color:var(--dm-border); }
        body.dark .helpdesk-header h3 { color:var(--dm-text); }
        body.dark .contact-btn { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-text); }
        body.dark .section-label { color:var(--dm-muted); }
        body.dark .sesi-pill { background: var(--dm-card2); border-color: var(--dm-border); color: var(--dm-muted); }
        body.dark .sesi-pill.selected { background: #1e3a5f; border-color: #3b82f6; color: #93c5fd; }
        body.dark .evidence-method-btn { background: var(--dm-card2); border-color: var(--dm-border); color: var(--dm-muted); }
        body.dark .evidence-method-btn.active { background: #1e3a5f; border-color: #3b82f6; color: #93c5fd; }
        body.dark .upload-evidence-box { background: var(--dm-card2); border-color: var(--dm-border); }
        body.dark .upload-evidence-box:hover { background: rgba(29,78,216,.12); border-color: var(--blue-light); }
        body.dark .upload-evidence-label { color: var(--dm-text); }
        body.dark .evidence-preview-name { background: var(--dm-card2); border-color: var(--dm-border); color: var(--dm-muted); }
        body.dark .popup-box    { background:var(--dm-card); }
        body.dark .popup-title  { color:var(--dm-text); }
        body.dark .popup-desc   { color:var(--dm-muted); }
        body.dark .popup-detail { background:var(--dm-card2); border-color:var(--dm-border); }
        body.dark .pd-label     { color:var(--dm-muted); }
        body.dark .pd-val       { color:var(--dm-text); }
        body.dark .popup-hint   { color:var(--dm-muted); }
        body.dark .popup-hint strong { color:rgba(255,255,255,.55); }
        body.dark .popup-btn-batal { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .popup-btn-batal:hover { background:rgba(255,255,255,.08); }
        body.dark .tp-trigger { background:var(--dm-card2); border-color:var(--dm-border); }
        body.dark .tp-trigger:hover { background:var(--dm-card); border-color:rgba(0,102,204,.5); }
        body.dark .tp-trigger-val  { color:var(--dm-text); }
        body.dark .tp-sheet        { background:var(--dm-card); }
        body.dark .tp-handle       { background:var(--dm-border); }
        body.dark .tp-header       { border-color:var(--dm-border); }
        body.dark .tp-header-title { color:var(--yellow); }
        body.dark .tp-preview      { color:var(--yellow); }
        body.dark .tp-btn-batal    { background:var(--dm-card2); border-color:var(--dm-border); color:var(--dm-muted); }
        body.dark .tp-drum-item    { color:rgba(255,255,255,.18); }
        body.dark .tp-drum-item.near  { color:rgba(255,255,255,.45); }
        body.dark .tp-drum-item.active { color:var(--yellow); }
        body.dark .tp-drum-highlight { background:rgba(255,215,0,.07); border-color:rgba(255,215,0,.18); }
        body.dark .tp-drum-wrap::before, body.dark .tp-drum-wrap::after { background:var(--yellow); }
        body.dark .tp-drum-wrap .tp-mask-top    { background:linear-gradient(to bottom, var(--dm-card) 30%, rgba(13,31,60,0)); }
        body.dark .tp-drum-wrap .tp-mask-bottom { background:linear-gradient(to top,   var(--dm-card) 30%, rgba(13,31,60,0)); }

        @media (min-width:600px) {
            .page-content { padding:0 0; }
            .hero-card { border-radius:20px; margin:16px 0 20px; }
        }

        /* ─── SIDEBAR (admin/pimpinan only) ──────────────── */
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: 240px; background: linear-gradient(180deg, var(--blue) 0%, var(--blue-mid) 100%); z-index: 200; display: flex; flex-direction: column; box-shadow: 4px 0 24px rgba(0,0,0,.18); transform: translateX(-100%); transition: transform .3s cubic-bezier(.4,0,.2,1); }
        .sidebar.open { transform: translateX(0); }
        .sidebar-header { display: flex; align-items: center; gap: 10px; padding: 18px 16px 14px; border-bottom: 1px solid rgba(255,255,255,.12); }
        .sidebar-brand-icon { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,215,0,.18); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sidebar-brand-icon i { color: var(--yellow); font-size: 15px; }
        .sidebar-brand-text .sb-t1 { font-size: 13px; font-weight: 800; color: var(--white); line-height: 1.2; }
        .sidebar-brand-text .sb-t2 { font-size: 9px; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .5px; }
        .sidebar-close { margin-left: auto; background: rgba(255,255,255,.1); border: none; border-radius: 8px; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.7); font-size: 14px; cursor: pointer; transition: background .2s; flex-shrink: 0; }
        .sidebar-close:hover { background: rgba(255,255,255,.2); }
        .sidebar-section-label { font-size: 9.5px; font-weight: 700; color: rgba(255,255,255,.38); text-transform: uppercase; letter-spacing: 1px; padding: 18px 16px 6px; }
        .sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 10px; }
        .sidebar-link { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: rgba(255,255,255,.75); font-size: 13px; font-weight: 600; transition: background .2s, color .2s; margin-bottom: 2px; }
        .sidebar-link i { font-size: 14px; width: 18px; text-align: center; flex-shrink: 0; }
        .sidebar-link:hover { background: rgba(255,255,255,.1); color: var(--white); }
        .sidebar-link.active { background: rgba(255,215,0,.15); color: var(--yellow); }
        .sidebar-link.active i { color: var(--yellow); }
        .sidebar-footer { padding: 14px 10px; border-top: 1px solid rgba(255,255,255,.1); }
        .sidebar-link-logout { display: flex; align-items: center; gap: 11px; padding: 10px 12px; border-radius: 10px; text-decoration: none; color: rgba(255,100,100,.8); font-size: 13px; font-weight: 600; transition: background .2s, color .2s; }
        .sidebar-link-logout i { font-size: 14px; width: 18px; text-align: center; }
        .sidebar-link-logout:hover { background: rgba(220,38,38,.15); color: #fca5a5; }
        .sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 199; backdrop-filter: blur(2px); }
        .sidebar-backdrop.open { display: block; }
        .btn-sidebar-toggle { width: 34px; height: 34px; background: linear-gradient(135deg, var(--blue), var(--blue-mid)); border: none; border-radius: 9px; display: flex; align-items: center; justify-content: center; color: var(--yellow); font-size: 14px; cursor: pointer; text-decoration: none; transition: all .2s; }
        .btn-sidebar-toggle:hover { opacity: .85; }
        .btn-dashboard { display: flex; align-items: center; gap: 5px; padding: 5px 11px; background: linear-gradient(135deg, var(--blue), var(--blue-mid)); color: var(--yellow); border: none; border-radius: 8px; font-size: 11.5px; font-weight: 700; text-decoration: none; cursor: pointer; white-space: nowrap; transition: all .2s; letter-spacing: .3px; }
        .btn-dashboard:hover { background: linear-gradient(135deg, var(--blue-mid), var(--blue-light)); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,51,102,.3); }
        .btn-dashboard i { font-size: 12px; }
        .accent-dashboard .menu-icon { background: linear-gradient(135deg, #0f172a, #1e3a5f); color: var(--yellow); }
        .accent-dashboard { border-top: 3px solid var(--yellow); }
        body.dark .accent-dashboard { border-top-color: var(--yellow-dark); }

        #popupDesktopWarning { display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); align-items: center; justify-content: center; padding: 20px; }
        #popupDesktopWarning.show { display: flex; }
        .pdw-box { background: #ffffff; border-radius: 24px; padding: 40px 36px 36px; max-width: 460px; width: 100%; text-align: center; box-shadow: 0 32px 80px rgba(0,0,0,0.35); animation: pdwSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) both; position: relative; }
        @keyframes pdwSlideIn { from { opacity: 0; transform: translateY(30px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .pdw-icon-wrap { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, #fff3cd, #ffe082); display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; box-shadow: 0 8px 24px rgba(255,193,7,0.35); }
        .pdw-icon-wrap i { font-size: 36px; color: #f59e0b; }
        .pdw-title { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 10px; line-height: 1.3; }
        .pdw-desc { font-size: 14px; font-weight: 500; color: #475569; line-height: 1.7; margin-bottom: 28px; }
        .pdw-desc strong { color: #003366; }
        .pdw-phones { display: flex; align-items: center; justify-content: center; gap: 18px; margin-bottom: 28px; }
        .pdw-phone-icon { display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .pdw-phone-icon .pi-circle { width: 52px; height: 52px; border-radius: 14px; background: linear-gradient(135deg, #003366, #0066cc); display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px rgba(0,51,102,0.3); }
        .pdw-phone-icon .pi-circle i { color: #FFD700; font-size: 22px; }
        .pdw-phone-icon span { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .pdw-arrow { font-size: 20px; color: #cbd5e1; margin-top: -10px; }
        .pdw-divider { height: 1px; background: #f1f5f9; margin-bottom: 20px; }
        .pdw-footer-note { font-size: 12px; color: #94a3b8; font-weight: 500; }
        .pdw-footer-note i { color: #f59e0b; margin-right: 4px; }
        .pdw-btn-dashboard { display: inline-flex; align-items: center; justify-content: center; gap: 8px; margin-top: 20px; padding: 13px 28px; background: linear-gradient(135deg, #003366, #0066cc); color: #FFD700; font-family: "Plus Jakarta Sans", sans-serif; font-size: 14px; font-weight: 700; border: none; border-radius: 12px; cursor: pointer; text-decoration: none; box-shadow: 0 6px 20px rgba(0,51,102,0.35); transition: opacity 0.2s, transform 0.15s; width: 100%; }
        .pdw-btn-dashboard:hover { opacity: 0.9; transform: translateY(-1px); }
        .pdw-btn-dashboard i { font-size: 15px; }
    </style>
</head>
<body>

<!-- ══ POPUP PERINGATAN DESKTOP ══════════════════════════════════════════ -->
<div id="popupDesktopWarning" role="dialog" aria-modal="true" aria-labelledby="pdwTitle">
    <div class="pdw-box">
        <div class="pdw-icon-wrap">
            <i class="fas fa-mobile-screen-button"></i>
        </div>
        <h2 class="pdw-title" id="pdwTitle">Gunakan Smartphone</h2>
        <p class="pdw-desc">
            Halaman absensi <strong>TRACE</strong> dirancang khusus untuk perangkat <strong>smartphone</strong>.<br>
            Silakan buka halaman ini melalui ponsel Anda untuk melakukan absensi.
        </p>
        <div class="pdw-phones">
            <div class="pdw-phone-icon">
                <div class="pi-circle"><i class="fas fa-desktop"></i></div>
                <span>Desktop</span>
            </div>
            <div class="pdw-arrow"><i class="fas fa-arrow-right"></i></div>
            <div class="pdw-phone-icon">
                <div class="pi-circle"><i class="fas fa-mobile-screen"></i></div>
                <span>Smartphone</span>
            </div>
        </div>
        <div class="pdw-divider"></div>
        <p class="pdw-footer-note">
            <i class="fas fa-circle-info"></i>
            Akses absensi hanya tersedia di perangkat mobile.
        </p>
        <?php if ($is_admin): ?>
        <a href="dashboard.php" class="pdw-btn-dashboard">
            <i class="fas fa-gauge-high"></i>
            Buka Dashboard
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
/* Deteksi desktop & tampilkan popup — jalankan sebelum konten lain */
(function() {
    function isDesktop() {
        var ua = navigator.userAgent || '';
        var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Tablet/i.test(ua);
        var isWideScreen = window.innerWidth >= 1024;
        return !isMobile && isWideScreen;
    }

    function showDesktopWarning() {
        var popup = document.getElementById('popupDesktopWarning');
        if (popup) {
            popup.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (isDesktop()) showDesktopWarning();
        });
    } else {
        if (isDesktop()) showDesktopWarning();
    }

    window.addEventListener('resize', function() {
        if (isDesktop()) {
            showDesktopWarning();
        } else {
            var popup = document.getElementById('popupDesktopWarning');
            if (popup) {
                popup.classList.remove('show');
                document.body.style.overflow = '';
            }
        }
    });
})();
</script>

<?php if ($is_admin): ?>
<!-- SIDEBAR (admin/pimpinan) -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-brand-icon"><i class="fas fa-fingerprint"></i></div>
        <div class="sidebar-brand-text">
            <div class="sb-t1">TRACE</div>
            <div class="sb-t2">Menu Admin</div>
        </div>
        <button class="sidebar-close" id="sidebarClose"><i class="fas fa-xmark"></i></button>
    </div>

    <div class="sidebar-nav">
        <div class="sidebar-section-label">Beranda</div>
        <a href="#" class="sidebar-link active" onclick="closeSidebar();scrollToTop();return false;">
            <i class="fas fa-house"></i> Beranda
        </a>
        <a href="#" class="sidebar-link" onclick="closeSidebar();openModal();return false;">
            <i class="fas fa-fingerprint"></i> Absensi
        </a>
        <a href="#" class="sidebar-link" onclick="closeSidebar();scrollToHistory();return false;">
            <i class="fas fa-clock-rotate-left"></i> Riwayat
        </a>
        <a href="#" class="sidebar-link" onclick="closeSidebar();scrollToHelpdesk();return false;">
            <i class="fas fa-headset"></i> Helpdesk
        </a>

        <div class="sidebar-section-label">Admin</div>
        <a href="dashboard.php" class="sidebar-link">
            <i class="fas fa-chart-line"></i> Dashboard
        </a>

        <div class="sidebar-section-label">Tampilan</div>
        <a href="#" class="sidebar-link" id="sidebarDarkToggle" onclick="return false;">
            <i class="fas fa-moon"></i> <span id="sidebarDarkLabel">Mode Gelap</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <a href="../logout.php" class="sidebar-link-logout">
            <i class="fas fa-right-from-bracket"></i> Keluar
        </a>
    </div>
</aside>
<?php endif; ?>

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
        <?php if ($is_admin): ?>
        <button class="btn-sidebar-toggle" id="btnSidebarToggle" title="Menu Admin">
            <i class="fas fa-bars"></i>
        </button>
        <?php else: ?>
        <button class="dark-toggle" id="darkToggle" aria-label="Toggle dark mode"></button>
        <a href="../logout.php" class="btn-logout" title="Keluar">
            <i class="fas fa-right-from-bracket"></i>
        </a>
        <?php endif; ?>
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

    <?php if ($pw_sukses): ?>
    <div class="flash-alert flash-sukses" id="flashAlert">
        <i class="fas fa-shield-check"></i>
        <span>Password berhasil diubah! Akun Anda sekarang lebih aman.</span>
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
                    // Jika status alfa (auto-insert sistem), tetap tampilkan sebagai "Belum Absen"
                    if ($s === 'alfa') {
                        $status_chip_class = 'belum';
                        $status_chip_label = '● Belum Absen';
                    } else {
                        $status_chip_class = $s;
                        $label_map = [
                            'hadir'=>'✓ Hadir','izin'=>'Izin','sakit'=>'Sakit',
                            'cuti'=>'Cuti',
                            'dinas_luar'=>'Dinas','tugas_luar'=>'Tugas Luar'
                        ];
                        $status_chip_label = $label_map[$s] ?? ucfirst($s);
                    }
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
            <?php if ($absensi_hari_ini === null || ($absensi_hari_ini['status_kehadiran'] ?? '') === 'alfa'): ?>
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

        <?php if ($is_admin): ?>
        <!-- Dashboard — hanya admin & pimpinan -->
        <a href="dashboard.php" class="menu-card wide accent-dashboard">
            <div class="menu-icon"><i class="fas fa-chart-line"></i></div>
            <div class="menu-body">
                <div class="menu-title">Dashboard</div>
                <div class="menu-desc">Pantau rekap &amp; statistik kehadiran seluruh pegawai</div>
            </div>
            <div class="menu-arrow"><i class="fas fa-chevron-right"></i></div>
        </a>
        <?php endif; ?>

    </div>

    <!-- HISTORY -->
    <p class="section-label" id="historySection">Riwayat Absensi</p>
    <div class="history-list">
        <?php if (empty($history)): ?>
        <div class="history-empty">
            <i class="fas fa-inbox"></i>
            <p>Belum ada riwayat absensi.</p>
        </div>
        <?php elseif (!empty($history)): foreach ($history as $h):
            $tgl   = new DateTime($h['tanggal']);
            $day   = $tgl->format('j');
            $month = strtoupper(substr($bln_id[$tgl->format('F')] ?? $tgl->format('M'), 0, 3));
            $jam_m = $h['jam_masuk']  ? substr($h['jam_masuk'], 0, 5)  : '--:--';
            $jam_k = $h['jam_keluar'] ? substr($h['jam_keluar'], 0, 5) : '--:--';
            // Tombol update jam keluar: hanya jika sudah ada jam masuk, jam_keluar kosong, tanggal = hari ini, DAN status hadir
            $boleh_update = !empty($h['jam_masuk']) && empty($h['jam_keluar']) && ($h['tanggal'] === date('Y-m-d')) && ($h['status_kehadiran'] === 'hadir');
        ?>
        <div class="history-item" id="histitem-<?php echo (int)$h['id']; ?>">
            <div class="hi-date">
                <div class="hd-day"><?php echo $day; ?></div>
                <div class="hd-mon"><?php echo $month; ?></div>
            </div>
            <div class="hi-info">
                <div class="hi-top">
                    <span class="hi-kegiatan"><?php echo htmlspecialchars(str_replace('_',' ', $h['shift_atau_kegiatan'] ?? '-')); ?></span>
                    <?php echo badge_status($h['status_kehadiran']); ?>
                </div>
                <div class="hi-jam" id="hijam-<?php echo (int)$h['id']; ?>"><i class="fas fa-clock"></i><?php echo $jam_m; ?> &mdash; <?php echo $jam_k; ?></div>
                <?php if (!empty($h['lokasi'])): ?>
                <div class="hi-lokasi"><i class="fas fa-location-dot"></i><?php echo htmlspecialchars($h['lokasi']); ?></div>
                <?php endif; ?>
                <?php if ($boleh_update): ?>
                <button class="btn-update-jam" id="btnujam-<?php echo (int)$h['id']; ?>"
                        onclick="updateJamKeluar(<?php echo (int)$h['id']; ?>, this)">
                    <i class="fas fa-clock-rotate-left"></i> Update Jam Keluar
                </button>
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
                <p>Layanan Senin–Jumat, 08.00–14.00 WIB</p>
            </div>
        </div>
        <div class="helpdesk-contacts">
            <a href="https://wa.me/6282130040002?text=Halo+Helpdesk+Polmain,+saya+butuh+bantuan+terkait+sistem+absensi." target="_blank" class="contact-btn">
                <div class="contact-icon ci-wa"><i class="fab fa-whatsapp"></i></div>
                <div class="contact-info">
                    <div class="ci-name">WhatsApp Helpdesk</div>
                    <div class="ci-value">+62 821-3004-0002</div>
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
            <form class="absensi-form" action="proses_absensi.php" method="POST" id="formAbsensi" enctype="multipart/form-data">

                <!-- Tanggal — readonly, otomatis -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-calendar"></i> Tanggal</label>
                    <input type="text" class="form-control input-readonly"
                           value="<?php echo $tanggal_panjang; ?>" readonly>
                    <input type="hidden" name="tanggal" value="<?php echo date('Y-m-d'); ?>">
                </div>

                <?php if ($position !== 'staff'): ?>
                <!-- Jenis Kegiatan — hanya untuk dosen -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-chalkboard-teacher"></i> Jenis Kegiatan</label>
                    <select name="jenis_kegiatan" class="form-select" id="selectJenisKegiatan" required onchange="onJenisKegiatanChange(this.value)">
                        <option value="mengajar">Mengajar</option>
                        <option value="rapat">Rapat</option>
                        <option value="administratif">Administratif</option>
                        <option value="penelitian">Penelitian</option>
                        <option value="pengabdian">Pengabdian</option>
                        <option value="lainnya">Lainnya</option>
                    </select>
                </div>

                <!-- Sesi Mengajar — hanya muncul saat memilih "Mengajar" -->
                <div class="form-group" id="grupSesiMengajar">
                    <label class="form-label"><i class="fas fa-layer-group"></i> Sesi ke-</label>
                    <div class="sesi-pills" id="sesiPills">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                        <button type="button"
                                class="sesi-pill <?php echo $s === 1 ? 'selected' : ''; ?>"
                                data-sesi="<?php echo $s; ?>"
                                onclick="pilihSesi(this)">
                            Sesi <?php echo $s; ?>
                        </button>
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="sesi_mengajar" id="inputSesiMengajar" value="1">
                    <div id="infoSesiTerpakai" style="font-size:11px;color:var(--gray-400);margin-top:5px;display:flex;align-items:center;gap:4px;display:none;">
                        <i class="fas fa-circle-info" style="font-size:10px;"></i>
                        <span id="infoSesiTerpakaiText"></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Jam Masuk — otomatis dari server, tidak bisa diubah -->
                <div class="form-group">
                    <label class="form-label" id="labelJamMasuk">
                        <i class="fas fa-clock"></i> <span id="textJamMasuk">Jam Masuk</span>
                    </label>
                    <div class="tp-trigger" id="tpMasukTrigger" style="cursor:default;opacity:.85;pointer-events:none;">
                        <i class="fas fa-clock tp-trigger-icon"></i>
                        <span class="tp-trigger-val" id="tpMasukDisplay"><?php echo date('H:i'); ?></span>
                        <i class="fas fa-lock" style="font-size:12px;color:var(--gray-400);margin-left:auto;"></i>
                    </div>
                    <div style="font-size:11px;color:var(--gray-400);margin-top:4px;display:flex;align-items:center;gap:4px;">
                        <i class="fas fa-circle-info" style="font-size:10px;"></i>
                        Waktu tercatat otomatis sesuai jam server dan tidak dapat diubah.
                    </div>
                    <input type="hidden" name="jam_masuk" id="inputJamMasuk" value="<?php echo date('H:i:s'); ?>">
                </div>

                <!-- Jam Keluar (wajib untuk dosen mengajar, opsional untuk kegiatan lain & staff) -->
                <div class="form-group" id="grupJamKeluar">
                    <?php if ($position === 'dosen'): ?>
                    <label class="form-label" id="labelJamKeluar">
                        <i class="fas fa-clock"></i> <span id="textJamKeluar">Jam Selesai</span>
                        <span id="jamSelesaiWajib" style="color:var(--red);font-weight:700;"> *</span>
                        <span id="jamSelesaiOpsional" style="font-weight:400;font-style:italic;text-transform:none;display:none;"> (opsional)</span>
                    </label>
                    <?php else: ?>
                    <label class="form-label" id="labelJamKeluar">
                        <i class="fas fa-clock"></i> <span id="textJamKeluar">Jam Keluar</span> <span style="font-weight:400;font-style:italic;text-transform:none;">(opsional)</span>
                    </label>
                    <?php endif; ?>
                    <div class="tp-trigger" id="tpKeluarTrigger" onclick="openTimePicker('keluar')">
                        <i class="fas fa-clock tp-trigger-icon"></i>
                        <span class="tp-trigger-val" id="tpKeluarDisplay"><?php echo $position === 'dosen' ? 'Pilih jam selesai' : 'Pilih jam keluar'; ?></span>
                        <i class="fas fa-chevron-right tp-trigger-arrow"></i>
                    </div>
                    <?php if ($position === 'dosen'): ?>
                    <div style="font-size:11px;color:var(--red);margin-top:4px;display:flex;align-items:center;gap:4px;" id="errorJamSelesai" style="display:none;">
                        <i class="fas fa-circle-exclamation" style="font-size:10px;"></i>
                        Jam selesai wajib diisi untuk kegiatan mengajar.
                    </div>
                    <?php endif; ?>
                    <input type="hidden" name="jam_keluar" id="inputJamKeluar" value="">
                </div>

                <!-- Lokasi -->
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-location-dot"></i> Lokasi</label>
                    <div class="input-wrap" style="position:relative;">
                        <input type="text" name="lokasi" class="form-control input-readonly"
                               placeholder="Otomatis terisi oleh sistem GPS"
                               id="inputLokasi" style="padding-right:42px;" readonly>
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
                    <select name="status_kehadiran" id="inputStatus" class="form-select" onchange="updateLabelsByStatus(this.value)">
                        <option value="hadir" selected>Hadir</option>
                        <option value="izin">Izin</option>
                        <option value="sakit">Sakit</option>
                        <option value="cuti">Cuti</option>
                        <?php if ($position === 'staff'): ?>
                        <option value="dinas_luar">Dinas</option>
                        <?php else: ?>
                        <option value="tugas_luar">Tugas Luar</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Lama Cuti -->
                <div class="form-group" id="grupLamaCuti" style="display:none;">
                    <label class="form-label"><i class="fas fa-calendar-day"></i> Berapa Hari Cuti <span style="color:var(--red);font-weight:700;"> *</span></label>
                    <input type="number" id="inputLamaCuti" class="form-control" placeholder="Contoh: 3" min="1">
                </div>

                <!-- Keterangan -->
                <div class="form-group">
                    <label class="form-label" id="labelKeterangan">
                        <i class="fas fa-pen-to-square"></i>
                        <?php if ($position === 'dosen'): ?>
                        <span id="labelKeteranganText">Nama Mata Kuliah/Praktikum</span>
                        <span id="labelKeteranganWajib" style="color:var(--red);font-weight:700;"> *</span>
                        <?php else: ?>
                        <span id="labelKeteranganText">Keterangan</span>
                        <span id="labelKeteranganOpsional" style="font-weight:400;font-style:italic;text-transform:none;"> (opsional)</span>
                        <?php endif; ?>
                    </label>
                    <textarea name="keterangan" id="inputKeterangan" class="form-control"
                              placeholder="<?php echo $position === 'dosen' ? 'Contoh: Pemrograman Web, Praktikum Basis Data...' : 'Tuliskan keterangan jika diperlukan...'; ?>"
                              <?php echo $position === 'dosen' ? 'required' : ''; ?>></textarea>
                    <div id="errorKeterangan" style="display:none;font-size:11px;color:var(--red);margin-top:4px;align-items:center;gap:4px;">
                        <i class="fas fa-circle-exclamation" style="font-size:10px;"></i>
                        Nama mata kuliah/praktikum wajib diisi.
                    </div>
                </div>

                <?php if ($position === 'dosen'): ?>
                <!-- Upload Foto Evidence (hanya dosen) -->
                <div class="form-group" id="grupFotoEvidence">
                    <label class="form-label"><i class="fas fa-camera"></i> Foto Evidence <span style="color:var(--red);font-weight:700;"> *</span> <span style="font-weight:400;font-style:italic;text-transform:none;font-size:10px;">(wajib untuk dosen)</span></label>

                    <div class="evidence-method-btns">
                        <button type="button" class="evidence-method-btn active" id="btnMethodCamera" onclick="switchEvidenceMethod('camera')">
                            <i class="fas fa-camera"></i> Buka Kamera
                        </button>
                        <button type="button" class="evidence-method-btn" id="btnMethodGallery" onclick="switchEvidenceMethod('gallery')">
                            <i class="fas fa-image"></i> Pilih dari Galeri
                        </button>
                    </div>

                    <div class="upload-evidence-wrap">
                        <div class="camera-wrap show" id="cameraWrap">
                            <video class="camera-video" id="cameraVideo" autoplay playsinline muted></video>
                            <div class="camera-hint" id="cameraHint">Arahkan kamera ke objek</div>
                            <canvas id="cameraCanvas" style="display:none;"></canvas>
                            <div class="camera-controls">
                                <button type="button" class="btn-close-camera" onclick="closeCamera()" title="Tutup kamera">
                                    <i class="fas fa-xmark"></i>
                                </button>
                                <button type="button" class="btn-capture" onclick="capturePhoto()" title="Ambil foto" id="btnCapture">
                                    <i class="fas fa-circle-dot"></i>
                                </button>
                                <button type="button" class="btn-flip-camera" onclick="flipCamera()" title="Ganti kamera" id="btnFlip">
                                    <i class="fas fa-rotate"></i>
                                </button>
                            </div>
                        </div>

                        <div class="upload-evidence-box" id="evidenceDropBox" style="display:none;">
                            <input type="file" name="foto_evidence" id="inputFotoEvidence"
                                   accept="image/jpeg,image/png,image/webp"
                                   onchange="onEvidenceSelected(this)">
                            <div class="upload-evidence-icon"><i class="fas fa-image"></i></div>
                            <div class="upload-evidence-label">Tap untuk pilih foto dari galeri</div>
                            <div class="upload-evidence-sub">JPG, PNG, WEBP &mdash; maks. 20 MB</div>
                        </div>

                        <div class="evidence-preview-wrap" id="evidencePreviewWrap">
                            <img src="" alt="Preview" class="evidence-preview-img" id="evidencePreviewImg">
                            <button type="button" class="evidence-preview-remove" onclick="hapusEvidence()" title="Hapus foto">
                                <i class="fas fa-xmark"></i>
                            </button>
                            <div class="evidence-preview-name">
                                <i class="fas fa-image"></i>
                                <span id="evidencePreviewName">-</span>
                            </div>
                        </div>
                    </div>

                    <div id="errorFotoEvidence" style="display:none;font-size:11px;color:var(--red);margin-top:4px;align-items:center;gap:4px;">
                        <i class="fas fa-circle-exclamation" style="font-size:10px;"></i>
                        <span id="errorFotoEvidenceText">Foto evidence wajib disertakan untuk dosen.</span>
                    </div>
                </div>
                <?php endif; ?>

                <input type="hidden" name="position" value="<?php echo htmlspecialchars($position); ?>">
                <input type="hidden" name="metode_absensi" value="manual">

                <button type="submit" class="btn-absen-submit" id="btnSubmitAbsensi" disabled>
                    <i class="fas fa-location-dot"></i> Mendeteksi Lokasi...
                </button>
            </form>
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
        <p class="popup-hint">Anda sudah tercatat hadir untuk tanggal ini. Hubungi admin jika ada kesalahan.</p>
        <div class="popup-actions">
            <button class="popup-btn-batal" onclick="tutupPopupDuplikat()" style="flex:1;">
                <i class="fas fa-check"></i> Mengerti
            </button>
        </div>
    </div>
</div>

<!-- ── POPUP SUCCESS ABSENSI (ANIMASI CENTANG) ────────────────────────── -->
<?php if ($flash_pesan && $flash_tipe === 'sukses' && $absensi_hari_ini): ?>
<style>
    .animate-check { width: 72px; height: 72px; margin: 0 auto 16px; display: block; }
    .animate-check .circle {
        stroke-dasharray: 166; stroke-dashoffset: 166; stroke-width: 3.5; stroke-miterlimit: 10;
        stroke: #22c55e; fill: rgba(34, 197, 94, 0.1);
        animation: stroke-circle 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
    }
    .animate-check .check {
        transform-origin: 50% 50%; stroke-dasharray: 48; stroke-dashoffset: 48;
        stroke-width: 4; stroke-linecap: round; stroke-linejoin: round;
        stroke: #22c55e; fill: none;
        animation: stroke-check 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.5s forwards;
    }
    @keyframes stroke-circle { 100% { stroke-dashoffset: 0; } }
    @keyframes stroke-check { 100% { stroke-dashoffset: 0; } }
    
    body.dark .animate-check .circle { stroke: #4ade80; fill: rgba(74, 222, 128, 0.1); }
    body.dark .animate-check .check { stroke: #4ade80; }
    
    #popupSuccessAbsensi .popup-box {
        animation: popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) both;
    }
    @keyframes popIn {
        from { transform: scale(0.8) translateY(20px); opacity: 0; }
        to { transform: scale(1) translateY(0); opacity: 1; }
    }
</style>
<div class="popup-overlay open" id="popupSuccessAbsensi" style="z-index: 99999; display: flex;">
    <div class="popup-box" style="text-align: center; padding: 32px 24px 24px; max-width: 320px;">
        <svg class="animate-check" viewBox="0 0 52 52">
            <circle class="circle" cx="26" cy="26" r="24"/>
            <path class="check" d="M16 26l6 6 14-14"/>
        </svg>
        <h3 class="popup-title" style="margin-bottom: 6px;">Absensi Berhasil!</h3>
        <p class="popup-desc" style="margin-bottom: 20px;">
            <?php echo htmlspecialchars($flash_pesan); ?>
        </p>
        <div class="popup-detail" style="text-align: left; margin-bottom: 0;">
            <div class="pd-row">
                <span class="pd-label"><i class="fas fa-circle-dot"></i> Status</span>
                <span class="pd-val"><?php echo ucfirst(str_replace('_', ' ', $absensi_hari_ini['status_kehadiran'])); ?></span>
            </div>
            <div class="pd-row">
                <span class="pd-label"><i class="fas fa-clock"></i> Waktu</span>
                <span class="pd-val"><?php 
                    $jam = $position === 'dosen' ? ($absensi_hari_ini['jam_mulai'] ?? '') : ($absensi_hari_ini['jam_masuk'] ?? '');
                    echo $jam ? substr($jam, 0, 5) : '--:--'; 
                ?></span>
            </div>
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Hilangkan animasi statis usai dimuat agar bersih saat ditutup
        setTimeout(function() {
            var box = document.querySelector('#popupSuccessAbsensi .popup-box');
            if (box) box.style.animation = 'none';
        }, 600);

        // Auto Close dalam 3 detik
        setTimeout(function() {
            var popup = document.getElementById('popupSuccessAbsensi');
            if(popup) {
                popup.classList.remove('open');
                setTimeout(function() { popup.remove(); }, 350); // tunggu animasi css opacity habis
            }
        }, 3000); 
    });
</script>
<?php endif; ?>

<!-- ── POPUP PERINGATAN PASSWORD DEMO ────────────────────────────────────── -->
<?php if ($pakai_password_demo || $pw_error): ?>
<style>
    #popupPasswordDemo .popup-box { border-top: 4px solid #f59e0b; max-width: 360px; }
    @keyframes gpwShake { 0%,100% { transform: scale(1) translateX(0); } 20% { transform: scale(1) translateX(-7px); } 40% { transform: scale(1) translateX(7px); } 60% { transform: scale(1) translateX(-5px); } 80% { transform: scale(1) translateX(5px); } }
    #popupPasswordDemo .popup-icon { width: 64px; height: 64px; border-radius: 20px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); box-shadow: 0 4px 16px rgba(245,158,11,.25); }
    #popupPasswordDemo .popup-icon i { font-size: 28px; color: #d97706; }
    #popupPasswordDemo .popup-title { color: #92400e; }
    body.dark #popupPasswordDemo .popup-icon { background: linear-gradient(135deg, #451a03 0%, #78350f 100%); box-shadow: 0 4px 16px rgba(245,158,11,.15); }
    body.dark #popupPasswordDemo .popup-title { color: #fcd34d; }
    .demo-warn-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 13px; display: flex; align-items: flex-start; gap: 9px; margin-bottom: 18px; }
    body.dark .demo-warn-box { background: rgba(245,158,11,.08); border-color: rgba(245,158,11,.25); }
    .demo-warn-box i { color: #d97706; font-size: 13px; flex-shrink: 0; margin-top: 2px; }
    .demo-warn-box span { font-size: 12px; color: #92400e; line-height: 1.55; }
    body.dark .demo-warn-box span { color: #fcd34d; }
    .gpw-group { margin-bottom: 12px; text-align: left; }
    .gpw-label { font-size: 11.5px; font-weight: 700; color: var(--gray-600); margin-bottom: 5px; display: flex; align-items: center; gap: 5px; letter-spacing: .2px; text-transform: uppercase; }
    body.dark .gpw-label { color: var(--dm-muted); }
    .gpw-input-wrap { position: relative; }
    .gpw-input { width: 100%; height: 44px; padding: 0 42px 0 13px; border: 1.5px solid var(--gray-200); border-radius: 11px; font-size: 13.5px; font-family: "Plus Jakarta Sans", sans-serif; color: var(--gray-800); background: var(--gray-50); outline: none; transition: border-color .2s, box-shadow .2s; }
    .gpw-input:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.15); background: #fff; }
    body.dark .gpw-input { background: var(--dm-card2); border-color: var(--dm-border); color: var(--dm-text); }
    body.dark .gpw-input:focus { border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.12); }
    .gpw-eye { position: absolute; right: 0; top: 0; height: 44px; width: 40px; display: flex; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; color: var(--gray-400); transition: color .2s; }
    .gpw-eye:hover { color: var(--gray-600); }
    .gpw-error { font-size: 11.5px; color: var(--red); margin-top: 5px; display: flex; align-items: center; gap: 4px; }
    .gpw-error i { font-size: 10px; }
    .gpw-strength { display: flex; gap: 4px; margin-top: 7px; }
    .gpw-strength-bar { flex: 1; height: 3px; border-radius: 3px; background: var(--gray-200); transition: background .3s; }
    .gpw-strength-label { font-size: 11px; color: var(--gray-400); margin-top: 4px; text-align: right; transition: color .3s; }
    .btn-ganti-pw { width: 100%; height: 46px; border-radius: 12px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border: none; font-size: 13.5px; font-weight: 700; font-family: "Plus Jakarta Sans", sans-serif; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 7px; box-shadow: 0 4px 14px rgba(245,158,11,.35); transition: all .2s; margin-top: 4px; }
    .btn-ganti-pw:hover { background: linear-gradient(135deg, #d97706, #b45309); box-shadow: 0 6px 20px rgba(245,158,11,.45); transform: translateY(-1px); }
    .btn-ganti-pw:disabled { opacity: .6; cursor: not-allowed; transform: none; }
    .gpw-sukses { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 11px; padding: 13px 15px; display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
    .gpw-sukses i { color: #16a34a; font-size: 18px; flex-shrink: 0; }
    .gpw-sukses span { font-size: 13px; color: #166534; font-weight: 600; line-height: 1.45; }
    body.dark .gpw-sukses { background: rgba(22,163,74,.1); border-color: rgba(22,163,74,.3); }
    body.dark .gpw-sukses span { color: #86efac; }
</style>

<div class="popup-overlay <?php echo ($pakai_password_demo || $pw_error) ? 'open' : ''; ?>" id="popupPasswordDemo" style="z-index:9999;">
    <div class="popup-box" id="popupPasswordDemoBox">

        <div class="popup-icon">
            <i class="fas fa-lock-open"></i>
        </div>
        <h3 class="popup-title">Password Tidak Aman</h3>
        <p class="popup-desc">
            Akun Anda masih menggunakan <strong style="color:#b45309;">password demo</strong>.
            Segera buat password baru yang kuat.
        </p>

        <div class="demo-warn-box">
            <i class="fas fa-triangle-exclamation"></i>
            <span>Password demo mudah ditebak dan membahayakan data absensi Anda. Peringatan ini akan terus muncul hingga password diganti.</span>
        </div>

        <?php if ($pw_error): ?>
        <div class="gpw-error" style="margin-bottom:12px;font-size:12.5px;">
            <i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($pw_error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="" id="formGantiPassword" autocomplete="off">
            <input type="hidden" name="_action" value="ganti_password">

            <div class="gpw-group">
                <div class="gpw-label"><i class="fas fa-key"></i> Password Baru</div>
                <div class="gpw-input-wrap">
                    <input type="password" name="pw_baru" id="gpwBaru" class="gpw-input"
                           placeholder="Minimal 6 karakter" autocomplete="new-password" required>
                    <button type="button" class="gpw-eye" onclick="toggleGpw('gpwBaru','eyeBaru')">
                        <i class="fas fa-eye" id="eyeBaru"></i>
                    </button>
                </div>
                <div class="gpw-strength" id="gpwStrengthBars">
                    <div class="gpw-strength-bar" id="gpwBar1"></div>
                    <div class="gpw-strength-bar" id="gpwBar2"></div>
                    <div class="gpw-strength-bar" id="gpwBar3"></div>
                    <div class="gpw-strength-bar" id="gpwBar4"></div>
                </div>
                <div class="gpw-strength-label" id="gpwStrengthLabel"></div>
            </div>

            <div class="gpw-group">
                <div class="gpw-label"><i class="fas fa-check-double"></i> Konfirmasi Password</div>
                <div class="gpw-input-wrap">
                    <input type="password" name="pw_ulang" id="gpwUlang" class="gpw-input"
                           placeholder="Ulangi password baru" autocomplete="new-password" required>
                    <button type="button" class="gpw-eye" onclick="toggleGpw('gpwUlang','eyeUlang')">
                        <i class="fas fa-eye" id="eyeUlang"></i>
                    </button>
                </div>
                <div id="gpwMatchMsg" style="font-size:11.5px;margin-top:5px;display:none;align-items:center;gap:4px;"></div>
            </div>

            <button type="submit" class="btn-ganti-pw" id="btnGantiPw">
                <i class="fas fa-shield-check"></i> Simpan Password Baru
            </button>
        </form>

        <p style="font-size:11.5px;color:var(--gray-400);text-align:center;margin-top:10px;line-height:1.5;">
            <i class="fas fa-lock" style="font-size:10px;"></i>
            Popup ini tidak dapat ditutup sebelum password diganti.
        </p>

    </div>
</div>
<?php endif; ?>

<!-- ── TIMEPICKER OVERLAY ────────────────────────────────────────── -->
<div class="tp-overlay" id="tpOverlay">
    <div class="tp-sheet">
        <div class="tp-handle"></div>
        <div class="tp-header">
            <div class="tp-header-title" id="tpTitle">Pilih Jam Masuk</div>
            <div class="tp-header-actions">
                <button class="tp-btn-batal" onclick="closeTimePicker()">Batal</button>
                <button class="tp-btn-ok" onclick="confirmTimePicker()">Pilih</button>
            </div>
        </div>
        <div class="tp-preview">
            <span id="tpPreviewH">00</span><span style="opacity:.35">:</span><span id="tpPreviewM">00</span>
        </div>
        <div class="tp-drums">
            <div class="tp-drum-wrap" id="tpDrumHourWrap">
                <div class="tp-mask-top"></div>
                <div class="tp-drum-highlight"></div>
                <div class="tp-drum" id="tpDrumHour"></div>
                <div class="tp-mask-bottom"></div>
            </div>
            <div class="tp-separator">:</div>
            <div class="tp-drum-wrap" id="tpDrumMinWrap">
                <div class="tp-mask-top"></div>
                <div class="tp-drum-highlight"></div>
                <div class="tp-drum" id="tpDrumMin"></div>
                <div class="tp-mask-bottom"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const ITEM_H   = 42; 
    const VISIBLE  = 5; 
    const CENTER   = Math.floor(VISIBLE / 2);

    let _target    = null;
    let _selH      = 0;
    let _selM      = 0;

    function buildDrum(el, count, pad, loop) {
        el.innerHTML = '';
        for (let i = 0; i < CENTER; i++) {
            const d = document.createElement('div');
            d.className = 'tp-drum-item';
            el.appendChild(d);
        }
        for (let i = 0; i < count; i++) {
            const d = document.createElement('div');
            d.className = 'tp-drum-item';
            d.textContent = String(i).padStart(pad, '0');
            d.dataset.val = i;
            el.appendChild(d);
        }
        for (let i = 0; i < CENTER; i++) {
            const d = document.createElement('div');
            d.className = 'tp-drum-item';
            el.appendChild(d);
        }
    }

    function snapTo(drum, val, animate) {
        const y = -(val * ITEM_H);
        if (animate) {
            drum.style.transition = 'top .25s cubic-bezier(.25,.46,.45,.94)';
        } else {
            drum.style.transition = 'none';
        }
        drum.style.top = y + 'px';
        refreshItems(drum, val);
    }

    function refreshItems(drum, active) {
        const items = drum.querySelectorAll('.tp-drum-item[data-val]');
        items.forEach(el => {
            const v = parseInt(el.dataset.val);
            el.classList.remove('active', 'near');
            if (v === active) el.classList.add('active');
            else if (Math.abs(v - active) === 1) el.classList.add('near');
        });
        if (drum === document.getElementById('tpDrumHour')) {
            _selH = active;
            document.getElementById('tpPreviewH').textContent = String(active).padStart(2,'0');
        } else {
            _selM = active;
            document.getElementById('tpPreviewM').textContent = String(active).padStart(2,'0');
        }
    }

    function attachDrag(drum, count) {
        let startY   = 0;
        let startTop = 0;
        let dragging = false;
        let lastY    = 0;
        let vel      = 0;
        let lastT    = 0;
        let rafId    = null;

        function getTop() { return parseInt(drum.style.top) || 0; }
        function clamp(top) { return Math.min(0, Math.max(-(count - 1) * ITEM_H, top)); }
        function topToVal(top) { return Math.round(-top / ITEM_H); }

        function onStart(y) {
            dragging = true; vel = 0;
            startY = y; startTop = getTop();
            lastY = y; lastT = Date.now();
            drum.style.transition = 'none';
            if (rafId) cancelAnimationFrame(rafId);
        }

        function onMove(y) {
            if (!dragging) return;
            const dy   = y - startY;
            const now  = Date.now();
            vel = (y - lastY) / Math.max(1, now - lastT) * 16;
            lastY = y; lastT = now;
            const newTop = clamp(startTop + dy);
            drum.style.top = newTop + 'px';
            refreshItems(drum, topToVal(newTop));
        }

        function onEnd() {
            if (!dragging) return;
            dragging = false;
            let top = getTop();
            function momentum() {
                vel *= 0.88;
                top = clamp(top + vel);
                drum.style.top = top + 'px';
                refreshItems(drum, topToVal(top));
                if (Math.abs(vel) > 0.5) {
                    rafId = requestAnimationFrame(momentum);
                } else {
                    const v = Math.max(0, Math.min(count - 1, topToVal(top)));
                    snapTo(drum, v, true);
                }
            }
            rafId = requestAnimationFrame(momentum);
        }

        drum.addEventListener('mousedown',  e => { e.preventDefault(); onStart(e.clientY); });
        window.addEventListener('mousemove', e => { if (dragging) onMove(e.clientY); });
        window.addEventListener('mouseup',   ()  => onEnd());

        drum.addEventListener('touchstart', e => { e.preventDefault(); onStart(e.touches[0].clientY); }, { passive:false });
        drum.addEventListener('touchmove',  e => { e.preventDefault(); onMove(e.touches[0].clientY); }, { passive:false });
        drum.addEventListener('touchend',   () => onEnd());

        drum.addEventListener('click', e => {
            const item = e.target.closest('.tp-drum-item[data-val]');
            if (!item) return;
            snapTo(drum, parseInt(item.dataset.val), true);
        });

        drum.parentElement.addEventListener('wheel', e => {
            e.preventDefault();
            const cur = topToVal(getTop());
            const next = Math.max(0, Math.min(count - 1, cur + (e.deltaY > 0 ? 1 : -1)));
            snapTo(drum, next, true);
        }, { passive: false });
    }

    const drumH = document.getElementById('tpDrumHour');
    const drumM = document.getElementById('tpDrumMin');
    buildDrum(drumH, 24, 2, false);
    buildDrum(drumM, 60, 2, false);
    attachDrag(drumH, 24);
    attachDrag(drumM, 60);

    window.openTimePicker = function (target) {
        if (target === 'masuk') return;

        _target = target;
        document.getElementById('tpTitle').textContent = target === 'masuk' ? 'Pilih Jam Masuk' : 'Pilih Jam Keluar';

        const hidden = document.getElementById(target === 'masuk' ? 'inputJamMasuk' : 'inputJamKeluar');
        let h = 0, m = 0;
        if (hidden.value && hidden.value.includes(':')) {
            const parts = hidden.value.split(':');
            h = parseInt(parts[0]) || 0;
            m = parseInt(parts[1]) || 0;
        } else if (target === 'masuk') {
            const now = new Date();
            h = now.getHours(); m = now.getMinutes();
        }
        h = Math.max(0, Math.min(23, h));
        m = Math.max(0, Math.min(59, m));

        snapTo(drumH, h, false);
        snapTo(drumM, m, false);

        document.getElementById('tpOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    window.closeTimePicker = function () {
        document.getElementById('tpOverlay').classList.remove('open');
        document.body.style.overflow = '';
    };

    window.confirmTimePicker = function () {
        const hStr = String(_selH).padStart(2,'0');
        const mStr = String(_selM).padStart(2,'0');
        const val  = `${hStr}:${mStr}`;

        if (_target === 'masuk') {
            document.getElementById('inputJamMasuk').value    = val;
            const disp = document.getElementById('tpMasukDisplay');
            disp.textContent = val;
            disp.classList.remove('placeholder');
        } else {
            document.getElementById('inputJamKeluar').value   = val;
            const disp = document.getElementById('tpKeluarDisplay');
            disp.textContent = val;
            disp.classList.remove('placeholder');
            const errEl = document.getElementById('errorJamSelesai');
            const triggerEl = document.getElementById('tpKeluarTrigger');
            if (errEl) errEl.style.display = 'none';
            if (triggerEl) { triggerEl.style.outline = ''; triggerEl.style.outlineOffset = ''; }
        }
        closeTimePicker();
    };

    document.getElementById('tpOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeTimePicker();
    });

    document.getElementById('tpKeluarDisplay').classList.add('placeholder');
})();
</script>

<script>
const absensiHariIni = <?php echo json_encode($absensi_hari_map, JSON_UNESCAPED_UNICODE); ?>;
const positionUser   = '<?php echo $position; ?>';
const mengajarSesiTerpakai = <?php echo json_encode(array_values(array_unique($mengajar_sesi_terpakai ?? [])), JSON_UNESCAPED_UNICODE); ?>;

const labelShift = {
    mengajar:'Mengajar', rapat:'Rapat', administratif:'Administratif',
    penelitian:'Penelitian', pengabdian:'Pengabdian', lainnya:'Lainnya'
};
const labelStatus = {
    hadir:'Hadir', izin:'Izin', sakit:'Sakit',
    alfa:'Alfa', cuti:'Cuti', dinas_luar:'Dinas Luar', tugas_luar:'Tugas Luar'
};

function onJenisKegiatanChange(val) {
    const grupSesi = document.getElementById('grupSesiMengajar');
    if (!grupSesi) return;
    if (val === 'mengajar') {
        grupSesi.style.display = '';
        updateSesiPills();
    } else {
        grupSesi.style.display = 'none';
    }

    if (positionUser === 'dosen') {
        const labelText   = document.getElementById('labelKeteranganText');
        const labelWajib  = document.getElementById('labelKeteranganWajib');
        const labelOps    = document.getElementById('labelKeteranganOpsional');
        const textarea    = document.getElementById('inputKeterangan');
        const errEl       = document.getElementById('errorKeterangan');
        const jamWajib    = document.getElementById('jamSelesaiWajib');
        const jamOpsional = document.getElementById('jamSelesaiOpsional');
        const errJam      = document.getElementById('errorJamSelesai');
        const tpKeluar    = document.getElementById('tpKeluarTrigger');

        if (val === 'mengajar') {
            if (labelText)  labelText.textContent = 'Nama Mata Kuliah/Praktikum';
            if (labelWajib) { labelWajib.style.display = 'inline'; }
            if (labelOps)   { labelOps.style.display   = 'none';   }
            if (textarea) {
                textarea.required    = true;
                textarea.placeholder = 'Contoh: Pemrograman Web, Praktikum Basis Data...';
            }
            if (jamWajib)    { jamWajib.style.display    = 'inline'; }
            if (jamOpsional) { jamOpsional.style.display = 'none';   }
        } else {
            if (labelText)  labelText.textContent = 'Keterangan';
            if (labelWajib) { labelWajib.style.display = 'none'; }
            if (labelOps)   {
                if (!labelOps) {
                    const sp = document.createElement('span');
                    sp.id = 'labelKeteranganOpsional';
                    sp.style.cssText = 'font-weight:400;font-style:italic;text-transform:none;';
                    sp.textContent = ' (opsional)';
                    document.getElementById('labelKeterangan').appendChild(sp);
                } else {
                    labelOps.style.display = 'inline';
                }
            }
            if (textarea) {
                textarea.required    = false;
                textarea.placeholder = 'Tuliskan keterangan jika diperlukan...';
            }
            if (errEl) errEl.style.display = 'none';
            if (jamWajib)    { jamWajib.style.display    = 'none';   }
            if (jamOpsional) { jamOpsional.style.display = 'inline'; }
            if (errJam)    { errJam.style.display = 'none'; }
            if (tpKeluar)  { tpKeluar.style.outline = ''; tpKeluar.style.outlineOffset = ''; }
        }
    }
}

function updateSesiPills() {
    const pills = document.querySelectorAll('.sesi-pill');
    let defaultSet = false;
    pills.forEach(pill => {
        const s = parseInt(pill.dataset.sesi);
        if (mengajarSesiTerpakai.includes(s)) {
            pill.classList.add('sudah-terpakai');
            pill.classList.remove('selected');
            pill.disabled = true;
        } else {
            pill.classList.remove('sudah-terpakai');
            pill.disabled = false;
            if (!defaultSet) {
                pill.classList.add('selected');
                document.getElementById('inputSesiMengajar').value = s;
                defaultSet = true;
            } else {
                pill.classList.remove('selected');
            }
        }
    });

    const infoEl = document.getElementById('infoSesiTerpakai');
    const infoText = document.getElementById('infoSesiTerpakaiText');
    if (mengajarSesiTerpakai.length > 0) {
        const labels = mengajarSesiTerpakai.map(s => 'Sesi ' + s).join(', ');
        infoText.textContent = labels + ' sudah tercatat hari ini.';
        infoEl.style.display = 'flex';
    } else {
        infoEl.style.display = 'none';
    }
}

function pilihSesi(el) {
    if (el.classList.contains('sudah-terpakai')) return;
    document.querySelectorAll('.sesi-pill').forEach(p => p.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('inputSesiMengajar').value = el.dataset.sesi;
}

// ── Modifikasi UI & Label Berdasarkan Status (Cuti, Sakit, Dinas) ─────────────
function updateLabelsByStatus(status) {
    const textJamMasuk = document.getElementById('textJamMasuk');
    const textJamKeluar = document.getElementById('textJamKeluar');
    const grupJamKeluar = document.getElementById('grupJamKeluar');
    const grupLamaCuti = document.getElementById('grupLamaCuti');
    const inputLamaCuti = document.getElementById('inputLamaCuti');
    const tpKeluarDisplay = document.getElementById('tpKeluarDisplay');

    // Default setting awal
    if (textJamMasuk) textJamMasuk.textContent = 'Jam Masuk';
    if (textJamKeluar) textJamKeluar.textContent = positionUser === 'dosen' ? 'Jam Selesai' : 'Jam Keluar';
    if (grupJamKeluar) grupJamKeluar.style.display = '';
    if (grupLamaCuti) grupLamaCuti.style.display = 'none';
    if (inputLamaCuti) inputLamaCuti.required = false;

    if (tpKeluarDisplay && (tpKeluarDisplay.textContent === 'Pilih jam keluar' || tpKeluarDisplay.textContent === 'Pilih jam selesai')) {
        tpKeluarDisplay.textContent = positionUser === 'dosen' ? 'Pilih jam selesai' : 'Pilih jam keluar';
    }

    if (status === 'sakit' || status === 'izin') {
        if (textJamMasuk) textJamMasuk.textContent = 'Jam Rekam';
        if (grupJamKeluar) grupJamKeluar.style.display = 'none'; // Sembunyikan Jam Keluar jika sakit/izin
    } else if (status === 'cuti') {
        if (textJamMasuk) textJamMasuk.textContent = 'Jam Rekam';
        if (grupJamKeluar) grupJamKeluar.style.display = 'none';
        if (grupLamaCuti) grupLamaCuti.style.display = ''; // Tampilkan Berapa Hari Cuti
        if (inputLamaCuti) inputLamaCuti.required = true;
    } else if (status === 'dinas_luar' || status === 'tugas_luar') {
        if (textJamMasuk) textJamMasuk.textContent = 'Jam Mulai';
        if (textJamKeluar) textJamKeluar.textContent = 'Jam Selesai';
        if (tpKeluarDisplay && (tpKeluarDisplay.textContent === 'Pilih jam keluar' || tpKeluarDisplay.textContent === 'Pilih jam selesai')) {
            tpKeluarDisplay.textContent = positionUser === 'dosen' ? 'Pilih jam selesai' : 'Pilih jam keluar';
        }
    }
}

(function initSesiMengajar() {
    const sel = document.getElementById('selectJenisKegiatan');
    if (!sel) return;
    onJenisKegiatanChange(sel.value);
})();

(function initKeteranganListener() {
    const ket    = document.getElementById('inputKeterangan');
    const errKet = document.getElementById('errorKeterangan');
    if (!ket) return;
    ket.addEventListener('input', function () {
        if (this.value.trim()) {
            if (errKet) errKet.style.display = 'none';
            this.style.outline = '';
            this.style.outlineOffset = '';
        }
    });
})();

(function () {
    window._absensiHariIni = absensiHariIni;
    window._labelShift     = labelShift;
    window._labelStatus    = labelStatus;
    
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

    const body = document.body;
    const toggle = document.getElementById('darkToggle');
    if (localStorage.getItem('absensiDark') === '1') body.classList.add('dark');
    if (toggle) toggle.addEventListener('click', () => {
        body.classList.toggle('dark');
        localStorage.setItem('absensiDark', body.classList.contains('dark') ? '1' : '0');
    });

    const navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(el => {
        el.addEventListener('click', function () {
            navItems.forEach(n => n.classList.remove('active'));
            this.classList.add('active');
        });
    });

    // Inisialisasi awal layout berdasarkan status bawaan
    const inputStatus = document.getElementById('inputStatus');
    if (inputStatus) updateLabelsByStatus(inputStatus.value);
})();

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

function setSubmitBtn(state) {
    const btn = document.getElementById('btnSubmitAbsensi');
    if (!btn) return;
    if (state === 'loading') {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mendeteksi Lokasi...';
    } else if (state === 'ready') {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Simpan Absensi';
    } else if (state === 'error') {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-location-xmark"></i> Lokasi Tidak Terdeteksi';
    }
}

function requestGPS() {
    const spinner = document.getElementById('gpsSpinner');
    const ok      = document.getElementById('gpsOk');

    if (!navigator.geolocation) {
        showGpsNotif('info', 'Browser Anda tidak mendukung GPS. Silakan isi lokasi secara manual.');
        setSubmitBtn('error');
        return;
    }

    spinner.style.display = 'inline';
    setSubmitBtn('loading');

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
                    setSubmitBtn('ready');
                })
                .catch(() => { ok.style.display = 'inline'; setSubmitBtn('ready'); });
        },
        err => {
            spinner.style.display = 'none';
            const pesan = {
                1: 'Akses lokasi <strong>ditolak</strong>. Aktifkan izin lokasi di pengaturan browser, lalu muat ulang halaman.',
                2: 'Lokasi tidak dapat ditentukan. Pastikan GPS aktif dan perangkat terhubung jaringan.',
                3: 'Permintaan lokasi <strong>habis waktu</strong>. Periksa sinyal GPS dan coba lagi.',
            }[err.code] || 'Gagal mendapatkan lokasi. Isi lokasi secara manual.';
            showGpsNotif(err.code === 1 ? 'warning' : 'error', pesan);
            setSubmitBtn('error');
        },
        { timeout: 10000, maximumAge: 60000 }
    );
}

function openModal() {
    document.getElementById('modalAbsensi').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    document.querySelectorAll('.nav-item')[1].classList.add('active');

    if (!gpsRequested) {
        gpsRequested = true;
        requestGPS();
    }

    setTimeout(function () {
        const cameraWrap = document.getElementById('cameraWrap');
        if (cameraWrap && cameraWrap.classList.contains('show')) {
            if (typeof startCamera === 'function') startCamera();
        }
    }, 350);
}
function closeModal() {
    document.getElementById('modalAbsensi').classList.remove('open');
    document.body.style.overflow = '';
}
document.getElementById('modalAbsensi').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});

document.getElementById('formAbsensi').addEventListener('submit', function (e) {
    e.preventDefault();
    cekDuplikatLaluSubmit();
});

function getShiftDipilih() {
    if (positionUser === 'staff') return 'absensi';
    const sel = document.querySelector('[name="jenis_kegiatan"]');
    if (!sel) return null;
    const val = sel.value;
    if (val === 'mengajar') {
        const sesi = document.getElementById('inputSesiMengajar')?.value || '1';
        return 'mengajar_' + sesi;
    }
    return val;
}

function cekDuplikatLaluSubmit() {
    if (positionUser === 'dosen') {
        const jenisKegiatan = document.querySelector('[name="jenis_kegiatan"]')?.value;
        const jamSelesai = document.getElementById('inputJamKeluar').value;
        const errEl = document.getElementById('errorJamSelesai');
        const triggerEl = document.getElementById('tpKeluarTrigger');
        const grupJamKeluar = document.getElementById('grupJamKeluar');
        const isJamKeluarHidden = grupJamKeluar && grupJamKeluar.style.display === 'none';

        // Hanya validasi jam selesai bila memang form jam selesai tidak disembunyikan
        if (jenisKegiatan === 'mengajar' && !jamSelesai && !isJamKeluarHidden) {
            if (errEl) { errEl.style.display = 'flex'; }
            if (triggerEl) { triggerEl.style.outline = '2px solid var(--red)'; triggerEl.style.outlineOffset = '2px'; }
            document.getElementById('tpKeluarTrigger').scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        } else {
            if (errEl) { errEl.style.display = 'none'; }
            if (triggerEl) { triggerEl.style.outline = ''; triggerEl.style.outlineOffset = ''; }
        }

        if (jenisKegiatan === 'mengajar') {
            const ket    = document.getElementById('inputKeterangan');
            const errKet = document.getElementById('errorKeterangan');
            if (ket && !ket.value.trim()) {
                if (errKet) { errKet.style.display = 'flex'; }
                if (ket) { ket.style.outline = '2px solid var(--red)'; ket.style.outlineOffset = '2px'; }
                ket.scrollIntoView({ behavior: 'smooth', block: 'center' });
                ket.focus();
                return;
            } else {
                if (errKet) { errKet.style.display = 'none'; }
                if (ket) { ket.style.outline = ''; ket.style.outlineOffset = ''; }
            }
        }

        const inputFoto  = document.getElementById('inputFotoEvidence');
        const errFoto    = document.getElementById('errorFotoEvidence');
        const errFotoTxt = document.getElementById('errorFotoEvidenceText');
        const dropBox    = document.getElementById('evidenceDropBox');
        
        const adaFile     = inputFoto && inputFoto.files && inputFoto.files.length > 0;
        const adaBlob     = !!window._capturedBlob;
        if (!adaFile && !adaBlob) {
            if (errFotoTxt) errFotoTxt.textContent = 'Foto evidence wajib disertakan untuk dosen.';
            if (errFoto) { errFoto.style.display = 'flex'; }
            if (dropBox) { dropBox.style.outline = '2px solid var(--red)'; dropBox.style.outlineOffset = '2px'; }
            const cameraWrapEl = document.getElementById('cameraWrap');
            if (cameraWrapEl && cameraWrapEl.classList.contains('show')) {
                cameraWrapEl.style.outline = '2px solid var(--red)';
                cameraWrapEl.style.outlineOffset = '2px';
                cameraWrapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else if (dropBox) {
                dropBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        } else {
            if (errFoto) { errFoto.style.display = 'none'; }
            if (dropBox) { dropBox.style.outline = ''; dropBox.style.outlineOffset = ''; }
            const cameraWrapEl = document.getElementById('cameraWrap');
            if (cameraWrapEl) { cameraWrapEl.style.outline = ''; cameraWrapEl.style.outlineOffset = ''; }
        }
    }

    const shiftDipilih = getShiftDipilih();
    const absensiHariIni = window._absensiHariIni || {};
    const labelStatus    = window._labelStatus    || {};

    const recordAda = absensiHariIni[shiftDipilih];

    if (!shiftDipilih || !recordAda || recordAda.status === 'alfa') {
        submitAbsensi();
        return;
    }

    let shiftLabel;
    if (shiftDipilih && shiftDipilih.startsWith('mengajar_')) {
        const sesi = shiftDipilih.split('_')[1];
        shiftLabel = 'Mengajar Sesi ' + sesi;
    } else {
        shiftLabel = window._labelShift?.[shiftDipilih] ?? shiftDipilih;
    }

    const data = recordAda;
    document.getElementById('popupShift').textContent     = shiftLabel;
    document.getElementById('popupStatus').textContent    = labelStatus[data.status]  ?? data.status;
    document.getElementById('popupJamMasuk').textContent  = data.jam_masuk  || '--:--';
    document.getElementById('popupJamKeluar').textContent = data.jam_keluar || '--:--';
    document.getElementById('popupDuplikat').classList.add('open');
}

function tutupPopupDuplikat() {
    document.getElementById('popupDuplikat').classList.remove('open');
}

function lanjutkanAbsensi() {
    tutupPopupDuplikat();
    submitAbsensi();
}

function submitAbsensi() {
    const btn  = document.getElementById('btnSubmitAbsensi');
    const form = document.getElementById('formAbsensi');
    
    // --- Proses Menambahkan Lama Cuti ke Keterangan ---
    const statusVal = document.getElementById('inputStatus').value;
    const ketEl = document.getElementById('inputKeterangan');
    const cutiEl = document.getElementById('inputLamaCuti');
    
    if (ketEl) {
        if (ketEl.dataset.originalValue === undefined) {
            ketEl.dataset.originalValue = ketEl.value; // simpan input asli user
        }
        if (statusVal === 'cuti' && cutiEl && cutiEl.value) {
            let ori = ketEl.dataset.originalValue.trim();
            let days = cutiEl.value + " Hari";
            ketEl.value = ori ? (days + " - " + ori) : days;
        } else {
            // Restore jika bukan cuti
            ketEl.value = ketEl.dataset.originalValue;
        }
    }
    // ---------------------------------------------------

    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    btn.disabled  = true;

    const blobKamera = window._capturedBlob || null;
    const inp        = document.getElementById('inputFotoEvidence');
    let dtSuccess    = false;

    if (blobKamera && inp) {
        try {
            const dt   = new DataTransfer();
            const file = new File([blobKamera], 'kamera_' + Date.now() + '.jpg', { type: 'image/jpeg' });
            dt.items.add(file);
            inp.files = dt.files;
            if (inp.files.length > 0) dtSuccess = true;
        } catch (e) {
        }
    }

    // Gunakan submit bawaan browser agar flash message tidak terhapus di background
    if (dtSuccess || !blobKamera) {
        form.submit();
        return;
    }

    // Fallback AJAX fetch jika DataTransfer gagal (untuk browser jadul)
    const fd = new FormData(form);

    if (blobKamera && (!inp || !inp.files || inp.files.length === 0)) {
        const filename = 'kamera_' + Date.now() + '.jpg';
        fd.set('foto_evidence', new File([blobKamera], filename, { type: 'image/jpeg' }));
    }

    fetch(form.action || 'proses_absensi.php', {
        method: 'POST',
        body: fd
    }).then(function (res) {
        return res.text();
    }).then(function (html) {
        // Timpa seluruh DOM jika menggunakan fallback fetch agar animasi tetap muncul
        document.open();
        document.write(html);
        document.close();
    }).catch(function () {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Simpan Absensi';
        alert('Gagal mengirim data. Coba lagi.');
    });
}

document.getElementById('popupDuplikat').addEventListener('click', function (e) {
    if (e.target === this) tutupPopupDuplikat();
});

function scrollToTop()      { window.scrollTo({top:0, behavior:'smooth'}); setActive(0); }
function scrollToHistory()  { document.getElementById('historySection').scrollIntoView({behavior:'smooth', block:'start'}); setActive(2); }
function scrollToHelpdesk() { document.getElementById('helpdeskSection').scrollIntoView({behavior:'smooth', block:'start'}); setActive(3); }

function setActive(idx) {
    document.querySelectorAll('.nav-item').forEach((n, i) => n.classList.toggle('active', i === idx));
}

(function() {
    const sidebar   = document.getElementById('sidebar');
    const backdrop  = document.getElementById('sidebarBackdrop');
    const btnToggle = document.getElementById('btnSidebarToggle');
    const btnClose  = document.getElementById('sidebarClose');

    if (!sidebar) return; 

    window.closeSidebar = function() {
        sidebar.classList.remove('open');
        backdrop.classList.remove('open');
    };

    btnToggle && btnToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('open');
    });
    btnClose  && btnClose.addEventListener('click', closeSidebar);
    backdrop  && backdrop.addEventListener('click', closeSidebar);

    const sidebarDark  = document.getElementById('sidebarDarkToggle');
    const sidebarLabel = document.getElementById('sidebarDarkLabel');
    if (sidebarDark) {
        function updateSidebarDarkLabel() {
            if (sidebarLabel) {
                sidebarLabel.textContent = document.body.classList.contains('dark') ? 'Mode Terang' : 'Mode Gelap';
            }
        }
        updateSidebarDarkLabel();
        sidebarDark.addEventListener('click', function() {
            document.body.classList.toggle('dark');
            localStorage.setItem('absensiDark', document.body.classList.contains('dark') ? '1' : '0');
            updateSidebarDarkLabel();
        });
    }
})();

(function () {
    let _stream       = null;   
    let _facingMode   = 'environment'; 
    let _capturedBlob = null;   
    let _activeMethod = 'camera'; 
    let _cameraStarted = false;

    const video       = document.getElementById('cameraVideo');
    const canvas      = document.getElementById('cameraCanvas');
    const cameraWrap  = document.getElementById('cameraWrap');
    const dropBox     = document.getElementById('evidenceDropBox');
    const previewWrap = document.getElementById('evidencePreviewWrap');
    const previewImg  = document.getElementById('evidencePreviewImg');
    const previewName = document.getElementById('evidencePreviewName');
    const cameraHint  = document.getElementById('cameraHint');
    const btnFlip     = document.getElementById('btnFlip');

    if (!video) return;

    window.switchEvidenceMethod = function (method) {
        _activeMethod = method;

        document.getElementById('btnMethodCamera').classList.toggle('active', method === 'camera');
        document.getElementById('btnMethodGallery').classList.toggle('active', method === 'gallery');

        if (previewWrap.classList.contains('show')) {
            hapusEvidence();
            return; 
        }

        if (method === 'camera') {
            dropBox.style.display   = 'none';
            cameraWrap.classList.add('show');
            startCamera();
        } else {
            stopCamera();
            cameraWrap.classList.remove('show');
            dropBox.style.display   = '';
        }
    };

    function startCamera() {
        if (_stream) return; 

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showCameraError('Browser tidak mendukung akses kamera. Gunakan mode galeri.');
            return;
        }

        if (cameraHint) cameraHint.textContent = 'Memulai kamera...';

        const constraints = {
            video: {
                facingMode: { ideal: _facingMode },
                width:  { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (stream) {
                _stream = stream;
                _cameraStarted = true;
                video.srcObject = stream;
                video.play();
                if (cameraHint) cameraHint.textContent = 'Arahkan kamera ke objek';

                navigator.mediaDevices.enumerateDevices().then(function (devices) {
                    const cams = devices.filter(d => d.kind === 'videoinput');
                    if (btnFlip) btnFlip.style.display = cams.length > 1 ? '' : 'none';
                }).catch(function () {
                    if (btnFlip) btnFlip.style.display = 'none';
                });
            })
            .catch(function (err) {
                let msg = 'Gagal membuka kamera.';
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    msg = 'Izin kamera ditolak. Aktifkan akses kamera di pengaturan browser.';
                } else if (err.name === 'NotFoundError') {
                    msg = 'Tidak ada kamera yang ditemukan di perangkat ini.';
                } else if (err.name === 'NotReadableError') {
                    msg = 'Kamera sedang digunakan aplikasi lain.';
                }
                showCameraError(msg);
            });
    }

    function stopCamera() {
        if (_stream) {
            _stream.getTracks().forEach(t => t.stop());
            _stream = null;
        }
        if (video) { video.srcObject = null; }
    }

    window.capturePhoto = function () {
        if (!_stream || !video.videoWidth) {
            if (cameraHint) cameraHint.textContent = 'Kamera belum siap, tunggu sebentar...';
            return;
        }

        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');

        if (_facingMode === 'user') {
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
        }
        ctx.drawImage(video, 0, 0);

        canvas.toBlob(function (blob) {
            if (!blob) return;
            _capturedBlob = blob;
            window._capturedBlob = blob; 
            const filename = 'kamera_' + Date.now() + '.jpg';

            const url = URL.createObjectURL(blob);
            previewImg.src   = url;
            previewName.textContent = filename;
            previewWrap.classList.add('show');

            cameraWrap.classList.remove('show');
            stopCamera();

            injectFileToInput(blob, filename);

            const errEl = document.getElementById('errorFotoEvidence');
            if (errEl) errEl.style.display = 'none';
            const dropBoxEl = document.getElementById('evidenceDropBox');
            if (dropBoxEl) { dropBoxEl.style.outline = ''; dropBoxEl.style.outlineOffset = ''; }

        }, 'image/jpeg', 0.92);
    };

    window.flipCamera = function () {
        _facingMode = (_facingMode === 'environment') ? 'user' : 'environment';
        stopCamera();
        setTimeout(startCamera, 150);
    };

    window.closeCamera = function () {
        stopCamera();
        cameraWrap.classList.remove('show');
        dropBox.style.display = '';
        document.getElementById('btnMethodCamera').classList.remove('active');
        document.getElementById('btnMethodGallery').classList.add('active');
        _activeMethod = 'gallery';
    };

    function injectFileToInput(blob, filename) {
        try {
            const dt   = new DataTransfer();
            const file = new File([blob], filename, { type: 'image/jpeg' });
            dt.items.add(file);

            const inp = document.getElementById('inputFotoEvidence');
            if (inp) {
                inp.files = dt.files;
            }
        } catch (e) {
            console.warn('DataTransfer injection not supported, using FormData override.', e);
        }
    }

    function showCameraError(msg) {
        if (cameraHint) {
            cameraHint.style.cssText = 'background:rgba(220,38,38,.8);white-space:normal;text-align:center;padding:6px 14px;width:90%;';
            cameraHint.textContent = msg;
        }
        setTimeout(function () { closeCamera(); }, 2000);
    }

    window.hapusEvidence = function () {
        _capturedBlob = null;
        window._capturedBlob = null; 

        const inp = document.getElementById('inputFotoEvidence');
        if (inp) inp.value = '';
        previewImg.src = '';
        previewWrap.classList.remove('show');

        const errEl = document.getElementById('errorFotoEvidence');
        if (errEl) errEl.style.display = 'none';

        if (_activeMethod === 'camera') {
            dropBox.style.display = 'none';
            cameraWrap.classList.add('show');
            startCamera();
        } else {
            cameraWrap.classList.remove('show');
            dropBox.style.display = '';
        }
    };

    window.onEvidenceSelected = function (input) {
        const errEl   = document.getElementById('errorFotoEvidence');
        const errText = document.getElementById('errorFotoEvidenceText');
        const box     = document.getElementById('evidenceDropBox');

        if (!input.files || !input.files[0]) return;
        const file = input.files[0];

        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowed.includes(file.type)) {
            if (errText) errText.textContent = 'Format tidak didukung. Gunakan JPG, PNG, atau WEBP.';
            if (errEl)   errEl.style.display = 'flex';
            input.value = '';
            return;
        }
        if (file.size > 20 * 1024 * 1024) {
            if (errText) errText.textContent = 'File terlalu besar. Maksimal 20 MB.';
            if (errEl)   errEl.style.display = 'flex';
            input.value = '';
            return;
        }
        if (errEl) errEl.style.display = 'none';

        const reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            previewName.textContent = file.name;
            if (box) box.style.display = 'none';
            previewWrap.classList.add('show');
        };
        reader.readAsDataURL(file);
    };

    if (dropBox) {
        dropBox.addEventListener('dragover', function (e) { e.preventDefault(); dropBox.classList.add('drag-over'); });
        dropBox.addEventListener('dragleave', function () { dropBox.classList.remove('drag-over'); });
        dropBox.addEventListener('drop', function (e) {
            e.preventDefault();
            dropBox.classList.remove('drag-over');
            const inp = document.getElementById('inputFotoEvidence');
            if (e.dataTransfer.files.length > 0) {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(e.dataTransfer.files[0]);
                    inp.files = dt.files;
                    window.onEvidenceSelected(inp);
                } catch (err) {
                    console.warn('Drag-drop not fully supported.', err);
                }
            }
        });
    }

    const btnAbsensi = document.getElementById('btnAbsensi');
    if (btnAbsensi) {
        btnAbsensi.addEventListener('click', function () {
            setTimeout(function () {
                if (_activeMethod === 'camera' && !_stream && !previewWrap.classList.contains('show')) {
                    startCamera();
                }
            }, 350);
        });
    }

    document.querySelectorAll('.nav-item').forEach(function (el) {
        el.addEventListener('click', function () {
            if (this.querySelector('i.fa-fingerprint')) {
                setTimeout(function () {
                    if (_activeMethod === 'camera' && !_stream && !previewWrap.classList.contains('show')) {
                        startCamera();
                    }
                }, 350);
            }
        });
    });

    const origCloseModal = window.closeModal;
    window.closeModal = function () {
        stopCamera();
        if (origCloseModal) origCloseModal();
    };

    if (document.getElementById('modalAbsensi')?.classList.contains('open')) {
        startCamera();
    }

})();

(function() {
    <?php if ($pw_error): ?>
    var popup = document.getElementById('popupPasswordDemo');
    if (popup) {
        popup.classList.add('open');
        var errEl = popup.querySelector('.gpw-error');
        if (errEl) errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    <?php elseif ($pakai_password_demo): ?>
    window.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            var popup = document.getElementById('popupPasswordDemo');
            if (popup) popup.classList.add('open');
        }, 800);
    });
    <?php endif; ?>
})();

function tutupPopupPasswordDemo() {}

(function() {
    var popup = document.getElementById('popupPasswordDemo');
    if (!popup) return;
    popup.addEventListener('click', function(e) {
        if (e.target === this) {
            var box = popup.querySelector('.popup-box');
            if (box) {
                box.style.animation = 'none';
                box.offsetHeight; 
                box.style.animation = 'gpwShake 0.35s ease';
            }
        }
    });
})();

function updateJamKeluar(absensiId, btn) {
    if (!confirm('Update jam keluar sesuai waktu sekarang?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';

    var formData = new FormData();
    formData.append('_action', 'update_jam_keluar');
    formData.append('absensi_id', absensiId);

    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok) {
            var jamEl = document.getElementById('hijam-' + absensiId);
            if (jamEl) {
                var teks = jamEl.innerText || jamEl.textContent;
                var jamMasuk = teks.split('—')[0].trim();
                jamEl.innerHTML = '<i class="fas fa-clock" style="font-size:10px;color:var(--gray-300);margin-right:3px;"></i>'
                    + jamMasuk + ' \u2014 ' + data.jam_keluar;
            }
            btn.remove();

            var item = document.getElementById('histitem-' + absensiId);
            if (item) {
                var notif = document.createElement('div');
                notif.style.cssText = 'font-size:11px;color:#16a34a;font-weight:700;margin-top:5px;display:flex;align-items:center;gap:4px;';
                notif.innerHTML = '<i class="fas fa-circle-check" style="font-size:10px;"></i> Jam keluar berhasil diperbarui.';
                item.querySelector('.hi-info').appendChild(notif);
                setTimeout(function() { notif.remove(); }, 3000);
            }
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-clock-rotate-left"></i> Update Jam Keluar';
            alert(data.pesan || 'Gagal update. Coba lagi.');
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-clock-rotate-left"></i> Update Jam Keluar';
        alert('Terjadi kesalahan koneksi. Coba lagi.');
    });
}

function toggleGpw(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!inp) return;
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    if (icon) {
        icon.classList.toggle('fa-eye',      !show);
        icon.classList.toggle('fa-eye-slash', show);
    }
}

(function() {
    var inputBaru  = document.getElementById('gpwBaru');
    var inputUlang = document.getElementById('gpwUlang');
    if (!inputBaru) return;

    var bars  = [
        document.getElementById('gpwBar1'),
        document.getElementById('gpwBar2'),
        document.getElementById('gpwBar3'),
        document.getElementById('gpwBar4'),
    ];
    var label    = document.getElementById('gpwStrengthLabel');
    var matchMsg = document.getElementById('gpwMatchMsg');

    function getStrength(pw) {
        var score = 0;
        if (pw.length >= 6)  score++;
        if (pw.length >= 10) score++;
        if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
        if (/\d/.test(pw) || /[^a-zA-Z0-9]/.test(pw)) score++;
        return score; 
    }

    var colors = ['#ef4444','#f97316','#f59e0b','#16a34a'];
    var labels = ['Sangat Lemah','Lemah','Cukup','Kuat'];

    inputBaru.addEventListener('input', function() {
        var pw  = this.value;
        var str = pw.length ? getStrength(pw) : 0;
        bars.forEach(function(b, i) {
            b.style.background = (pw.length && i < str) ? colors[str - 1] : '';
        });
        if (label) {
            label.textContent = pw.length ? labels[str - 1] : '';
            label.style.color = pw.length ? colors[str - 1] : '';
        }
        checkMatch();
    });

    function checkMatch() {
        if (!matchMsg || !inputUlang) return;
        var baru  = inputBaru.value;
        var ulang = inputUlang.value;
        if (!ulang) { matchMsg.style.display = 'none'; return; }
        matchMsg.style.display = 'flex';
        if (baru === ulang) {
            matchMsg.innerHTML = '<i class="fas fa-circle-check" style="color:#16a34a;font-size:10px;"></i> <span style="color:#16a34a;">Password cocok</span>';
        } else {
            matchMsg.innerHTML = '<i class="fas fa-circle-xmark" style="color:#dc2626;font-size:10px;"></i> <span style="color:#dc2626;">Password tidak cocok</span>';
        }
    }

    if (inputUlang) inputUlang.addEventListener('input', checkMatch);

    var form = document.getElementById('formGantiPassword');
    if (form) {
        form.addEventListener('submit', function() {
            var btn = document.getElementById('btnGantiPw');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
            }
        });
    }
})();
</script>
</body>
</html>
