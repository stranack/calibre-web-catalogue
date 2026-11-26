<?php
declare(strict_types=1);
/**
 * The web app manifest: what lets Chrome on Android (and other browsers)
 * install the library as an app with its own icon. Installed, it opens full
 * screen, with no address bar or tabs.
 *
 * It's a PHP page rather than a .json file so the name follows site_title in
 * the settings, and because .htaccess keeps .json files private.
 */
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$site = (string) cfg('site_title', 'Library');
$short = mb_strlen($site) <= 12 ? $site : 'Library';

echo json_encode([
    'id'               => './',
    'name'             => $site,
    'short_name'       => $short,
    'description'      => 'Browse and read the books in ' . $site . '.',
    'start_url'        => './index.php?source=app',
    'scope'            => './',
    'display'          => 'fullscreen',
    'display_override' => ['fullscreen', 'standalone'],
    'background_color' => '#ffffff',
    'theme_color'      => '#1f6a58',
    'icons'            => [
        ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
