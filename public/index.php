cat > public/index.php <<'PHP'
<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';

echo "<h1>" . h(($config['app_name'] ?? 'EasyRace')) . " avviato</h1>";
echo "<p>Ambiente: " . h(($config['env'] ?? 'local')) . "</p>";
PHP
