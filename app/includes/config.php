<?php
// app/includes/config.php

return [
  'app_name' => 'EasyRace',
  'env' => 'local', // local | prod

  'db' => [
    'host' => 'localhost',
    'name' => 'easyrace',
    'user' => 'root',
    'pass' => 'root',
    'charset' => 'utf8mb4',
  ],

  'paths' => [
    'root' => dirname(__DIR__, 2),
  ],
];
