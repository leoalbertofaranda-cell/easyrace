<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';

auth_start_session();
$conn = db($config);

$error = '';
$full_name = '';
$email = '';

// se già loggato
if (auth_user()) {
  header("Location: dashboard.php");
  exit;
}

// return_url relativo a /easyrace/public (es: "race_public.php?id=11")
$return_url = (string)($_GET['return_url'] ?? $_POST['return_url'] ?? '');
$return_url = trim($return_url);
if ($return_url !== '') {
  if (preg_match('~^(https?:)?//~i', $return_url) || str_contains($return_url, "\n") || str_contains($return_url, "\r")) {
    $return_url = '';
  }
  $return_url = ltrim($return_url, '/');
  if (str_contains($return_url, '..')) $return_url = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $full_name = trim((string)($_POST['full_name'] ?? ''));
  $email     = trim((string)($_POST['email'] ?? ''));
  $pass      = (string)($_POST['password'] ?? '');
  $pass2     = (string)($_POST['password_confirm'] ?? '');

  if ($full_name === '' || $email === '' || $pass === '' || $pass2 === '') {
    $error = "Compila tutti i campi.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Email non valida.";
  } elseif (strlen($pass) < 8) {
    $error = "Password troppo corta (minimo 8 caratteri).";
  } elseif ($pass !== $pass2) {
    $error = "Le password non coincidono.";
  } else {
    // email già usata?
    $stmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    if (!$stmt) {
      $error = "Errore server.";
    } else {
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($row) {
        $error = "Esiste già un account con questa email. Vai al login.";
      } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
          INSERT INTO users (email, password_hash, full_name, role, is_active)
          VALUES (?, ?, ?, 'athlete', 1)
        ");
        if (!$stmt) {
          $error = "Errore server.";
        } else {
          $stmt->bind_param("sss", $email, $hash, $full_name);
          if (!$stmt->execute()) {
            $error = ($stmt->errno === 1062)
              ? "Esiste già un account con questa email. Vai al login."
              : "Errore server.";
          }
          $stmt->close();
        }

        // login automatico
        if ($error === '') {
          $stmt = $conn->prepare("SELECT id,email,full_name,role,password_hash,is_active FROM users WHERE email=? LIMIT 1");
          if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && (int)$user['is_active'] === 1) {
              auth_login($user);

              // dopo registrazione: profilo atleta (obbligatorio) oppure return_url se lo passi tu
              if ($return_url !== '') {
                header("Location: " . $return_url);
                exit;
              }
              header("Location: athlete_profile.php");
              exit;
            }
          }
          header("Location: login.php");
          exit;
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Registrazione atleta</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body{ background:#f6f7f8; }
    .wrap{ max-width:420px; margin:48px auto; padding:0 14px; }
    .card{
      background:#fff; border:1px solid #e5e7eb; border-radius:16px;
      padding:18px; box-shadow:0 6px 18px rgba(0,0,0,.06);
    }
    .brand{ font-weight:900; font-size:22px; letter-spacing:-.02em; margin:0 0 6px; }
    .sub{ color:#555; margin:0 0 14px; font-size:14px; line-height:1.35; }
    label{ font-weight:700; font-size:13px; margin:10px 0 6px; display:block; }
    input{
      width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:12px;
    }
    .btn{
      width:100%; padding:10px 12px; border:0; border-radius:12px;
      font-weight:800; cursor:pointer; background:#111827; color:#fff;
    }
    .btn:hover{ opacity:.95; }
    .note{ margin-top:12px; font-size:13px; color:#555; }
    .note a{ font-weight:800; text-decoration:none; }
    .note a:hover{ text-decoration:underline; }
    .alert{ padding:12px; border-radius:12px; margin:12px 0; font-size:14px; }
    .alert-danger{ background:#ffecec; border:1px solid #ffb3b3; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand">Registrazione atleta</div>
    <div class="sub">Crea il tuo account per iscriverti alle gare.</div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url, ENT_QUOTES, 'UTF-8'); ?>">

      <label>Nome e cognome</label>
      <input type="text" name="full_name" value="<?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>" required>

      <label>Email</label>
      <input type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>

      <label>Password</label>
      <input type="password" name="password" required>

      <label>Conferma password</label>
      <input type="password" name="password_confirm" required>

      <div style="height:12px;"></div>
      <button type="submit" class="btn">Crea account</button>

      <div class="note">
        Hai già un account?
        <a href="login.php<?php echo $return_url ? ('?return_url=' . urlencode($return_url)) : ''; ?>">Vai al login</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
