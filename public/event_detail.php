<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
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
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EasyRace - Evento</title></head>
<body style="font-family:system-ui;max-width:980px;margin:40px auto;padding:0 16px;">
  <h1><?php echo htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
  <p>
    <a href="events.php?org_id=<?php echo (int)$event['organization_id']; ?>">← Eventi</a>
    · <a href="race_new.php?event_id=<?php echo (int)$event['id']; ?>">+ Nuova gara</a>
    · <a href="logout.php">Logout</a>
  </p>

  <p>
    <b>Periodo:</b>
    <?php echo htmlspecialchars($event['starts_on'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
    →
    <?php echo htmlspecialchars($event['ends_on'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
    · <b>Stato:</b> <?php echo htmlspecialchars($event['status'], ENT_QUOTES, 'UTF-8'); ?>
  </p>

  <?php if (!empty($event['description'])): ?>
    <p><?php echo nl2br(htmlspecialchars($event['description'], ENT_QUOTES, 'UTF-8')); ?></p>
  <?php endif; ?>

  <h2>Gare / Tappe</h2>

  <?php if (!$races): ?>
    <p>Nessuna gara inserita.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
      <thead>
        <tr><th>Titolo</th><th>Luogo</th><th>Data/Ora</th><th>Disciplina</th><th>Stato</th></tr>
      </thead>
      <tbody>
        <?php foreach ($races as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['title'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['start_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['discipline'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
