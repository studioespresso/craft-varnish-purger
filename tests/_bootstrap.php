<?php

define('YII_ENV', 'test');
define('YII_DEBUG', true);

// Set path constants
define('CRAFT_BASE_PATH', __DIR__ . '/_craft');
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_VENDOR_PATH', __DIR__ . '/../vendor');

error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', CRAFT_STORAGE_PATH . '/logs/phperrors.log');
ini_set('display_errors', 1);

// Load Composer's autoloader
require_once CRAFT_VENDOR_PATH . '/autoload.php';

// Load dotenv?
if (file_exists(CRAFT_BASE_PATH . '/.env')) {
    if (class_exists(Dotenv\Dotenv::class)) {
        Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();
    }
}

// The suite cleans and reinstalls its database. Hosts like ddev set CRAFT_DB_* env vars, which override
// tests/_craft/config/db.php: drop them so that file decides, otherwise a test run wipes the project database.
const TEST_DB = 'testing';
foreach (array_keys(getenv()) as $name) {
    if (str_starts_with($name, 'CRAFT_DB_')) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}

// Load and run Craft
define('CRAFT_ENVIRONMENT', 'test');
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

if ($app->getConfig()->getDb()->database !== TEST_DB) {
    fwrite(STDERR, sprintf("Refusing to run: tests would use database \"%s\" instead of \"%s\".\n", $app->getConfig()->getDb()->database, TEST_DB));
    exit(1);
}
