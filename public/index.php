<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';

$appName = $config['app_name'] ?? 'EasyRace';
$env = $config['env'] ?? 'local';

echo "<h1>{$appName} avviato</h1>";
echo "<p>Ambiente: {$env}</p>";

try {
  $conn = db($config);
  echo "<p>DB: OK</p>";
} catch (Throwable $e) {
  echo "<p>DB: ERRORE - " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}
