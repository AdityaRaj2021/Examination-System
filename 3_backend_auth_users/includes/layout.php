<?php
declare(strict_types=1);
// Small helpers shared by the page templates.

/** <meta csrf> + window.EXAMPRO config for the JavaScript files. */
function page_boot(array $extra = []): string
{
    $api = [];
    foreach (['auth', 'admin', 'examiner', 'student'] as $n) $api[$n] = url("api/$n.php");
    $cfg = ['base' => base_path(), 'api' => $api] + $extra;
    return csrf_meta() . "\n<script>window.EXAMPRO=" . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ";</script>";
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    foreach ([DIR_FE_ADMIN, DIR_FE_STUDENT] as $dir) {   // admin/examiner scripts live in 1_, shared css/js in 2_
        $f = EXAMPRO_ROOT . '/' . $dir . '/assets/' . $path;
        if (is_file($f)) return e(base_path() . '/' . $dir . '/assets/' . $path) . '?v=' . filemtime($f);
    }
    return e(base_path() . '/' . DIR_FE_STUDENT . '/assets/' . $path) . '?v=1';
}
