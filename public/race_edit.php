<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/audit.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

[$actor_id, $actor_role] = actor_from_auth($u);
$actor_id   = ($actor_id > 0) ? $actor_id : null;
$actor_role = ($actor_role !== '') ? $actor_role : null;

/**
 * ======================================================
 * ID gara (edit)
 * ======================================================
 */
$race_id = (int)($_GET['id'] ?? 0);
if ($race_id <= 0) { header("Location: events.php"); exit; }

/**
 * ======================================================
 * SETTINGS QUOTE (platform + procacciatori)
 * ======================================================
 */

// Platform settings (tabella già esistente)
$ps = [
  'fee_type' => 'fixed',      // fixed|percent
  'fee_value_cents' => 0,     // usato se fixed
  'fee_value_bp' => null,     // usato se percent (basis points)
  'round_to_cents' => 50,     // es. 50 = arrotonda a 0,50 €
];

$res = $conn->query("
  SELECT fee_type, fee_value_cents, fee_value_bp, round_to_cents
  FROM platform_settings
  ORDER BY id ASC
  LIMIT 1
");
if ($res && ($row = $res->fetch_assoc())) {
  $ps['fee_type'] = (string)$row['fee_type'];
  $ps['fee_value_cents'] = (int)$row['fee_value_cents'];
  $ps['fee_value_bp'] = isset($row['fee_value_bp']) && $row['fee_value_bp'] !== null ? (int)$row['fee_value_bp'] : null;
  $ps['round_to_cents'] = (int)$row['round_to_cents'];
}

// Fee procacciatori (Admin) -> mappa [admin_id => settings]
$admin_fee_map = [];
$res = $conn->query("SELECT admin_user_id, fee_type, fee_value_cents, fee_value_bp, round_to_cents FROM admin_settings");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $admin_id = (int)$row['admin_user_id'];
    $admin_fee_map[$admin_id] = [
      'fee_type' => (string)$row['fee_type'],
      'fee_value_cents' => (int)$row['fee_value_cents'],
      'fee_value_bp' => ($row['fee_value_bp'] !== null ? (int)$row['fee_value_bp'] : null),
      'round_to_cents' => (int)$row['round_to_cents'],
    ];
  }
}

// Per la preview JS: per ora consideriamo admin fee fixed in centesimi (fee_value_cents)
$admin_fee_map_cents = [];
foreach ($admin_fee_map as $aid => $s) {
  $admin_fee_map_cents[(int)$aid] = (int)($s['fee_value_cents'] ?? 0);
}

/**
 * Calcolo fee piattaforma in centesimi (fixed o percent)
 */
function calc_platform_fee_cents(int $base_cents, array $ps): int {
  $base_cents = max(0, $base_cents);

  if (($ps['fee_type'] ?? 'fixed') === 'percent') {
    $bp = (int)($ps['fee_value_bp'] ?? 0); // 100 bp = 1%
    if ($bp <= 0) return 0;
    return (int) round($base_cents * ($bp / 10000));
  }

  $fixed = (int)($ps['fee_value_cents'] ?? 0);
  return max(0, $fixed);
}

/**
 * Arrotondamento verso l'alto al passo configurato (es. 50 cent)
 */
function round_up_to(int $cents, int $step): int {
  $cents = max(0, $cents);
  $step = (int)$step;
  if ($step <= 0) return $cents;
  return (int)(ceil($cents / $step) * $step);
}

/**
 * conversione importo (es. "13,50" -> 1350)
 */
function money_to_cents(string $s): int {
  $s = trim($s);
  if ($s === '') return 0;
  $s = str_replace(['€', ' '], '', $s);
  $s = str_replace(',', '.', $s);
  $s = preg_replace('/[^0-9.]/', '', $s);
  if ($s === '' || $s === '.') return 0;

  $parts = explode('.', $s, 3);
  if (count($parts) > 2) {
    $s = $parts[0] . '.' . $parts[1];
  }

  $val = (float)$s;
  if ($val < 0) $val = 0;
  return (int) round($val * 100);
}

/**
 * cents -> "15,00"
 */
function cents_to_money_it(int $cents): string {
  $cents = max(0, (int)$cents);
  $s = number_format($cents / 100, 2, ',', '');
  return $s;
}

/**
 * DB datetime "YYYY-mm-dd HH:ii:ss" -> input datetime-local "YYYY-mm-ddTHH:ii"
 */
function dbdt_to_input(?string $dt): string {
  $dt = trim((string)$dt);
  if ($dt === '') return '';
  if (strpos($dt, 'T') !== false) return substr($dt, 0, 16);
  $dt = str_replace(' ', 'T', $dt);
  return substr($dt, 0, 16);
}

/**
 * ======================================================
 * Carico gara + controllo accesso (manage su organization)
 * ======================================================
 */
$stmt = $conn->prepare("
  SELECT r.*, e.organization_id
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE r.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $race_id, $u['id']);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) { header("Location: events.php"); exit; }

$event_id = (int)$race['event_id'];
$org_id   = (int)($race['organization_id'] ?? 0);

/**
 * ======================================================
 * Stripe readiness (per warning in pagina)
 * ======================================================
 */
$stripe_ready = false;
if ($org_id > 0) {
  $stmt = $conn->prepare("
    SELECT stripe_account_id, stripe_charges_enabled, stripe_payouts_enabled, stripe_details_submitted
    FROM organizations
    WHERE id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $org_id);
  $stmt->execute();
  $o = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $stripe_ready =
    !empty($o['stripe_account_id']) &&
    (int)($o['stripe_charges_enabled'] ?? 0) === 1 &&
    (int)($o['stripe_payouts_enabled'] ?? 0) === 1 &&
    (int)($o['stripe_details_submitted'] ?? 0) === 1;
}

/**
 * ======================================================
 * Form defaults + preload da DB
 * ======================================================
 */
$error = '';
$ok = '';

$form = [
  'title' => (string)($race['title'] ?? ''),
  'location' => (string)($race['location'] ?? ''),
  'start_at' => dbdt_to_input($race['start_at'] ?? ''),
  'discipline' => (string)($race['discipline'] ?? 'other'),
  'status' => (string)($race['status'] ?? 'draft'),
  'base_fee' => cents_to_money_it((int)($race['base_fee_cents'] ?? 0)),
  'organizer_iban' => (string)($race['organizer_iban'] ?? ''),
  'payment_instructions' => (string)($race['payment_instructions'] ?? ''),
  'payment_mode' => (string)($race['payment_mode'] ?? 'manual'),
  'ref_admin_id' => (string)((int)($race['ref_admin_id'] ?? 0)),

  // --- fee tier (serve per display persistente) ---
  'fee_early_eur'   => cents_to_money_it((int)($race['fee_early_cents'] ?? 0)),
  'fee_regular_eur' => cents_to_money_it((int)($race['fee_regular_cents'] ?? 0)),
  'fee_late_eur'    => cents_to_money_it((int)($race['fee_late_cents'] ?? 0)),
  'fee_early_until' => (string)($race['fee_early_until'] ?? ''),
  'fee_late_from'   => (string)($race['fee_late_from'] ?? ''),
];

// normalizza payment_mode
if (!in_array($form['payment_mode'], ['manual','stripe','both'], true)) {
  $form['payment_mode'] = 'manual';
}

// lista procacciatori (opzionale): prendo utenti role=admin
$admins = [];
$res = $conn->query("SELECT id, full_name, email FROM users WHERE role='admin' ORDER BY full_name ASC");
if ($res) $admins = $res->fetch_all(MYSQLI_ASSOC);

/**
 * ======================================================
 * POST = UPDATE
 * ======================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // contatore ricalcolo (deve esistere sempre)
  $recalc_count = 0;

  // flag blindato (niente goto)
  $do_recalc = true;

  // --- input form (SEMPRE su $form, così non sparisce a display) ---
  $form['title']         = trim((string)($_POST['title'] ?? ''));
  $form['location']      = trim((string)($_POST['location'] ?? ''));
  $form['start_at']      = (string)($_POST['start_at'] ?? '');
  $form['close_at']      = (string)($_POST['close_at'] ?? '');
  $form['discipline']    = (string)($_POST['discipline'] ?? 'other');
  $form['status']        = (string)($_POST['status'] ?? 'draft');
  $form['base_fee']      = trim((string)($_POST['base_fee'] ?? ''));
  $form['organizer_iban']= strtoupper(trim((string)($_POST['organizer_iban'] ?? '')));
  $form['payment_instructions'] = trim((string)($_POST['payment_instructions'] ?? ''));
  $form['payment_mode']  = (string)($_POST['payment_mode'] ?? 'manual');
  $form['ref_admin_id']  = (string)($_POST['ref_admin_id'] ?? '0');

  if (!in_array($form['payment_mode'], ['manual','stripe','both'], true)) {
    $form['payment_mode'] = 'manual';
  }

  // fee tier: input
  $form['fee_early_eur']   = trim((string)($_POST['fee_early_eur'] ?? ''));
  $form['fee_regular_eur'] = trim((string)($_POST['fee_regular_eur'] ?? ''));
  $form['fee_late_eur']    = trim((string)($_POST['fee_late_eur'] ?? ''));
  $form['fee_early_until'] = (string)($_POST['fee_early_until'] ?? '');
  $form['fee_late_from']   = (string)($_POST['fee_late_from'] ?? '');

  // --- variabili “pulite” ---
  $title      = $form['title'];
  $location   = $form['location'];
  $start_at = null;
if ($form['start_at'] !== '') {
  // "YYYY-MM-DDTHH:MM" -> "YYYY-MM-DD HH:MM:SS"
  $start_at = str_replace('T', ' ', $form['start_at']) . ':00';
}

$close_at_sql = '';
if (($form['close_at'] ?? '') !== '') {
  $close_at_sql = str_replace('T', ' ', (string)$form['close_at']) . ':00';
}

  $discipline = $form['discipline'];
  $status     = $form['status'];

  $base_fee_cents = money_to_cents($form['base_fee']);
  $organizer_iban = ($form['organizer_iban'] !== '') ? $form['organizer_iban'] : null;

  $payment_instructions = $form['payment_instructions'];
  $payment_mode         = $form['payment_mode'];

  // fee tier (usa eur_to_cents DEFINITA FUORI, es. helpers.php)
  $fee_early_cents   = eur_to_cents($form['fee_early_eur']);
  $fee_regular_cents = eur_to_cents($form['fee_regular_eur']);
  $fee_late_cents    = eur_to_cents($form['fee_late_eur']);

  // date tier
  $fee_early_until = trim($form['fee_early_until']);
  $fee_late_from   = trim($form['fee_late_from']);
  if ($fee_early_until === '') $fee_early_until = null;
  if ($fee_late_from === '')   $fee_late_from = null;

  $ref_admin_id = (int)$form['ref_admin_id'];
  if ($ref_admin_id <= 0) $ref_admin_id = 0; // useremo NULLIF

  // ======================================================
  // VALIDAZIONI + BLINDATURE
  // ======================================================
  if ($title === '') {
    $error = "Titolo obbligatorio.";
    $do_recalc = false;
  }

  // se archived: non ricalcolare registrations
  if (($status ?? '') === 'archived') $do_recalc = false;

  // se base fee 0: non ricalcolare registrations (evita aggiornamenti a 0)
  if ((int)$base_fee_cents <= 0) $do_recalc = false;

  if ($error === '') {

    // snapshot BEFORE per audit (solo campi rilevanti)
    $stmtB = $conn->prepare("
      SELECT
        title, location, start_at, discipline, status,
        base_fee_cents, organizer_iban, payment_instructions, payment_mode, ref_admin_id,
        fee_early_cents, fee_regular_cents, fee_late_cents,
        fee_early_until, fee_late_from
      FROM races
      WHERE id=? LIMIT 1
    ");
    if (!$stmtB) throw new RuntimeException("Errore DB (prepare before): " . h($conn->error));
    $stmtB->bind_param("i", $race_id);
    $stmtB->execute();
    $before_race = $stmtB->get_result()->fetch_assoc();
    $stmtB->close();

    // UPDATE races
$stmt = $conn->prepare("
  UPDATE races
  SET
    title=?,
    location=?,
    start_at=?,
    discipline=?,
    status=?,
    base_fee_cents=?,
    organizer_iban=?,
    payment_instructions=?,
    payment_mode=?,
    ref_admin_id=NULLIF(?,0),
    fee_early_cents=?,
    fee_regular_cents=?,
    fee_late_cents=?,
    fee_early_until=?,
    fee_late_from=?,
    close_at = NULLIF(?, '')
  WHERE id=?
  LIMIT 1
");
if (!$stmt) throw new RuntimeException("Errore DB (prepare): " . h($conn->error));

$stmt->bind_param(
  "sssssisssiiiisssi",
  $title,
  $location,
  $start_at,
  $discipline,
  $status,
  $base_fee_cents,
  $organizer_iban,
  $payment_instructions,
  $payment_mode,
  $ref_admin_id,
  $fee_early_cents,
  $fee_regular_cents,
  $fee_late_cents,
  $fee_early_until,
  $fee_late_from,
  $close_at_sql,
  $race_id
);

$stmt->execute();
$stmt->close();


    // snapshot AFTER per audit
    $stmtA = $conn->prepare("
      SELECT
        title, location, start_at, discipline, status,
        base_fee_cents, organizer_iban, payment_instructions, payment_mode, ref_admin_id,
        fee_early_cents, fee_regular_cents, fee_late_cents,
        fee_early_until, fee_late_from
      FROM races
      WHERE id=? LIMIT 1
    ");
    if (!$stmtA) throw new RuntimeException("Errore DB (prepare after): " . h($conn->error));
    $stmtA->bind_param("i", $race_id);
    $stmtA->execute();
    $after_race = $stmtA->get_result()->fetch_assoc();
    $stmtA->close();

    /**
     * ======================================================
     * RICALCOLO QUOTE SU REGISTRATIONS (solo NON PAGATI)
     * ======================================================
     */
    if ($do_recalc) {

      // --- helper: admin fee in cents (fixed o percent) ---
      function calc_admin_fee_cents(int $base_cents, ?int $admin_id, array $admin_fee_map): int {
        if (!$admin_id || $admin_id <= 0) return 0;
        if (!isset($admin_fee_map[$admin_id])) return 0;

        $s = $admin_fee_map[$admin_id];
        $type = (string)($s['fee_type'] ?? 'fixed');

        if ($type === 'percent') {
          $bp = (int)($s['fee_value_bp'] ?? 0);
          if ($bp <= 0) return 0;
          return (int) round($base_cents * ($bp / 10000));
        }

        $fixed = (int)($s['fee_value_cents'] ?? 0);
        return max(0, $fixed);
      }

      $ref_admin_id_int = (int)$ref_admin_id;

      // prendo tutte le registrations da ricalcolare
      $regs_to_recalc = [];
      $stmtR = $conn->prepare("
        SELECT id, fee_tier_code
        FROM registrations
        WHERE race_id=?
          AND payment_status='unpaid'
          AND status IN ('pending','confirmed','blocked')
      ");
      $stmtR->bind_param("i", $race_id);
      $stmtR->execute();
      $regs_to_recalc = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC);
      $stmtR->close();

      // preparo UPDATE registrations
      $stmtU = $conn->prepare("
        UPDATE registrations
        SET
          base_fee_cents=?,
          platform_fee_cents=?,
          admin_fee_cents=?,
          rounding_delta_cents=?,
          fee_total_cents=?,
          organizer_net_cents=?,

          fee_race_cents=?,
          fee_platform_cents=?,
          fee_admin_cents=?,

          fee_tier_label=?
        WHERE id=? AND race_id=?
        LIMIT 1
      ");
      if (!$stmtU) throw new RuntimeException("Errore DB (prepare update registrations): " . h($conn->error));

      $recalc_count = 0;

      foreach ($regs_to_recalc as $rr) {
        $reg_id = (int)$rr['id'];
        $tier   = (string)($rr['fee_tier_code'] ?? 'regular');

        // 1) quota “gara” in base al tier
        $race_fee_cents = 0;
        $tier_label = 'Regular';

        if ($tier === 'early') {
          $race_fee_cents = (int)($fee_early_cents ?? 0);
          if ($race_fee_cents <= 0) $race_fee_cents = (int)$base_fee_cents;
          $tier_label = 'Early';
        } elseif ($tier === 'late') {
          $race_fee_cents = (int)($fee_late_cents ?? 0);
          if ($race_fee_cents <= 0) $race_fee_cents = (int)$base_fee_cents;
          $tier_label = 'Late';
        } else {
          $race_fee_cents = (int)($fee_regular_cents ?? 0);
          if ($race_fee_cents <= 0) $race_fee_cents = (int)$base_fee_cents;
          $tier_label = 'Regular';
        }

        $race_fee_cents = max(0, (int)$race_fee_cents);

        // 2) fee piattaforma
        $platform_cents = calc_platform_fee_cents($race_fee_cents, $ps);
        $platform_cents = max(0, (int)$platform_cents);

        // 3) fee admin/procacciatore
        $admin_cents = calc_admin_fee_cents($race_fee_cents, $ref_admin_id_int, $admin_fee_map);
        $admin_cents = max(0, (int)$admin_cents);

        // 4) totale + arrotondamento
        $pre_total = $race_fee_cents + $platform_cents + $admin_cents;

        $step = (int)($ps['round_to_cents'] ?? 0);
        $rounded_total = ($step > 0) ? round_up_to($pre_total, $step) : $pre_total;

        $rounding_delta = $rounded_total - $pre_total;

        // organizer net: per ora = quota gara (organizzatore)
        $organizer_net = $race_fee_cents;

        $stmtU->bind_param(
          "iiiiiiiiisii",
          $race_fee_cents,
          $platform_cents,
          $admin_cents,
          $rounding_delta,
          $rounded_total,
          $organizer_net,

          $race_fee_cents,
          $platform_cents,
          $admin_cents,

          $tier_label,
          $reg_id,
          $race_id
        );

        $stmtU->execute();
        $recalc_count++;
      }

      $stmtU->close();
    }

    // audit DOPO ricalcolo (così recalc_count è corretto)
    audit_log(
      $conn,
      'RACE_EDIT',
      'race',
      (int)$race_id,
      null,
      [
        'race_id'             => (int)$race_id,
        'organization_id'     => (int)($race['organization_id'] ?? 0),
        'recalc_unpaid_regs'  => (int)$recalc_count,
        'before'             => $before_race,
        'after'              => $after_race
      ]
    );

    // redirect
    header("Location: race_edit.php?id=".$race_id);
    exit;
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EasyRace - Modifica gara</title>


<style>
  *, *::before, *::after { box-sizing: border-box; }
  body{ background:#f6f7f8; color:#111; }
  .re-wrap{ max-width: 980px; margin: 28px auto; padding: 0 14px; font-family: system-ui; }
  .re-top{ display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap; }
  .re-title{ font-weight: 950; font-size: 28px; margin:0; }
  .re-back a{ text-decoration:none; font-weight:800; }
  .re-sub{ color:#555; margin: 6px 0 18px; }

  .re-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:16px;
    padding:16px;
    box-shadow:0 6px 18px rgba(0,0,0,.05);
    margin: 14px 0;
  }
  .re-card h3{ margin:0 0 10px; font-size:16px; font-weight:950; }

  .re-grid{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 860px){
    .re-grid{ grid-template-columns: 1fr 1fr; }
    .re-span-2{ grid-column: 1 / -1; }
  }

  label{ display:block; font-weight:800; font-size:13px; margin: 8px 0 6px; }
  input, select, textarea{
    width:100%;
    padding:10px 12px;
    border:1px solid #d1d5db;
    border-radius:12px;
    background:#fff;
  }
  textarea{ min-height: 120px; resize: vertical; }

  .re-help{ color:#6b7280; font-size:12px; margin-top:6px; line-height:1.35; }
  .re-box{
    border:1px dashed #d1d5db;
    background:#fafafa;
    border-radius:14px;
    padding:12px;
  }

  .re-actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; margin-top: 10px; }
  .btn{
    display:inline-block;
    padding:10px 14px;
    border-radius:12px;
    border:1px solid #d1d5db;
    background:#fff;
    font-weight:900;
    cursor:pointer;
  }
  .btn-primary{ border:0; background:#111827; color:#fff; }

  .re-alert{
    padding:12px 14px; border-radius:14px; border:1px solid #ffb3b3; background:#ffecec; margin: 12px 0;
  }
</style>



</head>

<body>
<div class="re-wrap">

  <div class="re-top">
  <h1 class="re-title">Modifica gara</h1>
  <div class="re-back">
    <a href="event_detail.php?id=<?php echo (int)$event_id; ?>">← Torna all’evento</a>
  </div>
</div>
<p class="re-sub">Imposta quote, dettagli gara e modalità di pagamento.</p>

<?php if ($error): ?>
  <div class="re-alert"><?php echo h($error); ?></div>
<?php endif; ?>

<form method="post">

  <div class="re-card">
    <h3>QUOTA ISCRIZIONI</h3>
    <div class="re-grid">
      <div>
        <label>Apertura Iscrizioni (€)</label>
        <input type="text" inputmode="decimal" name="fee_early_eur" value="<?php echo h($form['fee_early_eur'] ?? ''); ?>">
      </div>
      <div>
        <label>Quota di apertura fino al</label>
        <input type="date" name="fee_early_until" value="<?php echo h((string)($form['fee_early_until'] ?? '')); ?>">
      </div>

      <div>
        <label>Quota Regolare (€)</label>
        <input type="text" inputmode="decimal" name="fee_regular_eur" value="<?php echo h($form['fee_regular_eur'] ?? ''); ?>">
      </div>
      <div>
        <label>Quota Ultimi posti (€)</label>
        <input type="text" inputmode="decimal" name="fee_late_eur" value="<?php echo h($form['fee_late_eur'] ?? ''); ?>">
      </div>

      <div class="re-span-2">
        <label>Ultimi posti dal</label>
        <input type="date" name="fee_late_from" value="<?php echo h((string)($form['fee_late_from'] ?? '')); ?>">
      </div>
    </div>
  </div>

  <div class="re-card">
    <h3>Dati gara</h3>
    <div class="re-grid">
      <div class="re-span-2">
        <label>Titolo *</label>
        <input name="title" value="<?php echo h($form['title']); ?>" required>
      </div>
      <div>
        <label>Luogo</label>
        <input name="location" value="<?php echo h($form['location']); ?>">
      </div>
      <div>
        <label>Data/Ora (inizio)</label>
        <input type="datetime-local" name="start_at" value="<?php echo h($form['start_at']); ?>">
      </div>

<?php
$close_at_val = '';
if (!empty($race['close_at'])) {
  $close_at_val = str_replace(' ', 'T', substr((string)$race['close_at'], 0, 16));
}
?>

<div class="col-12 col-md-4">
  <label class="form-label fw-bold">Chiusura iscrizioni</label>
  <input
    type="datetime-local"
    name="close_at"
    class="form-control"
    value="<?php echo h($close_at_val); ?>"
  >
  <div class="form-text">Se vuoto: iscrizioni sempre aperte.</div>
</div>


      <div>
        <label>Disciplina</label>
        <select name="discipline">
  <option value="cycling" <?php echo ($form['discipline']==='cycling'?'selected':''); ?>>Ciclismo</option>
  <option value="running" <?php echo ($form['discipline']==='running'?'selected':''); ?>>Corsa</option>
  <option value="other"   <?php echo ($form['discipline']==='other'?'selected':''); ?>>Altro</option>
</select>
      </div>
      <div>
        <label>Stato</label>
        <select name="status">
  <option value="draft"    <?php echo ($form['status']==='draft'?'selected':''); ?>>Bozza</option>
  <option value="open"     <?php echo ($form['status']==='open'?'selected':''); ?>>Aperta</option>
  <option value="closed"   <?php echo ($form['status']==='closed'?'selected':''); ?>>Chiusa</option>
  <option value="archived" <?php echo ($form['status']==='archived'?'selected':''); ?>>Archiviata</option>
</select>
      </div>
    </div>
  </div>

  <div class="re-card">
    <h3>Quota e incassi</h3>
    <div class="re-grid">
      <div>
        <label for="base_fee">Quota fallback (se non usi gli scaglioni)</label>

<div class="re-help">
  Usata solo se non sono impostate le tariffe a scaglioni.
  Se è presente la quota <b>"Apertura Iscrizioni"</b>, questa viene considerata la quota principale.
</div>

        <input id="base_fee" name="base_fee" value="<?php echo h($form['base_fee'] ?? ''); ?>" placeholder="es. 13,50" inputmode="decimal" autocomplete="off">
        <div class="re-help">Importo che l’organizzatore intende incassare per ogni iscritto.</div>
      </div>

      <div class="re-box">
        <label for="total_fee_preview">Quota online (finale atleta)</label>
        <input id="total_fee_preview" value="" readonly style="background:#f3f4f6;">
        <div class="re-help">Include commissioni di servizio EasyRace (ed eventuale procacciatore).</div>
        <div id="fee_breakdown" class="re-help"></div>
      </div>

      <div class="re-span-2">
        <label>IBAN organizzatore (per questa gara)</label>
        <input name="organizer_iban" value="<?php echo h($form['organizer_iban']); ?>" placeholder="es. IT60X0542811101000000123456">
      </div>
    </div>
  </div>

  <div class="re-card">
    <h3>Pagamenti</h3>
    <div class="re-grid">
      <div class="re-span-2">
        <label>Istruzioni pagamento (testo libero)</label>
        <textarea name="payment_instructions" placeholder="Esempio: bonifico, causale, intestazione, scadenze..."><?php echo h($form['payment_instructions'] ?? ''); ?></textarea>
      </div>

      <div>
        <label>Metodo di pagamento</label>
        <select name="payment_mode" id="payment_mode">
          <option value="manual" <?php echo ($form['payment_mode']==='manual'?'selected':''); ?>>Manuale</option>
          <option value="stripe" <?php echo ($form['payment_mode']==='stripe'?'selected':''); ?>>Carta (Stripe)</option>
          <option value="both"   <?php echo ($form['payment_mode']==='both'?'selected':''); ?>>Manuale + Carta</option>
        </select>
      </div>

      <div>
        <label>Procacciatore (opzionale)</label>
        <select name="ref_admin_id" id="ref_admin_id">
          <option value="0" <?php echo ($form['ref_admin_id']==='0'?'selected':''); ?>>Nessuno</option>
          <?php foreach ($admins as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" <?php echo ((string)$a['id']===$form['ref_admin_id']?'selected':''); ?>>
              <?php echo h(($a['full_name'] ?? 'Admin').' · '.($a['email'] ?? '')); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php
      $pm = (string)($form['payment_mode'] ?? 'manual');
      $needsStripe = in_array($pm, ['stripe','both'], true);
    ?>
    <?php if ($needsStripe && !$stripe_ready): ?>
      <div style="margin-top:12px;padding:12px;border:1px solid #f2c46f;border-radius:14px;background:#fff6e5;">
        <div style="font-weight:950;margin-bottom:6px;">Stripe non attivo</div>
        <div style="color:#555;font-size:13px;line-height:1.4;">
          Hai selezionato pagamenti con carta, ma l’organizzazione non ha completato l’onboarding Stripe.
        </div>
        <?php if ($org_id > 0): ?>
          <div style="margin-top:8px;">
            <a href="stripe_onboarding.php?org_id=<?php echo (int)$org_id; ?>" style="font-weight:950;text-decoration:none;">
              Vai ad attivare Stripe →
            </a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>

  <div class="re-actions">
    <button type="submit" class="btn btn-primary">Salva modifiche</button>
  </div>

</form>




<script>
(function(){
  function parseEuroToCents(str){
    str = (str || '').trim();
    if (!str) return 0;
    str = str.replace(/\./g, '').replace(',', '.');
    var n = Number(str);
    if (!isFinite(n) || n < 0) return 0;
    return Math.round(n * 100);
  }

  function centsToEuro(cents){
    cents = Math.max(0, parseInt(cents || 0, 10));
    return (cents/100).toFixed(2).replace('.', ',');
  }

  function roundUpTo(cents, step){
    step = parseInt(step || 0, 10) || 0;
    if (step <= 0) return cents;
    return Math.ceil(cents / step) * step;
  }

  var platformSettings = <?php echo json_encode($ps, JSON_UNESCAPED_UNICODE); ?>;
  var adminFeeMapCents = <?php echo json_encode($admin_fee_map_cents, JSON_UNESCAPED_UNICODE); ?>;

  var baseEl = document.getElementById('base_fee');
  var totalEl = document.getElementById('total_fee_preview');
  var breakdownEl = document.getElementById('fee_breakdown');
  var procEl = document.getElementById('ref_admin_id') || document.querySelector('select[name="ref_admin_id"]');

  function calcPlatformFeeCents(baseCents){
    if (platformSettings.fee_type === 'percent') {
      var bp = parseInt(platformSettings.fee_value_bp || 0, 10) || 0;
      return Math.round(baseCents * (bp / 10000));
    }
    return parseInt(platformSettings.fee_value_cents || 0, 10) || 0;
  }

  function getAdminFeeCents(){
    if (!procEl) return 0;
    var v = String(procEl.value || '').trim();
    if (!v || v === '0') return 0;
    return parseInt(adminFeeMapCents[v] || 0, 10) || 0;
  }

  function recalc(){
    var baseCents = parseEuroToCents(baseEl ? baseEl.value : '');

    if (!baseCents) {
      totalEl.value = '';
      breakdownEl.textContent = '';
      return;
    }

    var platformCents = calcPlatformFeeCents(baseCents);
var adminCents = getAdminFeeCents();

var preTotal = baseCents + platformCents + adminCents;
var totalCents = roundUpTo(preTotal, platformSettings.round_to_cents);
var roundingDelta = totalCents - preTotal;

// piattaforma = fee fissa + arrotondamento
var platformTake = platformCents + roundingDelta;

totalEl.value = totalCents ? (centsToEuro(totalCents) + ' €') : '';

var parts = [];
parts.push('Base ' + centsToEuro(baseCents) + '€');
parts.push('EasyRace ' + centsToEuro(platformTake) + '€');
if (adminCents) parts.push('Procacciatore ' + centsToEuro(adminCents) + '€');

breakdownEl.textContent = 'Dettaglio: ' + parts.join(' + ');

  }

  if (baseEl) {
    baseEl.addEventListener('input', recalc);
    baseEl.addEventListener('change', recalc);
  }
  if (procEl) {
    procEl.addEventListener('change', recalc);
  }

  recalc();
})();
</script>

</body>
</html>
