<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

$conn = db($config);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: calendar.php"); exit; }

// evento + org
$stmt = $conn->prepare("
  SELECT e.*, o.name AS org_name
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  WHERE e.id=?
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
  header("HTTP/1.1 404 Not Found");
  exit("Evento non trovato.");
}

// gare
$stmt = $conn->prepare("
  SELECT id,title,location,start_at,discipline,status
  FROM races
  WHERE event_id=?
  ORDER BY start_at ASC, id ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$races = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header('Evento: ' . ($event['title'] ?? ''));
?>

<p>
  <a href="calendar.php">← Calendario</a>
</p>

<p>
  <b>Organizzazione:</b> <?php echo h($event['org_name'] ?? ''); ?><br>
  <b>Periodo:</b> <?php echo h($event['starts_on'] ?? '-'); ?> → <?php echo h($event['ends_on'] ?? '-'); ?><br>
  <b>Stato:</b> <?php echo h($event['status'] ?? '-'); ?>
</p>

<?php if (!empty($event['description'])): ?>
  <p><?php echo nl2br(h($event['description'])); ?></p>
<?php endif; ?>

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
          <td><a href="race_public.php?id=<?php echo (int)$r['id']; ?>">Apri</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
