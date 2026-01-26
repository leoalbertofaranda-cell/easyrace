<?php
// public/my_registrations.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/helpers.php';

require_login();

$u = auth_user();
$role = (string)($u['role'] ?? '');
if ($role !== 'athlete') {
  page_header('Le mie iscrizioni');
  echo "<div style='padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;margin:12px 0;'>";
  echo "<div style='font-weight:900;margin-bottom:4px;'>Accesso non disponibile</div>";
  echo "<div style='color:#555;font-size:14px;'>Questa sezione è riservata agli account atleta.</div>";
  echo "</div>";
  page_footer();
  exit;
}

$conn = db($config);

// helper IT minimale
function it_datetime(?string $dt): string {
  if (!$dt) return '-';
  $ts = strtotime($dt);
  if (!$ts) return $dt;
  return date('d/m/Y H:i', $ts);
}
function it_status(string $s): string {
  return match ($s) {
    'confirmed' => 'Approvato',
    'pending'   => 'In valutazione',
    'cancelled' => 'Annullato',
    'blocked'   => 'Bloccato',
    default     => $s,
  };
}

$user_id = (int)($u['id'] ?? 0);

// registrazioni (ultime prima)
$stmt = $conn->prepare("
  SELECT
    r.id,
    r.race_id,
    r.status,
    r.status_reason,
    r.payment_status,
    r.created_at,
    r.confirmed_at,
    r.paid_at,
    r.fee_total_cents,
    r.fee_tier_label,

    ra.title AS race_title,
    ra.start_at AS race_start_at,
    ra.location AS race_location,

    e.title AS event_title,
    o.name AS org_name
  FROM registrations r
  JOIN races ra ON ra.id = r.race_id
  JOIN events e ON e.id = ra.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE r.user_id = ?
  ORDER BY r.created_at DESC, r.id DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header('Le mie iscrizioni');
?>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
    <div style="min-width:260px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Atleta</div>
      <div style="font-size:22px;font-weight:900;line-height:1.2;">
        <?php echo h($u['full_name'] ?? ''); ?>
      </div>
      <div style="margin-top:6px;color:#555;font-size:14px;">
        Qui trovi lo stato delle tue iscrizioni.
      </div>
    </div>
    <div style="min-width:220px;display:grid;gap:8px;align-content:start;">
      <div>
        <div style="font-size:12px;color:#666;">Totale</div>
        <div style="font-weight:900;"><?php echo (int)count($rows); ?></div>
      </div>
      <a href="calendar.php"
         style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;text-align:center;">
        Vai al calendario
      </a>
    </div>
  </div>
</section>

<?php if (!$rows): ?>
  <div style="padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;margin:12px 0;">
    <div style="font-weight:900;margin-bottom:4px;">Nessuna iscrizione</div>
    <div style="color:#555;font-size:14px;">Non hai ancora effettuato iscrizioni a gare.</div>
  </div>
<?php else: ?>

  <div style="display:grid;gap:12px;margin-top:12px;">
    <?php foreach ($rows as $r): ?>
      <?php
        $pay = (string)($r['payment_status'] ?? '');
        $pay_label = ($pay === 'paid') ? 'Pagato' : 'Non pagato';
      ?>
      <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
          <div style="min-width:260px;flex:1;">
            <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">
              <?php echo h($r['org_name'] ?? ''); ?> · <?php echo h($r['event_title'] ?? ''); ?>
            </div>
            <div style="font-size:18px;font-weight:900;line-height:1.2;margin-top:2px;">
              <?php echo h($r['race_title'] ?? ''); ?>
            </div>
            <div style="margin-top:6px;color:#444;">
              <span style="font-weight:700;">Data/Ora:</span> <?php echo h(it_datetime($r['race_start_at'] ?? null)); ?><br>
              <span style="font-weight:700;">Luogo:</span> <?php echo h($r['race_location'] ?? '-'); ?>
            </div>
          </div>

          <div style="min-width:260px;display:grid;gap:8px;align-content:start;">
            <div>
              <div style="font-size:12px;color:#666;">Stato</div>
              <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;">
                <?php echo h(it_status((string)($r['status'] ?? ''))); ?>
              </span>
            </div>

            <div>
              <div style="font-size:12px;color:#666;">Pagamento</div>
              <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #eee;font-weight:800;color:#555;">
                <?php echo h($pay_label); ?>
              </span>
            </div>

            <div>
              <div style="font-size:12px;color:#666;">Quota</div>
              <div style="font-weight:900;">
                € <?php echo h(cents_to_eur((int)($r['fee_total_cents'] ?? 0))); ?>
                <?php if (!empty($r['fee_tier_label'] ?? '')): ?>
                  <span style="color:#666;font-weight:700;font-size:12px;">
                    (<?php echo h((string)$r['fee_tier_label']); ?>)
                  </span>
                <?php endif; ?>
              </div>
            </div>

            <a href="race_public.php?id=<?php echo (int)$r['race_id']; ?>"
               style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;text-align:center;">
              Apri gara
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

<?php page_footer(); ?>
