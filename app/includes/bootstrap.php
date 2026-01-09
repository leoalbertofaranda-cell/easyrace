<?php
// app/includes/bootstrap.php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$ROOT = $config['paths']['root'];

// Errori in locale: ON (in prod li spegneremo)
if (($config['env'] ?? 'local') === 'local') {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
  error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Log file (semplice)
ini_set('log_errors', '1');
@ini_set('error_log', $ROOT . '/storage/logs/php_error.log');

// helper base
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
