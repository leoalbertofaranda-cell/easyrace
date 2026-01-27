<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';

auth_start_session();

$error = '';
$info  = '';

$return_url = (string)($_GET['return_url'] ?? $_POST['return_url'] ?? '');
$return_url = trim($return_url);

// sicurezza base: blocca URL esterni e path traversal
if ($return_url !== '') {
  if (preg_match('~^(https?:)?//~i', $return_url) || str_contains($return_url, "\n") || str_contains($return_url, "\r")) {
    $return_url = '';
  }
  $return_url = ltrim($return_url, '/');
  if (str_contains($return_url, '..')) $return_url = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = "Inserisci email e password.";
  } else {
    try {
      $conn = db($config);
      $stmt = $conn->prepare("SELECT id,email,full_name,role,password_hash,is_active FROM users WHERE email=? LIMIT 1");
      if (!$stmt) throw new RuntimeException('prepare failed');
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

        // se arriva da una pagina specifica, torna lì; altrimenti dashboard
        if ($return_url !== '') {
          header("Location: " . $return_url);
          exit;
        }
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
  <link rel="stylesheet" href="assets/app.css">
  <style>
    body{ background:#f6f7f8; }
    *, *::before, *::after {
  box-sizing: border-box;
}

    .wrap{ max-width:420px; margin:48px auto; padding:0 14px; }
    .card{
      background:#fff; border:1px solid #e5e7eb; border-radius:16px;
      padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06);
    }
    .brand{ font-weight:900; font-size:22px; letter-spacing:-.02em; margin:0 0 6px; }
    .sub{ color:#555; margin:0 0 14px; font-size:14px; line-height:1.35; }
    label{ font-weight:700; font-size:13px; margin:10px 0 6px; display:block; }
    input{ width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:12px; }
    input:focus{ outline:none; border-color:#94a3b8; box-shadow:0 0 0 3px rgba(148,163,184,.25); }
    .btn{
      width:100%; padding:10px 12px; border:0; border-radius:12px;
      font-weight:800; cursor:pointer;
    }
    .btn-primary{ background:#111827; color:#fff; }
    .btn-primary:hover{ opacity:.95; }
    .note{ margin-top:12px; font-size:13px; color:#555; }
    .note a{ font-weight:800; text-decoration:none; }
    .note a:hover{ text-decoration:underline; }
    .alert{ padding:12px; border-radius:12px; margin:12px 0; font-size:14px; }
    .alert-danger{ background:#ffecec; border:1px solid #ffb3b3; }
    .muted{ color:#6b7280; font-size:12px; margin-top:10px; }
  </style>
</head>
<body>

<div class="wrap">
  <div class="card">
    <div class="brand">Accedi</div>
    <div class="sub">Entra in EasyRace per gestire gare, iscrizioni e profilo atleta.</div>

    <?php if ($error): ?>
      <div class="alert alert-danger">
        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8'); ?>">

      <label>Email</label>
      <input type="email" name="email" required>

      <label>Password</label>
      <input type="password" name="password" required>

      <div style="height:12px;"></div>
      <button type="submit" class="btn btn-primary">Entra</button>

      <div class="note">
        Non hai un account? <a href="signup.php<?php echo $return_url ? ('?return_url=' . urlencode($return_url)) : ''; ?>">Registrati</a>
      </div>

      <div class="muted">
        Se stai entrando come organizzatore o admin, usa l’account che ti ha fornito la piattaforma.
      </div>
    </form>
  </div>
</div>

</body>
</html>
