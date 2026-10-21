<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Page not found · GLC-BLOC</title>
    <!-- Self-contained like production.php: no database, helper or stylesheet needed. -->
    <style>
        :root { --navy: #0d0b5e; --indigo: #2622b0; --muted: #61657f; --bg: #f4f5fb; --border: #e5e7f2; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--navy); font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid var(--border); border-radius: 20px; padding: 36px 28px 30px;
                text-align: center; box-shadow: 0 20px 50px rgba(16, 15, 60, .08); position: relative; overflow: hidden; }
        .card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 4px; background: linear-gradient(100deg, #ff0a7e, #5b1fb0 42%, #1b1890 62%, #00c2e6); }
        img { width: 120px; height: auto; margin-bottom: 18px; }
        .code { font-size: .8rem; font-weight: 700; letter-spacing: .12em; color: var(--muted); margin: 0 0 6px; }
        h1 { font-size: 1.45rem; margin: 0 0 8px; }
        p { color: var(--muted); line-height: 1.55; margin: 0 0 22px; overflow-wrap: anywhere; }
        a { display: inline-block; padding: 11px 22px; border-radius: 12px; background: var(--indigo); color: #fff; text-decoration: none; font-weight: 600; }
        a:hover, a:focus-visible { background: #1b1890; }
    </style>
</head>
<body>
    <main class="card">
        <img src="/assets/img/logo-sm.png" alt="GLC-BLOC">
        <p class="code">ERROR 404</p>
        <h1>Page not found</h1>
        <p>
            <?php if (ENVIRONMENT !== 'production') : ?>
                <?= nl2br(esc($message)) ?>
            <?php else : ?>
                The page you were looking for does not exist or has moved. Check the address, or go back to the start.
            <?php endif; ?>
        </p>
        <a href="/">Back to sign in</a>
    </main>
</body>
</html>
