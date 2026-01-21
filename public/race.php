<?php
// public/race.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/categories.php';
require_once __DIR__ . '/../app/includes/fees.php';
require_once __DIR__ . '/../app/includes/helpers.php';

require_login();

$u = auth_user();
$conn = db($config);

/**
 * Fallback escape HTML
 */
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/**
 * Arrotonda ai 50 centesimi (se non hai già helper)
 */
if (!function_exists('round_up_to_50_cents')) {
  function round_up_to_50_cents(int $cents): int {
    return (int)(ceil($cents / 50) * 50);
  }
}

$race_id = (int)($_GET['id'] ?? 0);
if ($race_id <= 0) {
  header("Location: events.php");
  exit;
}

// Carico gara + evento + org
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.id AS event_id, e.organization_id, o.name AS org_name
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE r.id=?
  LIMIT 1
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata.");
}

// Permesso: devi essere membro dell'organizzazione (o superuser)
require_manage_org($conn, (int)$race['organization_id']);

$error = '';

/**
 * Messaggi da redirect
 */
$err = (string)($_GET['err'] ?? '');
if ($err === 'profile_required') {
  $error = "Completa il profilo atleta (data di nascita e sesso) prima di iscriverti.";
} elseif ($err === 'season_missing') {
  $error = "Regolamento/stagione non configurati per questa gara. Contatta l’organizzazione.";
} elseif ($err === 'category_missing') {
  $error = "Categoria non determinabile per i tuoi dati. Contatta l’organizzazione.";
} elseif ($err === 'race_closed') {
  $error = "Iscrizioni chiuse.";
}

/**
 * ======================================================
 * POST: UN SOLO BLOCCO (manage + atleta)
 * ======================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $action = (string)($_POST['action'] ?? '');
  $reg_id = (int)($_POST['reg_id'] ?? 0);

    // annulla iscrizione (by admin/organizer)
    if ($reg_id > 0 && $action === 'cancel') {

      $stmt = $conn->prepare("
        UPDATE registrations
        SET
          status='cancelled',
          status_reason='CANCELLED_BY_ADMIN',
          payment_status='unpaid',
          confirmed_at=NULL,
          paid_total_cents=0,
          paid_at=NULL
        WHERE id=? AND race_id=?
        LIMIT 1
      ");
      $stmt->bind_param("ii", $reg_id, $race_id);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    }


  // ======================================================
  // MANAGE (organizer / admin / superuser)
  // ======================================================
  if (can_manage()) {

    // chiudi / apri gara
    if (in_array($action, ['close_race','open_race'], true)) {
      $new = ($action === 'close_race') ? 'closed' : 'open';

      $stmt = $conn->prepare("UPDATE races SET status=? WHERE id=? LIMIT 1");
      $stmt->bind_param("si", $new, $race_id);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    }

    // pagamento
    if ($reg_id > 0 && in_array($action, ['mark_paid','mark_unpaid'], true)) {

      $stmt = $conn->prepare("
        SELECT status
        FROM registrations
        WHERE id=? AND race_id=? LIMIT 1
      ");
      $stmt->bind_param("ii", $reg_id, $race_id);
      $stmt->execute();
      $cur = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$cur || ($cur['status'] ?? '') === 'cancelled') {
        $error = "Iscrizione non valida.";
      } else {

        if ($action === 'mark_paid') {
          $stmt = $conn->prepare("
            UPDATE registrations
            SET
              paid_total_cents = fee_total_cents,
              payment_status='paid',
              paid_at=NOW(),
              status = IF(status='pending','confirmed', status),
              confirmed_at = IF(status='pending', NOW(), confirmed_at),
              status_reason='OK'
            WHERE id=? AND race_id=? LIMIT 1
          ");
        } else {
          $stmt = $conn->prepare("
            UPDATE registrations
            SET
              paid_total_cents = 0,
              payment_status='unpaid',
              paid_at=NULL,
              status = IF(status='confirmed','pending', status),
              confirmed_at = IF(status='confirmed', NULL, confirmed_at),
              status_reason='PAYMENT_REQUIRED'
            WHERE id=? AND race_id=? LIMIT 1
          ");
        }

        $stmt->bind_param("ii", $reg_id, $race_id);
        $stmt->execute();
        $stmt->close();

        header("Location: race.php?id=".$race_id);
        exit;
      }
    }

    // conferma / torna pending
    if ($reg_id > 0 && in_array($action, ['confirm','pending'], true)) {

      if ($action === 'confirm') {
        $stmt = $conn->prepare("
          UPDATE registrations
          SET status='confirmed', confirmed_at=NOW()
          WHERE id=? AND race_id=? LIMIT 1
        ");
      } else {
        $stmt = $conn->prepare("
          UPDATE registrations
          SET status='pending', confirmed_at=NULL
          WHERE id=? AND race_id=? LIMIT 1
        ");
      }

      $stmt->bind_param("ii", $reg_id, $race_id);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    }
  }

  // ======================================================
  // ATLETA
  // ======================================================
  if (is_athlete()) {

    // annulla iscrizione
       if ($action === 'cancel') {
      $stmt = $conn->prepare("
        UPDATE registrations
        SET
          status='cancelled',
          status_reason='CANCELLED_BY_USER',
          payment_status='unpaid',
          confirmed_at=NULL,
          paid_total_cents=0,
          paid_at=NULL
        WHERE race_id=? AND user_id=?
        LIMIT 1
      ");
      $stmt->bind_param("ii", $race_id, $u['id']);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    }

  }

} // FINE POST

   /**
 * ======================================================
 * RENDICONTAZIONE (solo pagati)
 * ======================================================
 */
$kpi = [
  'paid_count' => 0,
  'paid_total_cents' => 0,
  'org_total_cents' => 0,
  'platform_total_cents' => 0,
  'admin_total_cents' => 0,
  'rounding_total_cents' => 0,
];

if (can_manage()) {
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
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($row) {
    $kpi['paid_count'] = (int)$row['paid_count'];
    $kpi['paid_total_cents'] = (int)$row['paid_total_cents'];
    $kpi['org_total_cents'] = (int)$row['org_total_cents'];
    $kpi['platform_total_cents'] = (int)$row['platform_total_cents'];
    $kpi['admin_total_cents'] = (int)$row['admin_total_cents'];
    $kpi['rounding_total_cents'] = (int)$row['rounding_total_cents'];
  }
}
 
        
/**
 * ======================================================
 * PARTE ATLETA: stato iscrizione personale
 * ======================================================
 */
$myReg = null;
if (is_athlete()) {
  $stmt = $conn->prepare("
    SELECT id,status,created_at,payment_status,paid_total_cents,fee_total_cents,paid_at
    FROM registrations
    WHERE race_id=? AND user_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $race_id, $u['id']);
  $stmt->execute();
  $myReg = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

/**
 * ======================================================
 * Lista iscritti (solo manage)
 * ======================================================
 */
$regs = [];
if (can_manage()) {
  $stmt = $conn->prepare("
    SELECT
      r.id,
      r.status,
      r.status_reason,
      r.created_at,
      r.confirmed_at,
      r.payment_status,
      r.fee_total_cents,
      r.paid_total_cents,
      r.paid_at,
      r.category_code,
      r.category_label,
      u.full_name,
      u.email
    FROM registrations r
    JOIN users u ON u.id = r.user_id
    WHERE r.race_id=?
    ORDER BY u.full_name ASC
  ");
  $stmt->bind_param("i", $race_id);
  $stmt->execute();
  $regs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$pageTitle = 'Gara: ' . ($race['title'] ?? '');
page_header($pageTitle);
?>

<p>
  <a href="event_detail.php?id=<?php echo (int)$race['event_id']; ?>">← Evento</a>
</p>

<p>
  <b>Organizzazione:</b> <?php echo h($race['org_name'] ?? ''); ?><br>
  <b>Evento:</b> <?php echo h($race['event_title'] ?? ''); ?><br>
  <b>Luogo:</b> <?php echo h($race['location'] ?? '-'); ?><br>
  <b>Data/Ora:</b> <?php echo h($race['start_at'] ?? '-'); ?><br>
  <b>Disciplina:</b> <?php echo h($race['discipline'] ?? '-'); ?><br>
  <b>Stato gara:</b> <?php echo h($race['status'] ?? '-'); ?>
</p>

<div style="margin:10px 0 16px;">
  <a href="race_edit.php?id=<?php echo (int)$race_id; ?>"
     style="display:inline-block;padding:8px 12px;border:1px solid #ccc;text-decoration:none;">
    ✏️ Modifica gara
  </a>
</div>


<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<?php if (can_manage()): ?>
  <div style="margin:12px 0; padding:12px; border:1px solid #ddd; border-radius:12px;">
    <b>Iscrizioni:</b>
    <?php if (($race['status'] ?? '') === 'open'): ?>
      <span style="font-weight:700;">APERTE</span>
      <form method="post" style="display:inline;margin-left:10px;">
        <input type="hidden" name="action" value="close_race">
        <button type="submit" onclick="return confirm('Chiudere le iscrizioni per questa gara?');">Chiudi iscrizioni</button>
      </form>
    <?php else: ?>
      <span style="font-weight:700;">CHIUSE</span>
      <form method="post" style="display:inline;margin-left:10px;">
        <input type="hidden" name="action" value="open_race">
        <button type="submit" onclick="return confirm('Riaprire le iscrizioni per questa gara?');">Riapri iscrizioni</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (can_manage()): ?>
  <div style="margin:12px 0; padding:12px; border:1px solid #ddd; border-radius:12px;">
    <b>Rendicontazione (solo pagati)</b><br>
    Iscritti pagati: <b><?php echo (int)$kpi['paid_count']; ?></b><br>
    Incassato: <b>€ <?php echo h(cents_to_eur((int)$kpi['paid_total_cents'])); ?></b><br>
    Organizzatore: <b>€ <?php echo h(cents_to_eur((int)$kpi['org_total_cents'])); ?></b><br>
    Piattaforma: <b>€ <?php echo h(cents_to_eur((int)$kpi['platform_total_cents'])); ?></b><br>
    Admin: <b>€ <?php echo h(cents_to_eur((int)$kpi['admin_total_cents'])); ?></b><br>
    Arrotondamenti: <b>€ <?php echo h(cents_to_eur((int)$kpi['rounding_total_cents'])); ?></b>
  </div>
<?php endif; ?>


<?php if (is_athlete()): ?>
  <h2>La tua iscrizione</h2>

  <?php if (!$myReg || ($myReg['status'] ?? '') === 'cancelled'): ?>
    <p>Non sei iscritto.</p>
    <form method="post">
      <input type="hidden" name="action" value="register">
      <button type="submit" style="padding:10px 14px;">Iscriviti</button>
    </form>
  <?php else: ?>
    <p>
      Stato: <b><?php echo h($myReg['status'] ?? ''); ?></b>
      · dal <?php echo h($myReg['created_at'] ?? ''); ?>
    </p>
    <p>
      Pagamento:
      <?php if (($myReg['payment_status'] ?? '') === 'paid'): ?>
        <b style="color:green;">Pagato</b>
      <?php else: ?>
        <b style="color:#c00;">Da pagare</b>
      <?php endif; ?>
      <?php if (!empty($myReg['fee_total_cents'])): ?>
        · Quota € <?php echo h(cents_to_eur((int)$myReg['fee_total_cents'])); ?>
      <?php endif; ?>
      <?php if (!empty($myReg['paid_total_cents'])): ?>
        · Pagato € <?php echo h(cents_to_eur((int)$myReg['paid_total_cents'])); ?>
      <?php endif; ?>
    </p>

    <form method="post" onsubmit="return confirm('Vuoi annullare l’iscrizione?');">
      <input type="hidden" name="action" value="cancel">
      <button type="submit" style="padding:10px 14px;">Annulla iscrizione</button>
    </form>
  <?php endif; ?>
<?php endif; ?>

<?php if (can_manage()): ?>
  <h2>Iscritti</h2>

  <p>
  <a href="export_race_report.php?race_id=<?php echo (int)$race_id; ?>">
    Scarica CSV rendicontazione (solo pagati)
  </a>
</p>

<p>
 <a href="export_race_regs.php?race_id=<?php echo (int)$race_id; ?>">
    Scarica CSV concorrenti (per segreteria)
  </a>
</p>

<p>
  <a href="bibs.php?race_id=<?php echo (int)$race_id; ?>">
    Assegna pettorali / Startlist cronometristi
  </a>
</p>


  <?php if (!$regs): ?>
    <p>Nessuna iscrizione.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Email</th>
          <th>Stato</th>
          <th>Motivo</th>
          <th>Categoria</th>
          <th>Quota</th>
          <th>Pagato</th>
          <th>Pagamento</th>
          <th>Data</th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($regs as $r): ?>
          <tr>
            <td><?php echo h($r['full_name'] ?? ''); ?></td>
            <td><?php echo h($r['email'] ?? ''); ?></td>
            <td><?php echo h($r['status'] ?? ''); ?></td>
            <td><?php echo h($r['status_reason'] ?? '-'); ?></td>
            <td>
              <?php
                $cc = (string)($r['category_code'] ?? '');
                $cl = (string)($r['category_label'] ?? '');
                echo $cc ? h($cc . ' — ' . $cl) : '-';
              ?>
            </td>

            <td>€ <?php echo h(cents_to_eur((int)($r['fee_total_cents'] ?? 0))); ?></td>
            <td>€ <?php echo h(cents_to_eur((int)($r['paid_total_cents'] ?? 0))); ?></td>

            <td>
              <?php if (($r['payment_status'] ?? '') === 'paid'): ?>
                <b style="color:green;">Pagato</b>
                <?php if (!empty($r['paid_at'])): ?>
                  <br><small><?php echo h($r['paid_at']); ?></small>
                <?php endif; ?>
              <?php else: ?>
                <b style="color:#c00;">Non pagato</b>
              <?php endif; ?>
            </td>

            <td><?php echo h($r['created_at'] ?? ''); ?></td>

            <td>
              <?php if (($r['status'] ?? '') !== 'confirmed'): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="confirm">
                  <button type="submit">Conferma</button>
                </form>
              <?php endif; ?>

              <?php if (($r['status'] ?? '') !== 'pending'): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="pending">
                  <button type="submit">Pending</button>
                </form>
              <?php endif; ?>

              <?php if (($r['status'] ?? '') !== 'cancelled'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Annullare iscrizione?');">
                  <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="cancel">
                  <button type="submit">Annulla</button>
                </form>
              <?php endif; ?>

              <?php if (($r['payment_status'] ?? '') !== 'paid'): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="mark_paid">
                  <button type="submit">Segna pagato</button>
                </form>
              <?php else: ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="action" value="mark_unpaid">
                  <button type="submit">Annulla pagamento</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<?php page_footer(); ?>
