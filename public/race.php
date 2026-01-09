<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();

$u = auth_user();
$conn = db($config);

$race_id = (int)($_GET['id'] ?? 0);
if ($race_id <= 0) { header("Location: events.php"); exit; }

// Carico gara + evento + org (accesso chiuso: serve membership su organization_users)
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.organization_id, o.name AS org_name
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE r.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $race_id, $u['id']);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata o accesso non consentito.");
}

$error = '';

// AZIONI ADMIN su iscrizioni (confirm/pending/cancel)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_manage()) {
  $action = $_POST['action'] ?? '';
  $reg_id = (int)($_POST['reg_id'] ?? 0);

  if ($reg_id > 0 && in_array($action, ['confirm','cancel','pending'], true)) {
    $newStatus = ($action === 'confirm') ? 'confirmed' : (($action === 'cancel') ? 'cancelled' : 'pending');

    $stmt = $conn->prepare("UPDATE registrations SET status=? WHERE id=? AND race_id=? LIMIT 1");
    $stmt->bind_param("sii", $newStatus, $reg_id, $race_id);
    $stmt->execute();
    $stmt->close();

    header("Location: race.php?id=".$race_id);
    exit;
  }
}

// Stato iscrizione dell’utente (se atleta)
$myReg = null;
if (is_athlete()) {
  $stmt = $conn->prepare("SELECT id,status,created_at FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
  $stmt->bind_param("ii", $race_id, $u['id']);
  $stmt->execute();
  $myReg = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// Azioni atleta: register / cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_athlete()) {
  $action = $_POST['action'] ?? '';

  if ($action === 'register') {
    try {
      $status = 'pending';
      $stmt = $conn->prepare("INSERT INTO registrations (race_id,user_id,status) VALUES (?,?,?)");
      $stmt->bind_param("iis", $race_id, $u['id'], $status);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    } catch (Throwable $e) {
      $error = "Non riesco a completare l’iscrizione.";
    }
  }

  if ($action === 'cancel') {
    try {
      $status = 'cancelled';
      $stmt = $conn->prepare("UPDATE registrations SET status=? WHERE race_id=? AND user_id=? LIMIT 1");
      $stmt->bind_param("sii", $status, $race_id, $u['id']);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    } catch (Throwable $e) {
      $error = "Non riesco ad annullare l’iscrizione.";
    }
  }
}

// Lista iscritti (solo manage)
$regs = [];
if (can_manage()) {
  $stmt = $conn->prepare("
    SELECT r.id, r.status, r.created_at, u.full_name, u.email
    FROM registrations r
    JOIN users u ON u.id = r.user_id
    WHERE r.race_id=?
    ORDER BY r.created_at ASC
  ");
  $stmt->bind_param("i", $race_id);
  $stmt->execute();
  $regs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Gara</title>
</head>
<body style="font-family:system-ui;max-width:980px;margin:40px auto;padding:0 16px;">
  <h1><?php echo htmlspecialchars($race['title'], ENT_QUOTES, 'UTF-8'); ?></h1>

  <p>
    <a href="event_detail.php?id=<?php echo (int)$race['event_id']; ?>">← Evento</a>
    · <a href="logout.php">Logout</a>
  </p>

  <p>
    <b>Organizzazione:</b> <?php echo htmlspecialchars($race['org_name'], ENT_QUOTES, 'UTF-8'); ?><br>
    <b>Evento:</b> <?php echo htmlspecialchars($race['event_title'], ENT_QUOTES, 'UTF-8'); ?><br>
    <b>Luogo:</b> <?php echo htmlspecialchars($race['location'] ?? '-', ENT_QUOTES, 'UTF-8'); ?><br>
    <b>Data/Ora:</b> <?php echo htmlspecialchars($race['start_at'] ?? '-', ENT_QUOTES, 'UTF-8'); ?><br>
    <b>Disciplina:</b> <?php echo htmlspecialchars($race['discipline'] ?? '-', ENT_QUOTES, 'UTF-8'); ?><br>
    <b>Stato gara:</b> <?php echo htmlspecialchars($race['status'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
  </p>

  <?php if ($error): ?>
    <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <?php if (is_athlete()): ?>
    <h2>La tua iscrizione</h2>

    <?php if (!$myReg || ($myReg['status'] ?? '') === 'cancelled'): ?>
      <p>Non sei iscritto.</p>
      <form method="post">
        <input type="hidden" name="action" value="register">
        <button type="submit" style="padding:10px 14px;">Iscriviti</button>
      </form>
    <?php else: ?>
      <p>
        Stato: <b><?php echo htmlspecialchars($myReg['status'], ENT_QUOTES, 'UTF-8'); ?></b>
        · dal <?php echo htmlspecialchars($myReg['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <form method="post" onsubmit="return confirm('Vuoi annullare l’iscrizione?');">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" style="padding:10px 14px;">Annulla iscrizione</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (can_manage()): ?>
    <h2>Iscritti</h2>

    <?php if (!$regs): ?>
      <p>Nessuna iscrizione.</p>
    <?php else: ?>
      <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Email</th>
            <th>Stato</th>
            <th>Data</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($regs as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <?php if (($r['status'] ?? '') !== 'confirmed'): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit">Conferma</button>
                  </form>
                <?php endif; ?>

                <?php if (($r['status'] ?? '') !== 'pending'): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="pending">
                    <button type="submit">Pending</button>
                  </form>
                <?php endif; ?>

                <?php if (($r['status'] ?? '') !== 'cancelled'): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Annullare iscrizione?');">
                    <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit">Annulla</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

</body>
</html>
