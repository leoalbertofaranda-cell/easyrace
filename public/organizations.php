<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

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

page_header('Organizzazioni');
?>

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
          <td><?php echo h($r['name'] ?? ''); ?></td>
          <td><?php echo h($r['city'] ?? ''); ?></td>
          <td><?php echo h($r['email'] ?? ''); ?></td>
          <td><?php echo h($r['phone'] ?? ''); ?></td>
          <td><?php echo h($r['org_role'] ?? ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
