<?php
// public/events.php (MANAGE)
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

function it_date(?string $d): string {
  if (!$d) return '-';
  $ts = strtotime($d);
  if (!$ts) return $d;
  return date('d/m/Y', $ts);
}

function it_event_status(string $s): string {
  return match ($s) {
    'draft'     => 'Bozza',
    'published' => 'Pubblicato',
    'archived'  => 'Archiviato',
    default     => $s,
  };
}

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
$allowed = array_map(static fn($r)=> (int)$r['id'], $orgs);
if ($org_id > 0 && !in_array($org_id, $allowed, true)) {
  $org_id = $orgs ? (int)$orgs[0]['id'] : 0;
}

$events = [];
if ($org_id > 0) {
  // Ordinamento: più recenti in alto (starts_on desc, fallback id desc)
  $stmt = $conn->prepare("
    SELECT id, title, starts_on, ends_on, status
    FROM events
    WHERE organization_id=?
    ORDER BY
      CASE WHEN starts_on IS NULL OR starts_on='' THEN 1 ELSE 0 END,
      starts_on DESC,
      id DESC
  ");
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

  <p>
    <a href="event_new.php?org_id=<?php echo (int)$org_id; ?>">+ Nuovo evento</a>
  </p>

  <?php if (!$events): ?>
    <p>Nessun evento per questa organizzazione.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
      <thead>
        <tr>
          <th>Titolo</th>
          <th>Dal</th>
          <th>Al</th>
          <th>Stato</th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td><?php echo h((string)($e['title'] ?? '')); ?></td>
            <td><?php echo h(it_date($e['starts_on'] ?? null)); ?></td>
            <td><?php echo h(it_date($e['ends_on'] ?? null)); ?></td>
            <td><?php echo h(it_event_status((string)($e['status'] ?? ''))); ?></td>
            <td>
              <a href="event_detail.php?id=<?php echo (int)$e['id']; ?>">Apri</a>
              <?php if (($e['status'] ?? '') === 'published'): ?>
                · <a href="event_public.php?id=<?php echo (int)$e['id']; ?>" target="_blank" rel="noopener">Pubblico</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php endif; ?>

<?php page_footer(); ?>
