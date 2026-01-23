<?php
// public/audit_logs.php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/helpers.php';

require_login();

$u = auth_user();
$conn = db($config);

$race_id = (int)($_GET['race_id'] ?? 0);
$action  = trim((string)($_GET['action'] ?? ''));
$limit   = (int)($_GET['limit'] ?? 200);
if ($limit <= 0) $limit = 200;
if ($limit > 500) $limit = 500;

$org_id = 0;
$race = null;

// se arriva race_id: ricavo org_id e faccio check permessi su quell’org
if ($race_id > 0) {
  $stmt = $conn->prepare("
    SELECT r.id, e.organization_id, r.title
    FROM races r
    JOIN events e ON e.id = r.event_id
    WHERE r.id=?
    LIMIT 1
  ");
  $stmt->bind_param("i", $race_id);
  $stmt->execute();
  $race = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$race) {
    header("HTTP/1.1 404 Not Found");
    exit("Gara non trovata.");
  }

  $org_id = (int)($race['organization_id'] ?? 0);
  require_manage_org($conn, $org_id);
} else {
  // senza race_id: per ora richiediamo superuser (evitiamo leakage tra org)
  if (!function_exists('is_superuser') || !is_superuser()) {
    header("HTTP/1.1 400 Bad Request");
    exit("Devi passare race_id.");
  }
}

// Query
$sql = "
  SELECT id, action, entity_type, entity_id, org_id, payload, created_at
  FROM audit_logs
  WHERE 1=1
";
$types = "";
$args  = [];

if ($org_id > 0) {
  $sql .= " AND org_id=? ";
  $types .= "i";
  $args[] = $org_id;
}
if ($action !== '') {
  $sql .= " AND action=? ";
  $types .= "s";
  $args[] = $action;
}

$sql .= " ORDER BY id DESC LIMIT ? ";
$types .= "i";
$args[] = $limit;

$stmt = $conn->prepare($sql);
if (!$stmt) {
  header("HTTP/1.1 500 Internal Server Error");
  exit("Errore DB (prepare): " . h($conn->error));
}

// bind dinamico
$stmt->bind_param($types, ...$args);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pageTitle = "Audit log";
if ($race_id > 0) $pageTitle .= " — Gara #".$race_id;

page_header($pageTitle);
?>

<p>
  <a href="<?php echo $race_id > 0 ? 'race.php?id='.(int)$race_id : 'events.php'; ?>">← Indietro</a>
</p>

<?php if ($race_id > 0): ?>
  <p><b>Gara:</b> <?php echo h((string)($race['title'] ?? '')); ?> (ID <?php echo (int)$race_id; ?>)</p>
<?php endif; ?>

<form method="get" style="margin:12px 0; padding:12px; border:1px solid #ddd; border-radius:12px;">
  <input type="hidden" name="race_id" value="<?php echo (int)$race_id; ?>">

  <label><b>Azione</b></label><br>
  <input name="action" value="<?php echo h($action); ?>" placeholder="es. REG_REGISTER" style="padding:8px 10px; min-width:260px;">
  <span style="color:#666; font-size:12px;">(vuoto = tutte)</span>

  <div style="margin-top:10px;">
    <label><b>Limite</b></label><br>
    <input name="limit" type="number" value="<?php echo (int)$limit; ?>" min="1" max="500" style="padding:8px 10px; width:120px;">
  </div>

  <div style="margin-top:10px;">
    <button type="submit" style="padding:10px 14px;">Filtra</button>
  </div>
</form>

<?php if (!$rows): ?>
  <p>Nessun log trovato.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>ID</th>
        <th>Data</th>
        <th>Azione</th>
        <th>Entità</th>
        <th>Org</th>
        <th>Payload</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo h((string)$r['created_at']); ?></td>
          <td><b><?php echo h((string)$r['action']); ?></b></td>
          <td><?php echo h((string)$r['entity_type']); ?> #<?php echo (int)$r['entity_id']; ?></td>
          <td><?php echo h((string)($r['org_id'] ?? '')); ?></td>
          <td>
            <details>
              <summary>Apri</summary>
              <pre style="white-space:pre-wrap; font-size:12px;"><?php echo h((string)$r['payload']); ?></pre>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
