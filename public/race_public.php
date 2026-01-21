<?php
// public/race_public.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/categories.php';
require_once __DIR__ . '/../app/includes/fees.php';

$conn = db($config);

/**
 * Helper IT
 */
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
    'PROFILE_INCOMPLETE'     => 'Profilo atleta incompleto',
    'CANCELLED_BY_USER'      => 'Annullata da te',
    default                  => $r,
  };
}

function reason_hint(string $r): string {
  return match ($r) {
    'PAYMENT_REQUIRED'       => 'Fai segnare il pagamento dall’organizzazione: solo dopo comparirai nell’elenco pubblico.',
    'MEMBERSHIP_NOT_ALLOWED' => 'Il tuo tesseramento non risulta ammesso per questa gara. Contatta l’organizzazione.',
    'CERT_MISSING'           => 'Inserisci il certificato medico nel profilo atleta.',
    'CERT_EXPIRED'           => 'Il certificato medico risulta scaduto. Aggiornalo nel profilo atleta.',
    'PROFILE_INCOMPLETE'     => 'Completa il profilo atleta (data di nascita e sesso).',
    default                  => '',
  };
}


function it_datetime(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if (!$ts) return $dt;
  return date('d/m/Y H:i', $ts);
}

/**
 * Calcolo fee reale per gara.
 * NB: base fee viene letto da riga races (preferenza: fee_cents, fallback: base_fee_cents).
 */
function compute_fees_for_race(mysqli $conn, array $race): array {
  $race_fee_cents = 0;

  if (isset($race['fee_cents'])) {
    $race_fee_cents = (int)$race['fee_cents'];
  } elseif (isset($race['base_fee_cents'])) {
    $race_fee_cents = (int)$race['base_fee_cents'];
  }

  // Platform settings (global)
  $platform = get_platform_settings($conn);

  // Admin settings (opzionale, se la gara punta a un admin di riferimento)
  $admin_fee_settings = [
    'fee_type' => 'fixed',
    'fee_value_cents' => 0,
    'fee_value_bp' => null,
    'round_to_cents' => (int)($platform['round_to_cents'] ?? 50),
    'iban' => null,
  ];

  $ref_admin_id = (int)($race['ref_admin_id'] ?? 0);
  if ($ref_admin_id > 0) {
    $admin_fee_settings = get_admin_settings($conn, $ref_admin_id);
    if (empty($admin_fee_settings['round_to_cents'])) {
      $admin_fee_settings['round_to_cents'] = (int)($platform['round_to_cents'] ?? 50);
    }
  }

  $fees = calc_fees_total($race_fee_cents, $platform, $admin_fee_settings);

  // organizer_net: per ora = base (poi lo colleghiamo alla logica reale delle fee platform/admin)
  $organizer_net = $fees['race_fee_cents'];

  return [
    'base_fee_cents'       => $fees['race_fee_cents'],
    'platform_fee_cents'   => $fees['platform_fee_cents'],
    'admin_fee_cents'      => $fees['admin_fee_cents'],

    'fee_race_cents'       => $fees['race_fee_cents'],
    'fee_platform_cents'   => $fees['platform_fee_cents'],
    'fee_admin_cents'      => $fees['admin_fee_cents'],

    'fee_total_cents'      => $fees['total_cents'],

    'rounding_delta_cents' => 0,
    'organizer_net_cents'  => $organizer_net,
  ];
}

function it_payment(string $p): string {
  return match ($p) {
    'paid'   => 'Pagato',
    'unpaid' => 'Non pagato',
    default  => $p,
  };
}

function it_race_status(string $s): string {
  return match ($s) {
    'open'   => 'Iscrizioni aperte',
    'closed' => 'Iscrizioni chiuse',
    default  => $s,
  };
}


// ======================================================
// LOAD RACE
// ======================================================
$race_id = (int)($_GET['id'] ?? 0);
if ($race_id <= 0) { header("Location: calendar.php"); exit; }

// Carico gara + evento + org (PUBBLICO)
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.id AS event_id, o.name AS org_name, e.status AS event_status
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE r.id = ? AND e.status = 'published'
  LIMIT 1
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata (o evento non pubblicato).");
}

// Ruolo/loggato
$role   = function_exists('current_role') ? (string)current_role() : '';
$logged = !empty($role);

$u = null;
if ($logged && function_exists('auth_user')) {
  $u = auth_user();
}

$error = '';

// Iscrizioni consentite solo se gara open
$canRegister = (($race['status'] ?? '') === 'open');

// ======================================================
// Divisioni gara (solo lettura)
// ======================================================
$raceDivisions = [];
$hasDivisions  = false;

$stmt = $conn->prepare("
  SELECT id, code, label
  FROM race_divisions
  WHERE race_id = ? AND is_active = 1
  ORDER BY sort_order ASC, label ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$raceDivisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hasDivisions = !empty($raceDivisions);


// Stato iscrizione dell'utente (solo atleta)
$myReg = null;
if (($role ?? '') === 'athlete' && !empty($u['id'])) {
  $stmt = $conn->prepare("
    SELECT id, status, status_reason, payment_status, created_at,
       division_id, division_code, division_label
FROM registrations
WHERE race_id=? AND user_id=?
LIMIT 1
  ");
  $stmt->bind_param("ii", $race_id, $u['id']);
  $stmt->execute();
  $myReg = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

$profile_ok = false;

if (($role ?? '') === 'athlete' && !empty($u['id'])) {
  $stmt = $conn->prepare("SELECT birth_date, gender FROM athlete_profile WHERE user_id=? LIMIT 1");
  $stmt->bind_param("i", $u['id']);
  $stmt->execute();
  $ap = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $bd = (string)($ap['birth_date'] ?? '');
  $g  = strtoupper((string)($ap['gender'] ?? ''));

  $profile_ok = ($bd !== '' && ($g === 'M' || $g === 'F'));
}


// ======================================================
// POST ATLETA: register / cancel (solo se open)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role ?? '') === 'athlete' && !empty($u['id'])) {

  if (!$canRegister) {
    $error = "Iscrizioni chiuse.";
  } else {

    $action = (string)($_POST['action'] ?? '');

    // -------------------------
    // REGISTER
    // -------------------------
    if ($action === 'register') {
      try {

        $st_now = (string)($myReg['status'] ?? '');
        if ($myReg && $st_now !== '' && $st_now !== 'cancelled') {
          throw new RuntimeException("Sei già iscritto a questa gara.");
        }

        // 1) Determina season_id (robusta)
        $season_id   = (int)($race['rulebook_season_id'] ?? 0);
        $rulebook_id = (int)($race['rulebook_id'] ?? 0);
        if ($rulebook_id <= 0) $rulebook_id = 2;

        if ($season_id <= 0) {
          $stmt = $conn->prepare("SELECT id FROM rulebook_seasons WHERE rulebook_id=? AND is_active=1 LIMIT 1");
          $stmt->bind_param("i", $rulebook_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          $season_id = (int)($row['id'] ?? 0);
        }
        if ($season_id <= 0) {
          $stmt = $conn->prepare("SELECT id FROM rulebook_seasons WHERE rulebook_id=? ORDER BY season_year DESC LIMIT 1");
          $stmt->bind_param("i", $rulebook_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          $season_id = (int)($row['id'] ?? 0);
        }
        if ($season_id <= 0) {
          throw new RuntimeException("Stagione regolamento non trovata.");
        }

        // 2) Athlete profile
        $stmt = $conn->prepare("SELECT birth_date, gender FROM athlete_profile WHERE user_id=? LIMIT 1");
        $stmt->bind_param("i", $u['id']);
        $stmt->execute();
        $ap = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $birth_date = (string)($ap['birth_date'] ?? '');
        $gender     = (string)($ap['gender'] ?? '');
        if ($birth_date === '' || $gender === '') {
  throw new RuntimeException("PROFILE_INCOMPLETE");
}


        // 3) Categoria (salvo code + label)
        // 3) Categoria (code + label reale)
$cat_code = get_category_for_athlete_by_season($conn, $season_id, $birth_date, $gender);
if (!$cat_code) {
  throw new RuntimeException("Categoria non trovata.");
}

$cat_code_db  = (string)$cat_code;
$cat_label_db = $cat_code_db;

$stmt = $conn->prepare("SELECT rulebook_id, season_year FROM rulebook_seasons WHERE id=? LIMIT 1");
$stmt->bind_param("i", $season_id);
$stmt->execute();
$seasonRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($seasonRow) {
  $rb_id = (int)$seasonRow['rulebook_id'];
  $yr    = (int)$seasonRow['season_year'];

  $stmt = $conn->prepare("
    SELECT name
    FROM rulebook_categories
    WHERE rulebook_id=? AND season_year=? AND code=?
    LIMIT 1
  ");
  $stmt->bind_param("iis", $rb_id, $yr, $cat_code_db);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!empty($row['name'])) {
    $cat_label_db = (string)$row['name'];
  }
}


        // 4) Fee reali
        $fees = compute_fees_for_race($conn, $race);

        $base_fee_cents_db       = (int)$fees['base_fee_cents'];
        $platform_fee_cents_db   = (int)$fees['platform_fee_cents'];
        $admin_fee_cents_db      = (int)$fees['admin_fee_cents'];
        $rounding_delta_cents_db = (int)($fees['rounding_delta_cents'] ?? 0);
        $fee_total_cents_db      = (int)$fees['fee_total_cents'];
        $organizer_net_cents_db  = (int)($fees['organizer_net_cents'] ?? $base_fee_cents_db);

        // nuovi campi (sinonimi)
        $fee_race_cents_db       = (int)$fees['fee_race_cents'];
        $fee_platform_cents_db   = (int)$fees['fee_platform_cents'];
        $fee_admin_cents_db      = (int)$fees['fee_admin_cents'];

        // 5) Division (default: NULL)
        $division_id_db    = null;
        $division_code_db  = null;
        $division_label_db = null;


        // ======================================================
        // STEP 3 (SENZA STRIPE): l'iscrizione parte SEMPRE pending + unpaid
        // ======================================================
        $status_db         = 'pending';
        $status_reason_db  = 'PAYMENT_REQUIRED';
        $payment_status_db = 'unpaid';
        $confirmed_at_db   = null;

        // Pagamento sempre a zero in fase di iscrizione
        $paid_total_cents_db = 0;

// 5) Division scelta (solo se la gara ha division)
if ($hasDivisions) {
  $division_id_in = (int)($_POST['division_id'] ?? 0);
  if ($division_id_in <= 0) {
    throw new RuntimeException("Seleziona una divisione.");
  }

  // verifica che appartenga alla gara ed è attiva
  $stmt = $conn->prepare("
    SELECT id, code, label
    FROM race_divisions
    WHERE id=? AND race_id=? AND is_active=1
    LIMIT 1
  ");
  $stmt->bind_param("ii", $division_id_in, $race_id);
  $stmt->execute();
  $d = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$d) {
    throw new RuntimeException("Divisione non valida.");
  }

  $division_id_db    = (int)$d['id'];
  $division_code_db  = (string)$d['code'];
  $division_label_db = (string)$d['label'];
}

        // 6) UPSERT
        $stmt = $conn->prepare("
          INSERT INTO registrations (
            race_id, user_id,
            status, status_reason, payment_status, confirmed_at,
            base_fee_cents, platform_fee_cents, admin_fee_cents,
            rounding_delta_cents, fee_total_cents, organizer_net_cents,
            paid_total_cents,
            fee_race_cents, fee_platform_cents, fee_admin_cents,
            category_code, category_label,
            division_id, division_code, division_label
            ) VALUES (
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?,
            ?, ?, ?,
            ?, ?
            , ?, ?, ?
          )
          ON DUPLICATE KEY UPDATE
  status = VALUES(status),
  status_reason = VALUES(status_reason),
  payment_status = VALUES(payment_status),
  confirmed_at = VALUES(confirmed_at),
  base_fee_cents = VALUES(base_fee_cents),
  platform_fee_cents = VALUES(platform_fee_cents),
  admin_fee_cents = VALUES(admin_fee_cents),
  rounding_delta_cents = VALUES(rounding_delta_cents),
  fee_total_cents = VALUES(fee_total_cents),
  organizer_net_cents = VALUES(organizer_net_cents),
  paid_total_cents = VALUES(paid_total_cents),
  fee_race_cents = VALUES(fee_race_cents),
  fee_platform_cents = VALUES(fee_platform_cents),
  fee_admin_cents = VALUES(fee_admin_cents),
  category_code = VALUES(category_code),
  category_label = VALUES(category_label),
  division_id = VALUES(division_id),
  division_code = VALUES(division_code),
  division_label = VALUES(division_label)

        ");
        if (!$stmt) {
          throw new RuntimeException("Errore DB (prepare): " . h($conn->error));
        }

        // tipi: ii + ssss + 10i + ss  = 18 parametri
        $stmt->bind_param(
  "iissssiiiiiiiiiississ",
  $race_id,
  $u['id'],
  $status_db,
  $status_reason_db,
  $payment_status_db,
  $confirmed_at_db,
  $base_fee_cents_db,
  $platform_fee_cents_db,
  $admin_fee_cents_db,
  $rounding_delta_cents_db,
  $fee_total_cents_db,
  $organizer_net_cents_db,
  $paid_total_cents_db,
  $fee_race_cents_db,
  $fee_platform_cents_db,
  $fee_admin_cents_db,
  $cat_code_db,
  $cat_label_db,
  $division_id_db,
  $division_code_db,
  $division_label_db
);


        if (!$stmt->execute()) {
          $msg = $stmt->error ?: $conn->error;
          $stmt->close();
          throw new RuntimeException("Errore DB (execute): " . h($msg));
        }
        $stmt->close();

        header("Location: race_public.php?id=" . $race_id);
        exit;

      } catch (Throwable $e) {
  $msg = (string)$e->getMessage();
  if ($msg === 'PROFILE_INCOMPLETE') {
    $error = "Profilo atleta incompleto: completa data di nascita e sesso in 'Profilo atleta' e riprova.";
  } else {
    $error = "Iscrizione non completata: " . $msg;
  }
}

    }

    // -------------------------
// CANCEL
// -------------------------
if ($action === 'cancel') {
  try {
    // Step 3: annullamento deve anche resettare pagamento/confirmed
    $stmt = $conn->prepare("
      UPDATE registrations
      SET
        status = 'cancelled',
        status_reason = 'CANCELLED_BY_USER',
        payment_status = 'unpaid',
        confirmed_at = NULL,
        paid_total_cents = 0,
        paid_at = NULL
      WHERE race_id = ? AND user_id = ?
      LIMIT 1
    ");
    $stmt->bind_param("ii", $race_id, $u['id']);
    $stmt->execute();
    $stmt->close();

    header("Location: race_public.php?id=" . $race_id);
    exit;

  } catch (Throwable $e) {
    $error = "Annullamento non completato: " . $e->getMessage();
  }
}

  }
}

// ======================================================
// Iscritti pubblici: solo confermati + pagati
// ======================================================
$publicRegs = [];
$stmt = $conn->prepare("
  SELECT
    COALESCE(ap.first_name, '') AS first_name,
    COALESCE(ap.last_name,  '') AS last_name,
    COALESCE(ap.club_name,  '') AS club_name,
    COALESCE(ap.city,       '') AS city
  FROM registrations r
  LEFT JOIN athlete_profile ap ON ap.user_id = r.user_id
  WHERE r.race_id = ? AND r.status = 'confirmed' AND r.payment_status = 'paid'
  ORDER BY ap.last_name ASC, ap.first_name ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$publicRegs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Quota (preview)
$fees_preview = compute_fees_for_race($conn, $race);
$fee_total_cents_preview = (int)$fees_preview['fee_total_cents'];

$pageTitle = 'Gara: ' . ($race['title'] ?? '');
page_header($pageTitle);
?>

<p>
  <a href="event_public.php?id=<?php echo (int)$race['event_id']; ?>">← Evento</a>
</p>

<p>
  <b>Organizzazione:</b> <?php echo h($race['org_name'] ?? ''); ?><br>
  <b>Evento:</b> <?php echo h($race['event_title'] ?? ''); ?><br>
  <b>Luogo:</b> <?php echo h($race['location'] ?? '-'); ?><br>
  <b>Data/Ora:</b> <?php echo h(it_datetime($race['start_at'] ?? null)); ?><br>
  <b>Disciplina:</b> <?php echo h($race['discipline'] ?? '-'); ?><br>
  <b>Stato gara:</b> <?php echo h(it_race_status((string)($race['status'] ?? ''))); ?>
</p>

<p><b>Quota iscrizione:</b> € <?php echo h(cents_to_eur($fee_total_cents_preview)); ?></p>
<p><small>La conferma dell’iscrizione avviene dopo la verifica del pagamento.</small></p>

<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<h2>Iscritti confermati</h2>


<?php if (!$publicRegs): ?>
  <p>Al momento non ci sono iscritti confermati.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Cognome</th>
        <th>Team / Club</th>
        <th>Città</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($publicRegs as $r): ?>
        <tr>
          <td><?php echo h($r['first_name'] ?? ''); ?></td>
          <td><?php echo h($r['last_name'] ?? ''); ?></td>
          <td><?php echo h($r['club_name'] ?? ''); ?></td>
          <td><?php echo h($r['city'] ?? ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Iscrizione</h2>

<?php if (!$logged): ?>
  <p>Per iscriverti devi accedere.</p>
  <p><a href="login.php">Accedi</a></p>

<?php elseif (($role ?? '') !== 'athlete'): ?>
  <p>Sei loggato come <b><?php echo h($role); ?></b>. L’iscrizione è disponibile solo per account atleta.</p>

<?php else: ?>
  <?php if (!$canRegister): ?>
    <p><b>Iscrizioni chiuse.</b></p>

  <?php elseif (!$profile_ok): ?>
    <p><b>Profilo atleta incompleto.</b></p>
    <p>Completa data di nascita e sesso per poterti iscrivere.</p>
    <p><a href="athlete_profile.php">Completa profilo atleta</a></p>

  <?php else: ?>


    <?php $st = (string)($myReg['status'] ?? ''); ?>

    <?php if (!$myReg || $st === '' || $st === 'cancelled'): ?>
      <p>Non sei iscritto.</p>
      <form method="post">
        <input type="hidden" name="action" value="register">

<?php if ($hasDivisions): ?>
  <div style="margin:10px 0;">
    <label for="division_id"><b>Divisione</b></label><br>
    <select name="division_id" id="division_id" required style="padding:8px 10px; min-width:260px;">
      <option value="">Seleziona…</option>
      <?php foreach ($raceDivisions as $d): ?>
        <option value="<?php echo (int)$d['id']; ?>">
          <?php echo h((string)$d['label']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
<?php endif; ?>


        <button type="submit" style="padding:10px 14px;">Iscriviti</button>
      </form>

    <?php else: ?>
      <p>
        Stato: <b><?php echo h(it_status($st)); ?></b>
        <?php if (!empty($myReg['division_label'])): ?>
  · Divisione: <b><?php echo h((string)$myReg['division_label']); ?></b>
<?php endif; ?>
        <?php if (!empty($myReg['status_reason'])): ?>
          · <?php echo h(it_reason((string)$myReg['status_reason'])); ?>
        <?php endif; ?>
        <?php
  $hint = '';
  if (!empty($myReg['status_reason'])) {
    $hint = reason_hint((string)$myReg['status_reason']);
  }
?>
<?php if ($hint): ?>
  <br><small><?php echo h($hint); ?></small>
<?php endif; ?>

        <?php if (!empty($myReg['payment_status'])): ?>
          · <?php echo h(it_payment((string)$myReg['payment_status'])); ?>
        <?php endif; ?>
        · dal <?php echo h(it_datetime($myReg['created_at'] ?? null)); ?>
      </p>

      <?php if ($st === 'pending'): ?>
        <p style="margin-top:6px;">
          La tua iscrizione è stata registrata ma non è ancora definitiva.<br>
          <small>L’iscrizione sarà visibile nell’elenco pubblico solo dopo la conferma del pagamento.</small>
        </p>
      <?php elseif ($st === 'blocked'): ?>
        <p style="margin-top:6px;">Iscrizione bloccata: serve sistemare i requisiti (certificato / tesseramento).</p>
      <?php endif; ?>

      <?php if ($st === 'pending' || $st === 'confirmed'): ?>
        <form method="post" onsubmit="return confirm('Vuoi annullare l’iscrizione?');">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" style="padding:10px 14px;">Annulla iscrizione</button>
        </form>
      <?php endif; ?>

    <?php endif; ?>

  <?php endif; ?>
<?php endif; ?>

<?php page_footer(); ?>
