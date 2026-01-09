<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';

$appName = $config['app_name'] ?? 'EasyRace';
$env = $config['env'] ?? 'local';

echo "<h1>{$appName} avviato</h1>";
echo "<p>Ambiente: {$env}</p>";
