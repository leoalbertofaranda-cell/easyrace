<?php
// public/event_public.php (PUBBLICO)
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

$conn = db($config);

function it_date(?string $d): string {
  if (!$d) return '-';
  $ts = strtotime($d);
  if (!$ts) return $d;
  return date('d/m/Y', $ts);
}

function it_datetime(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if (!$ts) return $dt;
  return date('d/m/Y H:i', $ts);
}

function it_race_status(string $s): string {
  return match ($s) {
    'open'   => 'Iscrizioni aperte',
    'closed' => 'Iscrizioni chiuse',
    default  => $s,
  };
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: calendar.php"); exit; }

// evento + org (solo published)
$stmt = $conn->prepare("
  SELECT e.*, o.name AS org_name
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  WHERE e.id=? AND e.status='published'
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
  header("HTTP/1.1 404 Not Found");
  exit("Evento non trovato (o non pubblicato).");
}

// gare
$stmt = $conn->prepare("
  SELECT id,title,location,start_at,discipline,status
  FROM races
  WHERE event_id=?
  ORDER BY
    CASE WHEN start_at IS NULL OR start_at='' THEN 1 ELSE 0 END,
    start_at ASC,
    id ASC
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
  <b>Periodo:</b> <?php echo h(it_date($event['starts_on'] ?? null)); ?> → <?php echo h(it_date($event['ends_on'] ?? null)); ?><br>
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
      <tr>
        <th>Titolo</th>
        <th>Luogo</th>
        <th>Data/Ora</th>
        <th>Disciplina</th>
        <th>Iscrizioni</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($races as $r): ?>
        <?php $st = (string)($r['status'] ?? ''); ?>
        <tr>
          <td><?php echo h($r['title'] ?? ''); ?></td>
          <td><?php echo h($r['location'] ?? ''); ?></td>
          <td><?php echo h(it_datetime($r['start_at'] ?? null)); ?></td>
          <td><?php echo h($r['discipline'] ?? ''); ?></td>
          <td><?php echo h(it_race_status($st)); ?></td>
          <td>
            <?php if ($st === 'open'): ?>
              <a href="race_public.php?id=<?php echo (int)$r['id']; ?>">Iscriviti</a>
            <?php else: ?>
              <span>Apri</span>
              · <a href="race_public.php?id=<?php echo (int)$r['id']; ?>">Dettagli</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
