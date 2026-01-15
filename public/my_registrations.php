<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
$role = function_exists('current_role') ? (string)current_role() : '';
if ($role !== 'athlete') {
  header("Location: dashboard.php");
  exit;
}

$conn = db($config);
$u = auth_user();
$user_id = (int)($u['id'] ?? 0);
if ($user_id <= 0) { exit("Sessione non valida."); }

// helper locali (coerenti con race_public.php)
function it_status(string $s): string {
  return match ($s) {
    'confirmed' => 'Approvato',
    'pending'   => 'In valutazione',
    'cancelled' => 'Annullato',
    'blocked'   => 'Bloccato',
    default     => $s,
  };
}
function it_reason(string $r): string {
  return match ($r) {
    'OK'                     => 'OK',
    'PAYMENT_REQUIRED'       => 'Pagamento richiesto',
    'MEMBERSHIP_NOT_ALLOWED' => 'Tesseramento non ammesso',
    'CERT_MISSING'           => 'Certificato mancante',
    'CERT_EXPIRED'           => 'Certificato scaduto',
    default                  => $r,
  };
}
function it_payment(string $p): string {
  return match ($p) {
    'paid'   => 'Pagato',
    'unpaid' => 'Non pagato',
    default  => $p,
  };
}
function it_datetime(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if (!$ts) return (string)$dt;
  return date('d/m/Y H:i', $ts);
}

$error = '';
// azione rapida: cancel da lista
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $reg_id = (int)($_POST['reg_id'] ?? 0);

  if ($action === 'cancel' && $reg_id > 0) {
    try {
      $status = 'cancelled';
      $stmt = $conn->prepare("UPDATE registrations SET status=? WHERE id=? AND user_id=? LIMIT 1");
      $stmt->bind_param("sii", $status, $reg_id, $user_id);
      $stmt->execute();
      $stmt->close();
      header("Location: my_registrations.php");
      exit;
    } catch (Throwable $e) {
      $error = "Operazione non riuscita: " . $e->getMessage();
    }
  }
}

// lista iscrizioni atleta
$stmt = $conn->prepare("
  SELECT
    r.id AS reg_id,
    r.status, r.status_reason, r.payment_status,
    r.paid_total_cents,
    r.created_at,
    ra.id AS race_id,
    ra.title AS race_title,
    ra.start_at,
    e.id AS event_id,
    e.title AS event_title
  FROM registrations r
  JOIN races ra ON ra.id = r.race_id
  JOIN events e ON e.id = ra.event_id
  WHERE r.user_id = ?
  ORDER BY r.created_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header("Le mie iscrizioni");
?>

<p><a href="dashboard.php">← Dashboard</a></p>

<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <p>Non hai ancora iscrizioni.</p>
<?php else: ?>

  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>Gara</th>
        <th>Data</th>
        <th>Stato</th>
        <th>Pagamento</th>
        <th>Quota</th>
        <th>Azioni</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $st = (string)($r['status'] ?? '');
        $reason = (string)($r['status_reason'] ?? '');
        $pay = (string)($r['payment_status'] ?? '');
        $quota = (int)($r['paid_total_cents'] ?? 0);
      ?>
      <tr>
        <td>
          <div><b><?php echo h($r['race_title'] ?? ''); ?></b></div>
          <div style="opacity:.8;">Evento: <?php echo h($r['event_title'] ?? ''); ?></div>
          <div><a href="race_public.php?id=<?php echo (int)$r['race_id']; ?>">Apri</a></div>
        </td>
        <td><?php echo h(it_datetime($r['start_at'] ?? null)); ?></td>
        <td>
          <b><?php echo h(it_status($st)); ?></b>
          <?php if ($reason !== ''): ?>
            <div style="opacity:.85;"><?php echo h(it_reason($reason)); ?></div>
          <?php endif; ?>
        </td>
        <td><?php echo h($pay !== '' ? it_payment($pay) : '-'); ?></td>
        <td>€ <?php echo h(cents_to_eur($quota)); ?></td>
        <td>
          <?php if ($st === 'pending' || $st === 'confirmed'): ?>
            <form method="post" onsubmit="return confirm('Vuoi annullare l’iscrizione?');" style="margin:0;">
              <input type="hidden" name="action" value="cancel">
              <input type="hidden" name="reg_id" value="<?php echo (int)$r['reg_id']; ?>">
              <button type="submit">Annulla</button>
            </form>
          <?php else: ?>
            -
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php endif; ?>

<?php page_footer(); ?>
