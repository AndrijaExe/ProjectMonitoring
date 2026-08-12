<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

if (($_SERVER['APP_ENV'] ?? '') === 'test') {
    $testDb = dirname(__DIR__).'/var/data/monitoring-test.json';
    foreach ([$testDb, $testDb.'.tmp'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
