<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';


require_login();

$u = auth_user();
$role = (string)($u['role'] ?? '');

$conn = db($config);

// ===============================
// Payments summary (Stripe status)
// ===============================
$stmt = $conn->prepare("
 SELECT
  o.id,
  o.name,
  ou.org_role,
  o.stripe_account_id,
  o.stripe_onboarding_status,
  o.stripe_charges_enabled,
  o.stripe_payouts_enabled,
  o.stripe_details_submitted
FROM organization_users ou
JOIN organizations o ON o.id = ou.organization_id
WHERE ou.user_id = ?
ORDER BY o.name ASC

");
$stmt->bind_param("i", $u['id']);
$stmt->execute();
$orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stripe_ready_count = 0;
foreach ($orgs as $o) {
  $ready =
    !empty($o['stripe_account_id']) &&
    (int)($o['stripe_charges_enabled'] ?? 0) === 1 &&
    (int)($o['stripe_payouts_enabled'] ?? 0) === 1 &&
    (int)($o['stripe_details_submitted'] ?? 0) === 1;
  if ($ready) $stripe_ready_count++;
}

$is_owner_any = false;
$first_owner_org_id = 0;

foreach ($orgs as $o) {
  if ((string)($o['org_role'] ?? '') === 'owner') {
    $is_owner_any = true;
    if ($first_owner_org_id <= 0) $first_owner_org_id = (int)($o['id'] ?? 0);
  }
}

$stripe_any_ready = ($stripe_ready_count > 0);



page_header('Dashboard');
?>


<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
    <div style="min-width:260px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Dashboard</div>
      <div style="font-size:22px;font-weight:900;line-height:1.2;">
        Ciao <?php echo h($u['full_name'] ?? ''); ?>
      </div>
      <div style="margin-top:6px;color:#555;font-size:14px;">
        Ruolo: <strong><?php echo h($role); ?></strong>
      </div>
    </div>
    <div style="min-width:220px;display:grid;gap:8px;align-content:start;">
      <a href="calendar.php"
         style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;text-align:center;">
        Calendario eventi
      </a>
    </div>
  </div>
</section>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;background:#fafafa;">
  <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
    <div>
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Pagamenti</div>
      <div style="font-size:18px;font-weight:900;line-height:1.2;">Pagamenti con carta (Stripe)</div>
      <div style="margin-top:6px;color:#555;font-size:13px;">
        Stato complessivo delle tue organizzazioni.
      </div>
    </div>

    <span style="display:inline-block;padding:6px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;
      background:<?= $stripe_any_ready ? '#eaffea' : '#fff6e5' ?>;">
      <?= $stripe_any_ready ? 'ATTIVI' : 'NON ATTIVI' ?>
    </span>
  </div>

  <div style="margin-top:10px;font-size:13px;color:#444;line-height:1.5;">
    Organizzazioni collegate: <b><?= (int)count($orgs) ?></b><br>
    Organizzazioni pronte per Stripe: <b><?= (int)$stripe_ready_count ?></b>
  </div>

  <div style="margin-top:12px;">
    <a href="organizations.php"
       style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
      Vai a Organizzazioni →
    </a>
  </div>
</section>

<?php if (!$stripe_any_ready && !$is_owner_any): ?>
  <div style="margin-top:10px;color:#666;font-size:12px;">
    Solo l’owner dell’organizzazione può attivare i pagamenti con carta.
  </div>
<?php endif; ?>


<?php if (!$stripe_any_ready && $is_owner_any && $first_owner_org_id > 0): ?>
  <div style="margin-top:10px;">
    <a href="stripe_onboarding.php?org_id=<?php echo (int)$first_owner_org_id; ?>"
       style="display:inline-block;padding:10px 14px;border:1px solid #111;border-radius:10px;
              text-decoration:none;font-weight:900;">
      Attiva pagamenti con carta →
    </a>
    <div style="margin-top:6px;color:#666;font-size:12px;">
      (Placeholder: onboarding Stripe non ancora implementato)
    </div>
  </div>
<?php endif; ?>

<div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">

  <?php if (in_array($role, ['superuser','admin','organizer'], true)): ?>
    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Gestione</div>
      <div style="font-size:18px;font-weight:900;margin-top:4px;">Organizzazioni</div>
      <div style="color:#555;font-size:14px;margin-top:6px;">Crea e gestisci le organizzazioni.</div>
      <a href="organizations.php" style="display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
        Apri
      </a>
    </div>

    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Gestione</div>
      <div style="font-size:18px;font-weight:900;margin-top:4px;">Eventi</div>
      <div style="color:#555;font-size:14px;margin-top:6px;">Crea e pubblica eventi e gare.</div>
      <a href="events.php" style="display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
        Apri
      </a>
    </div>

  <?php else: ?>
    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Atleta</div>
      <div style="font-size:18px;font-weight:900;margin-top:4px;">Iscriviti alle gare</div>
      <div style="color:#555;font-size:14px;margin-top:6px;">Vai al calendario e apri un evento per vedere le gare.</div>
      <a href="calendar.php" style="display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
        Apri calendario
      </a>
    </div>

    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Account</div>
      <div style="font-size:18px;font-weight:900;margin-top:4px;">Profilo atleta</div>
      <div style="color:#555;font-size:14px;margin-top:6px;">Completa o aggiorna i tuoi dati.</div>
      <a href="athlete_profile.php" style="display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
        Apri profilo
      </a>
    </div>

<div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
  <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Atleta</div>
  <div style="font-size:18px;font-weight:900;margin-top:4px;">Le mie iscrizioni</div>
  <div style="color:#555;font-size:14px;margin-top:6px;">Controlla stato e pagamenti delle tue iscrizioni.</div>
  <a href="my_registrations.php" style="display:inline-block;margin-top:10px;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
    Apri
  </a>
</div>

  <?php endif; ?>

</div>


<?php

page_footer();
