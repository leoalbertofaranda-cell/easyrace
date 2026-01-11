<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/categories.php';

$conn = db($config);

$race_id = (int)($_GET['id'] ?? 0);
if ($race_id <= 0) { header("Location: calendar.php"); exit; }

// Carico gara + evento + org (PUBBLICO)
// Gara visibile se l'evento è published (anche se la gara è closed)
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.id AS event_id, o.name AS org_name, e.status AS event_status
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE r.id = ? AND e.status = 'published'
  LIMIT 1
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();


if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata (o evento non pubblicato).");
}

$role = current_role();
$logged = !empty($role);
$error = '';

$u = null;
if ($logged && function_exists('auth_user')) {
  $u = auth_user();
}

// Iscrizioni consentite solo se gara open
$canRegister = (($race['status'] ?? '') === 'open');

// Iscritti pubblici: solo confermati, ordine alfabetico
$publicRegs = [];
$stmt = $conn->prepare("
  SELECT u.first_name, u.last_name, u.club_name, u.city
  FROM registrations r
  JOIN users u ON u.id = r.user_id
  WHERE r.race_id = ? AND r.status = 'confirmed'
  ORDER BY u.last_name ASC, u.first_name ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$publicRegs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stato iscrizione dell'utente (solo atleta)
$myReg = null;
if ($role === 'athlete' && !empty($u['id'])) {
  $stmt = $conn->prepare("SELECT id,status,created_at FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
  $stmt->bind_param("ii", $race_id, $u['id']);
  $stmt->execute();
  $myReg = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// Azioni atleta: register / cancel (solo se open)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'athlete' && !empty($u['id'])) {
  if (!$canRegister) {
    $error = "Iscrizioni chiuse.";
  } else {
    $action = $_POST['action'] ?? '';

if ($action === 'register') {
  try {

    // 1) dati atleta
    $birth_year = (int)date('Y', strtotime($u['birth_date']));
    $gender     = $u['gender'];

    // 2) dati gara → regolamento
    $rulebook_id = (int)$race['rulebook_id'];
$season_id   = (int)$race['rulebook_season_id'];

    // 3) calcolo categoria
    $cat = get_category_for_athlete_by_season(
    $rulebook_id,
    $season_id,
    $gender,
    $birth_year,
    $conn
);

    if (!$cat) {
      throw new Exception("Categoria non determinabile.");
    }

    // 4) inserimento iscrizione con SNAPSHOT categoria
    $status = 'pending';

    $stmt = $conn->prepare("
      INSERT INTO registrations
      (race_id, user_id, status, category_id, category_code, category_label)
      VALUES (?,?,?,?,?,?)
    ");

    $stmt->bind_param(
      "iissss",
      $race_id,
      $u['id'],
      $status,
      $cat['id'],
      $cat['code'],
      $cat['name']
    );

    $stmt->execute();
    $stmt->close();

    header("Location: race_public.php?id=".$race_id);
    exit;

  } catch (Throwable $e) {
    $error = "Iscrizione non completata: " . $e->getMessage();
  }
}

  }
}

page_header('Gara: ' . ($race['title'] ?? ''));
?>

<p>
  <a href="event_public.php?id=<?php echo (int)$race['event_id']; ?>">← Evento</a>
</p>

<p>
  <b>Organizzazione:</b> <?php echo h($race['org_name'] ?? ''); ?><br>
  <b>Evento:</b> <?php echo h($race['event_title'] ?? ''); ?><br>
  <b>Luogo:</b> <?php echo h($race['location'] ?? '-'); ?><br>
  <b>Data/Ora:</b> <?php echo h($race['start_at'] ?? '-'); ?><br>
  <b>Disciplina:</b> <?php echo h($race['discipline'] ?? '-'); ?><br>
  <b>Stato gara:</b> <?php echo h($race['status'] ?? '-'); ?>
</p>

<?php
$base = (int)($race['base_fee_cents'] ?? 0);

// calcolo “preview” identico a quello di iscrizione (senza scrivere DB)
$ps = $conn->query("SELECT fee_type, fee_value FROM platform_settings LIMIT 1")->fetch_assoc();
$platform_fee = calc_fee_cents($ps['fee_type'] ?? 'fixed', (int)($ps['fee_value'] ?? 0), $base);

$admin_fee = 0;
$ref_admin_id = (int)($race['ref_admin_id'] ?? 0);
if ($ref_admin_id > 0) {
  $stmt2 = $conn->prepare("SELECT fee_type, fee_value FROM admin_settings WHERE admin_user_id=? LIMIT 1");
  $stmt2->bind_param("i", $ref_admin_id);
  $stmt2->execute();
  $as = $stmt2->get_result()->fetch_assoc();
  $stmt2->close();
  if ($as) $admin_fee = calc_fee_cents($as['fee_type'] ?? 'fixed', (int)($as['fee_value'] ?? 0), $base);
}

$raw_total = $base + $platform_fee + $admin_fee;
$paid_total = round_up_to_50_cents($raw_total);
?>
<p><b>Quota iscrizione:</b> € <?php echo h(cents_to_eur($paid_total)); ?></p>


<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<h2>Iscritti</h2>

<?php if (!$publicRegs): ?>
  <p>Nessun iscritto confermato (ancora).</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>Nome</th>
        <th>Cognome</th>
        <th>Club</th>
        <th>Città</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($publicRegs as $r): ?>
        <tr>
          <td><?php echo h($r['first_name'] ?? ''); ?></td>
          <td><?php echo h($r['last_name'] ?? ''); ?></td>
          <td><?php echo h($r['club_name'] ?? ''); ?></td>
          <td><?php echo h($r['city'] ?? ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h2>Iscrizione</h2>

<?php if (!$logged): ?>
  <p>Per iscriverti devi accedere.</p>
  <p><a href="login.php">Accedi</a></p>

<?php elseif ($role !== 'athlete'): ?>
  <p>Sei loggato come <b><?php echo h($role); ?></b>. L’iscrizione è disponibile solo per account atleta.</p>

<?php else: ?>
  <?php if (!$canRegister): ?>
    <p><b>Iscrizioni chiuse.</b></p>
  <?php else: ?>
    <?php if (!$myReg || ($myReg['status'] ?? '') === 'cancelled'): ?>
      <p>Non sei iscritto.</p>
      <form method="post">
        <input type="hidden" name="action" value="register">
        <button type="submit" style="padding:10px 14px;">Iscriviti</button>
      </form>
    <?php else: ?>
      <p>
        Stato: <b><?php echo h($myReg['status'] ?? ''); ?></b>
        · dal <?php echo h($myReg['created_at'] ?? ''); ?>
      </p>
      <form method="post" onsubmit="return confirm('Vuoi annullare l’iscrizione?');">
        <input type="hidden" name="action" value="cancel">
        <button type="submit" style="padding:10px 14px;">Annulla iscrizione</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<?php page_footer(); ?>
