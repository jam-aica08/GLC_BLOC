<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GLC-BLOC Login</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CDN -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #1a1a2e;
            --bg-card: #16213e;
            --accent: #f0a500;
            --text-light: #e0e0e0;
        }

        body {
            background-color: var(--bg-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .login-logo {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-logo .logo-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--accent), #e09200);
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            box-shadow: 0 8px 24px rgba(240, 165, 0, 0.3);
        }

        .login-logo .logo-icon i {
            font-size: 2rem;
            color: #1a1a2e;
        }

        .login-logo h2 {
            color: var(--accent);
            font-weight: 800;
            font-size: 2rem;
            letter-spacing: 2px;
            margin: 0;
        }

        .login-logo p {
            color: var(--text-light);
            font-size: 0.85rem;
            margin: 6px 0 0;
            opacity: 0.7;
            letter-spacing: 0.5px;
        }

        .login-card {
            background-color: var(--bg-card);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 36px 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
        }

        .login-card h5 {
            color: var(--text-light);
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 24px;
            text-align: center;
        }

        .form-label {
            color: var(--text-light);
            font-size: 0.85rem;
            font-weight: 500;
            opacity: 0.85;
        }

        .form-control {
            background-color: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: var(--text-light);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            background-color: rgba(255, 255, 255, 0.09);
            border-color: var(--accent);
            color: var(--text-light);
            box-shadow: 0 0 0 3px rgba(240, 165, 0, 0.15);
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.25);
        }

        .input-group-text {
            background-color: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: var(--accent);
        }

        .btn-login {
            background: linear-gradient(135deg, var(--accent), #e09200);
            border: none;
            color: #1a1a2e;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 11px;
            border-radius: 8px;
            width: 100%;
            letter-spacing: 0.5px;
            transition: all 0.2s;
            margin-top: 8px;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #e09200, #c07a00);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(240, 165, 0, 0.35);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #f8a4aa;
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .footer-note {
            text-align: center;
            margin-top: 20px;
            color: rgba(255, 255, 255, 0.25);
            font-size: 0.75rem;
        }

        .password-toggle {
            cursor: pointer;
            background-color: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-left: none;
            color: rgba(255, 255, 255, 0.4);
            border-radius: 0 8px 8px 0;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--accent);
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <!-- Logo -->
    <div class="login-logo">
        <div class="logo-icon">
            <i class="fa-solid fa-cube"></i>
        </div>
        <h2>GLC-BLOC</h2>
        <p>Guyo &amp; Liding Cooperative</p>
    </div>

    <!-- Login Card -->
    <div class="login-card">
        <h5><i class="fa-solid fa-lock me-2" style="color: var(--accent);"></i>GLC-BLOC</h5>

        <!-- Flash Error Message -->
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i>
                <?= esc($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center mb-3" role="alert"
                 style="background-color: rgba(25,135,84,0.15); border-color: rgba(25,135,84,0.3); color: #75c997;">
                <i class="fa-solid fa-circle-check me-2"></i>
                <?= esc($success) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
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
                        style="border-right: none;"
                    >
                    <button type="button" class="btn password-toggle" id="togglePassword" tabindex="-1">
                        <i class="fa-solid fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-login">
                <i class="fa-solid fa-right-to-bracket me-2"></i>Sign In
            </button>
        </form>
    </div>

    <div class="footer-note">
        &copy; <?= date('Y') ?> GLC-BLOC Cooperative Management System. All rights reserved.
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Password show/hide toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    if (togglePassword) {
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            toggleIcon.classList.toggle('fa-eye');
            toggleIcon.classList.toggle('fa-eye-slash');
        });
    }
</script>
</body>
</html>
