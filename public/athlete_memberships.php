<?php
// public/athlete_memberships.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';

require_login();
require_roles(['athlete']);

$conn = db($config);

$u = auth_user();
$user_id = (int)($u['id'] ?? 0);
if ($user_id <= 0) {
  header("HTTP/1.1 403 Forbidden");
  exit("Sessione non valida.");
}

$success = '';
$error = '';

// DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_membership_id'])) {
  $id = (int)($_POST['delete_membership_id'] ?? 0);
  if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM athlete_memberships WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
      $error = "Errore DB (prepare): " . h($conn->error);
    } else {
      $stmt->bind_param("ii", $id, $user_id);
      if (!$stmt->execute()) {
        $error = "Errore DB (execute): " . h($stmt->error);
      }
      $stmt->close();
    }
  }
  if (!$error) {
    header("Location: athlete_memberships.php");
    exit;
  }
}

// INSERT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_membership'])) {
  $fed  = trim((string)($_POST['membership_federation'] ?? ''));
  $num  = trim((string)($_POST['membership_number'] ?? ''));
  $year = trim((string)($_POST['membership_year'] ?? ''));

  if ($fed === '' || $num === '') {
    $error = "Ente e numero tessera sono obbligatori.";
  } else {
    $yearVal = ($year !== '' ? (string)(int)$year : null);

    $stmt = $conn->prepare("
      INSERT INTO athlete_memberships (user_id, federation_code, membership_number, year)
      VALUES (?, ?, ?, ?)
    ");
    if (!$stmt) {
      $error = "Errore DB (prepare): " . h($conn->error);
    } else {
      $stmt->bind_param("isss", $user_id, $fed, $num, $yearVal);
      if (!$stmt->execute()) {
        $error = "Errore DB (execute): " . h($stmt->error);
      }
      $stmt->close();
    }

    if (!$error) {
      header("Location: athlete_memberships.php");
      exit;
    }
  }
}

// LIST
$memberships = [];
$stmt = $conn->prepare("
  SELECT id, federation_code, membership_number, year, created_at
  FROM athlete_memberships
  WHERE user_id = ?
  ORDER BY created_at DESC
");
if (!$stmt) {
  $error = "Errore DB (prepare): " . h($conn->error);
} else {
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $memberships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>I miei tesseramenti</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>

<div class="container" style="max-width: 860px; margin: 24px auto; padding: 0 12px;">
  <h1>I miei tesseramenti</h1>

  <p style="margin-top:6px;">
    <a class="btn btn-light" href="athlete_profile.php">← Torna al profilo</a>
  </p>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?=h($error)?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success"><?=h($success)?></div>
  <?php endif; ?>

  <h3 style="margin-top:18px;">Aggiungi tesseramento</h3>

  <form method="post" style="margin-top:12px;">
    <input type="hidden" name="add_membership" value="1">

    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
      <div>
        <label>Ente</label>
        <select name="membership_federation" class="form-select">
          <?php foreach (['FCI','ACSI','FIDAL','UISP','CSI'] as $o): ?>
            <option value="<?=$o?>"><?=$o?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Nr tessera</label>
        <input name="membership_number" class="form-control" required>
      </div>

      <div>
        <label>Anno</label>
        <input name="membership_year" class="form-control" placeholder="2026">
      </div>
    </div>

    <div style="margin-top:12px;">
      <button class="btn btn-secondary" type="submit">➕ Aggiungi tesseramento</button>
    </div>
  </form>

  <hr style="margin:24px 0;">

  <h3>Elenco tesseramenti</h3>

  <?php if (!empty($memberships)): ?>
    <ul style="padding-left:18px;">
      <?php foreach ($memberships as $m): ?>
        <li style="margin-bottom:10px;">
          <strong><?=h($m['federation_code'])?></strong> – <?=h($m['membership_number'])?>
          <?php if (!empty($m['year'])): ?> (<?=h((string)$m['year'])?>)<?php endif; ?>

          <form method="post" style="display:inline; margin-left:10px;" onsubmit="return confirm('Eliminare questo tesseramento?');">
            <input type="hidden" name="delete_membership_id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit">Elimina</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="small text-muted">Nessun tesseramento aggiuntivo.</p>
  <?php endif; ?>

</div>

</body>
</html>
