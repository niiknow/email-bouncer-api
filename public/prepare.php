<?php
// Define path to application directory
defined('APP_PATH')
|| define('APP_PATH', realpath(dirname(__FILE__) . '/../'));

if (PHP_SAPI === 'cli-server') {
  // To help the built-in PHP dev server, check if the request was actually for
  // something which should probably be served as a static file
  $file = __DIR__ . $_SERVER['REQUEST_URI'];
  if (is_file($file)) {
    return false;
  }
}

$classLoader = require_once APP_PATH . '/vendor/autoload.php';
