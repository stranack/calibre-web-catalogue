<?php
/*
 * Calibre Web Catalogue: settings
 *
 * Copy this file to config.php (in the same app/ folder) and fill in the values.
 * config.php is never served to visitors: the app/ folder is blocked by .htaccess.
 */
return [

    // Shown in the header and the browser tab.
    'site_title' => 'My Library',

    // Password for admin.php (connecting Dropbox, running a sync by hand).
    // Use something long. Anyone with this password can trigger syncs.
    'admin_password' => 'change-me-to-something-long',

    // Dropbox app credentials, from https://www.dropbox.com/developers/apps
    // (the "App key" and "App secret" on your app's Settings tab).
    'dropbox_app_key'    => '',
    'dropbox_app_secret' => '',

    // Where the Calibre library sits inside your Dropbox, starting with a slash.
    // Leave out your computer's own folders: if the library is at
    // ~/Dropbox/Books/Calibre Library on your computer, use '/Books/Calibre Library'.
    'dropbox_library_path' => '/Calibre Library',

    // Links on each book page that open its files in your Dropbox (only
    // someone signed in to that Dropbox account can open them).
    //   'owner'    shown only on browsers where you've signed in to admin.php
    //   'everyone' shown to every visitor
    //   'off'      not shown
    'dropbox_links' => 'owner',

    // Secret used by cron.php?key=... if you trigger syncs from a web cron
    // service instead of a cPanel cron job. Leave empty to switch cron.php off.
    'cron_key' => '',

    // How many seconds one cron-job sync may spend fetching covers before it
    // stops and picks up again on the next run. The first sync of ~1,200 covers
    // usually needs a few minutes in total.
    'cron_time_budget' => 300,

    // false adds a "noindex" tag so Google and other search engines leave the
    // site out of their results. People with the link can still browse it.
    'allow_search_engines' => false,

    // Time zone for dates on the admin page and in the sync log.
    // See https://www.php.net/manual/en/timezones.php (e.g. 'America/Toronto').
    'timezone' => 'UTC',

    // Books per page in the grid.
    'per_page' => 48,

    // Advanced: read the library from a folder on this server instead of
    // Dropbox (for example, if you upload the Calibre folder yourself).
    // Leave empty to use Dropbox.
    'local_library_path' => '',

    // Advanced: keep data (the downloaded catalogue, sync state and Dropbox
    // token) outside the website folder. Leave empty to use books/data/.
    'data_dir' => '',
];
