<?php
// public/championship_detail.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/fees.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { header("Location: events.php"); exit; }

// Carico evento (solo se l'utente ha accesso all'org)
$stmt = $conn->prepare("
  SELECT e.*, o.name AS org_name
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  JOIN organization_users ou ON ou.organization_id = e.organization_id
  WHERE e.id=? AND ou.user_id=?
  LIMIT 1
");
$stmt->bind_param("ii", $event_id, $u['id']);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) { header("Location: events.php"); exit; }

if (($event['event_type'] ?? 'event') !== 'championship') {
  header("HTTP/1.1 404 Not Found");
  exit("Questo evento non è un campionato.");
}

// Carico gare dell'evento (anche closed va bene: è gestione interna)
$stmt = $conn->prepare("
  SELECT id, title, location, start_at, discipline, status
  FROM races
  WHERE event_id=?
  ORDER BY
    CASE WHEN start_at IS NULL OR start_at='' THEN 1 ELSE 0 END,
    start_at ASC, id ASC
");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$races = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ======================================================
// POST: salva quote campionato per gara
// ======================================================
$error = '';
$okmsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $race_id = (int)($_POST['race_id'] ?? 0);

  $to_cents = function($v): int {
  $s = trim((string)$v);
  if ($s === '') return 0;
  $s = str_replace(['€',' '], '', $s);
  $s = str_replace('.', '', $s);     // migliaia
  $s = str_replace(',', '.', $s);    // decimali IT -> punto
  if (!is_numeric($s)) return 0;
  return (int)round(((float)$s) * 100);
};

$early   = $to_cents($_POST['fee_member_early_eur'] ?? '');
$regular = $to_cents($_POST['fee_member_regular_eur'] ?? '');
$late    = $to_cents($_POST['fee_member_late_eur'] ?? '');


  if ($race_id <= 0) {
    $error = "Gara non valida.";
  } else {

    // sicurezza: la gara deve appartenere a questo evento
    $stmt = $conn->prepare("SELECT id FROM races WHERE id=? AND event_id=? LIMIT 1");
    $stmt->bind_param("ii", $race_id, $event_id);
    $stmt->execute();
    $rr = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$rr) {
      $error = "Gara non appartenente a questo campionato.";
    } else {

      // UPSERT su PK(championship_id,event_id?) -> qui usiamo event_id come championship_id
      // Per ora: championship_id = event_id (semplice e pratico).
      $championship_id = $event_id;

      $stmt = $conn->prepare("
        INSERT INTO championship_races (
          championship_id,
          race_id,
          fee_member_early_cents,
          fee_member_regular_cents,
          fee_member_late_cents
        ) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          fee_member_early_cents   = VALUES(fee_member_early_cents),
          fee_member_regular_cents = VALUES(fee_member_regular_cents),
          fee_member_late_cents    = VALUES(fee_member_late_cents)
      ");
      if (!$stmt) {
        $error = "Errore DB (prepare).";
      } else {
        $stmt->bind_param("iiiii", $championship_id, $race_id, $early, $regular, $late);
        $stmt->execute();
        $stmt->close();
        $okmsg = "Quote campionato salvate.";
      }
    }
  }
}

// ======================================================
// Carico quote campionato esistenti per tutte le gare
// ======================================================
$championship_id = $event_id;

$map = []; // race_id => row
$stmt = $conn->prepare("
  SELECT race_id, fee_member_early_cents, fee_member_regular_cents, fee_member_late_cents
  FROM championship_races
  WHERE championship_id=?
");
$stmt->bind_param("i", $championship_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($rows as $row) {
  $map[(int)$row['race_id']] = $row;
}

page_header('Campionato: ' . ($event['title'] ?? ''));
?>

<p>
  <a href="event_detail.php?id=<?php echo (int)$event_id; ?>">← Torna all’evento</a>
</p>

<div style="padding:12px;border:1px solid #ddd;border-radius:12px;margin:12px 0;">
  <div style="font-weight:900;font-size:18px;">🏆 Campionato</div>
  <div style="margin-top:6px;color:#444;">
    <b>Organizzazione:</b> <?php echo h($event['org_name'] ?? ''); ?><br>
    <b>Evento:</b> <?php echo h($event['title'] ?? ''); ?><br>
    <b>Periodo:</b> <?php echo h(it_date($event['starts_on'] ?? null)); ?> → <?php echo h(it_date($event['ends_on'] ?? null)); ?>
  </div>
</div>

<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<?php if ($okmsg): ?>
  <div style="padding:12px;background:#e8fff1;border:1px solid #9fe3b6;margin:12px 0;">
    <?php echo h($okmsg); ?>
  </div>
<?php endif; ?>

<h2>Gare / Tappe</h2>

<?php if (!$races): ?>
  <p>Nessuna gara presente.</p>
<?php else: ?>

  <div style="display:grid;gap:12px;margin-top:12px;">
    <?php foreach ($races as $r): ?>
      <?php
        $rid = (int)($r['id'] ?? 0);
        $row = $map[$rid] ?? [];

        $m_early   = (int)($row['fee_member_early_cents'] ?? 0);
        $m_regular = (int)($row['fee_member_regular_cents'] ?? 0);
        $m_late    = (int)($row['fee_member_late_cents'] ?? 0);
      ?>

      <div style="padding:14px;border:1px solid #ddd;border-radius:12px;">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div style="min-width:260px;flex:1;">
            <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#666;">Gara</div>
            <div style="font-size:18px;font-weight:900;"><?php echo h($r['title'] ?? ''); ?></div>
            <div style="margin-top:6px;color:#444;">
              <b>Luogo:</b> <?php echo h($r['location'] ?? '-'); ?><br>
              <b>Data/Ora:</b> <?php echo h(it_datetime($r['start_at'] ?? null)); ?><br>
              <b>Stato:</b> <?php echo h(label_status($r['status'] ?? '')); ?>
            </div>
          </div>

          <div style="min-width:320px;">
            <form method="post" style="margin:0;display:grid;gap:8px;">
              <input type="hidden" name="race_id" value="<?php echo (int)$rid; ?>">

              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <label style="flex:1;min-width:90px;">
                  Apertura (€)
                  <input name="fee_member_early_eur"
                         value="<?php echo h($m_early ? cents_to_eur((int)$m_early) : ''); ?>"
                         style="width:100%;padding:8px;"
                         inputmode="decimal">
                </label>

                <label style="flex:1;min-width:90px;">
                  Standard (€)
                  <input name="fee_member_regular_eur"
                         value="<?php echo h($m_regular ? cents_to_eur((int)$m_regular) : ''); ?>"
                         style="width:100%;padding:8px;"
                         inputmode="decimal">
                </label>

                <label style="flex:1;min-width:90px;">
                  Ritardo (€)
                  <input name="fee_member_late_eur"
                         value="<?php echo h($m_late ? cents_to_eur((int)$m_late) : ''); ?>"
                         style="width:100%;padding:8px;"
                         inputmode="decimal">
                </label>
              </div>

              <div style="font-size:12px;color:#666;">
                Inserisci i valori in <b>euro</b> (es: 15,00)
              </div>

              <button type="submit" style="padding:10px 12px;border:1px solid #ccc;background:#fff;cursor:pointer;">
                Salva quote campionato
              </button>
            </form>
          </div>
        </div>
      </div>

    <?php endforeach; ?>
  </div>

<?php endif; ?>


<?php page_footer(); ?>
