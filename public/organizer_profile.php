<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();

$u = auth_user();
if (!$u) { header("Location: login.php"); exit; }

$conn = db($config);

$role = (string)($u['role'] ?? '');
$allowed = in_array($role, ['organizer','admin','procacciatore','superuser','platform'], true);
if (!$allowed) {
  http_response_code(403);
  echo "Accesso negato.";
  exit;
}

$uid = (int)($u['id'] ?? 0);
if ($uid <= 0) { header("Location: login.php"); exit; }

// ------------------------------------------------------
// 1) Determino organization_id
// ------------------------------------------------------
$org_id = (int)($u['organization_id'] ?? 0);

if ($org_id <= 0) {
  $stmt = @$conn->prepare("
    SELECT organization_id
    FROM organization_users
    WHERE user_id = ?
    LIMIT 1
  ");
  if ($stmt) {
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $org_id = (int)($row['organization_id'] ?? 0);
  }
}

if ($org_id <= 0) {
  http_response_code(404);
  echo "Organizzazione non trovata per questo account.";
  exit;
}

// ------------------------------------------------------
// 2) Carico organizations (safe)
// ------------------------------------------------------
$stmt = $conn->prepare("SELECT o.* FROM organizations o WHERE o.id = ? LIMIT 1");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$org) {
  http_response_code(404);
  echo "Organizzazione non trovata.";
  exit;
}

// ------------------------------------------------------
// Helpers UI
// ------------------------------------------------------
function v($s): string {
  $s = trim((string)$s);
  return $s !== '' ? $s : '—';
}

function first_existing(array $row, array $keys): string {
  foreach ($keys as $k) {
    if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') {
      return (string)$row[$k];
    }
  }
  return '';
}

$name = v($org['name'] ?? ($org['ragione_sociale'] ?? ''));

// Stripe fields (nomi possibili)
$stripe_account_id     = $org['stripe_account_id'] ?? ($org['stripe_account'] ?? ($org['stripe_id'] ?? ''));
$stripe_onboard_status = $org['stripe_onboard_status'] ?? ($org['stripe_status'] ?? '');

$stripeLinked = !empty($stripe_account_id);
$stripeLabel  = $stripeLinked ? 'Collegato' : 'Non collegato';
$stripeStatus = v($stripe_onboard_status);

$identityFields = [
  'Codice fiscale'  => ['fiscal_code','codice_fiscale','cf'],
  'Partita IVA'     => ['vat_number','partita_iva','piva'],
  'Forma giuridica' => ['legal_form','forma_giuridica','tipo'],
];

$contactFields = [
  'Nome'     => ['contact_name','referente_nome','ref_name','name_contact'],
  'Email'    => ['contact_email','referente_email','ref_email','email_contact'],
  'Telefono' => ['contact_phone','referente_phone','ref_phone','phone_contact'],
];

page_header('Profilo organizzatore');
?>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:14px;background:#fff;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div>
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Organizzatore</div>
      <div style="font-size:22px;font-weight:900;line-height:1.2;">Profilo organizzatore</div>
      <div style="margin-top:6px;color:#555;font-size:14px;">
        Ragione sociale: <strong><?php echo h($name); ?></strong>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a href="dashboard.php"
         style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
        ← Dashboard
      </a>
    </div>
  </div>
</section>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:14px;background:#fff;">
  <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Identità</div>

  <div style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <?php foreach ($identityFields as $label => $keys): ?>
      <?php $val = first_existing($org, $keys); ?>
      <div style="padding:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;">
        <div style="font-size:12px;color:#666;"><?php echo h($label); ?></div>
        <div style="font-weight:900;"><?php echo h(v($val)); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:14px;background:#fff;">
  <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Referente</div>

  <div style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <?php foreach ($contactFields as $label => $keys): ?>
      <?php $val = first_existing($org, $keys); ?>
      <div style="padding:10px;border:1px solid #eee;border-radius:12px;background:#fafafa;">
        <div style="font-size:12px;color:#666;"><?php echo h($label); ?></div>
        <div style="font-weight:900;"><?php echo h(v($val)); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:14px;background:#fff;">
  <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Stato</div>

  <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
    <span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;">
      Account: Attivo
    </span>

    <span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;background:#fafafa;">
      Stripe: <?php echo h($stripeLabel); ?>
    </span>

    <span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #eee;font-weight:800;color:#555;background:#fafafa;">
      Stato Stripe: <?php echo h($stripeStatus); ?>
    </span>
  </div>

  <?php if (!$stripeLinked): ?>
    <div style="margin-top:12px;padding:10px;border:1px dashed #bbb;border-radius:12px;background:#fafafa;color:#555;">
      Pagamenti online disponibili dopo il collegamento dell’account Stripe.
      <div style="margin-top:8px;">
        <a href="stripe_onboarding.php?org_id=<?php echo (int)$org_id; ?>"
           style="display:inline-block;padding:10px 14px;border:1px solid #111;border-radius:10px;text-decoration:none;font-weight:900;">
          Collega Stripe →
        </a>
        <div style="margin-top:6px;color:#666;font-size:12px;">
          (Per la demo: anche solo pagina informativa / placeholder va bene)
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php
page_footer();
