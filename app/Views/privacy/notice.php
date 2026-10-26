<?php if ($layout !== null): ?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= view('privacy/body', ['schedule' => $schedule, 'config' => $config]) ?>
<?= $this->endSection() ?>
<?php else: ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Privacy &amp; Data Retention · GLC-BLOC</title>
    <?= view('partials/app_head') ?>
    <style>body { background: var(--glc-bg); }</style>
</head>
<body>
<main class="container py-4" style="max-width: 1100px;">
    <a href="<?= base_url('login') ?>" class="small d-inline-block mb-3"><i class="fa-solid fa-arrow-left me-1"></i>Back to sign in</a>
    <?= view('privacy/body', ['schedule' => $schedule, 'config' => $config]) ?>
</main>
</body>
</html>
<?php endif; ?>
