<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/audit.php';

require_login();

$u = auth_user();
[$actor_id, $actor_role] = actor_from_auth($u);


$conn = db($config);

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { header("Location: events.php"); exit; }

// Evento + org (permesso: manage org)
$stmt = $conn->prepare("
  SELECT e.*, o.name AS org_name
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  WHERE e.id=? LIMIT 1
");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
  header("HTTP/1.1 404 Not Found");
  exit("Evento non trovato.");
}


require_manage_org($conn, (int)$event['organization_id']);

audit_log(
  $conn,
  'EXPORT_EVENT_REPORT',
  'event',
  (int)$event_id,
  $actor_id,
  $actor_role,
  null,
  [
    'event_id'         => (int)$event_id,
    'organization_id' => (int)($event['organization_id'] ?? 0),
    'type'             => 'event_report'
  ]
);


header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="event_report_'.$event_id.'.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM Excel

// intestazione report
fputcsv($out, ['Organizzazione', (string)($event['org_name'] ?? '')]);
fputcsv($out, ['Evento', (string)($event['title'] ?? '')]);
fputcsv($out, ['Periodo', (string)($event['starts_on'] ?? ''), (string)($event['ends_on'] ?? '')]);
fputcsv($out, []);

// KPI evento (solo pagati)
$stmt = $conn->prepare("
  SELECT
    COUNT(*) AS paid_count,
    COALESCE(SUM(rg.paid_total_cents),0) AS paid_total_cents,
    COALESCE(SUM(rg.organizer_net_cents),0) AS org_total_cents,
    COALESCE(SUM(rg.platform_fee_cents),0) AS platform_total_cents,
    COALESCE(SUM(rg.admin_fee_cents),0) AS admin_total_cents,
    COALESCE(SUM(rg.rounding_delta_cents),0) AS rounding_total_cents
  FROM registrations rg
  JOIN races ra ON ra.id = rg.race_id
  WHERE ra.event_id=? AND rg.payment_status='paid'
");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$k = $stmt->get_result()->fetch_assoc();
$stmt->close();

$paid_count = (int)($k['paid_count'] ?? 0);
$paid_total = (int)($k['paid_total_cents'] ?? 0);
$org_total  = (int)($k['org_total_cents'] ?? 0);
$plat_total = (int)($k['platform_total_cents'] ?? 0);
$adm_total  = (int)($k['admin_total_cents'] ?? 0);
$round_tot  = (int)($k['rounding_total_cents'] ?? 0);

fputcsv($out, ['Rendicontazione EVENTO (solo pagati)']);
fputcsv($out, ['Iscritti pagati','Incassato €','Organizzatore €','Piattaforma €','Admin €','Arrotondamenti €']);
fputcsv($out, [
  $paid_count,
  cents_to_eur($paid_total),
  cents_to_eur($org_total),
  cents_to_eur($plat_total),
  cents_to_eur($adm_total),
  cents_to_eur($round_tot),
]);

fputcsv($out, []);
fputcsv($out, ['Dettaglio per GARA (solo pagati)']);
fputcsv($out, ['Race ID','Gara','Data/Ora','Pagati (n)','Incassato €','Organizzatore €','Piattaforma €','Admin €','Arrotondamenti €']);

// KPI per gara
$stmt = $conn->prepare("
  SELECT
    ra.id AS race_id,
    ra.title AS race_title,
    ra.start_at,
    COUNT(*) AS paid_count,
    COALESCE(SUM(rg.paid_total_cents),0) AS paid_total_cents,
    COALESCE(SUM(rg.organizer_net_cents),0) AS org_total_cents,
    COALESCE(SUM(rg.platform_fee_cents),0) AS platform_total_cents,
    COALESCE(SUM(rg.admin_fee_cents),0) AS admin_total_cents,
    COALESCE(SUM(rg.rounding_delta_cents),0) AS rounding_total_cents
  FROM races ra
  LEFT JOIN registrations rg
    ON rg.race_id = ra.id AND rg.payment_status='paid'
  WHERE ra.event_id=?
  GROUP BY ra.id
  ORDER BY ra.start_at ASC, ra.id ASC
");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $r) {
  fputcsv($out, [
    (int)($r['race_id'] ?? 0),
    (string)($r['race_title'] ?? ''),
    (string)($r['start_at'] ?? ''),
    (int)($r['paid_count'] ?? 0),
    cents_to_eur((int)($r['paid_total_cents'] ?? 0)),
    cents_to_eur((int)($r['org_total_cents'] ?? 0)),
    cents_to_eur((int)($r['platform_total_cents'] ?? 0)),
    cents_to_eur((int)($r['admin_total_cents'] ?? 0)),
    cents_to_eur((int)($r['rounding_total_cents'] ?? 0)),
  ]);
}

fclose($out);
exit;
