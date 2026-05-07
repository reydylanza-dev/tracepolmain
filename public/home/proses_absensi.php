<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// ── Guard: harus login ────────────────────────────────────────────────────────
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: ../login.php");
    exit;
}

// ── Hanya terima POST ─────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("location: index.php");
    exit;
}

require_once "../koneksi.php";
mysqli_query($link, "SET time_zone = '+07:00'"); // Sync timezone MySQL ke WIB

// ── Helper: redirect balik dengan pesan flash ──────────────────────────────────
function redirect_back(string $tipe, string $pesan): void {
    $_SESSION["flash_tipe"]  = $tipe;   // 'sukses' | 'error'
    $_SESSION["flash_pesan"] = $pesan;
    header("location: index.php");
    exit;
}

// ── Ambil & sanitasi input ───────────────────────────────────────────────────
$position    = $_SESSION["position"] ?? "staff";
$user_id     = $_SESSION["id"]       ?? null;
$nik_pegawai = $_SESSION["nik"]      ?? "";

// ── Fallback: ambil NIK dari DB jika session belum punya nik ────────────────
// (terjadi saat user login sebelum kolom nik ditambahkan ke credentials)
if (empty($nik_pegawai) && $user_id) {
    $r = mysqli_query($link, "SELECT nik FROM credentials WHERE id = " . (int)$user_id . " LIMIT 1");
    if ($r) {
        $row = mysqli_fetch_assoc($r);
        $nik_pegawai = $row['nik'] ?? "";
        if ($nik_pegawai) $_SESSION["nik"] = $nik_pegawai; // simpan ke session
    }
}

$tanggal        = trim($_POST["tanggal"]          ?? "");
$jam_masuk      = trim($_POST["jam_masuk"]        ?? "");
$jam_keluar     = trim($_POST["jam_keluar"]       ?? "");
$lokasi         = trim($_POST["lokasi"]           ?? "");
$koordinat      = trim($_POST["koordinat"]        ?? "");
$status         = trim($_POST["status_kehadiran"] ?? "hadir");
$keterangan     = trim($_POST["keterangan"]       ?? "");
$metode         = trim($_POST["metode_absensi"]   ?? "manual");

// Field khusus per tipe
$shift          = trim($_POST["shift"]            ?? "pagi");
$jenis_kegiatan = trim($_POST["jenis_kegiatan"]   ?? "mengajar");

// ── Validasi dasar ────────────────────────────────────────────────────────────
if (empty($nik_pegawai)) {
    redirect_back("error", "Sesi tidak valid. Silakan login kembali.");
}

if (empty($tanggal) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    redirect_back("error", "Tanggal absensi tidak valid.");
}

// Tidak boleh absen untuk tanggal yang akan datang
if ($tanggal > date('Y-m-d')) {
    redirect_back("error", "Tidak dapat mencatat absensi untuk tanggal yang akan datang.");
}

if (empty($jam_masuk) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam_masuk)) {
    redirect_back("error", "Jam masuk tidak valid.");
}

// Normalisasi jam ke format HH:MM:SS
$jam_masuk  = date('H:i:s', strtotime($jam_masuk));
$jam_keluar = (!empty($jam_keluar) && preg_match('/^\d{2}:\d{2}/', $jam_keluar))
              ? date('H:i:s', strtotime($jam_keluar))
              : null;

// Validasi jam keluar tidak sebelum jam masuk (jika diisi)
if ($jam_keluar && $jam_keluar <= $jam_masuk) {
    redirect_back("error", "Jam keluar tidak boleh sama atau sebelum jam masuk.");
}

// Validasi enum status
$status_staff = ['hadir','izin','sakit','alfa','cuti','dinas_luar'];
$status_dosen = ['hadir','izin','sakit','alfa','cuti','tugas_luar'];
$status_valid = ($position === 'dosen') ? $status_dosen : $status_staff;
if (!in_array($status, $status_valid)) {
    redirect_back("error", "Status kehadiran tidak valid.");
}

// Gabungkan lokasi + koordinat jika ada
$lokasi_final = $lokasi;
if (!empty($koordinat)) {
    $lokasi_final = $lokasi ? "{$lokasi} [{$koordinat}]" : $koordinat;
}

// ── Proses berdasarkan tipe pegawai ──────────────────────────────────────────
if ($position === 'dosen') {

    // Validasi enum jenis_kegiatan
    $kegiatan_valid = ['mengajar','rapat','administratif','penelitian','pengabdian','lainnya'];
    if (!in_array($jenis_kegiatan, $kegiatan_valid)) {
        redirect_back("error", "Jenis kegiatan tidak valid.");
    }

    // Cek apakah sudah ada record untuk kombinasi (dosen_nik, tanggal, jenis_kegiatan)
    // kelas_id = NULL untuk absensi mandiri (bukan dari jadwal)
    $sql_cek = "SELECT id, jam_masuk, jam_keluar FROM absensi_dosen
                WHERE  dosen_nik     = ?
                  AND  tanggal       = ?
                  AND  jenis_kegiatan = ?
                  AND  kelas_id      IS NULL
                LIMIT 1";

    $ada = null;
    if ($stmt = mysqli_prepare($link, $sql_cek)) {
        mysqli_stmt_bind_param($stmt, "sss", $nik_pegawai, $tanggal, $jenis_kegiatan);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ada = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }

    if ($ada) {
        // ── UPDATE: perbarui jam keluar / status / keterangan ──────────────
        $sql = "UPDATE absensi_dosen
                SET    jam_selesai      = COALESCE(?, jam_selesai),
                       lokasi           = ?,
                       metode_absensi   = ?,
                       status_kehadiran = ?,
                       keterangan       = ?,
                       updated_at       = NOW()
                WHERE  id = ?";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssi",
                $jam_keluar, $lokasi_final, $metode, $status, $keterangan, $ada['id']
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil diperbarui.");
            } else {
                redirect_back("error", "Gagal memperbarui absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }

    } else {
        // ── INSERT baru ────────────────────────────────────────────────────
        $sql = "INSERT INTO absensi_dosen
                    (dosen_nik, tanggal, jenis_kegiatan, jam_mulai, jam_selesai,
                     lokasi, metode_absensi, status_kehadiran, keterangan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssssss",
                $nik_pegawai, $tanggal, $jenis_kegiatan,
                $jam_masuk, $jam_keluar, $lokasi_final,
                $metode, $status, $keterangan
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil dicatat.");
            } else {
                // Duplicate key — sudah absen via jadwal mungkin
                if (mysqli_errno($link) === 1062) {
                    redirect_back("error", "Absensi untuk kegiatan ini pada tanggal tersebut sudah tercatat.");
                }
                redirect_back("error", "Gagal menyimpan absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }
    }

} else {
    // ── STAFF ─────────────────────────────────────────────────────────────────

    // Validasi enum shift
    $shift_valid = ['pagi','siang','malam','full'];
    if (!in_array($shift, $shift_valid)) {
        redirect_back("error", "Shift tidak valid.");
    }

    // Cek apakah sudah ada record untuk (staff_nik, tanggal, shift)
    $sql_cek = "SELECT id FROM absensi_staff
                WHERE  staff_nik = ?
                  AND  tanggal   = ?
                  AND  shift     = ?
                LIMIT 1";

    $ada = null;
    if ($stmt = mysqli_prepare($link, $sql_cek)) {
        mysqli_stmt_bind_param($stmt, "sss", $nik_pegawai, $tanggal, $shift);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ada = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }

    if ($ada) {
        // ── UPDATE ────────────────────────────────────────────────────────
        $sql = "UPDATE absensi_staff
                SET    jam_keluar       = COALESCE(?, jam_keluar),
                       lokasi_keluar    = COALESCE(?, lokasi_keluar),
                       metode_absensi   = ?,
                       status_kehadiran = ?,
                       keterangan       = ?,
                       updated_at       = NOW()
                WHERE  id = ?";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssi",
                $jam_keluar, $lokasi_final, $metode, $status, $keterangan, $ada['id']
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil diperbarui.");
            } else {
                redirect_back("error", "Gagal memperbarui absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }

    } else {
        // ── INSERT baru ────────────────────────────────────────────────────
        $sql = "INSERT INTO absensi_staff
                    (staff_nik, tanggal, shift, jam_masuk, jam_keluar,
                     lokasi_masuk, metode_absensi, status_kehadiran, keterangan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssssss",
                $nik_pegawai, $tanggal, $shift,
                $jam_masuk, $jam_keluar, $lokasi_final,
                $metode, $status, $keterangan
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil dicatat.");
            } else {
                if (mysqli_errno($link) === 1062) {
                    redirect_back("error", "Absensi shift ini pada tanggal tersebut sudah tercatat.");
                }
                redirect_back("error", "Gagal menyimpan absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }
    }
}

mysqli_close($link);

// Fallback — seharusnya tidak sampai sini
redirect_back("error", "Terjadi kesalahan tak terduga.");