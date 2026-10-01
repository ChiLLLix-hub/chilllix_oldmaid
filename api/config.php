<?php
// api/config.php

if (!function_exists('getConfigValue')) {
    function getConfigValue($key, $default = '')
    {
        $value = getenv($key);
        return ($value !== false && $value !== '') ? $value : $default;
    }
}

$config = [
    'db_host' => getConfigValue('DB_HOST', 'localhost'),
    'db_name' => getConfigValue('DB_NAME'),
    'db_user' => getConfigValue('DB_USER'),
    'db_pass' => getConfigValue('DB_PASS'),
    'realtime_relay_url' => getConfigValue('REALTIME_RELAY_URL'),
    'realtime_relay_secret' => getConfigValue('REALTIME_RELAY_SECRET'),
];

// Check local directory first, then fallback to cPanel private directory outside webroot
$localConfigPaths = [
    __DIR__ . '/config.local.php',
    dirname(__DIR__, 4) . '/config.local.php'
];

foreach ($localConfigPaths as $path) {
    if (is_readable($path)) {
        $fileConfig = require $path;
        if (is_array($fileConfig)) {
            $config = array_merge($config, $fileConfig);
            break;
        }
    }
}

return $config;
