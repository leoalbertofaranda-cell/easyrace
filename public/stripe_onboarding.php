<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

$org_id = (int)($_GET['org_id'] ?? 0);

// 1) Lista org dove l'utente è OWNER
$stmt = $conn->prepare("
  SELECT o.id, o.name
  FROM organization_users ou
  JOIN organizations o ON o.id = ou.organization_id
  WHERE ou.user_id = ? AND ou.org_role = 'owner'
  ORDER BY o.name ASC
");
$stmt->bind_param("i", $u['id']);
$stmt->execute();
$owner_orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$owner_orgs) {
  header("HTTP/1.1 403 Forbidden");
  exit("Solo l’owner di un’organizzazione può attivare i pagamenti con carta.");
}

// 2) Se org_id non passato:
// - se 1 sola org -> redirect automatico
// - se più org -> pagina scelta
if ($org_id <= 0) {
  if (count($owner_orgs) === 1) {
    $only_id = (int)$owner_orgs[0]['id'];
    header("Location: stripe_onboarding.php?org_id=" . $only_id);
    exit;
  }

  page_header('Attiva pagamenti - Seleziona organizzazione');
  ?>
  <section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;background:#fafafa;">
    <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Pagamenti</div>
    <div style="font-size:20px;font-weight:900;line-height:1.2;">Seleziona organizzazione</div>
    <div style="margin-top:6px;color:#555;font-size:13px;">
      Hai più organizzazioni come owner: scegli quale attivare.
    </div>

    <div style="margin-top:12px;display:grid;gap:8px;">
      <?php foreach ($owner_orgs as $o): ?>
        <a href="stripe_onboarding.php?org_id=<?php echo (int)$o['id']; ?>"
           style="display:block;padding:10px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;background:#fff;">
          <b><?php echo h((string)($o['name'] ?? '')); ?></b>
          <span style="color:#777;font-size:12px;"> →</span>
        </a>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:12px;">
      <a href="organizations.php"
         style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
        ← Torna a Organizzazioni
      </a>
    </div>
  </section>
  <?php
  page_footer();
  exit;
}

// 3) Validazione: org_id deve essere una delle owner_orgs
$allowed = false;
foreach ($owner_orgs as $o) {
  if ((int)$o['id'] === $org_id) { $allowed = true; break; }
}
if (!$allowed) {
  header("HTTP/1.1 403 Forbidden");
  exit("Accesso negato (organizzazione non valida per questo account).");
}

// 4) Carico dati org (Stripe status)
$stmt = $conn->prepare("
  SELECT
    id, name,
    stripe_account_id,
    stripe_onboarding_status,
    stripe_charges_enabled,
    stripe_payouts_enabled,
    stripe_details_submitted
  FROM organizations
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$org) {
  header("HTTP/1.1 404 Not Found");
  exit("Organizzazione non trovata.");
}

$stripe_ready =
  !empty($org['stripe_account_id']) &&
  (int)($org['stripe_charges_enabled'] ?? 0) === 1 &&
  (int)($org['stripe_payouts_enabled'] ?? 0) === 1 &&
  (int)($org['stripe_details_submitted'] ?? 0) === 1;

page_header('Attiva pagamenti - ' . ($org['name'] ?? 'Organizzazione'));
?>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;background:#fafafa;">
  <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
    <div>
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Pagamenti</div>
      <div style="font-size:20px;font-weight:900;line-height:1.2;">Stripe Connect</div>
      <div style="margin-top:6px;color:#555;font-size:14px;">
        Organizzazione: <b><?php echo h($org['name'] ?? ''); ?></b>
      </div>
    </div>

    <span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;
      background:<?php echo $stripe_ready ? '#eaffea' : '#fff6e5'; ?>;">
      <?php echo $stripe_ready ? 'ATTIVO' : 'NON ATTIVO'; ?>
    </span>
  </div>

  <div style="margin-top:12px;font-size:13px;color:#444;line-height:1.6;">
    <div><b>Account:</b> <?php echo !empty($org['stripe_account_id']) ? h($org['stripe_account_id']) : '<span style="color:#b00;font-weight:900;">mancante</span>'; ?></div>
    <div><b>Onboarding:</b> <?php echo h((string)($org['stripe_onboarding_status'] ?? 'not_started')); ?></div>
    <div><b>Charges enabled:</b> <?php echo ((int)($org['stripe_charges_enabled'] ?? 0) === 1) ? 'sì' : '<span style="color:#b00;font-weight:900;">no</span>'; ?></div>
    <div><b>Payouts enabled:</b> <?php echo ((int)($org['stripe_payouts_enabled'] ?? 0) === 1) ? 'sì' : '<span style="color:#b00;font-weight:900;">no</span>'; ?></div>
    <div><b>Details submitted:</b> <?php echo ((int)($org['stripe_details_submitted'] ?? 0) === 1) ? 'sì' : '<span style="color:#b00;font-weight:900;">no</span>'; ?></div>
  </div>

  <div style="margin-top:12px;padding:10px;border:1px dashed #ccc;border-radius:10px;background:#fff;">
    <div style="font-weight:900;margin-bottom:6px;">Onboarding (in arrivo)</div>
    <div style="color:#555;font-size:13px;line-height:1.4;">
      In questa versione non avviamo ancora la procedura Stripe.
      Qui metteremo il bottone che crea/collega l’account e apre l’onboarding guidato.
    </div>

    <button type="button" disabled
      style="margin-top:10px;padding:10px 14px;border:1px solid #ddd;border-radius:10px;background:#eee;color:#666;font-weight:900;cursor:not-allowed;">
      Avvia onboarding Stripe (in arrivo)
    </button>
  </div>

  <?php if (count($owner_orgs) > 1): ?>
    <div style="margin-top:10px;">
      <a href="stripe_onboarding.php"
         style="font-size:12px;font-weight:900;text-decoration:none;">
        Cambia organizzazione
      </a>
    </div>
  <?php endif; ?>

  <div style="margin-top:12px;">
    <a href="organizations.php"
       style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
      ← Torna a Organizzazioni
    </a>
  </div>
</section>

<?php page_footer(); ?>
