<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/audit.php';

require_login();

$u = auth_user();
[$actor_id, $actor_role] = actor_from_auth($u);


$conn = db($config);

$race_id = (int)($_GET['race_id'] ?? 0);
if ($race_id <= 0) {
  header("HTTP/1.1 400 Bad Request");
  exit('Race ID mancante');
}


// filtro opzionale divisione
$division_id = (int)($_GET['division_id'] ?? 0);

// sicurezza: verifica permessi
$stmt = $conn->prepare("
  SELECT r.id, r.event_id, e.organization_id
  FROM races r
  JOIN events e ON e.id = r.event_id
  WHERE r.id=?
  LIMIT 1
");
if (!$stmt) {
  header("HTTP/1.1 500 Internal Server Error");
  exit('Errore DB (prepare)');
}
$stmt->bind_param("i", $race_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  header("HTTP/1.1 404 Not Found");
  exit('Gara non trovata');
}

require_org_permission($conn, (int)$row['organization_id'], 'view_reports');

// AUDIT: log dell'export (prima di qualsiasi output/header CSV)
audit_log(
  $conn,
  'EXPORT_RACE_BIBS',
  'race',
  (int)$race_id,
  null,
  [
    'race_id'          => (int)$race_id,
    'organization_id' => (int)($row['organization_id'] ?? 0),
    'event_id'         => (int)($row['event_id'] ?? 0),
    'division_id'      => (int)$division_id,
    'filename'         => 'cronometristi_gara_'.$race_id.'.csv',
    'type'             => 'crono_bibs'
  ]
);



// intestazioni CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="cronometristi_gara_'.$race_id.'.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM Excel

fputcsv($out, [
  'Pettorale',
  'Cognome',
  'Nome',
  'Sesso',
  'Data di nascita',
  'Nazionalità',
  'Divisione',
  'Ente',
  'Numero tessera',
  'Categoria codice',
  'Categoria label',
  'Club'
]);

$sql = "
  SELECT
  rg.bib_number,
  ap.last_name,
  ap.first_name,
  ap.gender,
  ap.birth_date,
  ap.nationality_code,
  rg.division_label,
  ap.primary_membership_federation_code,
  ap.primary_membership_number,
  rg.category_code  AS category_code,
  rg.category_label AS category_label,
  ap.club_name
  FROM registrations rg
  LEFT JOIN athlete_profile ap ON ap.user_id = rg.user_id
  WHERE rg.race_id=?
    AND rg.status='confirmed'
    AND rg.payment_status='paid'
    AND rg.bib_number IS NOT NULL
";
if ($division_id > 0) $sql .= " AND rg.division_id = ? ";
$sql .= " ORDER BY rg.bib_number ASC ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  header("HTTP/1.1 500 Internal Server Error");
  exit('Errore DB (prepare export)');
}

if ($division_id > 0) $stmt->bind_param("ii", $race_id, $division_id);
else $stmt->bind_param("i", $race_id);

$stmt->execute();
$res = $stmt->get_result();

while ($r = $res->fetch_assoc()) {
  fputcsv($out, [
    $r['bib_number'],
    $r['last_name'],
    $r['first_name'],
    strtoupper((string)$r['gender']),
    $r['birth_date'],
    $r['nationality_code'],
    (string)($r['division_label'] ?? ''),
    $r['primary_membership_federation_code'],
    $r['primary_membership_number'],
    (string)($r['category_code'] ?? ''),
    (string)($r['category_label'] ?? ''),
    $r['club_name']
  ]);
}

$stmt->close();
fclose($out);
exit;
