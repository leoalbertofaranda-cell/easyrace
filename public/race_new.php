<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_login();
require_manage();

$u = auth_user();
$conn = db($config);

$event_id = (int)($_GET['event_id'] ?? 0);
if ($event_id <= 0) { header("Location: events.php"); exit; }

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

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

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

    <label>Quota base (organizzatore)</label><br>
    <input name="base_fee" value="<?php echo h($form['base_fee']); ?>" placeholder="es. 13,50" style="width:100%;padding:10px;margin:6px 0 12px;">

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
