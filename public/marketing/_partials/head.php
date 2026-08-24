<?php
/**
 * Shared document head.
 * Expects: $page_title, $page_description, optionally $page_key (nav highlight).
 */
$page_title       = $page_title       ?? APP_NAME;
$page_description = $page_description ?? 'A secure, timed online examination platform for universities and colleges.';
$page_key         = $page_key         ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title) ?></title>
<meta name="description" content="<?= e($page_description) ?>">
<meta name="theme-color" content="#131A2E">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($page_title) ?>">
<meta property="og:description" content="<?= e($page_description) ?>">
<meta property="og:image" content="<?= e(asset('img/exam-hall-1600.jpg')) ?>">

<link rel="preload" href="<?= e(asset('fonts/fraunces-400-700.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?= e(asset('fonts/plexsans-400-700.woff2')) ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/marketing.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
