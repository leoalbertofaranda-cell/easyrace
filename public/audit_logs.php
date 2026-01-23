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
    SELECT r.id, r.title, e.organization_id
    FROM races r
    JOIN events e ON e.id = r.event_id
    WHERE r.id=?
    LIMIT 1
  ");
  if (!$stmt) {
    header("HTTP/1.1 500 Internal Server Error");
    exit("Errore DB (prepare race): " . h($conn->error));
  }
  $stmt->bind_param("i", $race_id);
  $stmt->execute();
  $race = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$race) {
    header("HTTP/1.1 404 Not Found");
    exit("Gara non trovata.");
  }

  $race['id'] = (int)$race['id'];
  $race['organization_id'] = (int)$race['organization_id'];
  $race['title'] = (string)($race['title'] ?? '');
}


// Query (tabella nuova: audit_log)
$sql = "
  SELECT
    al.id,
    al.created_at,
    al.action,
    al.entity_type,
    al.entity_id,
    al.organization_id,
    al.actor_user_id,
    al.actor_role,
    al.message,
    al.meta,

    u.full_name AS actor_name,
    u.email     AS actor_email
  FROM audit_log al
  LEFT JOIN users u ON u.id = al.actor_user_id
  WHERE 1=1
";
$types = "";
$args  = [];

// se ho org, filtro per org (colonna nuova: organization_id)
if ($org_id > 0) {
  $sql .= " AND organization_id=? ";
  $types .= "i";
  $args[] = $org_id;
}

// se ho race_id, includo sia log diretti della gara sia log collegati via meta.race_id
if ($race_id > 0) {
  $sql .= " AND (
    (entity_type='race' AND entity_id=?)
    OR (JSON_EXTRACT(meta, '$.race_id') = ?)
  ) ";
  $types .= "ii";
  $args[] = $race_id;
  $args[] = $race_id;
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
        <th>Attore</th>
        <th>Payload</th>
      </tr>
    </thead>
   <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <!-- ID -->
      <td><?php echo (int)$r['id']; ?></td>

      <!-- Data -->
      <td><?php echo h((string)$r['created_at']); ?></td>

      <!-- Azione -->
      <td><b><?php echo h((string)$r['action']); ?></b></td>

      <!-- Entità -->
      <td><?php echo h((string)$r['entity_type']); ?> #<?php echo (int)$r['entity_id']; ?></td>

      <!-- Org -->
      <td><?php echo (int)($r['organization_id'] ?? 0); ?></td>

      <!-- Attore -->
      <td>
        <?php
          $an = trim((string)($r['actor_name'] ?? ''));
          $ae = trim((string)($r['actor_email'] ?? ''));
          $ar = trim((string)($r['actor_role'] ?? ''));

          if ($an !== '') {
            echo h($an);
            if ($ar !== '') echo " <small style='color:#666'>(" . h($ar) . ")</small>";
          } elseif ($ae !== '') {
            echo h($ae);
            if ($ar !== '') echo " <small style='color:#666'>(" . h($ar) . ")</small>";
          } elseif (!empty($r['actor_user_id'])) {
            echo "User #" . (int)$r['actor_user_id'];
            if ($ar !== '') echo " <small style='color:#666'>(" . h($ar) . ")</small>";
          } else {
            echo "<span style='color:#999'>—</span>";
          }
        ?>
      </td>

      <!-- Payload -->
      <td>
        <details>
          <summary>Apri</summary>
          <pre style="white-space:pre-wrap; font-size:12px;"><?php
            // nel DB nuovo il JSON sta in meta
            echo h((string)($r['meta'] ?? ''));
          ?></pre>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
</tbody>

  </table>
<?php endif; ?>

<?php page_footer(); ?>
