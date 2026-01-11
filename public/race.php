<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();

$u = auth_user();
$conn = db($config);

$race_id = (int)($_GET['id'] ?? 0);
if ($race_id <= 0) { header("Location: events.php"); exit; }

// Carico gara + evento + org (accesso chiuso: serve membership su organization_users)
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.organization_id, o.name AS org_name
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE r.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $race_id, $u['id']);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata o accesso non consentito.");
}

$error = '';

// ======================================================
// POST: UN SOLO BLOCCO (gara + iscrizioni + pagamenti)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can_manage()) {
  $action = $_POST['action'] ?? '';
  $reg_id = (int)($_POST['reg_id'] ?? 0);

  // 1) chiudi/riapri iscrizioni gara
  if (in_array($action, ['close_race','open_race'], true)) {
    $new = ($action === 'close_race') ? 'closed' : 'open';

    $stmt = $conn->prepare("UPDATE races SET status=? WHERE id=? LIMIT 1");
    $stmt->bind_param("si", $new, $race_id);
    $stmt->execute();
    $stmt->close();

    header("Location: race.php?id=".$race_id);
    exit;
  }

  // 2) toggle pagamento
  if ($reg_id > 0 && in_array($action, ['mark_paid','mark_unpaid'], true)) {
    if ($action === 'mark_paid') {
      $stmt = $conn->prepare("
        UPDATE registrations
        SET payment_status='paid', paid_at=NOW()
        WHERE id=? AND race_id=? LIMIT 1
      ");
    } else {
      $stmt = $conn->prepare("
        UPDATE registrations
        SET payment_status='unpaid', paid_at=NULL
        WHERE id=? AND race_id=? LIMIT 1
      ");
    }
    $stmt->bind_param("ii", $reg_id, $race_id);
    $stmt->execute();
    $stmt->close();

    header("Location: race.php?id=".$race_id);
    exit;
  }

  // 3) azioni su iscrizione (confirm/pending/cancel)
  if ($reg_id > 0 && in_array($action, ['confirm','cancel','pending'], true)) {
    $newStatus = ($action === 'confirm') ? 'confirmed' : (($action === 'cancel') ? 'cancelled' : 'pending');

    $stmt = $conn->prepare("UPDATE registrations SET status=? WHERE id=? AND race_id=? LIMIT 1");
    $stmt->bind_param("sii", $newStatus, $reg_id, $race_id);
    $stmt->execute();
    $stmt->close();

    header("Location: race.php?id=".$race_id);
    exit;
  }
}

// ======================================================
// PARTE ATLETA (se vuoi tenerla anche qui)
// ======================================================
$myReg = null;
if (is_athlete()) {
  $stmt = $conn->prepare("SELECT id,status,created_at,payment_status,paid_total_cents,paid_at FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
  $stmt->bind_param("ii", $race_id, $u['id']);
  $stmt->execute();
  $myReg = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// Azioni atleta: register / cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_athlete()) {
  $action = $_POST['action'] ?? '';

  if ($action === 'register') {
    try {
      $status = 'pending';
      $stmt = $conn->prepare("INSERT INTO registrations (race_id,user_id,status) VALUES (?,?,?)");
      $stmt->bind_param("iis", $race_id, $u['id'], $status);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    } catch (Throwable $e) {
      $error = "Non riesco a completare l’iscrizione.";
    }
  }

  if ($action === 'cancel') {
    try {
      $status = 'cancelled';
      $stmt = $conn->prepare("UPDATE registrations SET status=? WHERE race_id=? AND user_id=? LIMIT 1");
      $stmt->bind_param("sii", $status, $race_id, $u['id']);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    } catch (Throwable $e) {
      $error = "Non riesco ad annullare l’iscrizione.";
    }
  }
}

// ======================================================
// Lista iscritti (solo manage)
// ======================================================

$regs = [];
if (can_manage()) {
  $stmt = $conn->prepare("
    SELECT
      r.id,
      r.status,
      r.created_at,
      r.payment_status,
      r.paid_total_cents,
      r.paid_at,
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
      <?php if (!empty($myReg['paid_total_cents'])): ?>
        · Quota € <?php echo h(cents_to_eur((int)$myReg['paid_total_cents'])); ?>
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
  <a href="export_race_regs.php?race_id=<?php echo (int)$race_id; ?>">Scarica CSV iscritti (confermati)</a>
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
          <th>Quota</th>
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
