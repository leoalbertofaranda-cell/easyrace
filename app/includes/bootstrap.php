<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

// Errori ON in locale
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// helper base
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
require_once __DIR__ . '/db.php';
