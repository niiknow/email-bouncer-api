<?php

defined('APP_PATH') || define('APP_PATH', realpath(__DIR__ . '/..'));

require APP_PATH . '/vendor/autoload.php';

// Namespaced overrides for network/header functions used by App\Controllers.
// Must be loaded before any controller code runs so PHP resolves to them.
require __DIR__ . '/Support/functions.php';

date_default_timezone_set('UTC');

// Create the F3 singleton before PHPUnit starts tracking error handlers,
// since its constructor installs global error/exception handlers.
$f3 = \Base::instance();
$f3->set('TZ', 'UTC');
$f3->set('HALT', false);
$f3->set('QUIET', false);
$f3->set('CACHE', false);
