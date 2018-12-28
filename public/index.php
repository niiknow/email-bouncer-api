<?php
require_once 'prepare.php';
$f3 = \Base::instance();
$f3->config(APP_PATH . '.env.ini');

// F3 autoloader for application business code
$f3->set('AUTOLOAD', APP_PATH . '/src/');

// load routes
$f3->config(APP_PATH . '/routes.ini');

// Run app
$f3->run();
