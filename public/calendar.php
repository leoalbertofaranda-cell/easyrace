<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/layout.php';


$conn = db($config);

function badge_regs(string $s): string {
  // semplice: testo (niente CSS avanzato)
  return match ($s) {
    'open'   => 'Iscrizioni aperte',
    'closed' => 'Iscrizioni chiuse',
    default  => $s,
  };
}

// Eventi pubblicati + info su iscrizioni (da races)
// Regola badge: se esiste almeno una race open -> open, altrimenti closed (se esistono races), altrimenti '-'
$stmt = $conn->prepare("
  SELECT
    e.id,
    e.title,
    e.starts_on,
    e.ends_on,
    o.name AS org_name,
    CASE
      WHEN SUM(CASE WHEN r.status='open' THEN 1 ELSE 0 END) > 0 THEN 'open'
      WHEN COUNT(r.id) > 0 THEN 'closed'
      ELSE ''
    END AS regs_status
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  LEFT JOIN races r ON r.event_id = e.id
  WHERE e.status = 'published'
  GROUP BY e.id
  ORDER BY
    CASE
      WHEN e.starts_on IS NULL OR e.starts_on='' THEN 1
      WHEN e.starts_on >= CURDATE() THEN 0
      ELSE 2
    END,
    e.starts_on ASC,
    e.id DESC
");
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ======================================================
// FILTER (GET)
// ======================================================
$filter_open = (string)($_GET['open'] ?? '') === '1';
$show_past = (string)($_GET['past'] ?? '') === '1';

$events_view = $events;
if ($filter_open) {
  $events_view = array_values(array_filter($events, function($e) {
    return (string)($e['regs_status'] ?? '') === 'open';
  }));
}

// ======================================================
// SPLIT: Prossimi / Passati (solo rendering)
// ======================================================
$today = date('Y-m-d');

$events_upcoming = [];
$events_past = [];

foreach ($events_view as $e) {
  $starts_on = (string)($e['starts_on'] ?? '');
  // senza data -> trattiamo come "prossimo" (meglio visibile che perso)
  if ($starts_on === '' || $starts_on >= $today) {
    $events_upcoming[] = $e;
  } else {
    $events_past[] = $e;
  }
}

page_header('Calendario eventi');
?>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
    <div style="min-width:260px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Calendario</div>
      <div style="font-size:22px;font-weight:900;line-height:1.2;">
  <?php echo $filter_open ? 'Eventi con iscrizioni aperte' : 'Eventi pubblicati'; ?>
</div>
      <div style="margin-top:6px;color:#555;font-size:14px;">
  <?php if ($filter_open): ?>
    Sono mostrati solo gli eventi con almeno una gara aperta alle iscrizioni.
  <?php else: ?>
    Apri un evento per vedere le gare/tappe e iscriverti.
  <?php endif; ?>
</div>

    </div>
    <div style="min-width:220px;display:grid;gap:8px;align-content:start;">
      <div>
        <div style="font-size:12px;color:#666;">Totale eventi</div>
        <div style="font-weight:900;"><?php echo (int)count($events_view); ?></div>
      </div>
    </div>
  </div>
</section>

<form method="get" style="margin:0 0 12px;">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">

    <label style="display:inline-flex;gap:8px;align-items:center;border:1px solid #ddd;border-radius:10px;padding:8px 10px;">
      <input type="checkbox"
        name="open"
        value="1"
        onchange="this.form.submit()"
        <?php echo $filter_open ? 'checked' : ''; ?>>
      <span style="font-weight:800;">Solo iscrizioni aperte</span>
    </label>

    <label style="display:inline-flex;gap:8px;align-items:center;border:1px solid #ddd;border-radius:10px;padding:8px 10px;">
      <input type="checkbox"
        name="past"
        value="1"
        onchange="this.form.submit()"
        <?php echo $show_past ? 'checked' : ''; ?>>
      <span style="font-weight:800;">Mostra anche passati</span>
    </label>

    <?php if ($filter_open || $show_past): ?>
      <a href="calendar.php"
         style="display:inline-flex;align-items:center;gap:8px;border:1px solid #ddd;border-radius:10px;padding:8px 10px;color:#555;text-decoration:none;">
        <span style="font-weight:800;">Azzera filtri</span>
      </a>
    <?php endif; ?>

  </div>
</form>


<?php if (!$events_view): ?>

  <!-- empty state già presente -->

<?php else: ?>

  <?php if ($events_upcoming): ?>
    <h2 style="margin:18px 0 10px;">
  Attivi / Futuri <span style="color:#777;font-weight:700;">(<?php echo (int)count($events_upcoming); ?>)</span></h2>
    <div style="display:grid;gap:12px;margin-top:12px;">
      <?php foreach ($events_upcoming as $e): ?>
        <?php $rs = (string)($e['regs_status'] ?? ''); ?>
        <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
            <div style="min-width:260px;flex:1;">
              <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Evento</div>
              <div style="font-size:18px;font-weight:900;line-height:1.2;">
                <?php echo h($e['title'] ?? ''); ?>
              </div>
              <div style="margin-top:6px;color:#444;">
                <span style="font-weight:700;">Organizzazione:</span> <?php echo h($e['org_name'] ?? ''); ?><br>
                <span style="font-weight:700;">Periodo:</span>
                <?php
  $s1 = h(it_date($e['starts_on'] ?? null));
  $s2 = h(it_date($e['ends_on'] ?? null));
  echo $s1;
  if ($s2 !== '') echo " → " . $s2;
?>

              </div>
            </div>

            <div style="min-width:220px;display:grid;gap:8px;align-content:start;">
              <div>
                <div style="font-size:12px;color:#666;">Iscrizioni</div>
                <?php if ($rs): ?>
                  <?php $label = badge_regs($rs); ?>
                  <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;">
                    <?php echo h($label); ?>
                  </span>
                <?php else: ?>
                  <span style="color:#777;">-</span>
                <?php endif; ?>
              </div>

              <a href="event_public.php?id=<?php echo (int)$e['id']; ?>"
                 style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;text-align:center;">
                Apri evento
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

 <?php if ($show_past && $events_past): ?>
  <h2 style="margin:22px 0 10px;">
    Passati <span style="color:#777;font-weight:700;">(<?php echo (int)count($events_past); ?>)</span></h2>
    <div style="display:grid;gap:12px;margin-top:12px;">
      <?php foreach ($events_past as $e): ?>
        <?php $rs = (string)($e['regs_status'] ?? ''); ?>
        <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
            <div style="min-width:260px;flex:1;">
              <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Evento</div>
              <div style="font-size:18px;font-weight:900;line-height:1.2;">
                <?php echo h($e['title'] ?? ''); ?>
              </div>
              <div style="margin-top:6px;color:#444;">
                <span style="font-weight:700;">Organizzazione:</span> <?php echo h($e['org_name'] ?? ''); ?><br>
                <span style="font-weight:700;">Periodo:</span>
                <?php echo h(it_date($e['starts_on'] ?? null)); ?> → <?php echo h(it_date($e['ends_on'] ?? null)); ?>
              </div>
            </div>

            <div style="min-width:220px;display:grid;gap:8px;align-content:start;">
              <div>
                <div style="font-size:12px;color:#666;">Iscrizioni</div>
                <?php if ($rs): ?>
                  <?php $label = badge_regs($rs); ?>
                  <span style="display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #ddd;font-weight:900;">
                    <?php echo h($label); ?>
                  </span>
                <?php else: ?>
                  <span style="color:#777;">-</span>
                <?php endif; ?>
              </div>

              <a href="event_public.php?id=<?php echo (int)$e['id']; ?>"
                 style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;text-align:center;">
                Apri evento
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>


<?php page_footer(); ?>
