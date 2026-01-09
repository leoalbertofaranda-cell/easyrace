<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();

$u = auth_user();
$conn = db($config);

$org_id = (int)($_GET['org_id'] ?? 0);

// verifica che l’org appartenga all’utente
$stmt = $conn->prepare("SELECT 1 FROM organization_users WHERE organization_id=? AND user_id=? LIMIT 1");
$stmt->bind_param("ii", $org_id, $u['id']);
$stmt->execute();
$ok = (bool)$stmt->get_result()->fetch_row();
$stmt->close();
if (!$ok) { header("Location: events.php"); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim((string)($_POST['title'] ?? ''));
  $desc  = trim((string)($_POST['description'] ?? ''));
  $starts_on = $_POST['starts_on'] ?: null;
  $ends_on   = $_POST['ends_on'] ?: null;
  $status = $_POST['status'] ?? 'draft';

  if ($title === '') $error = "Titolo obbligatorio.";
  else {
    $stmt = $conn->prepare("INSERT INTO events (organization_id,title,description,starts_on,ends_on,status) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isssss", $org_id, $title, $desc, $starts_on, $ends_on, $status);
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    header("Location: event_detail.php?id=".$newId);
    exit;
  }
}
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EasyRace - Nuovo evento</title></head>
<body style="font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px;">
  <h1>Nuovo evento</h1>
  <p><a href="events.php?org_id=<?php echo (int)$org_id; ?>">← Torna agli eventi</a></p>

  <?php if ($error): ?>
    <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <label>Titolo *</label><br>
    <input name="title" style="width:100%;padding:10px;margin:6px 0 12px;" required>

    <label>Descrizione</label><br>
    <textarea name="description" style="width:100%;padding:10px;margin:6px 0 12px;" rows="4"></textarea>

    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <div style="flex:1;min-width:180px;">
        <label>Data inizio</label><br>
        <input type="date" name="starts_on" style="width:100%;padding:10px;margin:6px 0 12px;">
      </div>
      <div style="flex:1;min-width:180px;">
        <label>Data fine</label><br>
        <input type="date" name="ends_on" style="width:100%;padding:10px;margin:6px 0 12px;">
      </div>
    </div>

    <label>Stato</label><br>
    <select name="status" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="draft">draft</option>
      <option value="published">published</option>
      <option value="closed">closed</option>
      <option value="archived">archived</option>
    </select>

    <button type="submit" style="padding:10px 14px;">Crea evento</button>
  </form>
</body>
</html>
