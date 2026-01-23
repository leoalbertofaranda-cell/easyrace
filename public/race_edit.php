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
  // già formato input?
  if (strpos($dt, 'T') !== false) {
    // taglia eventuali secondi
    return substr($dt, 0, 16);
  }
  // formato DB con spazio
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
  'ref_admin_id' => (string)((int)($race['ref_admin_id'] ?? 0)),

  // --- fee tier (serve per display persistente) ---
  'fee_early_eur'   => cents_to_money_it((int)($race['fee_early_cents'] ?? 0)),
  'fee_regular_eur' => cents_to_money_it((int)($race['fee_regular_cents'] ?? 0)),
  'fee_late_eur'    => cents_to_money_it((int)($race['fee_late_cents'] ?? 0)),
  'fee_early_until' => (string)($race['fee_early_until'] ?? ''),
  'fee_late_from'   => (string)($race['fee_late_from'] ?? ''),
];

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
  $form['discipline']    = (string)($_POST['discipline'] ?? 'other');
  $form['status']        = (string)($_POST['status'] ?? 'draft');
  $form['base_fee']      = trim((string)($_POST['base_fee'] ?? ''));
  $form['organizer_iban']= strtoupper(trim((string)($_POST['organizer_iban'] ?? '')));
  $form['ref_admin_id']  = (string)($_POST['ref_admin_id'] ?? '0');

  // fee tier: input
  $form['fee_early_eur']   = trim((string)($_POST['fee_early_eur'] ?? ''));
  $form['fee_regular_eur'] = trim((string)($_POST['fee_regular_eur'] ?? ''));
  $form['fee_late_eur']    = trim((string)($_POST['fee_late_eur'] ?? ''));
  $form['fee_early_until'] = (string)($_POST['fee_early_until'] ?? '');
  $form['fee_late_from']   = (string)($_POST['fee_late_from'] ?? '');

  // --- variabili “pulite” ---
  $title      = $form['title'];
  $location   = $form['location'];
  $start_at   = ($form['start_at'] !== '') ? $form['start_at'] : null; // datetime-local -> DATETIME
  $discipline = $form['discipline'];
  $status     = $form['status'];

  $base_fee_cents = money_to_cents($form['base_fee']);
  $organizer_iban = ($form['organizer_iban'] !== '') ? $form['organizer_iban'] : null;

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
  // BLINDATURE RICALCOLO (qui solo flag, non goto)
  // ======================================================
  if (($status ?? '') === 'archived') $do_recalc = false;
  if ((int)$base_fee_cents <= 0)      $do_recalc = false;



// ======================================================
// RICALCOLO QUOTE SU REGISTRATIONS (solo NON pagate)
// ======================================================
if ($do_recalc) {
  $stmt = $conn->prepare("
    UPDATE registrations
    SET
      fee_total_cents = ?,
      platform_fee_cents = ?,
      admin_fee_cents = ?,
      organizer_net_cents = ?
    WHERE
      race_id = ?
      AND status <> 'cancelled'
      AND payment_status = 'unpaid'
  ");

  $stmt->bind_param(
    "iiiii",
    $fee_total_cents,
    $platform_fee_cents,
    $admin_fee_cents,
    $organizer_net_cents,
    $race_id
  );

  $stmt->execute();
  $recalc_count = $stmt->affected_rows;
  $stmt->close();
}



  // ======================================================
  // VALIDAZIONI + BLINDATURE
  // ======================================================
  if ($title === '') {
    $error = "Titolo obbligatorio.";
    $do_recalc = false; // inutile ricalcolare
  }

  // se archived: non ricalcolare registrations
  if ($status === 'archived') {
    $do_recalc = false;
  }

  // se base fee 0: non ricalcolare registrations (evita aggiornamenti a 0)
  if ((int)$base_fee_cents <= 0) {
    $do_recalc = false;
  }

  if ($error === '') {

    // Update semplice e robusto (con fee tier)


// snapshot BEFORE per audit (solo campi rilevanti)
$stmtB = $conn->prepare("
  SELECT
    title, location, start_at, discipline, status,
    base_fee_cents, organizer_iban, ref_admin_id,
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
    ref_admin_id=NULLIF(?,0),
    fee_early_cents=?,
    fee_regular_cents=?,
    fee_late_cents=?,
    fee_early_until=?,
    fee_late_from=?
  WHERE id=?
  LIMIT 1
");

if (!$stmt) {
  throw new RuntimeException("Errore DB (prepare): " . h($conn->error));
}

$stmt->bind_param(
  "sssssisiiiissi",
  $title,
  $location,
  $start_at,
  $discipline,
  $status,
  $base_fee_cents,
  $organizer_iban,
  $ref_admin_id,
  $fee_early_cents,
  $fee_regular_cents,
  $fee_late_cents,
  $fee_early_until,
  $fee_late_from,
  $race_id
);

$stmt->execute();
$stmt->close();


// ======================================================
// RICALCOLO QUOTE SU REGISTRATIONS (solo NON PAGATI, non cancellati)
// ======================================================
if ($do_recalc) {

  $stmtR = $conn->prepare("
    UPDATE registrations
    SET
      fee_total_cents       = ?,
      organizer_net_cents   = ?,
      platform_fee_cents    = ?,
      admin_fee_cents       = ?,
      rounding_delta_cents  = ?
    WHERE
      race_id = ?
      AND payment_status = 'unpaid'
      AND status <> 'cancelled'
  ");
  if ($stmtR) {
    $stmtR->bind_param(
      "iiiiii",
      $fee_total_cents,
      $organizer_net_cents,
      $platform_fee_cents,
      $admin_fee_cents,
      $rounding_delta_cents,
      $race_id
    );
    $stmtR->execute();
    $recalc_count = (int)$stmtR->affected_rows;
    $stmtR->close();
  }
}



audit_log(
  $conn,
  'RACE_EDIT',
  'race',
  (int)$race_id,
  null,
  [
    'race_id'            => (int)$race_id,
    'organization_id'   => (int)($race['organization_id'] ?? 0),
    'recalc_unpaid_regs'=> (int)$recalc_count,
    'before'            => $before_race,
    'after'             => $after_race
  ]
);


/**
 * ======================================================
 * RICALCOLO QUOTE SU REGISTRATIONS (solo NON PAGATI, non cancellati)
 * ======================================================
 * Regola: quando cambi le quote della gara, aggiornare i record "unpaid".
 * Non tocchiamo: payment_status='paid' e status='cancelled'
 */

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

$ref_admin_id_int = (int)$ref_admin_id; // già normalizzato sopra

// ======================================================
// BLINDATURE RICALCOLO
// ======================================================

$do_recalc = true;
$recalc_count = 0;

// 1) Se gara archived: non ricalcolo nulla
if (($status ?? '') === 'archived') {
  $do_recalc = false;
}

// 2) Se non ho una base fee valida: non ricalcolo (evita 0)
if ((int)$base_fee_cents <= 0) {
  $do_recalc = false;
}



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

if (!$stmtU) {
  throw new RuntimeException("Errore DB (prepare update registrations): " . h($conn->error));
}

$recalc_count = 0;

foreach ($regs_to_recalc as $rr) {
  $reg_id = (int)$rr['id'];
  $tier   = (string)($rr['fee_tier_code'] ?? 'regular');

  // 1) quota “gara” in base al tier
  $race_fee_cents = 0;

  if ($tier === 'early') {
    $race_fee_cents = (int)($fee_early_cents ?? 0);
    if ($race_fee_cents <= 0) $race_fee_cents = (int)$base_fee_cents;
    $tier_label = 'Early';
  } elseif ($tier === 'late') {
    $race_fee_cents = (int)($fee_late_cents ?? 0);
    if ($race_fee_cents <= 0) $race_fee_cents = (int)$base_fee_cents;
    $tier_label = 'Late';
  } else {
    // regular
    $race_fee_cents = (int)($fee_regular_cents ?? 0);
    if ($race_fee_cents <= 0) $race_fee_cents = (int)$base_fee_cents;
    $tier_label = 'Regular';
  }

  $race_fee_cents = max(0, (int)$race_fee_cents);

  // 2) fee piattaforma (da platform_settings già caricati in $ps)
  $platform_cents = calc_platform_fee_cents($race_fee_cents, $ps);
  $platform_cents = max(0, (int)$platform_cents);

  // 3) fee admin/procacciatore (da admin_settings già caricati in $admin_fee_map)
  $admin_cents = calc_admin_fee_cents($race_fee_cents, $ref_admin_id_int, $admin_fee_map);
  $admin_cents = max(0, (int)$admin_cents);

  // 4) totale + arrotondamento
  $pre_total = $race_fee_cents + $platform_cents + $admin_cents;

  $step = (int)($ps['round_to_cents'] ?? 0);
  $rounded_total = ($step > 0) ? round_up_to($pre_total, $step) : $pre_total;

  $rounding_delta = $rounded_total - $pre_total; // può essere 0 o positivo

  // organizer_net: per ora = quota gara (organizzatore)
  $organizer_net = $race_fee_cents;

  // UPDATE row
  $stmtU->bind_param(
    "iiiiiiiiisii",
    $race_fee_cents,    // base_fee_cents
    $platform_cents,    // platform_fee_cents
    $admin_cents,       // admin_fee_cents
    $rounding_delta,    // rounding_delta_cents
    $rounded_total,     // fee_total_cents
    $organizer_net,     // organizer_net_cents

    $race_fee_cents,    // fee_race_cents
    $platform_cents,    // fee_platform_cents
    $admin_cents,       // fee_admin_cents

    $tier_label,        // fee_tier_label
    $reg_id,            // id
    $race_id            // race_id
  );

  $stmtU->execute();
  $recalc_count++;
}

$stmtU->close();



    // ricarico per sicurezza (così vedi subito aggiornato)
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
</head>

<body style="font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px;">

  <h1>Modifica gara</h1>
  <p><a href="event_detail.php?id=<?php echo (int)$event_id; ?>">← Torna all’evento</a></p>

  <?php if ($error): ?>
    <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
      <?php echo h($error); ?>
    </div>
  <?php endif; ?>

  <form method="post">

  <h3>Quote iscrizione</h3>

<label>Early (€)</label><br>
<input type="text" inputmode="decimal" name="fee_early_eur"
       value="<?php echo h($form['fee_early_eur'] ?? ''); ?>"><br><br>

<label>Regular (€)</label><br>
<input type="text" inputmode="decimal" name="fee_regular_eur"
       value="<?php echo h($form['fee_regular_eur'] ?? ''); ?>"><br><br>

<label>Late (€)</label><br>
<input type="text" inputmode="decimal" name="fee_late_eur"
       value="<?php echo h($form['fee_late_eur'] ?? ''); ?>"><br><br>



    <label>Titolo *</label><br>
    <input name="title" value="<?php echo h($form['title']); ?>" style="width:100%;padding:10px;margin:6px 0 12px;" required>

    <label>Luogo</label><br>
    <input name="location" value="<?php echo h($form['location']); ?>" style="width:100%;padding:10px;margin:6px 0 12px;">

    <label>Data/Ora (inizio)</label><br>
    <input type="datetime-local" name="start_at" value="<?php echo h($form['start_at']); ?>" style="width:100%;padding:10px;margin:6px 0 12px;">

    <label>Disciplina</label><br>
    <select name="discipline" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="cycling" <?php echo ($form['discipline']==='cycling'?'selected':''); ?>>cycling</option>
      <option value="running" <?php echo ($form['discipline']==='running'?'selected':''); ?>>running</option>
      <option value="other" <?php echo ($form['discipline']==='other'?'selected':''); ?>>other</option>
    </select>

    <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">

      <div style="flex:1; min-width:240px;">
        <label for="base_fee">Quota base (organizzatore)</label><br>
        <input
          id="base_fee"
          name="base_fee"
          value="<?php echo h($form['base_fee'] ?? ''); ?>"
          placeholder="es. 13,50"
          style="width:100%;padding:10px;margin:6px 0 12px;"
          inputmode="decimal"
          autocomplete="off"
        >
        <div style="font-size:12px;color:#666;margin-top:-6px;">
          Importo che l’organizzatore intende incassare per ogni iscritto.
        </div>
      </div>

      <div style="flex:1; min-width:240px;">
        <label for="total_fee_preview">Quota online (finale atleta)</label><br>
        <input
          id="total_fee_preview"
          value=""
          readonly
          style="width:100%;padding:10px;margin:6px 0 12px;background:#f6f6f6;"
        >
        <div style="font-size:12px;color:#666;margin-top:-6px;">
          Include commissioni di servizio EasyRace
          <span style="white-space:nowrap;">(ed eventuale procacciatore)</span>.
        </div>
        <div id="fee_breakdown" style="font-size:12px;color:#666;margin-top:4px;"></div>
      </div>

    </div>

    <label>IBAN organizzatore (per questa gara)</label><br>
    <input name="organizer_iban" value="<?php echo h($form['organizer_iban']); ?>" placeholder="es. IT60X0542811101000000123456" style="width:100%;padding:10px;margin:6px 0 12px;">

    <label>Procacciatore (opzionale)</label><br>
    <select name="ref_admin_id" id="ref_admin_id" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="0" <?php echo ($form['ref_admin_id']==='0'?'selected':''); ?>>Nessuno</option>
      <?php foreach ($admins as $a): ?>
        <option value="<?php echo (int)$a['id']; ?>" <?php echo ((string)$a['id']===$form['ref_admin_id']?'selected':''); ?>>
          <?php echo h(($a['full_name'] ?? 'Admin').' · '.($a['email'] ?? '')); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label>Stato</label><br>
    <select name="status" style="width:100%;padding:10px;margin:6px 0 12px;">
      <option value="draft" <?php echo ($form['status']==='draft'?'selected':''); ?>>draft</option>
      <option value="open" <?php echo ($form['status']==='open'?'selected':''); ?>>open</option>
      <option value="closed" <?php echo ($form['status']==='closed'?'selected':''); ?>>closed</option>
      <option value="archived" <?php echo ($form['status']==='archived'?'selected':''); ?>>archived</option>
    </select>

    <button type="submit" style="padding:10px 14px;">Salva modifiche</button>

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

  // qui aggancio la select vera (ref_admin_id)
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

    // Se base è zero/vuota, non mostrare nulla
    if (!baseCents) {
      totalEl.value = '';
      breakdownEl.textContent = '';
      return;
    }

    var platformCents = calcPlatformFeeCents(baseCents);
    var adminCents = getAdminFeeCents();

    var totalCents = baseCents + platformCents + adminCents;
    totalCents = roundUpTo(totalCents, platformSettings.round_to_cents);

    totalEl.value = totalCents ? (centsToEuro(totalCents) + ' €') : '';

    var parts = [];
    if (baseCents) parts.push('Base ' + centsToEuro(baseCents) + '€');
    if (platformCents) parts.push('EasyRace ' + centsToEuro(platformCents) + '€');
    if (adminCents) parts.push('Procacciatore ' + centsToEuro(adminCents) + '€');
    breakdownEl.textContent = parts.length ? ('Dettaglio: ' + parts.join(' + ')) : '';
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
