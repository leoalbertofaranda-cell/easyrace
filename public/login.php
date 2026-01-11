<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';

auth_start_session();

$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = "Inserisci email e password.";
  } else {
    try {
      $conn = db($config);
      $stmt = $conn->prepare("SELECT id,email,full_name,role,password_hash,is_active FROM users WHERE email=? LIMIT 1");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $user = $stmt->get_result()->fetch_assoc();
     
      $stmt->close();

      if (!$user || (int)$user['is_active'] !== 1) {
        $error = "Credenziali non valide.";
      } elseif (!password_verify($pass, (string)$user['password_hash'])) {
        $error = "Credenziali non valide.";
      } else {
        auth_login($user);
        header("Location: dashboard.php");
        exit;
      }
    } catch (Throwable $e) {
      $error = "Errore server.";
    }
  }
}

// Se già loggato
if (auth_user()) {
  header("Location: dashboard.php");
  exit;
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Login</title>
</head>
<body style="font-family: system-ui; max-width: 420px; margin: 40px auto; padding: 0 16px;">
  <h1>Login</h1>

  <?php if ($error): ?>
    <div style="padding:12px; background:#ffecec; border:1px solid #ffb3b3; margin: 12px 0;">
      <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <label>Email</label><br>
    <input type="email" name="email" style="width:100%; padding:10px; margin:6px 0 12px;" required>

    <label>Password</label><br>
    <input type="password" name="password" style="width:100%; padding:10px; margin:6px 0 12px;" required>

    <button type="submit" style="padding:10px 14px;">Entra</button>
  </form>
</body>
</html>
