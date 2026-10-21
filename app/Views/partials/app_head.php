<?php
/**
 * Shared <head> assets: fonts, Bootstrap, icons, theme stylesheet and favicons.
 * The stylesheet URL carries a content hash so browsers pick up changes at once.
 */
$glcCss = FCPATH . 'assets/css/glc.css';
$glcVer = is_file($glcCss) ? substr(md5_file($glcCss), 0, 8) : '1';
?>
    <meta name="theme-color" content="#1b1890">
    <link rel="icon" href="<?= base_url('favicon.ico') ?>" sizes="any">
    <link rel="icon" type="image/png" href="<?= base_url('assets/img/favicon-64.png') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('assets/img/apple-touch-icon.png') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CDN -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/glc.css') ?>?v=<?= $glcVer ?>" rel="stylesheet">
