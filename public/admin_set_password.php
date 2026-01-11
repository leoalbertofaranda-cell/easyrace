<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
if (!in_array(current_role(), ['superuser','admin'], true)) {
  header("HTTP/1.1 403 Forbidden");
  exit("Accesso negato.");
}

$conn = db($config);

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $newpass = (string)($_POST['newpass'] ?? '');

  if ($email === '' || $newpass === '') {
    $err = "Email e nuova password sono obbligatori.";
  } elseif (strlen($newpass) < 8) {
    $err = "Password troppo corta (min 8).";
  } else {
    // Il tuo DB ha SOLO: users.password_hash (vedi screenshot)
    $hash = password_hash($newpass, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE email=? LIMIT 1");
    if (!$stmt) {
      $err = "Errore DB (prepare): " . $conn->error;
    } else {
      $stmt->bind_param("ss", $hash, $email);
      $stmt->execute();

      if ($stmt->affected_rows > 0) {
        $msg = "Password aggiornata per: " . $email;
      } else {
        // nessuna riga aggiornata: email non trovata oppure stessa password reimpostata (raro)
        $err = "Utente non trovato (email) oppure nessuna modifica effettuata.";
      }
      $stmt->close();
    }
  }
}

page_header('Admin · Imposta password utente');
?>

<p><a href="dashboard.php">Dashboard</a></p>

<?php if ($msg): ?>
  <div style="padding:12px;background:#eaffea;border:1px solid #b3ffb3;margin:12px 0;"><?php echo h($msg); ?></div>
<?php endif; ?>

<?php if ($err): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;"><?php echo h($err); ?></div>
<?php endif; ?>

<form method="post" style="max-width:520px;border:1px solid #ddd;border-radius:12px;padding:12px;">
  <label>Email utente</label><br>
  <input name="email" style="width:100%;padding:10px;margin:6px 0 12px;" placeholder="info@faranda.media" required>

  <label>Nuova password</label><br>
  <input type="text" name="newpass" style="width:100%;padding:10px;margin:6px 0 12px;" placeholder="min 8 caratteri" required>

  <button type="submit" style="padding:10px 14px;">Imposta password</button>
</form>

<?php page_footer(); ?>
