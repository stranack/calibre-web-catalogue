<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('COVER_DIR', APP_ROOT . '/covers');

mb_internal_encoding('UTF-8');

$GLOBALS['CONFIG'] = [
    'site_title'           => 'My Library',
    'admin_password'       => '',
    'dropbox_app_key'      => '',
    'dropbox_app_secret'   => '',
    'dropbox_library_path' => '/Calibre Library',
    'cron_key'             => '',
    'dropbox_links'        => 'owner',
    'cron_time_budget'     => 300,
    'allow_search_engines' => false,
    'per_page'             => 48,
    'local_library_path'   => '',
    'data_dir'             => '',
    'timezone'             => 'UTC',
];

$configFile = APP_DIR . '/config.php';
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $GLOBALS['CONFIG'] = array_merge($GLOBALS['CONFIG'], $loaded);
    }
    define('CONFIG_LOADED', true);
} else {
    define('CONFIG_LOADED', false);
}

define('DATA_DIR', rtrim($GLOBALS['CONFIG']['data_dir'] ?: APP_ROOT . '/data', '/'));
define('LIBRARY_DB', DATA_DIR . '/library.db');
define('STATE_FILE', DATA_DIR . '/state.json');

date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?: 'UTC');

require APP_DIR . '/helpers.php';
require APP_DIR . '/Catalog.php';
require APP_DIR . '/Sources.php';
require APP_DIR . '/SqliteFileReader.php';
require APP_DIR . '/Sync.php';
