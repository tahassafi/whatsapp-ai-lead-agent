<?php
/** Small shared helpers used across the app. */

/** HTML-escape. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Asset URL with a cache-busting version (file mtime). */
function asset_v(string $path): string
{
    $abs = ROOT_PATH . $path;
    $v = is_file($abs) ? (string)filemtime($abs) : '1';
    return $path . '?v=' . $v;
}

/** Decode a JSON array column ('["a.jpg","b.jpg"]') to a PHP array. */
function json_array(?string $json): array
{
    if ($json === null || $json === '') { return []; }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

/**
 * Normalise a stored car image reference to a site-relative URL.
 * Accepts absolute URLs, /-prefixed paths, or bare filenames stored by the
 * uploader (which live under /assets/optimized/).
 */
function car_image_url(string $img): string
{
    $img = trim($img);
    if ($img === '') { return ''; }
    if (str_starts_with($img, 'http://') || str_starts_with($img, 'https://')) { return $img; }
    if (str_starts_with($img, '/')) { return $img; }
    return '/assets/optimized/' . ltrim($img, '/');
}
