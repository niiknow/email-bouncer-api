<?php
require_once 'prepare.php';

function initdb($f3)
{
    $f3->set('CACHE', $f3->get('cache'));

    $cache = \Cache::instance();
    $extra = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

    if ($f3->get('db_password') !== null) {
        // establish database connection
        $f3->set('DB', new \DB\SQL(
            'mysql:host=' . $f3->get('db_host') . ';port=' . $f3->get('db_port') . ';dbname='.$f3->get('db_database'),
            $f3->get('db_username'),
            $f3->get('db_password')
        ));
    } else {
        $extra = ';';
        $dbpath = 'sqlite:' . APP_PATH . $f3->get('db_file');
        $f3->set('DB', new DB\SQL($dbpath));
    }

    // init if cache not found
    if ($f3->get('db_create') && !$cache->exists('tables')) {
        $db  = $f3->get('DB');
        $sql = "CREATE TABLE IF NOT EXISTS `bounces` (
  `email` VARCHAR(191) NOT NULL PRIMARY KEY,
  `count` INT unsigned NOT NULL,
  `payload` TEXT NOT NULL,
  `expired_at` TIMESTAMP NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)" . $extra;

        $db->exec($sql . ' CREATE INDEX [IF NOT EXISTS] bounces_expired_at ON bounces(expired_at);');
        $cache->set('tables', 'true', 600);
    }
}

function boot()
{
    $f3 = \Base::instance();

    // F3 autoloader for application business code
    $f3->set('AUTOLOAD', APP_PATH . '/src/');

    // load env
    $f3->config(APP_PATH . '/.env.ini');

    initdb($f3);

    // Run app
    $f3->run();

    // hide potentially sensitive info
    ini_set('expose_php', 'off');
    if (function_exists('header_remove')) {
        header_remove('X-Powered-By'); // PHP 5.3+
    }
}

boot();
