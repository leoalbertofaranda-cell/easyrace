<?php
// public/event_new.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/audit.php';

require_login();
require_manage();

$u = auth_user();
if (!$u) { header("Location: login.php"); exit; }

$conn = db($config);

$org_id = (int)($_GET['org_id'] ?? 0);
if ($org_id <= 0) { header("Location: events.php"); exit; }

// (opzionale ma consigliato) verifica che l'utente abbia accesso all'organizzazione
// Se hai già una funzione per questo, usa quella. Altrimenti lasciamo così per ora.

$error = '';

$title = '';
$desc = '';
$starts_on = null;
$ends_on = null;
$status = 'draft';
$event_type = 'event';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $title = trim((string)($_POST['title'] ?? ''));
  $desc  = trim((string)($_POST['description'] ?? ''));

  $starts_on = trim((string)($_POST['starts_on'] ?? ''));
  $starts_on = ($starts_on !== '') ? $starts_on : null;

  $ends_on = trim((string)($_POST['ends_on'] ?? ''));
  $ends_on = ($ends_on !== '') ? $ends_on : null;

  $status = (string)($_POST['status'] ?? 'draft');
  if (!in_array($status, ['draft','published','closed','archived'], true)) $status = 'draft';

  $event_type = (string)($_POST['event_type'] ?? 'event');
  if (!in_array($event_type, ['event','championship'], true)) $event_type = 'event';

  if ($title === '') {
    $error = "Titolo obbligatorio.";
  } else {

    $stmt = $conn->prepare("
      INSERT INTO events (organization_id, title, description, starts_on, ends_on, status, event_type)
      VALUES (?,?,?,?,?,?,?)
    ");
    if (!$stmt) {
      $error = "Errore DB (prepare).";
    } else {

      $stmt->bind_param("issssss", $org_id, $title, $desc, $starts_on, $ends_on, $status, $event_type);
      $stmt->execute();
      $newId = (int)$conn->insert_id;
      $stmt->close();

      // audit
      audit_log(
        $conn,
        'EVENT_CREATE',
        'event',
        $newId,
        null,
        [
          'event_id'        => $newId,
          'organization_id' => $org_id,
          'title'           => $title,
          'status'          => $status,
          'event_type'      => $event_type,
        ]
      );

      header("Location: event_detail.php?id=" . $newId);
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Nuovo evento</title>
</head>
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
    <input name="title" value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"
           style="width:100%;padding:10px;margin:6px 0 12px;" required>

    <label>Descrizione</label><br>
    <textarea name="description" style="width:100%;padding:10px;margin:6px 0 12px;" rows="4"><?php
      echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
    ?></textarea>

    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <div style="flex:1;min-width:180px;">
        <label>Data inizio</label><br>
        <input type="date" name="starts_on"
               value="<?php echo htmlspecialchars((string)($starts_on ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               style="width:100%;padding:10px;margin:6px 0 12px;">
      </div>
      <div style="flex:1;min-width:180px;">
        <label>Data fine</label><br>
        <input type="date" name="ends_on"
               value="<?php echo htmlspecialchars((string)($ends_on ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               style="width:100%;padding:10px;margin:6px 0 12px;">
      </div>
    </div>

    <label>Stato</label><br>
    <select name="status" style="width:100%;padding:10px;margin:6px 0 12px;">
      <?php foreach (['draft','published','closed','archived'] as $opt): ?>
        <option value="<?php echo $opt; ?>" <?php echo ($status === $opt) ? 'selected' : ''; ?>>
          <?php echo $opt; ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Tipo manifestazione</label><br>
    <select name="event_type" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="event" <?php echo ($event_type === 'event') ? 'selected' : ''; ?>>
        Manifestazione normale
      </option>
      <option value="championship" <?php echo ($event_type === 'championship') ? 'selected' : ''; ?>>
        Campionato (Trofeo)
      </option>
    </select>

    <button type="submit" style="padding:10px 14px;">Crea evento</button>
  </form>
</body>
</html>
