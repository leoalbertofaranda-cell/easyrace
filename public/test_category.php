<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/categories.php';

$conn = db($config);

$cat = get_category_for_athlete(
    2,
    2026,
    'M',
    1974,
    $conn
);

var_dump($cat);
