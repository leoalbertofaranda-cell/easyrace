<?php
// public/race_public.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/categories.php';

$conn = db($config);

if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

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

// Ruolo/loggato
$role = function_exists('current_role') ? current_role() : '';
$logged = !empty($role);

$u = null;
if ($logged && function_exists('auth_user')) {
  $u = auth_user();
}

$error = '';

// Iscrizioni consentite solo se gara open
$canRegister = (($race['status'] ?? '') === 'open');

// Stato iscrizione dell'utente (solo atleta)
$myReg = null;
if (($role ?? '') === 'athlete' && !empty($u['id'])) {
  $stmt = $conn->prepare("SELECT id,status,created_at FROM registrations WHERE race_id=? AND user_id=? LIMIT 1");
  $stmt->bind_param("ii", $race_id, $u['id']);
  $stmt->execute();
  $myReg = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// ======================================================
// POST ATLETA: register / cancel (solo se open)
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($role ?? '') === 'athlete' && !empty($u['id'])) {

  if (!$canRegister) {
    $error = "Iscrizioni chiuse.";
  } else {

    $action = (string)($_POST['action'] ?? '');

    // -------------------------
    // REGISTER
    // -------------------------
    if ($action === 'register') {
      try {
        // se c'è una reg attiva non cancelled, blocca
        if ($myReg && ($myReg['status'] ?? '') !== 'cancelled') {
          throw new RuntimeException("Sei già iscritto a questa gara.");
        }

        // --------------------------------------
        // Determinazione rulebook_season_id (robusta)
        // --------------------------------------
        $season_id   = (int)($race['rulebook_season_id'] ?? 0);
        $rulebook_id = (int)($race['rulebook_id'] ?? 0);

        // fallback fisso: FCI (dal tuo DB = 2)
        if ($rulebook_id <= 0) $rulebook_id = 2;

        // 1) stagione attiva
        if ($season_id <= 0) {
          $stmt = $conn->prepare("
            SELECT id
            FROM rulebook_seasons
            WHERE rulebook_id = ? AND is_active = 1
            LIMIT 1
          ");
          $stmt->bind_param("i", $rulebook_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          $season_id = (int)($row['id'] ?? 0);
        }

        // 2) stagione per anno gara
        if ($season_id <= 0) {
          $startAt = (string)($race['start_at'] ?? '');
          if ($startAt !== '') {
            $raceYear = (int)date('Y', strtotime($startAt));
            $stmt = $conn->prepare("
              SELECT id
              FROM rulebook_seasons
              WHERE rulebook_id = ? AND season_year = ?
              LIMIT 1
            ");
            $stmt->bind_param("ii", $rulebook_id, $raceYear);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $season_id = (int)($row['id'] ?? 0);
          }
        }

        // 3) ultima stagione disponibile
        if ($season_id <= 0) {
          $stmt = $conn->prepare("
            SELECT id
            FROM rulebook_seasons
            WHERE rulebook_id = ?
            ORDER BY season_year DESC
            LIMIT 1
          ");
          $stmt->bind_param("i", $rulebook_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          $season_id = (int)($row['id'] ?? 0);
        }

        if ($season_id <= 0) {
          throw new RuntimeException("Impossibile determinare la stagione (rulebook_season_id).");
        }

        // ----------------------------------------------------
        // Dati atleta: fonte unica = athlete_profile (NON sessione)
        // ----------------------------------------------------
        $stmt = $conn->prepare("SELECT birth_date, gender FROM athlete_profile WHERE user_id=? LIMIT 1");
        if (!$stmt) throw new RuntimeException("Errore DB (prepare atleta): " . h($conn->error));
        $stmt->bind_param("i", $u['id']);
        $stmt->execute();
        $ap = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $birth_date = (string)($ap['birth_date'] ?? '');
        $gender     = (string)($ap['gender'] ?? '');

        if ($birth_date === '' || $gender === '') {
          throw new RuntimeException("Profilo atleta incompleto: data di nascita e/o sesso mancanti.");
        }

        // 2) calcolo categoria (CODE es. M5)
        $cat_code = get_category_for_athlete_by_season($conn, $season_id, $birth_date, $gender);
        if (!$cat_code) {
          throw new RuntimeException("Categoria non trovata (season_id={$season_id}, birth_date={$birth_date}, gender={$gender}).");
        }

        // 3) recupero id + label da rulebook_categories
        $stmt = $conn->prepare("SELECT rulebook_id, season_year FROM rulebook_seasons WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $season_id);
        $stmt->execute();
        $seasonRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$seasonRow) {
          throw new RuntimeException("Stagione non trovata in rulebook_seasons (id={$season_id}).");
        }

        $rulebook_id = (int)$seasonRow['rulebook_id'];
        $season_year = (int)$seasonRow['season_year'];

        $stmt = $conn->prepare("
          SELECT id, name
          FROM rulebook_categories
          WHERE rulebook_id = ? AND season_year = ? AND code = ?
          LIMIT 1
        ");
        $stmt->bind_param("iis", $rulebook_id, $season_year, $cat_code);
        $stmt->execute();
        $catRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $cat_id    = (int)($catRow['id'] ?? 0);
        $cat_label = (string)($catRow['name'] ?? $cat_code);

        // 4) UPSERT: se esiste già (race_id,user_id), aggiorna e rimette pending
        $status = 'pending';

        $stmt = $conn->prepare("
          INSERT INTO registrations
            (race_id, user_id, status, category_id, category_code, category_label)
          VALUES
            (?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            status         = VALUES(status),
            category_id    = VALUES(category_id),
            category_code  = VALUES(category_code),
            category_label = VALUES(category_label)
        ");
        if (!$stmt) {
          throw new RuntimeException("Errore DB (prepare): " . h($conn->error));
        }

        $stmt->bind_param(
          "iisiss",
          $race_id,
          $u['id'],
          $status,
          $cat_id,
          $cat_code,
          $cat_label
        );

        if (!$stmt->execute()) {
          $msg = $stmt->error ?: $conn->error;
          $stmt->close();
          throw new RuntimeException("Errore DB (execute): " . h($msg));
        }
        $stmt->close();

        header("Location: race_public.php?id=" . $race_id);
        exit;

      } catch (Throwable $e) {
        $error = "Iscrizione non completata: " . $e->getMessage();
      }
    }

    // -------------------------
    // CANCEL
    // -------------------------
    if ($action === 'cancel') {
      try {
        $status = 'cancelled';
        $stmt = $conn->prepare("UPDATE registrations SET status=? WHERE race_id=? AND user_id=? LIMIT 1");
        $stmt->bind_param("sii", $status, $race_id, $u['id']);
        $stmt->execute();
        $stmt->close();

        header("Location: race_public.php?id=" . $race_id);
        exit;

      } catch (Throwable $e) {
        $error = "Annullamento non completato: " . $e->getMessage();
      }
    }

  }
}

// ======================================================
// Iscritti pubblici: solo confermati, ordine alfabetico
// (uso athlete_profile per nome/cognome/club/città)
// ======================================================
$publicRegs = [];
$stmt = $conn->prepare("
  SELECT
    ap.first_name,
    ap.last_name,
    ap.club_name,
    ap.city
  FROM registrations r
  JOIN athlete_profile ap ON ap.user_id = r.user_id
  WHERE r.race_id = ? AND r.status = 'confirmed'
  ORDER BY ap.last_name ASC, ap.first_name ASC
");
$stmt->bind_param("i", $race_id);
$stmt->execute();
$publicRegs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Ricalcolo quota preview
$base = (int)($race['base_fee_cents'] ?? 0);
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

$raw_total  = $base + $platform_fee + $admin_fee;
$paid_total = round_up_to_50_cents($raw_total);

$pageTitle = 'Gara: ' . ($race['title'] ?? '');
page_header($pageTitle);
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
        <th>Team / Club</th>
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

<?php elseif (($role ?? '') !== 'athlete'): ?>
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
