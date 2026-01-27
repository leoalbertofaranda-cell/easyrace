<?php
// public/event_public.php (PUBBLICO)
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

$conn = db($config);


function it_race_status(string $s): string {
  return match ($s) {
    'open'   => 'Iscrizioni aperte',
    'closed' => 'Iscrizioni chiuse',
    default  => $s,
  };
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: calendar.php"); exit; }

// evento + org (solo published)
$stmt = $conn->prepare("
  SELECT e.*, o.name AS org_name
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  WHERE e.id=? AND e.status='published'
  LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
  header("HTTP/1.1 404 Not Found");
  exit("Evento non trovato (o non pubblicato).");
}

// gare
$stmt = $conn->prepare("
  SELECT id,title,location,start_at,discipline,status
  FROM races
  WHERE event_id=?
  ORDER BY
    CASE WHEN start_at IS NULL OR start_at='' THEN 1 ELSE 0 END,
    start_at ASC,
    id ASC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$races = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header('Evento: ' . ($event['title'] ?? ''));
?>

<section style="margin:12px 0 16px;padding:14px;border:1px solid #ddd;border-radius:12px;">
  <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
    <div style="min-width:260px;">
      <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Evento</div>
      <div style="font-size:22px;font-weight:900;line-height:1.2;">
        <?php echo h($event['title'] ?? ''); ?>
      </div>
      <div style="margin-top:6px;color:#444;">
        <span style="font-weight:700;">Organizzazione:</span> <?php echo h($event['org_name'] ?? ''); ?>
      </div>
    </div>

    <div style="min-width:260px;display:grid;gap:8px;">
      <div>
        <div style="font-size:12px;color:#666;">Periodo</div>
        <div style="font-weight:900;">
          <?php echo h(it_date($event['starts_on'] ?? null)); ?> → <?php echo h(it_date($event['ends_on'] ?? null)); ?>
        </div>
      </div>
      <div>
        <div style="font-size:12px;color:#666;">Gare / Tappe</div>
        <div style="font-weight:900;"><?php echo (int)count($races); ?></div>
      </div>
    </div>
  </div>
</section>


<nav style="margin:10px 0 12px;">
  <a href="calendar.php"
     style="display:inline-block;padding:6px 10px;border:1px solid #ddd;border-radius:10px;text-decoration:none;">
    ← Torna al calendario
  </a>
</nav>


<?php /*

<p>
  <b>Organizzazione:</b> <?php echo h($event['org_name'] ?? ''); ?><br>
  <b>Periodo:</b> <?php echo h(it_date($event['starts_on'] ?? null)); ?> → <?php echo h(it_date($event['ends_on'] ?? null)); ?><br>
</p>

*/ ?>

<?php if (!empty($event['description'])): ?>
  <p><?php echo nl2br(h($event['description'])); ?></p>
<?php endif; ?>

<h2 style="margin-top:18px;">Gare / Tappe</h2>

<?php if (!$races): ?>
  <div style="padding:12px;border:1px solid #ddd;border-radius:12px;background:#fafafa;margin:12px 0;">
    <div style="font-weight:900;margin-bottom:4px;">Nessuna gara inserita</div>
    <div style="color:#555;font-size:14px;">Le gare/tappe non sono ancora state pubblicate per questo evento.</div>
  </div>
<?php else: ?>

 <div style="display:grid;gap:12px;margin-top:12px;">
  <?php foreach ($races as $r): ?>
    <?php $st = (string)($r['status'] ?? ''); ?>
    <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;">
        <div style="min-width:260px;flex:1;">
          <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Gara</div>
          <div style="font-size:18px;font-weight:900;line-height:1.2;">
            <?php echo h($r['title'] ?? ''); ?>
          </div>
          <div style="margin-top:6px;color:#444;">
            <span style="font-weight:700;">Luogo:</span> <?php echo h($r['location'] ?? '-'); ?><br>
            <span style="font-weight:700;">Data/Ora:</span> <?php echo h(it_datetime($r['start_at'] ?? null)); ?><br>
            <span style="font-weight:700;">Disciplina:</span> <?php echo h($r['discipline'] ?? '-'); ?>
          </div>
        </div>

        <div style="min-width:220px;display:grid;gap:8px;align-content:start;">
          <div>
            <div style="font-size:12px;color:#666;">Iscrizioni</div>
            <div style="font-weight:900;"><?php echo h(it_race_status($st)); ?></div>
          </div>

          <a href="race_public.php?id=<?php echo (int)$r['id']; ?>"
             style="display:inline-block;padding:8px 12px;border:1px solid #ddd;border-radius:10px;text-decoration:none;text-align:center;">
            <?php echo ($st === 'open') ? 'Apri / Iscriviti' : 'Apri'; ?>
          </a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php page_footer(); ?>
