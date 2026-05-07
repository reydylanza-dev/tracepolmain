<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: home/index.php");
    exit;
}

require_once "koneksi.php";

$username = $password = "";
$username_err = $password_err = $login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    if (empty(trim($_POST["username"]))) {
        $username_err = "Masukkan username Anda";
    } else {
        $username = trim($_POST["username"]);
    }

    if (empty(trim($_POST["password"]))) {
        $password_err = "Masukkan password Anda";
    } else {
        $password = trim($_POST["password"]);
    }

    if (empty($username_err) && empty($password_err)) {
        $sql = "SELECT id, username, password, position, nik FROM credentials WHERE username = ?";

        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $param_username);
            $param_username = $username;

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    mysqli_stmt_bind_result($stmt, $id, $username, $hashed_password, $position, $nik);
                    if (mysqli_stmt_fetch($stmt)) {
                        if (password_verify($password, $hashed_password)) {
                            $_SESSION["loggedin"]  = true;
                            $_SESSION["id"]        = $id;
                            $_SESSION["username"]  = $username;
                            $_SESSION["position"]  = $position;
                            $_SESSION["nik"]       = $nik;
                            header("location: home/index.php");
                            exit;
                        } else {
                            $login_err = "Username atau password Anda salah.";
                        }
                    }
                } else {
                    $login_err = "Username atau password Anda salah.";
                }
            } else {
                $login_err = "Terjadi kesalahan sistem, silakan coba lagi.";
            }
            mysqli_stmt_close($stmt);
        }
    }
    mysqli_close($link);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Masuk | TRACE Polmain</title>
    <link rel="icon" href="img/ico.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
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
            --gray-800:    #1e293b;
            --error:       #dc2626;
            --error-bg:    #fef2f2;
            --error-border:#fecaca;
            --success:     #16a34a;

            /* Dark mode tokens */
            --dm-bg:       #060f1e;
            --dm-surface:  #0d1f3c;
            --dm-surface2: #112244;
            --dm-border:   rgba(255,255,255,0.08);
            --dm-text:     #e2e8f0;
            --dm-muted:    rgba(255,255,255,0.4);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        html { height: 100%; }

        body {
            font-family: "Plus Jakarta Sans", sans-serif;
            min-height: 100vh;
            background: var(--gray-100);
            display: flex;
            flex-direction: column;
            transition: background 0.35s, color 0.35s;
        }

        /* ─── TOP BAR ─────────────────────────────────── */
        .top-bar {
            background: var(--white);
            border-bottom: 1px solid var(--gray-200);
            padding: 13px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 1px 6px rgba(0,0,0,0.06);
            transition: background 0.35s, border-color 0.35s;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .brand-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-mid) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .brand-icon i {
            color: var(--yellow);
            font-size: 15px;
        }

        .brand-text .t1 {
            font-size: 14px;
            font-weight: 800;
            color: var(--blue);
            line-height: 1.2;
            letter-spacing: -0.2px;
            transition: color 0.35s;
        }

        .brand-text .t2 {
            font-size: 10px;
            font-weight: 500;
            color: var(--gray-400);
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        /* Dark toggle */
        .dark-toggle {
            width: 44px;
            height: 24px;
            background: var(--gray-200);
            border: 1.5px solid var(--gray-300);
            border-radius: 999px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .dark-toggle::after {
            content: '';
            position: absolute;
            top: 2px;
            right: 2px;
            width: 16px;
            height: 16px;
            background: var(--yellow);
            border-radius: 50%;
            transition: all 0.3s;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }

        /* ─── MAIN LAYOUT ─────────────────────────────── */
        .page-body {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 32px 16px 48px;
        }

        /* Card container — full width on mobile, constrained on desktop */
        .login-card {
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        /* ─── HEADER BLOCK ────────────────────────────── */
        .card-header {
            background: linear-gradient(145deg, var(--blue) 0%, var(--blue-mid) 60%, var(--blue-light) 100%);
            border-radius: 20px 20px 0 0;
            padding: 32px 28px 28px;
            position: relative;
            overflow: hidden;
        }

        /* Decorative circles — pure CSS, no images */
        .card-header::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255,215,0,0.10);
        }

        .card-header::after {
            content: '';
            position: absolute;
            bottom: -40px;
            left: -40px;
            width: 130px;
            height: 130px;
            border-radius: 50%;
            background: rgba(255,255,255,0.05);
        }

        .header-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,215,0,0.15);
            border: 1px solid rgba(255,215,0,0.35);
            border-radius: 999px;
            padding: 4px 12px;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .header-badge span {
            font-size: 10px;
            font-weight: 700;
            color: var(--yellow);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .header-badge i {
            font-size: 9px;
            color: var(--yellow);
        }

        .header-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--white);
            line-height: 1.2;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
        }

        .header-title span {
            color: var(--yellow);
        }

        .header-sub {
            font-size: 13px;
            color: rgba(255,255,255,0.65);
            position: relative;
            z-index: 1;
        }

        /* Live clock strip */
        .clock-strip {
            margin-top: 20px;
            background: rgba(0,0,0,0.25);
            border-radius: 12px;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            z-index: 1;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .clock-time {
            font-size: 22px;
            font-weight: 800;
            color: var(--yellow);
            letter-spacing: 1px;
            font-variant-numeric: tabular-nums;
        }

        .clock-date {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
            text-align: right;
            line-height: 1.5;
        }

        /* ─── FORM BLOCK ──────────────────────────────── */
        .card-body {
            background: var(--white);
            border-radius: 0 0 20px 20px;
            padding: 28px 28px 24px;
            box-shadow: 0 12px 40px rgba(0,51,102,0.12);
            transition: background 0.35s;
        }

        /* Yellow accent bar */
        .accent-bar {
            height: 3px;
            background: linear-gradient(90deg, var(--yellow) 0%, var(--yellow-dark) 100%);
            border-radius: 3px;
            margin-bottom: 24px;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            border-radius: 10px;
            padding: 11px 13px;
            margin-bottom: 18px;
            font-size: 12.5px;
            line-height: 1.55;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .alert i { font-size: 13px; flex-shrink: 0; margin-top: 1px; }

        .alert-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error);
        }

        .alert-info {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        /* Form groups */
        .form-group { margin-bottom: 16px; }

        .form-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--gray-600);
            margin-bottom: 7px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            transition: color 0.35s;
        }

        .form-label i { font-size: 10px; color: var(--blue-light); }

        .input-wrap { position: relative; }

        .form-control {
            width: 100%;
            height: 48px;
            padding: 0 46px 0 14px;
            font-size: 15px;
            font-family: "Plus Jakarta Sans", sans-serif;
            color: var(--gray-800);
            background: var(--gray-50);
            border: 1.5px solid var(--gray-200);
            border-radius: 12px;
            outline: none;
            transition: all 0.25s;
            -webkit-appearance: none;
        }

        .form-control::placeholder { color: var(--gray-400); }

        .form-control:focus {
            background: var(--white);
            border-color: var(--blue-mid);
            box-shadow: 0 0 0 3px rgba(0,77,153,0.10);
        }

        .form-control.is-invalid {
            border-color: var(--error);
            background: var(--error-bg);
        }

        .input-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 15px;
            background: none;
            border: none;
            padding: 4px;
            cursor: pointer;
            transition: color 0.2s;
            line-height: 1;
        }

        .input-icon:hover { color: var(--blue); }

        .invalid-feedback {
            color: var(--error);
            font-size: 11.5px;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .invalid-feedback::before { content: '▲'; font-size: 7px; }

        /* Submit */
        .btn-submit {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, var(--blue) 0%, var(--blue-mid) 100%);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            font-family: "Plus Jakarta Sans", sans-serif;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            margin-top: 20px;
            margin-bottom: 14px;
            transition: all 0.3s;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,215,0,0.18), transparent);
            transform: translateX(-100%);
            transition: transform 0.5s;
        }

        .btn-submit:hover::before { transform: translateX(100%); }

        .btn-submit:hover {
            background: linear-gradient(135deg, var(--blue-mid) 0%, var(--blue-light) 100%);
            box-shadow: 0 8px 24px rgba(0,51,102,0.30);
            transform: translateY(-1px);
        }

        .btn-submit:active { transform: translateY(0); box-shadow: none; }

        .btn-submit.loading { pointer-events: none; opacity: 0.75; }

        .btn-submit .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: none;
        }

        .btn-submit.loading .spinner { display: block; }
        .btn-submit.loading .btn-label { display: none; }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Footer */
        .card-footer {
            text-align: center;
            font-size: 11px;
            color: var(--gray-400);
            padding-top: 6px;
            transition: color 0.35s;
        }

        /* ─── DARK MODE ───────────────────────────────── */
        body.dark {
            background: var(--dm-bg);
        }

        body.dark .top-bar {
            background: var(--dm-surface);
            border-color: var(--dm-border);
        }

        body.dark .brand-text .t1 { color: var(--yellow); }

        body.dark .dark-toggle {
            background: #1e3a5f;
            border-color: #2a4a73;
        }

        body.dark .dark-toggle::after {
            right: auto;
            left: 2px;
            background: #c7d2fe;
        }

        body.dark .card-body {
            background: var(--dm-surface);
            box-shadow: 0 12px 40px rgba(0,0,0,0.4);
        }

        body.dark .form-label  { color: var(--dm-muted); }

        body.dark .form-control {
            background: var(--dm-surface2);
            border-color: var(--dm-border);
            color: var(--dm-text);
        }

        body.dark .form-control::placeholder { color: rgba(255,255,255,0.25); }

        body.dark .form-control:focus {
            background: rgba(255,255,255,0.06);
            border-color: var(--yellow);
            box-shadow: 0 0 0 3px rgba(255,215,0,0.10);
        }

        body.dark .card-footer { color: var(--dm-muted); }

        /* ─── RESPONSIVE: DESKTOP TWO-COLUMN ─────────── */
        @media (min-width: 860px) {
            .page-body {
                align-items: center;
                min-height: calc(100vh - 62px);
                padding: 40px 24px;
            }

            .login-card {
                max-width: 900px;
                flex-direction: row;
                border-radius: 20px;
                overflow: hidden;
                box-shadow: 0 24px 80px rgba(0,30,80,0.20);
                gap: 0;
            }

            .card-header {
                width: 380px;
                flex-shrink: 0;
                border-radius: 0;
                padding: 48px 40px;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            .card-header::before {
                top: -80px; right: -80px;
                width: 250px; height: 250px;
            }

            .card-header::after {
                bottom: -60px; left: -60px;
                width: 200px; height: 200px;
            }

            .header-title { font-size: 30px; }

            .card-body {
                flex: 1;
                border-radius: 0;
                padding: 48px 44px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<!-- TOP BAR -->
<header class="top-bar">
    <a href="index.php" class="brand">
        <div class="brand-icon"><i class="fas fa-fingerprint"></i></div>
        <div class="brand-text">
            <div class="t1">TRACE (Time & Record Academic Connection Environment)</div>
            <div class="t2">Politeknik Masamy Internasional</div>
        </div>
    </a>
    <button class="dark-toggle" id="darkToggle" title="Ganti tema" aria-label="Toggle dark mode"></button>
</header>

<!-- MAIN -->
<main class="page-body">
    <div class="login-card">

        <!-- LEFT / HEADER BLOCK -->
        <div class="card-header">
            <div class="header-badge">
                <i class="fas fa-shield-halved"></i>
                <span>Absensi Mandiri</span>
            </div>
            <h1 class="header-title">Selamat<br>Datang <span>Kembali</span></h1>
            <p class="header-sub">Masuk untuk mencatat kehadiran Anda hari ini</p>

            <div class="clock-strip">
                <div class="clock-time" id="clockTime">--:--:--</div>
                <div class="clock-date" id="clockDate">Memuat...</div>
            </div>
        </div>

        <!-- RIGHT / FORM BLOCK -->
        <div class="card-body">
            <div class="accent-bar"></div>

            <?php if (!empty($login_err)): ?>
            <div class="alert alert-error">
                <i class="fas fa-circle-xmark"></i>
                <span><?php echo htmlspecialchars($login_err); ?></span>
            </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <i class="fas fa-circle-info"></i>
                <span>Gunakan username &amp; password yang telah diberikan oleh administrator.</span>
            </div>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="loginForm" novalidate>

                <div class="form-group">
                    <label class="form-label" for="username">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>"
                            value="<?php echo htmlspecialchars($username); ?>"
                            placeholder="Masukkan username Anda"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false">
                        <i class="fas fa-at input-icon" style="pointer-events:none;"></i>
                    </div>
                    <?php if (!empty($username_err)): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($username_err); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="passwordInput">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="input-wrap">
                        <input
                            type="password"
                            id="passwordInput"
                            name="password"
                            class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>"
                            placeholder="Masukkan password Anda"
                            autocomplete="current-password">
                        <button type="button" class="input-icon" id="togglePassword" aria-label="Lihat password">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                    <?php if (!empty($password_err)): ?>
                        <div class="invalid-feedback"><?php echo htmlspecialchars($password_err); ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <div class="spinner"></div>
                    <span class="btn-label">
                        <i class="fas fa-right-to-bracket"></i>&nbsp; Masuk Sekarang
                    </span>
                </button>

                <p class="card-footer">Catners® SecureID 5.0 &nbsp;·&nbsp; © 2026 IT Polmain</p>
            </form>
        </div>

    </div>
</main>

<script>
(function () {

    /* ── Clock ── */
    const days = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];

    function tick() {
        const now = new Date();
        const hh = String(now.getHours()).padStart(2,'0');
        const mm = String(now.getMinutes()).padStart(2,'0');
        const ss = String(now.getSeconds()).padStart(2,'0');
        document.getElementById('clockTime').textContent = `${hh}:${mm}:${ss}`;
        document.getElementById('clockDate').innerHTML =
            `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
    }
    tick();
    setInterval(tick, 1000);

    /* ── Dark mode ── */
    const body = document.body;
    const toggle = document.getElementById('darkToggle');

    if (localStorage.getItem('absensiDark') === '1') body.classList.add('dark');

    toggle.addEventListener('click', function () {
        body.classList.toggle('dark');
        localStorage.setItem('absensiDark', body.classList.contains('dark') ? '1' : '0');
    });

    /* ── Toggle password ── */
    const pwInput = document.getElementById('passwordInput');
    const eyeIcon = document.getElementById('eyeIcon');

    document.getElementById('togglePassword').addEventListener('click', function () {
        const show = pwInput.type === 'password';
        pwInput.type = show ? 'text' : 'password';
        eyeIcon.classList.toggle('fa-eye', !show);
        eyeIcon.classList.toggle('fa-eye-slash', show);
    });

    /* ── Loading state on submit ── */
    document.getElementById('loginForm').addEventListener('submit', function () {
        const btn = document.getElementById('submitBtn');
        btn.classList.add('loading');
    });

})();
</script>
</body>
</html>