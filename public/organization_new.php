<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();

$u = auth_user();
$conn = db($config);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim((string)($_POST['name'] ?? ''));
  $vat   = trim((string)($_POST['vat_code'] ?? ''));
  $city  = trim((string)($_POST['city'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));

  if ($name === '') {
    $error = "Il nome è obbligatorio.";
  } else {
    // transazione: crea org + assegna owner
    $conn->begin_transaction();
    try {
      $stmt = $conn->prepare("INSERT INTO organizations (name, vat_code, city, email, phone) VALUES (?,?,?,?,?)");
      $stmt->bind_param("sssss", $name, $vat, $city, $email, $phone);
      $stmt->execute();
      $orgId = (int)$conn->insert_id;
      $stmt->close();

      $orgRole = 'owner';
      $stmt = $conn->prepare("INSERT INTO organization_users (organization_id, user_id, org_role) VALUES (?,?,?)");
      $stmt->bind_param("iis", $orgId, $u['id'], $orgRole);
      $stmt->execute();
      $stmt->close();

      $conn->commit();
      header("Location: organizations.php");
      exit;
    } catch (Throwable $e) {
      $conn->rollback();

      // nome duplicato o altro
      $error = "Non riesco a creare l’organizzazione (nome già usato o errore DB).";
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Nuova organizzazione</title>
</head>
<body style="font-family: system-ui; max-width: 520px; margin: 40px auto; padding: 0 16px;">
  <h1>Nuova organizzazione</h1>
  <p><a href="organizations.php">← Torna alle organizzazioni</a></p>

  <?php if ($error): ?>
    <div style="padding:12px; background:#ffecec; border:1px solid #ffb3b3; margin: 12px 0;">
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <label>Nome *</label><br>
    <input name="name" style="width:100%; padding:10px; margin:6px 0 12px;" required>

    <label>P.IVA / CF</label><br>
    <input name="vat_code" style="width:100%; padding:10px; margin:6px 0 12px;">

    <label>Città</label><br>
    <input name="city" style="width:100%; padding:10px; margin:6px 0 12px;">

    <label>Email</label><br>
    <input type="email" name="email" style="width:100%; padding:10px; margin:6px 0 12px;">

    <label>Telefono</label><br>
    <input name="phone" style="width:100%; padding:10px; margin:6px 0 12px;">

    <button type="submit" style="padding:10px 14px;">Crea</button>
  </form>
</body>
</html>
