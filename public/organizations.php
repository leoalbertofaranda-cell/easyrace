<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();
require_manage();

$u = auth_user();
$conn = db($config);

$rows = [];
$stmt = $conn->prepare("
  SELECT o.id, o.name, o.city, o.email, o.phone, ou.org_role
  FROM organization_users ou
  JOIN organizations o ON o.id = ou.organization_id
  WHERE ou.user_id = ?
  ORDER BY o.name ASC
");
$stmt->bind_param("i", $u['id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Organizzazioni</title>
</head>
<body style="font-family: system-ui; max-width: 900px; margin: 40px auto; padding: 0 16px;">
  <h1>Organizzazioni</h1>
  <p><a href="dashboard.php">Dashboard</a> · <a href="organization_new.php">+ Nuova organizzazione</a> · <a href="logout.php">Logout</a></p>

  <?php if (!$rows): ?>
    <p>Nessuna organizzazione collegata al tuo account.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width:100%;">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Città</th>
          <th>Email</th>
          <th>Telefono</th>
          <th>Ruolo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['org_role'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
