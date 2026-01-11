<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

// org dell’utente
$stmt = $conn->prepare("
  SELECT o.id, o.name
  FROM organization_users ou
  JOIN organizations o ON o.id = ou.organization_id
  WHERE ou.user_id = ?
  ORDER BY o.name ASC
");
$stmt->bind_param("i", $u['id']);
$stmt->execute();
$orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$org_id = (int)($_GET['org_id'] ?? 0);
if ($org_id <= 0 && $orgs) $org_id = (int)$orgs[0]['id'];

// sicurezza: org_id deve essere tra quelle dell’utente
$allowed = array_map(fn($r)=> (int)$r['id'], $orgs);
if ($org_id > 0 && !in_array($org_id, $allowed, true)) {
  $org_id = $orgs ? (int)$orgs[0]['id'] : 0;
}

$events = [];
if ($org_id > 0) {
  $stmt = $conn->prepare("SELECT id,title,starts_on,ends_on,status FROM events WHERE organization_id=? ORDER BY id DESC");
  $stmt->bind_param("i", $org_id);
  $stmt->execute();
  $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

page_header('Eventi');
?>

<?php if (!$orgs): ?>
  <p>Prima crea un’organizzazione.</p>
<?php else: ?>

  <form method="get" style="margin: 16px 0;">
    <label><b>Organizzazione</b></label><br>
    <select name="org_id" style="padding:10px;min-width:320px" onchange="this.form.submit()">
      <?php foreach ($orgs as $o): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$o['id'] === $org_id ? 'selected' : ''); ?>>
          <?php echo h($o['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" style="padding:10px 14px;">Vai</button></noscript>
  </form>

  <p><a href="event_new.php?org_id=<?php echo (int)$org_id; ?>">+ Nuovo evento</a></p>

  <?php if (!$events): ?>
    <p>Nessun evento per questa organizzazione.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
      <thead>
        <tr><th>Titolo</th><th>Dal</th><th>Al</th><th>Stato</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td><?php echo h($e['title']); ?></td>
            <td><?php echo h($e['starts_on'] ?? ''); ?></td>
            <td><?php echo h($e['ends_on'] ?? ''); ?></td>
            <td><?php echo h($e['status']); ?></td>
            <td><a href="event_detail.php?id=<?php echo (int)$e['id']; ?>">Apri</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php endif; ?>

<?php page_footer(); ?>
