<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();

$u = auth_user();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Dashboard</title>
</head>
<body style="font-family: system-ui; max-width: 720px; margin: 40px auto; padding: 0 16px;">
  <h1>Dashboard</h1>
  <p>Ciao <?php echo htmlspecialchars($u['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8'); ?>)</p>
  <p><a href="logout.php">Logout</a></p>

  <p><a href="organizations.php">Organizzazioni</a></p>
  <p><a href="events.php">Eventi</a></p>



</body>
</html>
