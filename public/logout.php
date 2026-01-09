<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
auth_logout();
header("Location: login.php");
exit;
