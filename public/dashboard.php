<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();

$u = auth_user();
$role = (string)($u['role'] ?? '');

page_header('Dashboard');
?>

<p>Ciao <strong><?php echo h($u['full_name'] ?? ''); ?></strong>
  (<?php echo h($role); ?>)
</p>

<?php if (in_array($role, ['superuser','admin','organizer'], true)): ?>
  <ul>
    <li><a href="organizations.php">Organizzazioni</a></li>
    <li><a href="events.php">Eventi</a></li>
  </ul>
<?php else: ?>
  <p>Account atleta: gestione non disponibile.</p>
<?php endif; ?>

<?php
page_footer();
