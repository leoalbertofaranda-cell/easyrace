<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();

$u = auth_user();
if (($u['role'] ?? '') !== 'athlete') {
  header("HTTP/1.1 403 Forbidden");
  exit("Accesso negato.");
}

$conn = db($config);

// Le mie iscrizioni (per gara)
$stmt = $conn->prepare("
  SELECT
    reg.id AS reg_id,
    reg.status AS reg_status,
    reg.created_at AS reg_created_at,
    r.id AS race_id,
    r.title AS race_title,
    r.location AS race_location,
    r.start_at AS race_start_at,
    r.status AS race_status,
    e.id AS event_id,
    e.title AS event_title,
    e.status AS event_status,
    o.name AS org_name
  FROM registrations reg
  JOIN races r ON r.id = reg.race_id
  JOIN events e ON e.id = r.event_id
  JOIN organizations o ON o.id = e.organization_id
  WHERE reg.user_id = ?
  ORDER BY reg.created_at DESC
");
$stmt->bind_param("i", $u['id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header('Le mie iscrizioni');
?>

<?php if (!$rows): ?>
  <p>Non hai ancora nessuna iscrizione.</p>
  <p><a href="calendar.php">Vai al calendario</a></p>
<?php else: ?>

  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>Gara</th>
        <th>Quando</th>
        <th>Luogo</th>
        <th>Evento</th>
        <th>Organizzazione</th>
        <th>Stato iscrizione</th>
        <th>Stato gara</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo h($r['race_title'] ?? ''); ?></td>
          <td><?php echo h($r['race_start_at'] ?? ''); ?></td>
          <td><?php echo h($r['race_location'] ?? ''); ?></td>
          <td><?php echo h($r['event_title'] ?? ''); ?></td>
          <td><?php echo h($r['org_name'] ?? ''); ?></td>
          <td><b><?php echo h($r['reg_status'] ?? ''); ?></b></td>
          <td><?php echo h($r['race_status'] ?? ''); ?></td>
          <td>
            <a href="race_public.php?id=<?php echo (int)$r['race_id']; ?>">Apri</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

<?php endif; ?>

<?php page_footer(); ?>
