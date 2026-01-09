<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();

$u = auth_user();
$conn = db($config);

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { header("Location: events.php"); exit; }

// verifica evento accessibile
$stmt = $conn->prepare("
  SELECT e.id, e.organization_id
  FROM events e
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE e.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $event_id, $u['id']);
$stmt->execute();
$ev = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$ev) { header("Location: events.php"); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string)($_POST['title'] ?? ''));
  $location = trim((string)($_POST['location'] ?? ''));
  $start_at = $_POST['start_at'] ?: null;
  $discipline = $_POST['discipline'] ?? 'other';
  $status = $_POST['status'] ?? 'draft';

  if ($title === '') $error = "Titolo obbligatorio.";
  else {
    $stmt = $conn->prepare("INSERT INTO races (event_id,title,location,start_at,discipline,status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssss", $event_id, $title, $location, $start_at, $discipline, $status);
    $stmt->execute();
    $stmt->close();
    header("Location: event_detail.php?id=".$event_id);
    exit;
  }
}
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EasyRace - Nuova gara</title></head>
<body style="font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px;">
  <h1>Nuova gara</h1>
  <p><a href="event_detail.php?id=<?php echo (int)$event_id; ?>">← Torna all’evento</a></p>

  <?php if ($error): ?>
    <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <label>Titolo *</label><br>
    <input name="title" style="width:100%;padding:10px;margin:6px 0 12px;" required>

    <label>Luogo</label><br>
    <input name="location" style="width:100%;padding:10px;margin:6px 0 12px;">

    <label>Data/Ora (inizio)</label><br>
    <input type="datetime-local" name="start_at" style="width:100%;padding:10px;margin:6px 0 12px;">

    <label>Disciplina</label><br>
    <select name="discipline" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="cycling">cycling</option>
      <option value="running">running</option>
      <option value="other" selected>other</option>
    </select>

    <label>Stato</label><br>
    <select name="status" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="draft">draft</option>
      <option value="open">open</option>
      <option value="closed">closed</option>
      <option value="archived">archived</option>
    </select>

    <button type="submit" style="padding:10px 14px;">Crea gara</button>
  </form>
</body>
</html>
