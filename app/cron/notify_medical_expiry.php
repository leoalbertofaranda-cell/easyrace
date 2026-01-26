<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/mailer.php';

$conn = db($config);
$days = 30;

$stmt = $conn->prepare("
  SELECT
    ap.user_id,
    ap.medical_cert_valid_until,
    u.email,
    u.full_name
  FROM athlete_profile ap
  JOIN users u ON u.id = ap.user_id
  LEFT JOIN notifications_sent ns
    ON ns.user_id = ap.user_id
   AND ns.notif_type = 'MED_CERT_EXPIRY_30'
   AND ns.target_date = ap.medical_cert_valid_until
  WHERE ap.medical_cert_valid_until IS NOT NULL
    AND ap.medical_cert_valid_until <> ''
    AND ap.medical_cert_valid_until = DATE_ADD(CURDATE(), INTERVAL ? DAY)
    AND ns.id IS NULL
");
$stmt->bind_param("i", $days);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sent = 0;

$baseUrl = (string)($config['app_base_url'] ?? ''); // es: https://app.easyrace.app
$profileUrl = rtrim($baseUrl, '/') . '/public/athlete_profile.php';

foreach ($rows as $r) {
  $to = (string)$r['email'];
  if ($to === '') continue;

  $name  = trim((string)($r['full_name'] ?? ''));
  $until = (string)$r['medical_cert_valid_until'];

  $subject = "EasyRace — certificato medico in scadenza";
  $text = "Ciao {$name},\n\n"
        . "il tuo certificato medico risulta in scadenza il {$until} (tra {$days} giorni).\n"
        . "Per evitare blocchi nelle iscrizioni, aggiorna il profilo atleta e carica il certificato valido.\n\n"
        . "Aggiorna il profilo: {$profileUrl}\n\n"
        . "— EasyRace\n";

  $html = "<p>Ciao <b>".htmlspecialchars($name)."</b>,</p>"
        . "<p>il tuo certificato medico risulta in scadenza il <b>".htmlspecialchars($until)."</b> (tra {$days} giorni).</p>"
        . "<p>Per evitare blocchi nelle iscrizioni, aggiorna il profilo atleta e carica il certificato valido.</p>"
        . "<p><a href='".htmlspecialchars($profileUrl)."'>Aggiorna il profilo atleta</a></p>"
        . "<p>— EasyRace</p>";

  $ok = send_mail_smtp($config['smtp'], $to, $subject, $text, $html);

  if ($ok) {
    $ins = $conn->prepare("
      INSERT INTO notifications_sent (user_id, notif_type, target_date, sent_at)
      VALUES (?, 'MED_CERT_EXPIRY_30', ?, NOW())
    ");
    $uid = (int)$r['user_id'];
    $ins->bind_param("is", $uid, $until);
    $ins->execute();
    $ins->close();
    $sent++;
  }
}

echo "Sent: {$sent}\n";
