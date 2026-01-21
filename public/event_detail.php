<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: events.php"); exit; }

// evento
$stmt = $conn->prepare("
  SELECT e.*
  FROM events e
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE e.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $id, $u['id']);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) { header("Location: events.php"); exit; }

// gare
$stmt = $conn->prepare("SELECT id,title,location,start_at,discipline,status FROM races WHERE event_id=? ORDER BY start_at ASC, id ASC");
$stmt->bind_param("i", $id);
$stmt->execute();
$races = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = 'Evento: ' . ($event['title'] ?? '');
page_header($pageTitle);
?>

<p>
  <a href="events.php?org_id=<?php echo (int)$event['organization_id']; ?>">← Eventi</a>
  · <a href="race_new.php?event_id=<?php echo (int)$event['id']; ?>">+ Nuova gara</a>
</p>

<p>
  <b>Periodo:</b>
  <?php echo h($event['starts_on'] ?? '-'); ?>
  →
  <?php echo h($event['ends_on'] ?? '-'); ?>
  · <b>Stato:</b> <?php echo h($event['status'] ?? ''); ?>
</p>

<?php if (!empty($event['description'])): ?>
  <p><?php echo nl2br(h($event['description'])); ?></p>
<?php endif; ?>

<p>
  <a href="export_event_report.php?event_id=<?php echo (int)$event['id']; ?>">
    Scarica CSV rendicontazione EVENTO (solo pagati)
  </a>
</p>


<h2 style="margin-top:18px;">Gare / Tappe</h2>

<?php if (!$races): ?>
  <p>Nessuna gara inserita.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr><th>Titolo</th><th>Luogo</th><th>Data/Ora</th><th>Disciplina</th><th>Stato</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($races as $r): ?>
        <tr>
          <td><?php echo h($r['title'] ?? ''); ?></td>
          <td><?php echo h($r['location'] ?? ''); ?></td>
          <td><?php echo h($r['start_at'] ?? ''); ?></td>
          <td><?php echo h($r['discipline'] ?? ''); ?></td>
          <td><?php echo h($r['status'] ?? ''); ?></td>
          <td><a href="race.php?id=<?php echo (int)$r['id']; ?>">Apri</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
