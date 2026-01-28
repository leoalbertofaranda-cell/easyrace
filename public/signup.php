<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/auth.php';

auth_start_session();

if (auth_user()) {
  header("Location: dashboard.php");
  exit;
}


$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $full_name = trim((string)($_POST['full_name'] ?? ''));
  $email     = trim((string)($_POST['email'] ?? ''));
  $pass1     = (string)($_POST['password'] ?? '');
  $pass2     = (string)($_POST['password2'] ?? '');

  if ($full_name === '' || $email === '' || $pass1 === '' || $pass2 === '') {
    $error = "Compila tutti i campi.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Email non valida.";
  } elseif (strlen($pass1) < 8) {
    $error = "Password troppo corta (minimo 8 caratteri).";
  } elseif ($pass1 !== $pass2) {
    $error = "Le password non coincidono.";
  } else {
    try {
      $conn = db($config);

      // email già usata?
      $stmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
      $stmt->bind_param("s", $email);
      $stmt->execute();
      $exists = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if ($exists) {
        $error = "Questa email è già registrata. Vai al login.";
      } else {
        $hash = password_hash($pass1, PASSWORD_DEFAULT);

        // ruolo blindato: SOLO athlete
        $role = 'athlete';
        $is_active = 1;

        $stmt = $conn->prepare("
          INSERT INTO users (email, password_hash, full_name, role, is_active)
          VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssi", $email, $hash, $full_name, $role, $is_active);

        if (!$stmt->execute()) {
          $error = "Errore DB (insert).";
          $stmt->close();
        } else {
          $user_id = (int)$stmt->insert_id;
          $stmt->close();

          // carico utente “pulito” e faccio login
          $stmt = $conn->prepare("SELECT id,email,full_name,role,is_active FROM users WHERE id=? LIMIT 1");
          $stmt->bind_param("i", $user_id);
          $stmt->execute();
          $user = $stmt->get_result()->fetch_assoc();
          $stmt->close();

          if ($user) {
            auth_login($user);
            header("Location: athlete_profile.php");
            exit;
          } else {
            $ok = "Account creato. Ora fai il login.";
          }
        }
      }
    } catch (Throwable $e) {
      $error = "Errore server.";
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
  <style>
    *{ box-sizing:border-box; }
    body{ font-family:system-ui; margin:0; background:#f6f7fb; }
    .wrap{ max-width:420px; margin:40px auto; padding:0 16px; }
    .card{ background:#fff; border:1px solid #e6e8ef; border-radius:16px; padding:22px; box-shadow:0 10px 30px rgba(0,0,0,.06); }
    h1{ margin:0 0 6px; font-size:28px; }
    p{ margin:0 0 18px; color:#555; font-size:14px; line-height:1.4; }
    label{ display:block; font-weight:700; margin:14px 0 6px; font-size:13px; }
    input{
      width:100%;
      max-width:100%;
      display:block;
      padding:10px 12px;
      border:1px solid #d8dbe6;
      border-radius:12px;
      font-size:14px;
      outline:none;
    }
    input:focus{ border-color:#9aa3b2; }
    .btn{
      width:100%;
      display:block;
      margin-top:16px;
      padding:12px 14px;
      border-radius:12px;
      border:0;
      cursor:pointer;
      font-weight:800;
      background:#111827;
      color:#fff;
      font-size:14px;
    }
    .alert{ padding:12px; border-radius:12px; margin:12px 0; font-size:14px; }
    .alert.err{ background:#ffecec; border:1px solid #ffb3b3; }
    .alert.ok{ background:#e8fff1; border:1px solid #9ee6b8; }
    .links{ margin-top:14px; font-size:14px; }
    .links a{ font-weight:800; text-decoration:none; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Registrazione atleta</h1>
      <p>Crea il tuo account per iscriverti alle gare e gestire il profilo atleta.</p>

      <?php if ($error): ?>
        <div class="alert err"><?php echo h($error); ?></div>
      <?php endif; ?>

      <?php if ($ok): ?>
        <div class="alert ok"><?php echo h($ok); ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <label>Nome e cognome</label>
        <input type="text" name="full_name" value="<?php echo h($_POST['full_name'] ?? ''); ?>" required>

        <label>Email</label>
        <input type="email" name="email" value="<?php echo h($_POST['email'] ?? ''); ?>" required>

        <label>Password</label>
        <input type="password" name="password" required>

        <label>Conferma password</label>
        <input type="password" name="password2" required>

        <button class="btn" type="submit">Crea account</button>
      </form>

      <div class="links">
        Hai già un account? <a href="login.php">Vai al login</a>
      </div>
    </div>
  </div>
</body>
</html>
