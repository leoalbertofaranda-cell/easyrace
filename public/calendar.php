<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

$conn = db($config);

// eventi pubblici: per ora prendiamo tutti, poi filtriamo per status='published'
$stmt = $conn->prepare("
  SELECT e.id, e.title, e.starts_on, e.ends_on, e.status,
         o.name AS org_name
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  ORDER BY e.starts_on DESC, e.id DESC
");
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header('Calendario eventi');
?>

<?php if (!$events): ?>
  <p>Nessun evento in calendario.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>Evento</th>
        <th>Organizzazione</th>
        <th>Dal</th>
        <th>Al</th>
        <th>Stato</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td><?php echo h($e['title'] ?? ''); ?></td>
          <td><?php echo h($e['org_name'] ?? ''); ?></td>
          <td><?php echo h($e['starts_on'] ?? ''); ?></td>
          <td><?php echo h($e['ends_on'] ?? ''); ?></td>
          <td><?php echo h($e['status'] ?? ''); ?></td>
          <td><a href="event_public.php?id=<?php echo (int)$e['id']; ?>">Apri</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
