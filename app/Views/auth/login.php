<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Sign in · GLC-BLOC</title>
    <?= view('partials/app_head') ?>
    <style>
        body { min-height: 100vh; min-height: 100dvh; background: var(--glc-bg); }

        .login-shell { min-height: 100vh; min-height: 100dvh; display: grid; grid-template-columns: 1fr; }
        @media (min-width: 992px) { .login-shell { grid-template-columns: minmax(420px, 1.05fr) 1fr; } }

        /* Brand panel (large screens) */
        .login-brand {
            display: none;
            position: relative;
            overflow: hidden;
            background: var(--glc-gradient-dark);
            color: #fff;
            padding: 48px 56px;
            flex-direction: column;
            justify-content: space-between;
        }
        @media (min-width: 992px) { .login-brand { display: flex; } }
        .login-brand::before, .login-brand::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            filter: blur(10px);
            opacity: .55;
            pointer-events: none;
        }
        .login-brand::before { width: 420px; height: 420px; right: -140px; top: -120px; background: radial-gradient(circle, rgba(255,10,126,.55), transparent 65%); }
        .login-brand::after { width: 480px; height: 480px; left: -160px; bottom: -200px; background: radial-gradient(circle, rgba(0,194,230,.5), transparent 65%); }
        .login-brand > * { position: relative; z-index: 1; }
        .brand-tile { display: inline-block; }
        /* No backdrop: a soft light edge keeps the navy link visible on the dark panel. */
        .brand-tile img { width: 190px; height: auto; display: block; filter: drop-shadow(0 0 1.5px rgba(255,255,255,.95)) drop-shadow(0 0 12px rgba(255,255,255,.35)); }
        .login-brand h1 { font-size: clamp(1.9rem, 2.6vw, 2.6rem); font-weight: 800; line-height: 1.15; letter-spacing: -.02em; margin: 36px 0 14px; color: #fff; }
        .login-brand .lead { color: rgba(255,255,255,.78); font-size: 1rem; max-width: 460px; }
        .brand-points { list-style: none; padding: 0; margin: 28px 0 0; display: grid; gap: 14px; }
        .brand-points li { display: flex; gap: 12px; align-items: flex-start; color: rgba(255,255,255,.9); font-size: .93rem; }
        .brand-points i { width: 32px; height: 32px; flex-shrink: 0; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: rgba(255,255,255,.12); color: var(--glc-accent-on-dark); }
        .login-brand .foot { color: rgba(255,255,255,.55); font-size: .78rem; }

        /* Form side */
        .login-main { display: flex; align-items: center; justify-content: center; padding: 32px 16px; }
        .login-card { width: 100%; max-width: 420px; }
        .login-mobile-logo { text-align: center; margin-bottom: 20px; }
        .login-mobile-logo img { width: 132px; height: auto; }
        @media (min-width: 992px) { .login-mobile-logo { display: none; } }
        .login-panel { background: #fff; border: 1px solid var(--glc-border); border-radius: 20px; padding: 32px 28px; box-shadow: 0 20px 50px rgba(16,15,60,.08); position: relative; overflow: hidden; }
        .login-panel::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 4px; background: var(--glc-gradient); }
        @media (max-width: 400px) { .login-panel { padding: 26px 18px; } }
        .login-panel h2 { font-size: 1.5rem; font-weight: 800; color: var(--glc-navy); margin: 0 0 4px; letter-spacing: -.01em; }
        .login-panel .sub { color: var(--glc-muted); font-size: .9rem; margin-bottom: 24px; }
        .login-panel .form-label { font-size: .85rem; font-weight: 600; }
        .login-panel .input-group-text { background: #f6f7fc; color: var(--glc-indigo); border-color: var(--bs-border-color); }
        .login-panel .form-control { padding: 11px 14px; font-size: .95rem; }
        .password-toggle { border: 1px solid var(--bs-border-color); border-left: 0; background: #fff; color: var(--glc-muted); }
        .password-toggle:hover, .password-toggle:focus-visible { color: var(--glc-indigo); background: #fff; }
        .password-toggle:focus-visible { outline: 2px solid var(--glc-indigo); outline-offset: -2px; z-index: 5; }
        .btn-login { width: 100%; padding: 12px; font-weight: 700; font-size: 1rem; border-radius: 12px; margin-top: 4px; }
        .login-foot { text-align: center; margin-top: 18px; color: var(--glc-muted); font-size: .75rem; }
    </style>
</head>
<body>

<div class="login-shell">
    <!-- Brand panel -->
    <section class="login-brand" aria-hidden="true">
        <div>
            <span class="brand-tile"><img src="<?= base_url('assets/img/logo-sm.webp') ?>" alt="" width="190" height="73"></span>
            <h1>Guyo &amp; Liding<br>Cooperative</h1>
            <p class="lead">Contributions, loans and dividends for every member, recorded on a tamper-evident ledger.</p>
            <ul class="brand-points">
                <li><i class="fa-solid fa-people-group"></i><span>One place for members and administrators</span></li>
                <li><i class="fa-solid fa-link"></i><span>Every approved transaction is chained and verifiable</span></li>
                <li><i class="fa-solid fa-chart-line"></i><span>Clear balances, schedules and reports on any device</span></li>
            </ul>
        </div>
        <div class="foot">&copy; <?= date('Y') ?> GLC-BLOC Cooperative Management System</div>
    </section>

    <!-- Sign-in form -->
    <main class="login-main">
        <div class="login-card">
            <div class="login-mobile-logo">
                <img src="<?= base_url('assets/img/logo-sm.webp') ?>" alt="GLC-BLOC" width="132" height="51">
            </div>

            <div class="login-panel">
                <h2>Welcome back</h2>
                <p class="sub">Sign in to GLC-BLOC to continue.</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                        <i class="fa-solid fa-circle-exclamation me-2"></i>
                        <?= esc($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success d-flex align-items-center mb-3" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i>
                        <?= esc($success) ?>
                    </div>
                <?php endif; ?>

                <form action="<?= base_url('login') ?>" method="post">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                value="<?= esc($username) ?>"
                                required
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                            >
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-key"></i></span>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                required
                                autocomplete="current-password"
                            >
                            <button type="button" class="btn password-toggle" id="togglePassword" aria-label="Show password" aria-pressed="false">
                                <i class="fa-solid fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-gradient btn-login">
                        <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
                    </button>
                </form>
            </div>

            <div class="login-foot">&copy; <?= date('Y') ?> GLC-BLOC Cooperative Management System. All rights reserved.
                · <a href="<?= base_url('privacy') ?>">Privacy &amp; data retention</a></div>
        </div>
    </main>
</div>

<script>
    // Password show/hide toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    if (togglePassword) {
        togglePassword.addEventListener('click', function () {
            const show = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', show ? 'text' : 'password');
            togglePassword.setAttribute('aria-pressed', show ? 'true' : 'false');
            togglePassword.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggleIcon.classList.toggle('fa-eye', !show);
            toggleIcon.classList.toggle('fa-eye-slash', show);
        });
    }
</script>
</body>
</html>
