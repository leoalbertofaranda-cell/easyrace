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
if ($race_id <= 0) { header("Location: events.php"); exit; }

// gara + evento + org
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.organization_id, o.name AS org_name
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE r.id=? LIMIT 1
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata.");
}

require_manage_org($conn, (int)$race['organization_id']);

function slug(string $s): string {
  $s = trim($s);
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim($s, '-');
  return $s ?: 'gara';
}

$start = (string)($race['start_at'] ?? '');
$day = $start ? substr($start, 0, 10) : date('Y-m-d');

$fname = sprintf(
  'segreteria_iscritti_%s_%s_race-%d.csv',
  $day,
  slug((string)($race['title'] ?? 'gara')),
  $race_id
);

// AUDIT: log dell'export (prima di qualsiasi output/header CSV)
audit_log(
  $conn,
  'EXPORT_RACE_REGS_CSV',
  'race',
  (int)$race_id,
  $actor_id,
  $actor_role,
  null,
  [
    'race_id'          => (int)$race_id,
    'organization_id' => (int)$race['organization_id'],
    'event_id'         => (int)($race['event_id'] ?? 0),
    'filename'         => (string)$fname,
    'type'             => 'segreteria'
  ]
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM Excel
fputcsv($out, ['__DEBUG__', date('Y-m-d H:i:s')]);

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
  'Club',
  'Email',
  'Città',
  'Quota €',
  'Pagato il',
  'Reg ID'
]);

$stmt = $conn->prepare("
  SELECT
    rg.id AS reg_id,
    rg.bib_number,
    rg.division_label,
    rg.category_code,
    rg.category_label,
    rg.fee_total_cents,
    rg.paid_at,

    u.full_name,
    u.email,

    ap.first_name,
    ap.last_name,
    ap.gender,
    ap.birth_date,
    ap.nationality_code,
    ap.club_name,
    ap.city,
    ap.primary_membership_federation_code,
    ap.primary_membership_number
  FROM registrations rg
  JOIN users u ON u.id = rg.user_id
  LEFT JOIN athlete_profile ap ON ap.user_id = rg.user_id
  WHERE rg.race_id=?
    AND rg.status='confirmed'
    AND rg.payment_status='paid'
  ORDER BY ap.last_name ASC, ap.first_name ASC, u.full_name ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $r) {
  $last  = trim((string)($r['last_name'] ?? ''));
  $first = trim((string)($r['first_name'] ?? ''));

  if ($last === '' && $first === '') {
    $full = trim((string)($r['full_name'] ?? ''));
    if ($full !== '') {
      $parts = preg_split('/\s+/', $full) ?: [];
      if (count($parts) === 1) {
        $first = $parts[0];
      } else {
        $first = array_shift($parts);
        $last  = implode(' ', $parts);
      }
    }
  }

  fputcsv($out, [
    (string)($r['bib_number'] ?? ''), // può restare vuoto
    $last,
    $first,
    strtoupper((string)($r['gender'] ?? '')),
    (string)($r['birth_date'] ?? ''),
    (string)($r['nationality_code'] ?? ''),
    (string)($r['division_label'] ?? ''),
    (string)($r['primary_membership_federation_code'] ?? ''),
    (string)($r['primary_membership_number'] ?? ''),
    (string)($r['category_code'] ?? ''),
    (string)($r['category_label'] ?? ''),
    (string)($r['club_name'] ?? ''),
    (string)($r['email'] ?? ''),
    (string)($r['city'] ?? ''),
    cents_to_eur((int)($r['fee_total_cents'] ?? 0)),
    (string)($r['paid_at'] ?? ''),
    (string)($r['reg_id'] ?? ''),
  ]);
}

fclose($out);
exit;
