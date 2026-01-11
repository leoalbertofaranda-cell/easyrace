<?php
// public/race.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/categories.php';

require_login();

$u = auth_user();
$conn = db($config);

// Fee provvisorie (poi le rendiamo configurabili)
const PLATFORM_FEE_CENTS = 100; // €1,00
const ADMIN_FEE_CENTS    = 0;   // €0,00

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
if ($race_id <= 0) { header("Location: events.php"); exit; }

// Carico gara + evento + org (accesso chiuso: serve membership su organization_users)
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.id AS event_id, e.organization_id, o.name AS org_name
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

  // --- MANAGE (organizer/admin/superuser) ---
  if (can_manage()) {

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

    // 2) toggle pagamento (con paid_total_cents)
    if ($reg_id > 0 && in_array($action, ['mark_paid','mark_unpaid'], true)) {

      if ($action === 'mark_paid') {
        $stmt = $conn->prepare("
          UPDATE registrations
          SET
            paid_total_cents = fee_total_cents,
            payment_status='paid',
            paid_at=NOW()
          WHERE id=? AND race_id=? LIMIT 1
        ");
      } else {
        $stmt = $conn->prepare("
          UPDATE registrations
          SET
            paid_total_cents = 0,
            payment_status='unpaid',
            paid_at=NULL
          WHERE id=? AND race_id=? LIMIT 1
        ");
      }

      $stmt->bind_param("ii", $reg_id, $race_id);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    }

    // 3) azioni su iscrizione (confirm/pending/cancel) con confirmed_at
    if ($reg_id > 0 && in_array($action, ['confirm','cancel','pending'], true)) {

      if ($action === 'confirm') {
        $stmt = $conn->prepare("
          UPDATE registrations
          SET status='confirmed',
              confirmed_at=NOW()
          WHERE id=? AND race_id=? LIMIT 1
        ");
        $stmt->bind_param("ii", $reg_id, $race_id);
        $stmt->execute();
        $stmt->close();

        header("Location: race.php?id=".$race_id);
        exit;
      }

      if ($action === 'pending') {
        $stmt = $conn->prepare("
          UPDATE registrations
          SET status='pending',
              confirmed_at=NULL
          WHERE id=? AND race_id=? LIMIT 1
        ");
        $stmt->bind_param("ii", $reg_id, $race_id);
        $stmt->execute();
        $stmt->close();

        header("Location: race.php?id=".$race_id);
        exit;
      }

      // cancel
      $stmt = $conn->prepare("
        UPDATE registrations
        SET status='cancelled'
        WHERE id=? AND race_id=? LIMIT 1
      ");
      $stmt->bind_param("ii", $reg_id, $race_id);
      $stmt->execute();
      $stmt->close();

      header("Location: race.php?id=".$race_id);
      exit;
    }
  }

  // --- ATLETA ---
  if (is_athlete()) {

    // CANCEL atleta
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

    // REGISTER atleta
    if ($action === 'register') {
      try {
        // blocca se gara non open
        if (($race['status'] ?? '') !== 'open') {
          header("Location: race.php?id=".$race_id."&err=race_closed");
          exit;
        }

        // evita doppie iscrizioni (se esiste e non è cancelled)
        $stmt = $conn->prepare("SELECT id,status FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
        $stmt->bind_param("ii", $race_id, $u['id']);
        $stmt->execute();
        $existingReg = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existingReg && ($existingReg['status'] ?? '') !== 'cancelled') {
          throw new RuntimeException("Sei già iscritto a questa gara.");
        }

        // ----------------------------------------------------
        // Profilo atleta: fonte unica = athlete_profile
        // ----------------------------------------------------
        $stmt = $conn->prepare("SELECT birth_date, gender FROM athlete_profile WHERE user_id=? LIMIT 1");
        if (!$stmt) throw new RuntimeException("Errore DB (prepare atleta): ".h($conn->error));
        $stmt->bind_param("i", $u['id']);
        $stmt->execute();
        $ap = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $birth_date = (string)($ap['birth_date'] ?? '');
        $gender     = strtoupper((string)($ap['gender'] ?? ''));

        if ($birth_date === '' || ($gender !== 'M' && $gender !== 'F')) {
          header("Location: race.php?id=".$race_id."&err=profile_required");
          exit;
        }

        // ----------------------------------------------------
        // Determinazione rulebook_season_id (robusta)
        // ----------------------------------------------------
        $season_id   = (int)($race['rulebook_season_id'] ?? 0);
        $rulebook_id = (int)($race['rulebook_id'] ?? 0);

        // fallback fisso: FCI (dal tuo DB = 2) se non presente su gara
        if ($rulebook_id <= 0) $rulebook_id = 2;

        // 1) stagione attiva
        if ($season_id <= 0) {
          $stmt = $conn->prepare("
            SELECT id
            FROM rulebook_seasons
            WHERE rulebook_id = ? AND is_active = 1
            LIMIT 1
          ");
          $stmt->bind_param("i", $rulebook_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          $season_id = (int)($row['id'] ?? 0);
        }

        // 2) stagione per anno gara
        if ($season_id <= 0) {
          $startAt = (string)($race['start_at'] ?? '');
          if ($startAt !== '') {
            $raceYear = (int)date('Y', strtotime($startAt));
            $stmt = $conn->prepare("
              SELECT id
              FROM rulebook_seasons
              WHERE rulebook_id = ? AND season_year = ?
              LIMIT 1
            ");
            $stmt->bind_param("ii", $rulebook_id, $raceYear);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $season_id = (int)($row['id'] ?? 0);
          }
        }

        // 3) ultima stagione disponibile
        if ($season_id <= 0) {
          $stmt = $conn->prepare("
            SELECT id
            FROM rulebook_seasons
            WHERE rulebook_id = ?
            ORDER BY season_year DESC
            LIMIT 1
          ");
          $stmt->bind_param("i", $rulebook_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          $season_id = (int)($row['id'] ?? 0);
        }

        if ($season_id <= 0) {
          header("Location: race.php?id=".$race_id."&err=season_missing");
          exit;
        }

        // ----------------------------------------------------
        // Calcolo categoria (code) + recupero id/label
        // ----------------------------------------------------
        $cat_code = get_category_for_athlete_by_season($conn, $season_id, $birth_date, $gender);
        if (!$cat_code) {
          header("Location: race.php?id=".$race_id."&err=category_missing");
          exit;
        }

        $stmt = $conn->prepare("SELECT rulebook_id, season_year FROM rulebook_seasons WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $season_id);
        $stmt->execute();
        $seasonRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$seasonRow) {
          header("Location: race.php?id=".$race_id."&err=season_missing");
          exit;
        }

        $rulebook_id = (int)$seasonRow['rulebook_id'];
        $season_year = (int)$seasonRow['season_year'];

        $stmt = $conn->prepare("
          SELECT id, name
          FROM rulebook_categories
          WHERE rulebook_id = ? AND season_year = ? AND code = ?
          LIMIT 1
        ");
        $stmt->bind_param("iis", $rulebook_id, $season_year, $cat_code);
        $stmt->execute();
        $catRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cat_id    = (int)($catRow['id'] ?? 0);
        $cat_label = (string)($catRow['name'] ?? $cat_code);

        // ----------------------------------------------------
        // Quote snapshot
        // ----------------------------------------------------
        $base_fee_cents = (int)($race['base_fee_cents'] ?? 0);

        $platform_fee_cents = (int)PLATFORM_FEE_CENTS;
        $admin_fee_cents    = (int)ADMIN_FEE_CENTS;

        $subtotal = $base_fee_cents + $platform_fee_cents + $admin_fee_cents;
        $fee_total_cents = round_up_to_50_cents($subtotal);
        $rounding_delta_cents = $fee_total_cents - $subtotal;

        $organizer_net_cents = $base_fee_cents;

        // insert snapshot
        $status = 'pending';
        $payment_status = 'unpaid';
        $paid_total_cents = 0;

        $stmt = $conn->prepare("
          INSERT INTO registrations (
            race_id, user_id,
            status,
            category_id, category_code, category_label,
            base_fee_cents, platform_fee_cents, admin_fee_cents,
            rounding_delta_cents, fee_total_cents, organizer_net_cents,
            payment_status, paid_total_cents
          ) VALUES (
            ?, ?,
            ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?
          )
        ");
        if (!$stmt) {
          throw new RuntimeException("Errore DB (prepare): " . h($conn->error));
        }

        $stmt->bind_param(
          "iisissiiiiiisi",
          $race_id, $u['id'],
          $status,
          $cat_id, $cat_code, $cat_label,
          $base_fee_cents, $platform_fee_cents, $admin_fee_cents,
          $rounding_delta_cents, $fee_total_cents, $organizer_net_cents,
          $payment_status, $paid_total_cents
        );

        if (!$stmt->execute()) {
          $msg = $stmt->error ?: $conn->error;
          $stmt->close();
          throw new RuntimeException("Errore DB (execute): " . h($msg));
        }
        $stmt->close();

        header("Location: race.php?id=".$race_id);
        exit;

      } catch (Throwable $e) {
        $error = "Non riesco a completare l’iscrizione: " . $e->getMessage();
      }
    }
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
