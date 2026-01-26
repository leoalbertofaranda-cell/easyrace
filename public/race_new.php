<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();
require_manage();

$u = auth_user();
$conn = db($config);

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { header("Location: events.php"); exit; }

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

// verifica evento accessibile
$stmt = $conn->prepare("
  SELECT e.id, e.organization_id
  FROM events e
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE e.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $event_id, $u['id']);
$stmt->execute();
$ev = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$ev) { header("Location: events.php"); exit; }

// conversione importo (es. "13,50" -> 1350)
function money_to_cents(string $s): int {
  $s = trim($s);
  if ($s === '') return 0;
  $s = str_replace(['€', ' '], '', $s);
  $s = str_replace(',', '.', $s);

  // lascia solo numeri e punto
  $s = preg_replace('/[^0-9.]/', '', $s);
  if ($s === '' || $s === '.') return 0;

  // evita "12.3.4"
  $parts = explode('.', $s, 3);
  if (count($parts) > 2) {
    $s = $parts[0] . '.' . $parts[1];
  }

  $val = (float)$s;
  if ($val < 0) $val = 0;
  return (int) round($val * 100);
}

$error = '';

// valori default (per ripopolare form in caso errore)
$form = [
  'title' => '',
  'location' => '',
  'start_at' => '',
  'discipline' => 'other',
  'status' => 'draft',
  'base_fee' => '',
  'organizer_iban' => '',
  'ref_admin_id' => '0',
];

// lista procacciatori (opzionale): prendo utenti role=admin
$admins = [];
$res = $conn->query("SELECT id, full_name, email FROM users WHERE role='admin' ORDER BY full_name ASC");
if ($res) $admins = $res->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form['title'] = trim((string)($_POST['title'] ?? ''));
  $form['location'] = trim((string)($_POST['location'] ?? ''));
  $form['start_at'] = (string)($_POST['start_at'] ?? '');
  $form['discipline'] = (string)($_POST['discipline'] ?? 'other');
  $form['status'] = (string)($_POST['status'] ?? 'draft');
  $form['base_fee'] = trim((string)($_POST['base_fee'] ?? ''));
  $form['organizer_iban'] = strtoupper(trim((string)($_POST['organizer_iban'] ?? '')));
  $form['ref_admin_id'] = (string)($_POST['ref_admin_id'] ?? '0');
  $form['fee_early_eur']   = trim((string)($_POST['fee_early_eur'] ?? ''));
$form['fee_regular_eur'] = trim((string)($_POST['fee_regular_eur'] ?? ''));
$form['fee_late_eur']    = trim((string)($_POST['fee_late_eur'] ?? ''));
$form['fee_early_until'] = (string)($_POST['fee_early_until'] ?? '');
$form['fee_late_from']   = (string)($_POST['fee_late_from'] ?? '');


  $title = $form['title'];
  $location = $form['location'];
  $start_at = $form['start_at'] !== '' ? $form['start_at'] : null;
  $discipline = $form['discipline'];
  $status = $form['status'];

  $base_fee_cents = money_to_cents($form['base_fee']);
  $organizer_iban = $form['organizer_iban'] !== '' ? $form['organizer_iban'] : null;

  $ref_admin_id = (int)$form['ref_admin_id'];
  if ($ref_admin_id <= 0) $ref_admin_id = null;

  if ($title === '') {
    $error = "Titolo obbligatorio.";
  } else {
    $stmt = $conn->prepare("
      INSERT INTO races
        (event_id, title, location, start_at, discipline, status, base_fee_cents, organizer_iban, ref_admin_id)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // bind con null: usiamo variabili dedicate
    $ref_admin_id_bind = $ref_admin_id;      // può essere null
    $organizer_iban_bind = $organizer_iban;  // può essere null

    $stmt->bind_param(
      "isssssssi",
      $event_id,
      $title,
      $location,
      $start_at,
      $discipline,
      $status,
      $base_fee_cents,
      $organizer_iban_bind,
      $ref_admin_id_bind
    );

    // ATTENZIONE: bind_param richiede tipi coerenti.
    // Siccome qui abbiamo int + stringhe + possibili null, facciamo un bind più robusto:
    $stmt->close();

    // bind robusto (senza impazzire): preparo una query senza NULL nei type
    $stmt = $conn->prepare("
      INSERT INTO races
        (event_id, title, location, start_at, discipline, status, base_fee_cents, organizer_iban, ref_admin_id)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    // tipologia: i s s s s s i s i  (ref_admin_id se null -> 0 e lo settiamo NULL in query)
    $ref_admin_id_int = $ref_admin_id ?? 0;

    // se ref_admin_id è null, passiamo 0 e poi lo convertiamo a NULL con NULLIF
    $stmt->close();
    $stmt = $conn->prepare("
      INSERT INTO races
        (event_id, title, location, start_at, discipline, status, base_fee_cents, organizer_iban, ref_admin_id)
      VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?,0))
    ");
    $stmt->bind_param(
      "isssssisi",
      $event_id,
      $title,
      $location,
      $start_at,
      $discipline,
      $status,
      $base_fee_cents,
      $organizer_iban_bind,
      $ref_admin_id_int
    );

    $stmt->execute();
    $stmt->close();

    header("Location: event_detail.php?id=".$event_id);
    exit;
  }
}
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>EasyRace - Nuova gara</title></head>
<body style="font-family:system-ui;max-width:640px;margin:40px auto;padding:0 16px;">
  <h1>Nuova gara</h1>
  <p><a href="event_detail.php?id=<?php echo (int)$event_id; ?>">← Torna all’evento</a></p>

  <?php if ($error): ?>
    <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
      <?php echo h($error); ?>
    </div>
  <?php endif; ?>

  <form method="post">
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

    <?php
// --- Fee (in centesimi) disponibili lato server ---
// TODO: qui devi valorizzarle leggendo dal DB (platform_settings + admin_settings)
// Per ora metto fallback sicuro:
$platform_fee_cents = (int)($platform_fee_cents ?? 0);

// Mappa fee procacciatori: [admin_id => fee_cents]
// Se non ce l'hai ancora, resta vuota (fee=0)
$admin_fee_map_cents = $admin_fee_map_cents ?? [];
?>

<div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">

  <div style="flex:1; min-width:240px;">
    <label for="base_fee">
      Quota base (organizzatore)
    </label><br>
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

  <div style="margin-top:6px;font-size:13px;color:#555;line-height:1.35;">
  <strong>Esempio:</strong>
  se imposti <b>€15,00</b>, l’atleta paga €15,00 + commissioni EasyRace.
  Tu incassi <b>€15,00 netti</b>.
</div>


 <?php if (false): ?>

<h3>Quote iscrizione (opzionali)</h3>

<label>Early (€)</label><br>
<input type="number" step="0.01" name="fee_early_eur"
  value="<?php echo h(cents_to_eur((int)($race['fee_early_cents'] ?? 0))); ?>"><br><br>

<label>Early fino al</label><br>
<input type="date" name="fee_early_until"
  value="<?php echo h((string)($race['fee_early_until'] ?? '')); ?>"><br><br>

<label>Regular (€)</label><br>
<input type="number" step="0.01" name="fee_regular_eur"
  value="<?php echo h(cents_to_eur((int)($race['fee_regular_cents'] ?? 0))); ?>"><br><br>

<label>Late (€) – valido il giorno gara</label><br>
<input type="number" step="0.01" name="fee_late_eur"
  value="<?php echo h(cents_to_eur((int)($race['fee_late_cents'] ?? 0))); ?>"><br><br>

<div style="font-size:12px;color:#666;">
  Regular vale dal giorno successivo alla scadenza Early fino al giorno prima della gara.
  Late vale il giorno della gara (data gara).
</div>


<?php endif; ?>



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
    <div
      id="fee_breakdown"
      style="font-size:12px;color:#666;margin-top:4px;"
    ></div>
  </div>

</div>



<script>
(function(){
  // --- Helpers ---
  function parseEuroToCents(str){
    str = (str || '').trim();
    if (!str) return 0;
    // accetta "13,50" o "13.50" o "13" e anche "1.234,50"
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

  // --- Settings da PHP (platform) ---
  var platformSettings = <?php echo json_encode($ps, JSON_UNESCAPED_UNICODE); ?>;

  // --- Procacciatori (fixed per ora): { "12": 100, "15": 200, ... } ---
  var adminFeeMapCents = <?php echo json_encode($admin_fee_map_cents, JSON_UNESCAPED_UNICODE); ?>;

  // Elementi DOM
  var baseEl   = document.getElementById('base_fee');
  var totalEl  = document.getElementById('total_fee_preview');
  var breakdownEl = document.getElementById('fee_breakdown');

  // Procacciatore select: aggancio robusto (id o vari name)
  var procEl =
    document.getElementById('procacciatore') ||
    document.querySelector('select[name="procacciatore"], select[name="admin_id"], select[name="procacciatore_id"]');

  function calcPlatformFeeCents(baseCents){
    if (platformSettings.fee_type === 'percent') {
      var bp = parseInt(platformSettings.fee_value_bp || 0, 10) || 0; // basis points
      return Math.round(baseCents * (bp / 10000));
    }
    return parseInt(platformSettings.fee_value_cents || 0, 10) || 0; // fixed
  }

  function getAdminFeeCents(){
    if (!procEl) return 0;
    var v = String(procEl.value || '').trim();
    if (!v || v === '0') return 0;
    return parseInt(adminFeeMapCents[v] || 0, 10) || 0;
  }

  function recalc(){
    var baseCents = parseEuroToCents(baseEl ? baseEl.value : '');

    // Se la quota base è zero o vuota, non mostrare nulla
if (!baseCents) {
  totalEl.value = '';
  breakdownEl.textContent = '';
  return;
}

    var platformCents = calcPlatformFeeCents(baseCents);
    var adminCents = getAdminFeeCents();

    var totalCents = baseCents + platformCents + adminCents;

    // arrotondamento (usa quello della piattaforma)
    totalCents = roundUpTo(totalCents, platformSettings.round_to_cents);

    totalEl.value = totalCents ? (centsToEuro(totalCents) + ' €') : '';

    // Dettaglio
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

  // init
  recalc();
})();
</script>


    <label>IBAN organizzatore (per questa gara)</label><br>
    <input name="organizer_iban" value="<?php echo h($form['organizer_iban']); ?>" placeholder="es. IT60X0542811101000000123456" style="width:100%;padding:10px;margin:6px 0 12px;">

    <label>Procacciatore (opzionale)</label><br>
    <select name="ref_admin_id" style="width:100%;padding:10px;margin:6px 0 12px;">
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

    <button type="submit" style="padding:10px 14px;">Crea gara</button>
  </form>
</body>
</html>
