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
    'id' => (int)$user['id'],
    'email' => (string)$user['email'],
    'full_name' => (string)$user['full_name'],
    'role' => (string)$user['role'],
  ];
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
