<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/helpers.php';

require_login();

$u = auth_user();
$conn = db($config);

$race_id = (int)($_GET['race_id'] ?? 0);

// Divisioni gara (per assegnazione pettorali)
$raceDivisions = [];
$hasDivisions  = false;

$stmt = $conn->prepare("
  SELECT id, code, label
  FROM race_divisions
  WHERE race_id=? AND is_active=1
  ORDER BY sort_order ASC, label ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$raceDivisions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hasDivisions = !empty($raceDivisions);


if ($race_id <= 0) { header("Location: events.php"); exit; }

// Gara + evento + org
$stmt = $conn->prepare("
  SELECT r.*, e.title AS event_title, e.organization_id, o.name AS org_name
  FROM races r
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE r.id=? LIMIT 1
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$race = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$race) {
  header("HTTP/1.1 404 Not Found");
  exit("Gara non trovata.");
}

require_manage_org($conn, (int)$race['organization_id']);

$error = '';
$success = '';

// POST: assegna pettorale
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $reg_id = (int)($_POST['reg_id'] ?? 0);
  $bib    = trim((string)($_POST['bib_number'] ?? ''));

$division_id_in = (int)($_POST['division_id'] ?? 0); // 0 = nessuna

  if ($reg_id <= 0) {
    $error = "Registrazione non valida.";
  } elseif ($bib === '') {
    $error = "Inserisci un pettorale.";
  } elseif (!ctype_digit($bib)) {
    $error = "Il pettorale deve essere un numero intero.";
  } else {
    $bib_int = (int)$bib;

    try {
      // blocco: pettorale 0 non valido
      if ($bib_int <= 0) throw new RuntimeException("Pettorale non valido.");

$division_id_db    = null;
$division_code_db  = null;
$division_label_db = null;

if ($division_id_in > 0) {
  $stmt = $conn->prepare("
    SELECT id, code, label
    FROM race_divisions
    WHERE id=? AND race_id=? AND is_active=1
    LIMIT 1
  ");
  if (!$stmt) throw new RuntimeException("Errore DB (prepare division): ".h($conn->error));
  $stmt->bind_param("ii", $division_id_in, $race_id);
  $stmt->execute();
  $d = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$d) throw new RuntimeException("Divisione non valida.");

  $division_id_db    = (int)$d['id'];
  $division_code_db  = (string)$d['code'];
  $division_label_db = (string)$d['label'];
}

      // aggiorna (solo su quella gara)
      $stmt = $conn->prepare("
        UPDATE registrations
SET
  bib_number=?,
  bib_assigned_at=NOW(),
  division_id=?,
  division_code=?,
  division_label=?
WHERE id=? AND race_id=?
LIMIT 1

      ");
      if (!$stmt) throw new RuntimeException("Errore DB (prepare): ".h($conn->error));
      $stmt->bind_param(
  "iissii",
  $bib_int,
  $division_id_db,
  $division_code_db,
  $division_label_db,
  $reg_id,
  $race_id
);



      if (!$stmt->execute()) {
        $msg = $stmt->error ?: $conn->error;
        $stmt->close();

        // Duplicate entry (violazione uniq_race_bib)
        if (strpos($msg, 'Duplicate') !== false || strpos($msg, '1062') !== false) {
          throw new RuntimeException("Pettorale già assegnato in questa gara.");
        }
        throw new RuntimeException("Errore DB: ".$msg);
      }
      $stmt->close();

$success = "Pettorale assegnato.";
header("Location: bibs.php?race_id=".$race_id."&ok=1");
exit;

audit_log(
  $conn,
  'REG_BIB_SET',
  'registration',
  (int)$reg_id,
  $actor_id,
  $actor_role,
  null,
  [
    'race_id'          => (int)$race_id,
    'organization_id' => (int)($race['organization_id'] ?? 0),
    'after'            => [
      'bib_number'  => (int)$bib_int,
      'division_id' => (int)($division_id_db ?? 0)
    ]
  ]
);


      $success = "Pettorale assegnato.";
      header("Location: bibs.php?race_id=".$race_id."&ok=1");
      exit;

    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

if (!empty($_GET['ok'])) $success = "Salvato.";

// Lista iscritti (confermati + pagati) => solo loro devono avere pettorale
$stmt = $conn->prepare("
  SELECT
    rg.id AS reg_id,
    rg.bib_number,
    rg.bib_assigned_at,
    rg.category_code,
    rg.category_label,
    rg.division_id,
    rg.division_label,
    u.full_name,
    ap.first_name,
    ap.last_name,
    ap.gender,
    ap.birth_date,
    ap.club_name
  FROM registrations rg
  JOIN users u ON u.id = rg.user_id
  LEFT JOIN athlete_profile ap ON ap.user_id = rg.user_id
  WHERE rg.race_id=?
    AND rg.status='confirmed'
    AND rg.payment_status='paid'
  ORDER BY ap.last_name ASC, ap.first_name ASC, u.full_name ASC
");

$stmt->bind_param("i", $race_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header("Pettorali – " . ($race['title'] ?? ''));
?>

<p><a href="race.php?id=<?php echo (int)$race_id; ?>">← Torna alla gara</a></p>

<p>
  <b>Organizzazione:</b> <?php echo h($race['org_name'] ?? ''); ?><br>
  <b>Evento:</b> <?php echo h($race['event_title'] ?? ''); ?><br>
  <b>Gara:</b> <?php echo h($race['title'] ?? ''); ?><br>
  <b>Data/Ora:</b> <?php echo h($race['start_at'] ?? ''); ?>
</p>

<?php if ($success): ?>
  <div style="padding:12px;background:#eaffea;border:1px solid #9be29b;margin:12px 0;">
    <?php echo h($success); ?>
  </div>
<?php endif; ?>

<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;">
    <?php echo h($error); ?>
  </div>
<?php endif; ?>

<h2>Assegna pettorali (solo confermati e pagati)</h2>

<p style="margin:10px 0;">
  <a href="export_bibs_chrono.php?race_id=<?php echo (int)$race_id; ?>">
    📥 Scarica file cronometristi (CSV)
  </a>
</p>


<?php if (!$rows): ?>
  <p>Nessun iscritto confermato e pagato.</p>
<?php else: ?>

<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
  <thead>
    <tr>
      <th>Pettorale</th>
      <th>Cognome</th>
      <th>Nome</th>
      <th>Sesso</th>
      <th>Nascita</th>
      <th>Categoria</th>
      <th>Club</th>
      <th>Salva</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <?php
        $last  = trim((string)($r['last_name'] ?? ''));
        $first = trim((string)($r['first_name'] ?? ''));
        if ($last === '' && $first === '') {
          // fallback su full_name
          $fn = trim((string)($r['full_name'] ?? ''));
          $parts = preg_split('/\s+/', $fn);
          $first = $parts[0] ?? $fn;
          $last  = implode(' ', array_slice($parts, 1));
        }

        $gender = strtoupper(trim((string)($r['gender'] ?? '')));
        $bd     = (string)($r['birth_date'] ?? '');

        $cat_code = (string)($r['category_code'] ?? '');
        $cat_lab  = (string)($r['category_label'] ?? '');
        $cat_out  = $cat_code ? ($cat_code . ' — ' . $cat_lab) : $cat_lab;
      ?>
      <tr>
  <td style="white-space:nowrap;">
    <form method="post" style="display:flex;gap:6px;align-items:center;margin:0;">
      <input type="hidden" name="reg_id" value="<?php echo (int)$r['reg_id']; ?>">

      <?php if ($hasDivisions): ?>
        <select name="division_id" style="padding:6px 8px;">
          <option value="0">— Divisione —</option>
          <?php foreach ($raceDivisions as $d): ?>
            <option
              value="<?php echo (int)$d['id']; ?>"
              <?php if (!empty($r['division_id']) && (int)$r['division_id'] === (int)$d['id']) echo 'selected'; ?>
            >
              <?php echo h((string)$d['label']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <input
        type="text"
        name="bib_number"
        value="<?php echo h((string)($r['bib_number'] ?? '')); ?>"
        style="width:90px;padding:6px;"
        inputmode="numeric"
        pattern="[0-9]*"
      >
  </td>

  <td><?php echo h($last ?: '-'); ?></td>
  <td><?php echo h($first ?: '-'); ?></td>
  <td><?php echo h($gender ?: '-'); ?></td>
  <td><?php echo h($bd ?: '-'); ?></td>
  <td><?php echo h($cat_out ?: '-'); ?></td>
  <td><?php echo h((string)($r['club_name'] ?? '-')); ?></td>

  <td>
      <button type="submit" style="padding:6px 10px;">Salva</button>
    </form>
  </td>
</tr>

    <?php endforeach; ?>
  </tbody>
</table>

<?php endif; ?>

<?php page_footer(); ?>
