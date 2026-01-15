<?php
// app/includes/auth.php
declare(strict_types=1);

function auth_start_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
}

function auth_user(): ?array {
  auth_start_session();
  return $_SESSION['auth'] ?? null;
}

function auth_login(array $user): void {
  auth_start_session();
  session_regenerate_id(true);

  $_SESSION['auth'] = [
    'id'         => (int)($user['id'] ?? 0),
    'email'      => (string)($user['email'] ?? ''),
    'full_name'  => (string)($user['full_name'] ?? ''),
    'role'       => (string)($user['role'] ?? ''),

    // campi anagrafici (servono per categorie, iscrizioni, ecc.)
    'birth_date' => $user['birth_date'] ?? null,         // 'YYYY-MM-DD' oppure null
    'gender'     => (string)($user['gender'] ?? 'X'),     // 'M' | 'F' | 'X'
  ];
}

/**
 * Aggiorna alcuni campi dell’utente in sessione (utile dopo update profilo).
 * Passa solo i campi che vuoi aggiornare.
 */
function auth_refresh(array $fields): void {
  auth_start_session();
  if (empty($_SESSION['auth']) || !is_array($_SESSION['auth'])) return;

  foreach (['full_name','email','role','birth_date','gender'] as $k) {
    if (array_key_exists($k, $fields)) {
      $_SESSION['auth'][$k] = $fields[$k];
    }
  }
}

function auth_logout(): void {
  auth_start_session();
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
  }
  session_destroy();
}

function require_login(): void {
  if (!auth_user()) {
    header("Location: login.php");
    exit;
  }
}

function auth_role(): string {
  $u = auth_user();
  return (string)($u['role'] ?? '');
}

function require_roles(array $roles): void {
  $role = auth_role();
  if (!in_array($role, $roles, true)) {
    header("HTTP/1.1 403 Forbidden");
    exit("Accesso negato.");
  }
}

function require_manage(): void {
  require_login();

  // NON usare current_role() qui
  $u = auth_user();
  $role = (string)($u['role'] ?? '');

  if (!in_array($role, ['superuser','admin','organizer'], true)) {
    header("HTTP/1.1 403 Forbidden");
    exit("Accesso negato.");
  }
}



function can_manage(): bool {
  $r = auth_role();
  return in_array($r, ['superuser','admin','organizer'], true);
}

function is_athlete(): bool {
  return auth_role() === 'athlete';
}

function require_superuser(): void {
  require_login();
  $u = auth_user();
  $role = (string)($u['role'] ?? '');
  if ($role !== 'superuser') {
    header("HTTP/1.1 403 Forbidden");
    exit("Accesso negato");
  }
}
