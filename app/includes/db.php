<?php
// app/includes/db.php

declare(strict_types=1);

function db(array $config): mysqli {
  static $conn = null;
  if ($conn instanceof mysqli) return $conn;

  $db = $config['db'] ?? [];
  $host = (string)($db['host'] ?? 'localhost');
  $name = (string)($db['name'] ?? 'easyrace');
  $user = (string)($db['user'] ?? 'root');
  $pass = (string)($db['pass'] ?? 'root');
  $charset = (string)($db['charset'] ?? 'utf8mb4');

  $conn = @new mysqli($host, $user, $pass, $name);
  if ($conn->connect_errno) {
    throw new RuntimeException("DB connect failed: " . $conn->connect_error);
  }

  if (!$conn->set_charset($charset)) {
    throw new RuntimeException("DB charset failed: " . $conn->error);
  }

  return $conn;
}
