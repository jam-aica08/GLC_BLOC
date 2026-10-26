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
    <!-- Font, Bootstrap 5 and Font Awesome are served from public/assets/lib (the same
         files the CDNs serve): one connection instead of three on a phone's first visit. -->
    <link href="<?= base_url('assets/lib/plus-jakarta-sans-5.2.8/fonts.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/lib/bootstrap-5.3.2/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/lib/fontawesome-6.5.0/css/all.min.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/glc.css') ?>?v=<?= $glcVer ?>" rel="stylesheet">
