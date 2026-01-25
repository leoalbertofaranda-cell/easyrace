<?php
// public/race_public.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/categories.php';
require_once __DIR__ . '/../app/includes/fees.php';
require_once __DIR__ . '/../app/includes/audit.php';

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

function it_myreg_help(string $status, string $payment): string {
  // Regola di prodotto (come già implementato):
  // - quando ti iscrivi -> pending + unpaid
  // - l'organizzatore/admin conferma
  // - solo confirmed+paid appare nella lista pubblica
  if ($status === 'cancelled') return "Iscrizione annullata. Puoi iscriverti di nuovo finché le iscrizioni sono aperte.";
  if ($status === 'blocked')   return "Iscrizione bloccata: contatta l’organizzazione.";
  if ($status === 'pending')   return "Iscrizione registrata. In attesa di verifica e conferma da parte dell’organizzazione.";
  if ($status === 'confirmed' && $payment !== 'paid') return "Iscrizione approvata, ma risulta non pagata: completa il pagamento secondo le istruzioni dell’organizzazione.";
  if ($status === 'confirmed' && $payment === 'paid') return "Iscrizione confermata e pagata: sei presente nell’elenco pubblico dei partecipanti.";
  return "Stato iscrizione aggiornato.";
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
  SELECT r.*, e.title AS event_title, e.id AS event_id, o.name AS org_name, e.status AS event_status, e.organization_id
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

// ======================================================
// Stato iscrizione dell'utente (solo atleta)
// ======================================================
$myReg = null;
if (($role ?? '') === 'athlete' && !empty($u['id'])) {
  $stmt = $conn->prepare("
  SELECT
    id,
    user_id AS reg_user_id,
    status, status_reason, payment_status, created_at,
    fee_tier_label,
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

// ======================================================
// Profilo atleta completo?
// ======================================================
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

        // 1) season_id robusto
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

        // 2) profilo atleta (fonte unica)
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

        // 3) categoria (code + label)
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

        // 4) fee (UNA SOLA VERITÀ)
        [$tier_code_db, $tier_label_db, $base_fee_cents] = race_fee_pick_tier($race);

        $platform_settings = get_platform_settings($conn);
        $admin_user_id     = (int)($race['admin_user_id'] ?? 0);
        $admin_settings    = get_admin_settings($conn, $admin_user_id);

        $fees = calc_fees_total((int)$base_fee_cents, $platform_settings, $admin_settings);

        $base_fee_cents_db       = (int)($fees['race_fee_cents'] ?? 0);
        $platform_fee_cents_db   = (int)($fees['platform_fee_cents'] ?? 0);
        $admin_fee_cents_db      = (int)($fees['admin_fee_cents'] ?? 0);
        $fee_total_cents_db      = (int)($fees['total_cents'] ?? 0);
        $rounding_delta_cents_db = (int)($fees['rounding_delta_cents'] ?? 0);

        // organizer net: per ora = base fee
        $organizer_net_cents_db  = $base_fee_cents_db;

        // sinonimi
        $fee_race_cents_db       = $base_fee_cents_db;
        $fee_platform_cents_db   = $platform_fee_cents_db;
        $fee_admin_cents_db      = $admin_fee_cents_db;

        // 5) division (default NULL)
        $division_id_db    = null;
        $division_code_db  = null;
        $division_label_db = null;

        // pending + unpaid
        $status_db         = 'pending';
        $status_reason_db  = 'PAYMENT_REQUIRED';
        $payment_status_db = 'unpaid';
        $confirmed_at_db   = null;
        $paid_total_cents_db = 0;

        // division scelta
        if ($hasDivisions) {
          $division_id_in = (int)($_POST['division_id'] ?? 0);
          if ($division_id_in <= 0) {
            throw new RuntimeException("Seleziona una divisione.");
          }

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
            fee_tier_code, fee_tier_label,
            category_code, category_label,
            division_id, division_code, division_label
          ) VALUES (
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?,
            ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?
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
            fee_tier_code = VALUES(fee_tier_code),
            fee_tier_label = VALUES(fee_tier_label),
            category_code = VALUES(category_code),
            category_label = VALUES(category_label),
            division_id = VALUES(division_id),
            division_code = VALUES(division_code),
            division_label = VALUES(division_label)
        ");
        if (!$stmt) {
          throw new RuntimeException("Errore DB (prepare): " . h($conn->error));
        }

       // sicurezza: mysqli non digerisce NULL sugli "i"
if ($division_id_db === null) $division_id_db = 0;

$stmt->bind_param(
  "iissssiiiiiiiiiissssiss",
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
  $tier_code_db,
  $tier_label_db,
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

        // recupero reg_id
        $reg_id = (int)$conn->insert_id;
        if ($reg_id <= 0) {
          $stmt2 = $conn->prepare("SELECT id FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
          $stmt2->bind_param("ii", $race_id, $u['id']);
          $stmt2->execute();
          $tmp = $stmt2->get_result()->fetch_assoc();
          $stmt2->close();
          $reg_id = (int)($tmp['id'] ?? 0);
        }

        if ($reg_id > 0) {
          audit_log(
            $conn,
            'REG_CREATE',
            'registration',
            (int)$reg_id,
            null,
            [
              'race_id'          => (int)$race_id,
              'user_id'          => (int)($u['id'] ?? 0),
              'organization_id'  => (int)($race['organization_id'] ?? 0),
              'status'           => 'pending',
            ]
          );
        }

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

        $stmt2 = $conn->prepare("SELECT id FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
        $stmt2->bind_param("ii", $race_id, $u['id']);
        $stmt2->execute();
        $tmp = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

        $reg_id = (int)($tmp['id'] ?? 0);
        if ($reg_id > 0) {
          audit_log(
            $conn,
            'REG_CANCEL',
            'registration',
            (int)$reg_id,
            null,
            [
              'race_id'          => (int)$race_id,
              'user_id'          => (int)$u['id'],
              'organization_id'  => (int)($race['organization_id'] ?? 0),
              'reason'           => 'CANCELLED_BY_USER',
            ]
          );
        }

        header("Location: race_public.php?id=" . $race_id);
        exit;

      } catch (Throwable $e) {
        $error = "Annullamento non completato: " . $e->getMessage();
      }
    }

  }
}

// ======================================================
// Quota (preview)
// ======================================================
[$tier_code_preview, $tier_label_preview, $base_fee_cents_preview] = race_fee_pick_tier($race);
$platform_settings = get_platform_settings($conn);
$admin_settings    = get_admin_settings($conn, (int)($race['admin_user_id'] ?? 0));
$fees_preview      = calc_fees_total((int)$base_fee_cents_preview, $platform_settings, $admin_settings);
$fee_total_cents_preview = (int)($fees_preview['total_cents'] ?? 0);

// ======================================================
// PAGE
// ======================================================
$pageTitle = 'Gara: ' . ($race['title'] ?? '');
page_header($pageTitle);
?>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
    <div style="min-width:260px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Gara</div>
      <div style="font-size:22px;font-weight:900;line-height:1.2;">
        <?php echo h($race['title'] ?? ''); ?>
      </div>
      <div style="margin-top:6px;color:#444;">
        <span style="font-weight:700;">Organizzazione:</span> <?php echo h($race['org_name'] ?? ''); ?><br>
        <span style="font-weight:700;">Evento:</span> <?php echo h($race['event_title'] ?? ''); ?>
      </div>
    </div>

    <div style="min-width:260px;display:grid;gap:8px;">
      <div>
        <div style="font-size:12px;color:#666;">Luogo</div>
        <div style="font-weight:800;"><?php echo h($race['location'] ?? '-'); ?></div>
      </div>
      <div>
        <div style="font-size:12px;color:#666;">Data/Ora</div>
        <div style="font-weight:800;"><?php echo h(it_datetime($race['start_at'] ?? null)); ?></div>
      </div>
    </div>

    <div style="min-width:220px;display:grid;gap:8px;">
      <div>
        <div style="font-size:12px;color:#666;">Disciplina</div>
        <div style="font-weight:800;"><?php echo h($race['discipline'] ?? '-'); ?></div>
      </div>

<div>
  <div style="font-size:12px;color:#666;">Quota iscrizione</div>
  <div style="font-size:18px;font-weight:900;">
    € <?php echo h(cents_to_eur((int)$fee_total_cents_preview)); ?>
    <span style="font-size:12px;font-weight:700;color:#555;">
      (<?php echo h((string)$tier_label_preview); ?>)
    </span>
  </div>
</div>


  <div>
  <div style="font-size:12px;color:#666;">Iscrizioni</div>
  <div style="font-weight:900;">
    <?php echo h(it_race_status((string)($race['status'] ?? ''))); ?>
  </div>
</div>
<!-- /Iscrizioni -->

</div><!-- /colonna dx -->
</div><!-- /flex -->
</section><!-- /card header gara -->
<?php if (!empty($myReg)): ?>
  <?php
    $st = (string)($myReg['status'] ?? '');
    $ps = (string)($myReg['payment_status'] ?? '');
  ?>
  <div style="margin-top:6px;">
    <div style="font-size:12px;color:#666;">La tua iscrizione</div>

    <!-- BADGE ROW -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px;">
      <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;">
        <?php echo h(it_status($st)); ?>
      </span>

      <?php if (!empty($myReg['status_reason'] ?? '')): ?>
        <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #eee;font-weight:800;color:#555;">
          <?php echo h(it_reason((string)$myReg['status_reason'])); ?>
        </span>
      <?php endif; ?>

      <?php if (!empty($myReg['payment_status'] ?? '')): ?>
        <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #eee;font-weight:800;color:#555;">
          <?php echo h(($ps === 'paid') ? 'Pagato' : 'Non pagato'); ?>
        </span>
      <?php endif; ?>
    </div>

    <!-- HELP TEXT -->
    <div style="margin-top:6px;color:#555;font-size:13px;">
      <?php echo h(it_myreg_help($st, $ps)); ?>
    </div>

    <?php if (!empty($myReg['created_at'] ?? '')): ?>
      <div style="margin-top:4px;font-size:12px;color:#777;">
        Registrata il <?php echo h(it_datetime($myReg['created_at'] ?? null)); ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
$st = (string)($myReg['status'] ?? '');
$ps = (string)($myReg['payment_status'] ?? '');
$needsPayInfo = in_array($st, ['pending','confirmed'], true) && $ps !== 'paid';
?>

<?php if ($needsPayInfo && !empty($race['payment_instructions'])): ?>
  <div style="margin-top:10px;padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;">
    <div style="font-weight:900;margin-bottom:6px;">Come pagare</div>
    <div style="color:#444;font-size:14px;line-height:1.4;">
      <?php echo nl2br(h((string)$race['payment_instructions'])); ?>
    </div>
  </div>
<?php elseif ($needsPayInfo): ?>
  <div style="margin-top:10px;padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;">
    <div style="font-weight:900;margin-bottom:6px;">Pagamento richiesto</div>
    <div style="color:#444;font-size:14px;">
      Le istruzioni di pagamento non sono state ancora inserite dall’organizzazione.
    </div>
  </div>
<?php endif; ?>


<nav style="margin:10px 0 12px;">
  <a href="event_public.php?id=<?php echo (int)$race['event_id']; ?>"
     style="display:inline-block;padding:6px 10px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
    ← Torna all’evento
  </a>
</nav>


<?php /*

<p>
  <b>Organizzazione:</b> <?php echo h($race['org_name'] ?? ''); ?><br>
  <b>Evento:</b> <?php echo h($race['event_title'] ?? ''); ?><br>
  <b>Luogo:</b> <?php echo h($race['location'] ?? '-'); ?><br>
  <b>Data/Ora:</b> <?php echo h(it_datetime($race['start_at'] ?? null)); ?><br>
  <b>Disciplina:</b> <?php echo h($race['discipline'] ?? '-'); ?><br>
  <b>Stato gara:</b> <?php echo h(it_race_status((string)($race['status'] ?? ''))); ?>
</p>

*/ ?>


<?php /*

<p>
  <b>Quota iscrizione:</b>
  € <?php echo h(cents_to_eur((int)$fee_total_cents_preview)); ?>
  <small>(<?php echo h($tier_label_preview); ?>)</small>
</p>

*/ ?>

<p><small>La conferma dell’iscrizione avviene dopo la verifica del pagamento.</small></p>

<?php if (!empty($error)): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<h2>Iscritti confermati</h2>

<?php $my_uid = (int)($u['id'] ?? 0); ?>

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
        <?php $isMe = ($my_uid > 0 && (int)($r['reg_user_id'] ?? 0) === $my_uid); ?>

        <tr style="<?php echo $isMe ? 'background:#fafafa;border-left:4px solid #111;' : ''; ?>">

          <td>
  <?php echo h($r['first_name'] ?? ''); ?>
  <?php if ($isMe): ?>
    <span style="margin-left:6px;padding:2px 8px;border-radius:999px;border:1px solid #ddd;font-weight:900;font-size:12px;">
      Tu
    </span>
  <?php endif; ?>
</td>

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
  <div style="padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;margin:10px 0;">
    <div style="font-weight:900;margin-bottom:4px;">Iscrizioni chiuse</div>
    <div style="color:#555;font-size:14px;">Al momento non è possibile inviare nuove iscrizioni per questa gara.</div>
  </div>

<?php elseif (!$profile_ok): ?>
  <div style="padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;margin:10px 0;">
    <div style="font-weight:900;margin-bottom:4px;">Profilo atleta incompleto</div>
    <div style="color:#555;font-size:14px;margin-bottom:8px;">
      Completa data di nascita e sesso per poterti iscrivere.
    </div>
    <a href="athlete_profile.php" style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
      Completa profilo atleta
    </a>
  </div>


  <?php else: ?>

    <?php $st = (string)($myReg['status'] ?? ''); ?>

    <?php if (!$myReg || $st === '' || $st === 'cancelled'): ?>
  <p><small>Compila e conferma per inviare la richiesta di iscrizione.</small></p>
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
      <p style="margin-top:6px;color:#555;">
  <?php echo h(it_myreg_help((string)($myReg['status'] ?? ''), (string)($myReg['payment_status'] ?? ''))); ?>
</p>

<?php if (!empty($myReg['division_label'])): ?>
  <div style="margin-top:4px;font-size:12px;color:#777;">
    Divisione: <b><?php echo h((string)$myReg['division_label']); ?></b>
  </div>
<?php endif; ?>

<?php if (!empty($myReg['created_at'] ?? '')): ?>
  <div style="margin-top:4px;font-size:12px;color:#777;">
    Registrata il <?php echo h(it_datetime($myReg['created_at'] ?? null)); ?>
  </div>
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
