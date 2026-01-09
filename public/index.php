<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';

if (auth_user()) {
  header("Location: dashboard.php");
  exit;
}

header("Location: login.php");
exit;
