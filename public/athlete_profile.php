<?php
declare(strict_types=1);

// public/athlete_profile.php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/auth.php';

require_login();
require_roles(['athlete']);

$conn = db($config);

$u = auth_user();
$user_id = (int)($u['id'] ?? 0);
if ($user_id <= 0) {
  header('HTTP/1.1 403 Forbidden');
  exit('Sessione non valida.');
}

// Escape HTML (sicuro anche se bootstrap non lo definisce)
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

$email   = (string)($u['email'] ?? '');
$success = '';
$error   = '';
$taxWarn = '';

// ======================================================
// Campi obbligatori (servono sia in GET che in POST)
// ======================================================
$required = [
  'first_name' => 'Nome',
  'last_name' => 'Cognome',
  'birth_date' => 'Data di nascita',
  'gender' => 'Sesso',
  'shirt_size' => 'Taglia maglietta',
  'cap' => 'CAP',
  'city' => 'Città',
  'tax_code' => 'Codice fiscale',
  'phone_mobile' => 'Telefono mobile',
  'primary_membership_federation_code' => 'Ente tessera',
  'primary_membership_number' => 'Nr tessera',
  'medical_cert_date' => 'Data certificato medico',
];

// ======================================================
// Form defaults
// ======================================================
$form = [
  'first_name' => '',
  'last_name' => '',
  'birth_date' => '',
  'gender' => 'M',

  'shirt_size' => '',
  'pants_size' => '',
  'shoe_size' => '',

  'address_residence' => '',
  'cap' => '',
  'city' => '',
  'tax_code' => '',
  'phone_mobile' => '',
  'phone_landline' => '',

  'primary_membership_federation_code' => 'FCI',
  'primary_membership_number' => '',

  'club_name' => '',

  'medical_cert_date' => '',
  'medical_cert_type' => 'AGONISTICO',

  'consent_privacy' => 0,
  'consent_communications' => 0,
  'consent_marketing' => 0,
];

// ======================================================
// Carica profilo esistente (se c'è) + prefill form
// ======================================================
$existing = null;

$stmt = $conn->prepare("SELECT * FROM athlete_profile WHERE user_id = ? LIMIT 1");
if (!$stmt) {
  exit("Errore DB (prepare): " . h($conn->error));
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
  // aggiorna sessione (serve a race.php)
  $_SESSION['auth']['birth_date'] = $existing['birth_date'] ?? null;
  $_SESSION['auth']['gender']     = $existing['gender'] ?? null;

  foreach ($form as $k => $v) {
    if (array_key_exists($k, $existing)) {
      $form[$k] = $existing[$k] ?? $v;
    }
  }
  $form['consent_privacy']        = (int)($existing['consent_privacy'] ?? 0);
  $form['consent_communications'] = (int)($existing['consent_communications'] ?? 0);
  $form['consent_marketing']      = (int)($existing['consent_marketing'] ?? 0);
} else {
  // normalizza consensi anche in assenza profilo
  $form['consent_privacy']        = (int)($form['consent_privacy'] ?? 0);
  $form['consent_communications'] = (int)($form['consent_communications'] ?? 0);
  $form['consent_marketing']      = (int)($form['consent_marketing'] ?? 0);
}

// ======================================================
// Helper: calcola missing/profile_complete (sempre)
// ======================================================
$missing = [];
foreach ($required as $k => $label) {
  if (trim((string)($form[$k] ?? '')) === '') {
    $missing[] = $label;
  }
}
$profile_complete = (count($missing) === 0);

// ======================================================
// Salva profilo (POST)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $form['first_name'] = trim((string)($_POST['first_name'] ?? ''));
  $form['last_name']  = trim((string)($_POST['last_name'] ?? ''));
  $form['birth_date'] = trim((string)($_POST['birth_date'] ?? ''));
  $form['gender']     = (string)($_POST['gender'] ?? 'M');

  $form['shirt_size'] = trim((string)($_POST['shirt_size'] ?? ''));
  $form['pants_size'] = trim((string)($_POST['pants_size'] ?? ''));
  $form['shoe_size']  = trim((string)($_POST['shoe_size'] ?? ''));

  $form['address_residence'] = trim((string)($_POST['address_residence'] ?? ''));
  $form['cap']  = trim((string)($_POST['cap'] ?? ''));
  $form['city'] = trim((string)($_POST['city'] ?? ''));
  $form['tax_code'] = strtoupper(trim((string)($_POST['tax_code'] ?? '')));
  $form['tax_code'] = preg_replace('/\s+/', '', $form['tax_code']);

  // warning soft codice fiscale
  $taxWarn = '';
  if ($form['tax_code'] !== '' && function_exists('validate_tax_code') && !validate_tax_code($form['tax_code'])) {
    $taxWarn = "Attenzione: il Codice Fiscale inserito sembra non valido (controlla eventuali errori di battitura).";
  }

  $form['phone_mobile']   = trim((string)($_POST['phone_mobile'] ?? ''));
  $form['phone_landline'] = trim((string)($_POST['phone_landline'] ?? ''));

  $form['primary_membership_federation_code'] = trim((string)($_POST['primary_membership_federation_code'] ?? 'FCI'));
  $form['primary_membership_number'] = trim((string)($_POST['primary_membership_number'] ?? ''));

  $form['club_name'] = trim((string)($_POST['club_name'] ?? ''));

  $form['medical_cert_date'] = trim((string)($_POST['medical_cert_date'] ?? ''));
  $form['medical_cert_type'] = (string)($_POST['medical_cert_type'] ?? 'AGONISTICO');

  $form['consent_privacy']        = isset($_POST['consent_privacy']) ? 1 : 0;
  $form['consent_communications'] = isset($_POST['consent_communications']) ? 1 : 0;
  $form['consent_marketing']      = isset($_POST['consent_marketing']) ? 1 : 0;

  // valida obbligatori (messaggio singolo)
  foreach ($required as $k => $label) {
    if (trim((string)($form[$k] ?? '')) === '') {
      $error = "Campo obbligatorio mancante: {$label}.";
      break;
    }
  }

  // ricalcola missing/profile_complete per UI anche dopo POST
  $missing = [];
  foreach ($required as $k => $label) {
    if (trim((string)($form[$k] ?? '')) === '') {
      $missing[] = $label;
    }
  }
  $profile_complete = (count($missing) === 0);

  // ... qui sotto lasci invariato: controlli regex, calcolo validità, upload, INSERT/UPDATE, success, refresh session ...
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Profilo Atleta</title>
  <link rel="stylesheet" href="assets/app.css">

<style>
  *, *::before, *::after { box-sizing: border-box; }
  body{ background:#f6f7f8; }

  .ap-wrap{ max-width: 980px; margin: 28px auto; padding: 0 14px; }
  .ap-title{ font-weight: 900; font-size: 26px; margin: 0 0 8px; }
  .ap-sub{ color:#555; margin: 0 0 16px; }

  .ap-grid{
    display:grid;
    grid-template-columns: 1fr;
    gap: 14px;
  }
  @media (min-width: 900px){
    .ap-grid{ grid-template-columns: 1fr 1fr; }
    .ap-span-2{ grid-column: 1 / -1; }
  }

  .ap-card{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:16px;
    padding:16px;
    box-shadow:0 6px 18px rgba(0,0,0,.05);
  }
  .ap-card h3{
    margin:0 0 10px;
    font-size:16px;
    font-weight:900;
  }
  .ap-help{ color:#6b7280; font-size:13px; margin-top:-6px; margin-bottom:10px; }

  .ap-row{ display:grid; gap:10px; }
  .ap-row-2{
    display:grid;
    grid-template-columns: 1fr;
    gap: 12px;
  }
  @media (min-width: 720px){
    .ap-row-2{ grid-template-columns: 1fr 1fr; }
  }

  label{ display:block; font-weight:700; font-size:13px; margin: 8px 0 6px; }
  input, select, textarea{
    width:100%;
    padding:10px 12px;
    border:1px solid #d1d5db;
    border-radius:12px;
    background:#fff;
  }
  textarea{ min-height: 110px; resize: vertical; }

  .ap-alert{
    border-radius:14px;
    padding:12px 14px;
    border:1px solid #e5e7eb;
    background:#fff;
    margin: 0 0 14px;
  }
  .ap-alert.ok{ border-color:#86efac; background:#f0fdf4; }
  .ap-alert.ko{ border-color:#fde68a; background:#fffbeb; }
  .ap-alert strong{ font-weight:900; }
  .ap-actions{ display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 16px; }

  .btn{
    display:inline-block;
    padding:10px 12px;
    border-radius:12px;
    border:1px solid #d1d5db;
    background:#fff;
    text-decoration:none;
    font-weight:800;
    color:#111;
  }
  .btn-primary{
    border:0;
    background:#111827;
    color:#fff;
  }

  .ap-sticky{
    position: sticky;
    bottom: 0;
    background: rgba(246,247,248,.92);
    backdrop-filter: blur(8px);
    border-top:1px solid #e5e7eb;
    padding: 10px 0;
    margin-top: 14px;
  }
  .ap-sticky-inner{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
  }
  .ap-muted{ color:#6b7280; font-size:12px; }
</style>


</head>
<body>

<div class="container" style="max-width: 860px; margin: 24px auto; padding: 0 12px;">
  <h1>Profilo Atleta</h1>

  <?php if ($profile_complete): ?>
    <div class="alert alert-success">
      Profilo completo: puoi iscriverti alle gare.
    </div>
  <?php else: ?>
    <div class="alert alert-warning">
      <strong>Profilo incompleto:</strong> completa i campi obbligatori per poterti iscrivere.
      <div class="small" style="margin-top:6px;">
        Mancano: <?php echo h(implode(', ', $missing)); ?>
      </div>
    </div>
  <?php endif; ?>



  <div style="margin:8px 0 14px; display:flex; gap:10px; flex-wrap:wrap;">
  <a class="btn btn-outline-secondary btn-sm" href="dashboard.php">← Dashboard</a>
  <a class="btn btn-outline-secondary btn-sm" href="my_registrations.php">Le mie iscrizioni</a>
</div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?=h($error)?></div>
  <?php endif; ?>

  <?php if (!empty($taxWarn)): ?>
  <div class="alert alert-warning"><?=h($taxWarn)?></div>
<?php endif; ?>


  <?php if ($success): ?>
    <div class="alert alert-success"><?=h($success)?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <h3>Dati obbligatori</h3>

    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;margin:10px 0 16px;">
  <div style="color:#555;font-size:14px;margin-bottom:10px;">
    I campi con * sono necessari per iscriversi alle gare.
  </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
      <div>
        <label>Nome *</label>
        <input class="form-control" name="first_name" value="<?=h((string)$form['first_name'])?>" required>
      </div>

      <div>
        <label>Cognome *</label>
        <input class="form-control" name="last_name" value="<?=h((string)$form['last_name'])?>" required>
      </div>
</div>
      <label>Data di nascita *</label>
<input type="date" class="form-control" name="birth_date" value="<?=h((string)$form['birth_date'])?>" required>


      <div>
        <label>Sesso *</label>
        <select class="form-select" name="gender" required>
          <option value="M" <?=($form['gender']==='M'?'selected':'')?>>M</option>
          <option value="F" <?=($form['gender']==='F'?'selected':'')?>>F</option>
        </select>
      </div>

      <div>
        <label>E-mail *</label>
        <input class="form-control" value="<?=h($email)?>" readonly>
        <div class="small text-muted">È l’email usata per l’accesso.</div>
      </div>

      <div>
        <label>Taglia maglietta *</label>
        <input class="form-control" name="shirt_size" value="<?=h((string)$form['shirt_size'])?>" placeholder="S / M / L" required>
      </div>

      <div>
        <label>Ente tessera *</label>
        <select class="form-select" name="primary_membership_federation_code" required>
          <?php foreach (['FCI','ACSI','FIDAL','UISP','CSI'] as $o): ?>
            <option value="<?=$o?>" <?=($form['primary_membership_federation_code']===$o?'selected':'')?>>
              <?=$o?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Team / Club</label>
        <input class="form-control" name="club_name" value="<?=h((string)$form['club_name'])?>" placeholder="Es. ASD Example Team">
      </div>

      <div>
        <label>Nr tessera *</label>
        <input class="form-control" name="primary_membership_number" value="<?=h((string)$form['primary_membership_number'])?>" required>
        <div style="margin-top:8px;">
          <a class="btn btn-outline-secondary btn-sm" href="athlete_memberships.php">Gestisci tesseramenti aggiuntivi</a>
        </div>
      </div>

      <div>
        <label>CAP *</label>
        <input class="form-control" name="cap" value="<?=h((string)$form['cap'])?>" required>
      </div>

      <div>
        <label>Città *</label>
        <input class="form-control" name="city" value="<?=h((string)$form['city'])?>" required>
      </div>

      <div>
        <label>Codice fiscale *</label>
        <input class="form-control" name="tax_code" value="<?=h((string)$form['tax_code'])?>" required>
      </div>

      <div>
        <label>Telefono mobile *</label>
        <input class="form-control" name="phone_mobile" value="<?=h((string)$form['phone_mobile'])?>" required>
      </div>

      <label>Data certificato medico *</label>
<input type="date" class="form-control" name="medical_cert_date" value="<?=h((string)$form['medical_cert_date'])?>" required>


      <div>
        <label>Tipo certificato *</label>
        <select class="form-select" name="medical_cert_type" required>
          <option value="AGONISTICO" <?=($form['medical_cert_type']==='AGONISTICO'?'selected':'')?>>Agonistico</option>
          <option value="NON_AGONISTICO" <?=($form['medical_cert_type']==='NON_AGONISTICO'?'selected':'')?>>Non agonistico</option>
        </select>
      </div>
    </div>

    <h3 style="margin-top:18px;">Dati facoltativi</h3>

    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;margin:10px 0 16px;background:#fafafa;">
  <div style="color:#555;font-size:14px;margin-bottom:10px;">
    Questi dati aiutano l’organizzazione, ma non bloccano l’iscrizione.
  </div>


    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
      <div>
        <label>Taglia pantalone</label>
        <input class="form-control" name="pants_size" value="<?=h((string)$form['pants_size'])?>">
      </div>

      <div>
        <label>Numero scarpe</label>
        <input class="form-control" name="shoe_size" value="<?=h((string)$form['shoe_size'])?>">
      </div>

      <div style="grid-column: 1 / -1;">
        <label>Indirizzo residenza</label>
        <input class="form-control" name="address_residence" value="<?=h((string)$form['address_residence'])?>">
      </div>

      <div>
        <label>Telefono fisso</label>
        <input class="form-control" name="phone_landline" value="<?=h((string)$form['phone_landline'])?>">
      </div>

      <div>
        <label>Upload certificato medico (PDF/JPG/PNG)</label>
        <input class="form-control" type="file" name="medical_cert_file" accept=".pdf,.jpg,.jpeg,.png">
        <?php if (!empty($existing['medical_cert_file'])): ?>
          <div class="small text-muted">File attuale: <?=h((string)$existing['medical_cert_file'])?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($existing['medical_cert_valid_until'])): ?>
  <div class="small text-muted">
    Valido fino al: <?=h((string)$existing['medical_cert_valid_until'])?>
  </div>
  </div>
<?php endif; ?>


    <h3 style="margin-top:18px;">Consensi</h3>

    <div style="display:flex; flex-direction:column; gap:8px;">
      <label>
        <input type="checkbox" name="consent_privacy" value="1" <?=($form['consent_privacy'] ? 'checked' : '')?>>
        Autorizzazione trattamento dati * (obbligatoria)
      </label>

      <label>
        <input type="checkbox" name="consent_communications" value="1" <?=($form['consent_communications'] ? 'checked' : '')?>>
        Autorizzo comunicazioni di servizio
      </label>

      <label>
        <input type="checkbox" name="consent_marketing" value="1" <?=($form['consent_marketing'] ? 'checked' : '')?>>
        Autorizzo marketing
      </label>
    </div>

  <div style="height:72px;"></div> <!-- spacer per la barra sticky -->

<div style="
  position:sticky;
  bottom:0;
  background:#fff;
  border-top:1px solid #ddd;
  padding:12px 0;
  margin-top:18px;
">
  <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
    <div style="color:#666;font-size:12px;">
      La validità certificato viene calcolata automaticamente (+365 giorni).
    </div>
    <button class="btn btn-primary" type="submit">Salva profilo</button>
  </div>
</div>

  </form>
</div>

</body>
</html>
