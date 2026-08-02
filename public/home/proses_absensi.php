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
$jenis_kegiatan = trim($_POST["jenis_kegiatan"]   ?? "mengajar");
$sesi_mengajar  = (int)($_POST["sesi_mengajar"]   ?? 1); // 1–5, hanya berlaku untuk jenis_kegiatan=mengajar

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

    // Validasi sesi untuk mengajar
    if ($jenis_kegiatan === 'mengajar') {
        if ($sesi_mengajar < 1 || $sesi_mengajar > 5) {
            redirect_back("error", "Nomor sesi tidak valid (1–5).");
        }
        // Prefix keterangan dengan tag sesi agar bisa dibedakan
        $sesi_prefix    = "[Sesi {$sesi_mengajar}]";
        // Gabungkan prefix + keterangan user (jika ada)
        $keterangan = $keterangan !== ''
            ? "{$sesi_prefix} {$keterangan}"
            : $sesi_prefix;
    }

    // ── Upload foto evidence (opsional) ─────────────────────────────────
    $foto_evidence_path = null;
    if (isset($_FILES['foto_evidence']) && $_FILES['foto_evidence']['error'] === UPLOAD_ERR_OK) {
        $file      = $_FILES['foto_evidence'];
        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp'];
        $max_size  = 20 * 1024 * 1024; // 20 MB

        if (!in_array($ext, $allowed)) {
            redirect_back("error", "Format foto tidak didukung. Gunakan JPG, PNG, atau WEBP.");
        }
        if ($file['size'] > $max_size) {
            redirect_back("error", "Ukuran foto terlalu besar. Maksimal 20 MB.");
        }
        // Validasi magic bytes — pastikan benar-benar file gambar
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
            redirect_back("error", "File yang diunggah bukan gambar yang valid.");
        }

        $dir = dirname(__DIR__) . '/uploads/evidence/';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                redirect_back("error", "Gagal membuat folder uploads/evidence/. Periksa izin write pada folder uploads/.");
            }
        }
        // Pastikan folder dapat ditulis sebelum upload
        if (!is_writable($dir)) {
            redirect_back("error", "Folder uploads/evidence/ tidak dapat ditulis. Periksa permission folder (chmod 755 atau 775).");
        }
        $filename = 'evidence_'
            . preg_replace('/[^a-zA-Z0-9]/', '_', $nik_pegawai) . '_'
            . date('Ymd_His') . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
            $foto_evidence_path = 'uploads/evidence/' . $filename;
        } else {
            // Log detail error untuk debugging
            error_log("[proses_absensi] move_uploaded_file gagal: tmp={$file['tmp_name']}, dest={$dir}{$filename}, is_uploaded=" . (is_uploaded_file($file['tmp_name']) ? 'ya' : 'tidak'));
            redirect_back("error", "Gagal menyimpan foto evidence. Periksa izin folder uploads/evidence/ di server.");
        }
    }

    // ── Cek duplikat ────────────────────────────────────────────────────
    // Untuk mengajar: cek per sesi (menggunakan prefix keterangan)
    // Untuk kegiatan lain: cek per (dosen_nik, tanggal, jenis_kegiatan, kelas_id IS NULL)
    if ($jenis_kegiatan === 'mengajar') {
        $sql_cek = "SELECT id, jam_mulai, jam_selesai, metode_absensi FROM absensi_dosen
                    WHERE  dosen_nik      = ?
                      AND  tanggal        = ?
                      AND  jenis_kegiatan = 'mengajar'
                      AND  kelas_id       IS NULL
                      AND  keterangan     LIKE ?
                    LIMIT 1";
        $sesi_like = "[Sesi {$sesi_mengajar}]%";
        $ada = null;
        if ($stmt = mysqli_prepare($link, $sql_cek)) {
            mysqli_stmt_bind_param($stmt, "sss", $nik_pegawai, $tanggal, $sesi_like);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $ada = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
        }
    } else {
        $sql_cek = "SELECT id, jam_mulai, jam_selesai, metode_absensi FROM absensi_dosen
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
    }

    if ($ada) {
        // ── UPDATE: jika record berasal dari auto-alfa sistem, overwrite semua field
        //           jika sudah pernah absen manual, jam_masuk & lokasi tidak ditimpa
        $adalah_alfa_sistem = ($ada['metode_absensi'] === 'sistem');

        if ($adalah_alfa_sistem) {
            // Record alfa otomatis → overwrite penuh
            $sql = "UPDATE absensi_dosen
                    SET    jam_mulai        = ?,
                           jam_selesai      = ?,
                           lokasi           = ?,
                           metode_absensi   = ?,
                           status_kehadiran = ?,
                           keterangan       = ?,
                           foto_evidence    = COALESCE(?, foto_evidence),
                           updated_at       = NOW()
                    WHERE  id = ?";
        } else {
            // Sudah absen manual → jam_masuk & lokasi lama dipertahankan
            $sql = "UPDATE absensi_dosen
                    SET    jam_mulai        = COALESCE(jam_mulai, ?),
                           jam_selesai      = COALESCE(?, jam_selesai),
                           lokasi           = COALESCE(lokasi, ?),
                           metode_absensi   = ?,
                           status_kehadiran = ?,
                           keterangan       = ?,
                           foto_evidence    = COALESCE(?, foto_evidence),
                           updated_at       = NOW()
                    WHERE  id = ?";
        }

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssssi",
                $jam_masuk, $jam_keluar, $lokasi_final,
                $metode, $status, $keterangan,
                $foto_evidence_path, $ada['id']
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil dicatat.");
            } else {
                redirect_back("error", "Gagal menyimpan absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }

    } else {
        // ── INSERT baru ────────────────────────────────────────────────────
        $sql = "INSERT INTO absensi_dosen
                    (dosen_nik, tanggal, jenis_kegiatan, jam_mulai, jam_selesai,
                     lokasi, metode_absensi, status_kehadiran, keterangan, foto_evidence)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ssssssssss",
                $nik_pegawai, $tanggal, $jenis_kegiatan,
                $jam_masuk, $jam_keluar, $lokasi_final,
                $metode, $status, $keterangan, $foto_evidence_path
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil dicatat.");
            } else {
                // Duplicate key — sudah absen via jadwal mungkin
                if (mysqli_errno($link) === 1062) {
                    $pesan_dup = ($jenis_kegiatan === 'mengajar')
                        ? "Absensi mengajar sesi {$sesi_mengajar} pada tanggal tersebut sudah tercatat."
                        : "Absensi untuk kegiatan ini pada tanggal tersebut sudah tercatat.";
                    redirect_back("error", $pesan_dup);
                }
                redirect_back("error", "Gagal menyimpan absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }
    }

} else {
    // ── STAFF ─────────────────────────────────────────────────────────────────

    // Cek apakah sudah ada record untuk (staff_nik, tanggal)
    $sql_cek = "SELECT id, metode_absensi FROM absensi_staff
                WHERE  staff_nik = ?
                  AND  tanggal   = ?
                LIMIT 1";

    $ada = null;
    if ($stmt = mysqli_prepare($link, $sql_cek)) {
        mysqli_stmt_bind_param($stmt, "ss", $nik_pegawai, $tanggal);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ada = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }

    if ($ada) {
        // ── UPDATE: jika record berasal dari auto-alfa sistem, overwrite semua field
        //           jika sudah pernah absen manual, jam_masuk & lokasi tidak ditimpa
        $adalah_alfa_sistem = ($ada['metode_absensi'] === 'sistem');

        if ($adalah_alfa_sistem) {
            // Record alfa otomatis → overwrite penuh
            $sql = "UPDATE absensi_staff
                    SET    jam_masuk        = ?,
                           lokasi_masuk     = ?,
                           jam_keluar       = ?,
                           lokasi_keluar    = ?,
                           metode_absensi   = ?,
                           status_kehadiran = ?,
                           keterangan       = ?,
                           updated_at       = NOW()
                    WHERE  id = ?";
        } else {
            // Sudah absen manual → jam_masuk & lokasi lama dipertahankan
            $sql = "UPDATE absensi_staff
                    SET    jam_masuk        = COALESCE(jam_masuk, ?),
                           lokasi_masuk     = COALESCE(lokasi_masuk, ?),
                           jam_keluar       = COALESCE(?, jam_keluar),
                           lokasi_keluar    = COALESCE(?, lokasi_keluar),
                           metode_absensi   = ?,
                           status_kehadiran = ?,
                           keterangan       = ?,
                           updated_at       = NOW()
                    WHERE  id = ?";
        }

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "sssssssi",
                $jam_masuk, $lokasi_final,
                $jam_keluar, $lokasi_final,
                $metode, $status, $keterangan, $ada['id']
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil dicatat.");
            } else {
                redirect_back("error", "Gagal menyimpan absensi: " . mysqli_error($link));
            }
            mysqli_stmt_close($stmt);
        }

    } else {
        // ── INSERT baru ────────────────────────────────────────────────────
        $sql = "INSERT INTO absensi_staff
                    (staff_nik, tanggal, jam_masuk, jam_keluar,
                     lokasi_masuk, metode_absensi, status_kehadiran, keterangan)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "ssssssss",
                $nik_pegawai, $tanggal,
                $jam_masuk, $jam_keluar, $lokasi_final,
                $metode, $status, $keterangan
            );
            if (mysqli_stmt_execute($stmt)) {
                redirect_back("sukses", "Absensi berhasil dicatat.");
            } else {
                if (mysqli_errno($link) === 1062) {
                    redirect_back("error", "Absensi pada tanggal tersebut sudah tercatat.");
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
