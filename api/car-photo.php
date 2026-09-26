<?php
/**
 * Car photo converter for WhatsApp.
 *
 * WhatsApp image messages only accept JPEG/PNG - optimized car photos are
 * often WebP. This endpoint serves any car image as JPEG, converting WebP on
 * the fly and caching the result, so the WhatsApp bot can send real gallery
 * photos.
 *
 *   /api/car-photo.php?p=/assets/optimized/2026/05/some-car-3.webp
 *
 * Only files inside assets/optimized/ and assets/legacy-uploads/ are served.
 */

require __DIR__ . '/../config.php';

$rel = (string)($_GET['p'] ?? '');
$rel = '/' . ltrim($rel, '/');

/* strictly limit to the two public image folders */
if (!preg_match('#^/assets/(optimized|legacy-uploads)/#', $rel)
    || !preg_match('/\.(webp|jpe?g|png)$/i', $rel)
    || str_contains($rel, '..')) {
    http_response_code(404);
    exit;
}

$abs = realpath(ROOT_PATH . $rel);
$rootOpt = realpath(ROOT_PATH . '/assets/optimized');
$rootLeg = realpath(ROOT_PATH . '/assets/legacy-uploads');
if ($abs === false
    || (($rootOpt === false || !str_starts_with($abs, $rootOpt))
     && ($rootLeg === false || !str_starts_with($abs, $rootLeg)))) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

/* jpeg/png pass straight through */
if ($ext !== 'webp') {
    header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: public, max-age=604800');
    readfile($abs);
    exit;
}

/* webp -> jpeg, cached */
$cacheDir = ROOT_PATH . '/assets/cache/wa-jpg';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
$cache = $cacheDir . '/' . md5($rel . filemtime($abs)) . '.jpg';

if (!is_file($cache)) {
    if (!function_exists('imagecreatefromwebp')) {
        /* GD without webp support - serve the original as a last resort */
        header('Content-Type: image/webp');
        readfile($abs);
        exit;
    }
    $im = @imagecreatefromwebp($abs);
    if (!$im) { http_response_code(500); exit; }
    imagejpeg($im, $cache, 82);
    imagedestroy($im);
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . filesize($cache));
readfile($cache);
