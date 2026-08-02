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
    <link class="js-favicon" rel="icon" href="img/ico.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class', 
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                    },
                    colors: {
                        pol: {
                            blue: '#003366',
                            mid: '#004d99',
                            light: '#0066cc',
                        },
                        polyellow: {
                            DEFAULT: '#FFD700',
                            dark: '#E6BF00',
                        },
                        dm: {
                            bg: '#060f1e',
                            surface: '#0d1f3c',
                            surface2: '#112244',
                        }
                    },
                    screens: {
                        'desk': '860px', 
                    },
                    animation: {
                        'slide-down': 'slideDown 0.3s ease',
                        'pdw-slide-in': 'pdwSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) both',
                    },
                    keyframes: {
                        slideDown: {
                            from: { opacity: '0', transform: 'translateY(-8px)' },
                            to: { opacity: '1', transform: 'translateY(0)' },
                        },
                        pdwSlideIn: {
                            from: { opacity: '0', transform: 'translateY(30px) scale(0.95)' },
                            to: { opacity: '1', transform: 'translateY(0) scale(1)' },
                        }
                    }
                }
            }
        }
    </script>

    <style type="text/tailwindcss">
        @layer components {
            body.dark { 
                @apply bg-dm-bg; 
            }
            
            #popupDesktopWarning.show { display: flex !important; }
            .btn-submit.loading { pointer-events: none; opacity: 0.75; }
            .btn-submit.loading .spinner { display: block; }
            .btn-submit.loading .btn-label { display: none; }
        }
    </style>
</head>
<body class="min-h-[100dvh] flex flex-col bg-slate-100 font-sans text-slate-800 transition-colors duration-300">

<div id="popupDesktopWarning" role="dialog" aria-modal="true" aria-labelledby="pdwTitle" class="fixed inset-0 z-[99999] hidden items-center justify-center bg-black/75 p-5 backdrop-blur-[6px]">
    <div class="relative w-full max-w-[460px] animate-pdw-slide-in rounded-[24px] bg-white px-9 pb-9 pt-10 text-center shadow-[0_32px_80px_rgba(0,0,0,0.35)]">
        <button class="absolute right-3.5 top-3.5 flex h-8 w-8 cursor-pointer items-center justify-center rounded-full border-none bg-slate-100 text-[14px] text-slate-500 transition-colors duration-200 hover:bg-slate-200 hover:text-slate-900" id="pdwCloseBtn" aria-label="Tutup peringatan">
            <i class="fas fa-xmark"></i>
        </button>
        <div class="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-[#fff3cd] to-[#ffe082] shadow-[0_8px_24px_rgba(255,193,7,0.35)]">
            <i class="fas fa-mobile-screen-button text-[36px] text-amber-500"></i>
        </div>
        <h2 class="mb-2.5 text-[20px] font-extrabold leading-[1.3] text-slate-900" id="pdwTitle">Gunakan Smartphone</h2>
        <p class="mb-7 text-[14px] font-medium leading-[1.7] text-slate-600">
            Halaman login <strong class="text-pol-blue">TRACE</strong> dirancang khusus untuk perangkat <strong class="text-pol-blue">smartphone</strong>.<br>
            Silakan buka halaman ini melalui ponsel Anda untuk melakukan absensi.
        </p>
        <div class="mb-7 flex items-center justify-center gap-[18px]">
            <div class="flex flex-col items-center gap-1.5">
                <div class="flex h-[52px] w-[52px] items-center justify-center rounded-[14px] bg-gradient-to-br from-pol-blue to-pol-light shadow-[0_6px_16px_rgba(0,51,102,0.3)]">
                    <i class="fas fa-desktop text-[22px] text-polyellow"></i>
                </div>
                <span class="text-[11px] font-bold uppercase tracking-[0.5px] text-slate-500">Desktop</span>
            </div>
            <div class="-mt-2.5 text-[20px] text-slate-300"><i class="fas fa-arrow-right"></i></div>
            <div class="flex flex-col items-center gap-1.5">
                <div class="flex h-[52px] w-[52px] items-center justify-center rounded-[14px] bg-gradient-to-br from-pol-blue to-pol-light shadow-[0_6px_16px_rgba(0,51,102,0.3)]">
                    <i class="fas fa-mobile-screen text-[22px] text-polyellow"></i>
                </div>
                <span class="text-[11px] font-bold uppercase tracking-[0.5px] text-slate-500">Smartphone</span>
            </div>
        </div>
        <div class="mb-5 h-px bg-slate-100"></div>
        <p class="text-[12px] font-medium text-slate-400">
            <i class="fas fa-circle-info mr-1 text-amber-500"></i>
            Akses absensi hanya tersedia di perangkat mobile.
        </p>
    </div>
</div>

<script>
(function() {
    function isDesktop() {
        var ua = navigator.userAgent || '';
        var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile|Tablet/i.test(ua);
        var isWideScreen = window.innerWidth >= 1024;
        return !isMobile && isWideScreen;
    }
    function showDesktopWarning() {
        var popup = document.getElementById('popupDesktopWarning');
        if (popup) { popup.classList.add('show'); document.body.style.overflow = 'hidden'; }
    }
    function hideDesktopWarning() {
        var popup = document.getElementById('popupDesktopWarning');
        if (popup) { popup.classList.remove('show'); document.body.style.overflow = ''; }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (isDesktop()) showDesktopWarning();
            var btn = document.getElementById('pdwCloseBtn');
            if (btn) btn.addEventListener('click', hideDesktopWarning);
        });
    } else {
        if (isDesktop()) showDesktopWarning();
        var btn = document.getElementById('pdwCloseBtn');
        if (btn) btn.addEventListener('click', hideDesktopWarning);
    }
    window.addEventListener('resize', function() {
        if (isDesktop()) {
            showDesktopWarning();
        } else {
            hideDesktopWarning();
        }
    });
})();
</script>

<header class="sticky top-0 z-50 flex items-center justify-between border-b border-slate-200 bg-white px-5 py-[10px] desk:py-[13px] shadow-[0_1px_6px_rgba(0,0,0,0.06)] transition-colors duration-300 dark:border-white/10 dark:bg-dm-surface">
    <a href="index.php" class="flex items-center gap-2.5 no-underline">
        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-gradient-to-br from-pol-blue to-pol-mid">
            <i class="fas fa-fingerprint text-[15px] text-polyellow"></i>
        </div>
        <div class="flex flex-col">
            <div class="text-[14px] font-extrabold leading-tight tracking-[-0.2px] text-pol-blue transition-colors duration-300 dark:text-polyellow">TRACE (Time & Record Academic Connection Environment)</div>
            <div class="text-[10px] font-medium uppercase tracking-[0.6px] text-slate-400">Politeknik Masamy Internasional</div>
        </div>
    </a>
    <button class="relative h-6 w-11 shrink-0 cursor-pointer rounded-full border-[1.5px] border-slate-300 bg-slate-200 transition-all duration-300 after:absolute after:right-[2px] after:top-[2px] after:h-4 after:w-4 after:rounded-full after:bg-polyellow after:shadow-[0_1px_4px_rgba(0,0,0,0.2)] after:transition-all after:duration-300 dark:border-[#2a4a73] dark:bg-[#1e3a5f] dark:after:left-[2px] dark:after:right-auto dark:after:bg-indigo-200" id="darkToggle" title="Ganti tema" aria-label="Toggle dark mode"></button>
</header>

<main class="flex flex-1 items-center justify-center px-4 py-3 desk:items-center desk:px-6 desk:py-10">
    <div class="flex w-full max-w-[420px] flex-col desk:max-w-[900px] desk:flex-row desk:overflow-hidden desk:rounded-[20px] desk:shadow-[0_24px_80px_rgba(0,30,80,0.20)]">

        <div class="relative overflow-hidden rounded-t-[20px] bg-gradient-to-br from-pol-blue via-pol-mid to-pol-light p-5 desk:px-7 desk:pb-7 desk:pt-8 before:absolute before:-right-[60px] before:-top-[60px] before:h-[180px] before:w-[180px] before:rounded-full before:bg-polyellow/10 after:absolute after:-bottom-[40px] after:-left-[40px] after:h-[130px] after:w-[130px] after:rounded-full after:bg-white/5 desk:flex desk:w-[380px] desk:shrink-0 desk:flex-col desk:justify-center desk:rounded-none desk:p-10 desk:before:-right-[80px] desk:before:-top-[80px] desk:before:h-[250px] desk:before:w-[250px] desk:after:-bottom-[60px] desk:after:-left-[60px] desk:after:h-[200px] desk:after:w-[200px]">
            <div class="relative z-10 mb-3 desk:mb-4 inline-flex items-center gap-1.5 rounded-full border border-polyellow/35 bg-polyellow/15 px-3 py-1">
                <i class="fas fa-shield-halved text-[9px] text-polyellow"></i>
                <span class="text-[10px] font-bold uppercase tracking-[1.5px] text-polyellow">Absensi Mandiri</span>
            </div>
            <h1 class="relative z-10 mb-1 text-[24px] desk:text-[26px] font-extrabold leading-tight tracking-[-0.5px] text-white desk:mb-1.5 desk:text-[30px]">Selamat<br>Datang <span class="text-polyellow">Kembali</span></h1>
            <p class="relative z-10 text-[12px] desk:text-[13px] text-white/65">Masuk untuk mencatat kehadiran Anda hari ini</p>

            <div class="relative z-10 mt-3 desk:mt-5 flex items-center justify-between rounded-xl border border-white/10 bg-black/25 px-4 py-2 desk:py-2.5">
                <div class="font-variant-numeric text-[20px] desk:text-[22px] font-extrabold tabular-nums tracking-[1px] text-polyellow" id="clockTime">--:--:--</div>
                <div class="text-right text-[10px] desk:text-[11px] leading-relaxed text-white/60" id="clockDate">Memuat...</div>
            </div>
        </div>

        <div class="rounded-b-[20px] bg-white p-5 shadow-[0_12px_40px_rgba(0,51,102,0.12)] transition-colors duration-300 desk:flex desk:flex-1 desk:flex-col desk:justify-center desk:rounded-none desk:px-7 desk:pb-6 desk:pt-7 desk:p-11 desk:shadow-none dark:bg-dm-surface dark:shadow-[0_12px_40px_rgba(0,0,0,0.4)]">
            <div class="mb-4 desk:mb-6 h-[3px] rounded-[3px] bg-gradient-to-r from-polyellow to-polyellow-dark"></div>

            <?php if (!empty($login_err)): ?>
            <div class="mb-3 desk:mb-[18px] flex animate-slide-down items-start gap-[9px] rounded-[10px] border border-red-200 bg-red-50 px-3 py-2 desk:px-[13px] desk:py-[11px] text-[12px] desk:text-[12.5px] leading-relaxed text-red-600">
                <i class="fas fa-circle-xmark mt-[1px] shrink-0 text-[13px]"></i>
                <span><?php echo htmlspecialchars($login_err); ?></span>
            </div>
            <?php endif; ?>

            <div class="mb-3 desk:mb-[18px] flex items-start gap-[9px] rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 desk:px-[13px] desk:py-[11px] text-[12px] desk:text-[12.5px] leading-relaxed text-amber-900">
                <i class="fas fa-circle-info mt-[1px] shrink-0 text-[13px]"></i>
                <span>Gunakan username &amp; password Anda.</span>
            </div>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="loginForm" novalidate>

                <div class="mb-3 desk:mb-4">
                    <label class="mb-1 desk:mb-[7px] flex items-center gap-1.5 text-[11px] desk:text-[11.5px] font-bold uppercase tracking-[0.6px] text-slate-600 transition-colors duration-300 dark:text-white/40" for="username">
                        <i class="fas fa-user text-[10px] text-pol-light"></i> Username
                    </label>
                    <div class="relative">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="h-11 desk:h-12 w-full appearance-none rounded-xl border-[1.5px] border-slate-200 bg-slate-50 pl-[14px] pr-[46px] font-sans text-[14px] desk:text-[15px] text-slate-800 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-pol-mid focus:bg-white focus:shadow-[0_0_0_3px_rgba(0,77,153,0.10)] dark:border-white/10 dark:bg-dm-surface2 dark:text-slate-200 dark:placeholder:text-white/25 dark:focus:border-polyellow dark:focus:bg-white/5 dark:focus:shadow-[0_0_0_3px_rgba(255,215,0,0.10)] <?php echo (!empty($username_err)) ? '!border-red-600 !bg-red-50 focus:!shadow-[0_0_0_3px_rgba(220,38,38,0.10)]' : ''; ?>"
                            value="<?php echo htmlspecialchars($username); ?>"
                            placeholder="Masukkan username Anda"
                            autocomplete="username"
                            autocapitalize="none"
                            spellcheck="false">
                        <i class="fas fa-at absolute right-[14px] top-1/2 -translate-y-1/2 text-[14px] desk:text-[15px] leading-none text-slate-400" style="pointer-events:none;"></i>
                    </div>
                    <?php if (!empty($username_err)): ?>
                        <div class="mt-1 desk:mt-1.5 flex items-center gap-1 text-[11px] desk:text-[11.5px] text-red-600 before:text-[7px] before:content-['▲']"><?php echo htmlspecialchars($username_err); ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3 desk:mb-4">
                    <label class="mb-1 desk:mb-[7px] flex items-center gap-1.5 text-[11px] desk:text-[11.5px] font-bold uppercase tracking-[0.6px] text-slate-600 transition-colors duration-300 dark:text-white/40" for="passwordInput">
                        <i class="fas fa-lock text-[10px] text-pol-light"></i> Password
                    </label>
                    <div class="relative">
                        <input
                            type="password"
                            id="passwordInput"
                            name="password"
                            class="h-11 desk:h-12 w-full appearance-none rounded-xl border-[1.5px] border-slate-200 bg-slate-50 pl-[14px] pr-[46px] font-sans text-[14px] desk:text-[15px] text-slate-800 outline-none transition-all duration-200 placeholder:text-slate-400 focus:border-pol-mid focus:bg-white focus:shadow-[0_0_0_3px_rgba(0,77,153,0.10)] dark:border-white/10 dark:bg-dm-surface2 dark:text-slate-200 dark:placeholder:text-white/25 dark:focus:border-polyellow dark:focus:bg-white/5 dark:focus:shadow-[0_0_0_3px_rgba(255,215,0,0.10)] <?php echo (!empty($password_err)) ? '!border-red-600 !bg-red-50 focus:!shadow-[0_0_0_3px_rgba(220,38,38,0.10)]' : ''; ?>"
                            placeholder="Masukkan password Anda"
                            autocomplete="current-password">
                        <button type="button" class="absolute right-[14px] top-1/2 -translate-y-1/2 cursor-pointer border-none bg-transparent p-1 text-[14px] desk:text-[15px] leading-none text-slate-400 transition-colors duration-200 hover:text-pol-blue" id="togglePassword" aria-label="Lihat password">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                    <?php if (!empty($password_err)): ?>
                        <div class="mt-1 desk:mt-1.5 flex items-center gap-1 text-[11px] desk:text-[11.5px] text-red-600 before:text-[7px] before:content-['▲']"><?php echo htmlspecialchars($password_err); ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn-submit relative mb-2 mt-4 desk:mb-3.5 desk:mt-5 flex h-[46px] desk:h-[50px] w-full cursor-pointer items-center justify-center gap-2.5 overflow-hidden rounded-xl border-none bg-gradient-to-br from-pol-blue to-pol-mid font-sans text-[13px] desk:text-[14px] font-bold uppercase tracking-[0.8px] text-white transition-all duration-300 hover:-translate-y-[1px] hover:bg-gradient-to-br hover:from-pol-mid hover:to-pol-light hover:shadow-[0_8px_24px_rgba(0,51,102,0.30)] active:translate-y-0 active:shadow-none [-webkit-tap-highlight-color:transparent] before:absolute before:inset-0 before:-translate-x-full before:bg-gradient-to-r before:from-transparent before:via-polyellow/20 before:to-transparent before:transition-transform before:duration-500 hover:before:translate-x-full" id="submitBtn">
                    <div class="spinner hidden h-4 w-4 animate-spin rounded-full border-2 border-white/35 border-t-white"></div>
                    <span class="btn-label">
                        <i class="fas fa-right-to-bracket"></i>&nbsp; Masuk Sekarang
                    </span>
                </button>

                <p class="pt-0 desk:pt-1.5 text-center text-[10px] desk:text-[11px] text-slate-400 transition-colors duration-300 dark:text-white/40">Catners® SecureID 5.0 &nbsp;·&nbsp; © 2026 IT Polmain</p>
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

    /* ── Advanced UX & Behavior Enhancements ── */

    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('passwordInput');

    if (!usernameInput.value) {
        usernameInput.focus();
    } else if (!passwordInput.value) {
        passwordInput.focus();
    }

    const inputs = [usernameInput, passwordInput];
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            if (this.classList.contains('!border-red-600')) {
                this.classList.remove('!border-red-600', '!bg-red-50', 'focus:!shadow-[0_0_0_3px_rgba(220,38,38,0.10)]');
                const errorMsg = this.parentElement.nextElementSibling;
                if (errorMsg && errorMsg.classList.contains('text-red-600')) {
                    errorMsg.style.transition = 'opacity 0.3s ease';
                    errorMsg.style.opacity = '0';
                    setTimeout(() => errorMsg.remove(), 300); 
                }
            }
        });
    });

    const hasError = document.querySelector('.border-red-200'); 
    if (hasError && navigator.vibrate) {
        navigator.vibrate([50, 75, 50]); 
    }
    
    document.getElementById('loginForm').addEventListener('submit', function () {
        if (navigator.vibrate) navigator.vibrate(25); 
    });

    passwordInput.addEventListener('keyup', function(e) {
        const eyeBtn = document.getElementById('togglePassword');
        if (e.getModifierState && e.getModifierState('CapsLock')) {
            eyeBtn.classList.remove('text-slate-400');
            eyeBtn.classList.add('text-amber-500');
            eyeBtn.title = "Peringatan: Caps Lock Aktif!";
        } else {
            eyeBtn.classList.remove('text-amber-500');
            eyeBtn.classList.add('text-slate-400');
            eyeBtn.title = "Lihat password";
        }
    });

})();
</script>
</body>
</html>
