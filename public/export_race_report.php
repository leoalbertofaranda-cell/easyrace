<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/helpers.php';

require_login();

$u = auth_user();
$conn = db($config);

$race_id = (int)($_GET['race_id'] ?? 0);
if ($race_id <= 0) { header("Location: events.php"); exit; }

// Carico gara + evento + org (permesso: manage org)
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

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="race_report_'.$race_id.'.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM per Excel

// intestazione report
fputcsv($out, ['Organizzazione', (string)($race['org_name'] ?? '')]);
fputcsv($out, ['Evento', (string)($race['event_title'] ?? '')]);
fputcsv($out, ['Gara', (string)($race['title'] ?? '')]);
fputcsv($out, ['Data/Ora', (string)($race['start_at'] ?? '')]);
fputcsv($out, []);
fputcsv($out, ['Rendicontazione (solo pagati)']);
fputcsv($out, ['Iscritti pagati','Incassato €','Organizzatore €','Piattaforma €','Admin €','Arrotondamenti €']);

// KPI pagati
$stmt = $conn->prepare("
  SELECT
    COUNT(*) AS paid_count,
    COALESCE(SUM(paid_total_cents),0) AS paid_total_cents,
    COALESCE(SUM(organizer_net_cents),0) AS org_total_cents,
    COALESCE(SUM(platform_fee_cents),0) AS platform_total_cents,
    COALESCE(SUM(admin_fee_cents),0) AS admin_total_cents,
    COALESCE(SUM(rounding_delta_cents),0) AS rounding_total_cents
  FROM registrations
  WHERE race_id=? AND payment_status='paid'
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$k = $stmt->get_result()->fetch_assoc();
$stmt->close();

$paid_count = (int)($k['paid_count'] ?? 0);
$paid_total = (int)($k['paid_total_cents'] ?? 0);
$org_total  = (int)($k['org_total_cents'] ?? 0);
$plat_total = (int)($k['platform_total_cents'] ?? 0);
$adm_total  = (int)($k['admin_total_cents'] ?? 0);
$round_tot  = (int)($k['rounding_total_cents'] ?? 0);

fputcsv($out, [
  $paid_count,
  cents_to_eur($paid_total),
  cents_to_eur($org_total),
  cents_to_eur($plat_total),
  cents_to_eur($adm_total),
  cents_to_eur($round_tot),
]);

// dettaglio righe pagate
fputcsv($out, []);
fputcsv($out, ['Dettaglio iscritti (solo pagati)']);
fputcsv($out, ['Nome','Email','Categoria','Quota €','Pagato €','Pagato il','Stato','Motivo']);

$stmt = $conn->prepare("
  SELECT
    u.full_name,
    u.email,
    r.category_code,
    r.category_label,
    r.fee_total_cents,
    r.paid_total_cents,
    r.paid_at,
    r.status,
    r.status_reason
  FROM registrations r
  JOIN users u ON u.id = r.user_id
  WHERE r.race_id=? AND r.payment_status='paid'
  ORDER BY u.full_name ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $r) {
  $cat = trim((string)($r['category_code'] ?? ''));
  $lab = trim((string)($r['category_label'] ?? ''));
  $cat_out = $cat ? ($cat . ' — ' . $lab) : $lab;

  fputcsv($out, [
    (string)($r['full_name'] ?? ''),
    (string)($r['email'] ?? ''),
    $cat_out,
    cents_to_eur((int)($r['fee_total_cents'] ?? 0)),
    cents_to_eur((int)($r['paid_total_cents'] ?? 0)),
    (string)($r['paid_at'] ?? ''),
    (string)($r['status'] ?? ''),
    (string)($r['status_reason'] ?? ''),
  ]);
}

fclose($out);
exit;
