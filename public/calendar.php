<?php
// public/calendar.php (PUBBLICO)
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

$conn = db($config);

function it_date(?string $d): string {
  if (!$d) return '-';
  $ts = strtotime($d);
  if (!$ts) return $d;
  return date('d/m/Y', $ts);
}

function badge_regs(string $s): string {
  // semplice: testo (niente CSS avanzato)
  return match ($s) {
    'open'   => 'Iscrizioni aperte',
    'closed' => 'Iscrizioni chiuse',
    default  => $s,
  };
}

// Eventi pubblicati + info su iscrizioni (da races)
// Regola badge: se esiste almeno una race open -> open, altrimenti closed (se esistono races), altrimenti '-'
$stmt = $conn->prepare("
  SELECT
    e.id,
    e.title,
    e.starts_on,
    e.ends_on,
    o.name AS org_name,
    CASE
      WHEN SUM(CASE WHEN r.status='open' THEN 1 ELSE 0 END) > 0 THEN 'open'
      WHEN COUNT(r.id) > 0 THEN 'closed'
      ELSE ''
    END AS regs_status
  FROM events e
  JOIN organizations o ON o.id = e.organization_id
  LEFT JOIN races r ON r.event_id = e.id
  WHERE e.status = 'published'
  GROUP BY e.id
  ORDER BY
    CASE
      WHEN e.starts_on IS NULL OR e.starts_on='' THEN 1
      WHEN e.starts_on >= CURDATE() THEN 0
      ELSE 2
    END,
    e.starts_on ASC,
    e.id DESC
");
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

page_header('Calendario eventi');
?>

<p>
  <small>Eventi pubblicati. Apri un evento per vedere le gare e iscriverti.</small>
</p>

<?php if (!$events): ?>
  <p>Nessun evento pubblicato in calendario.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
    <thead>
      <tr>
        <th>Evento</th>
        <th>Organizzazione</th>
        <th>Dal</th>
        <th>Al</th>
        <th>Iscrizioni</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e): ?>
        <?php $rs = (string)($e['regs_status'] ?? ''); ?>
        <tr>
          <td><?php echo h($e['title'] ?? ''); ?></td>
          <td><?php echo h($e['org_name'] ?? ''); ?></td>
          <td><?php echo h(it_date($e['starts_on'] ?? null)); ?></td>
          <td><?php echo h(it_date($e['ends_on'] ?? null)); ?></td>
          <td><?php echo $rs ? h(badge_regs($rs)) : '-'; ?></td>
          <td><a href="event_public.php?id=<?php echo (int)$e['id']; ?>">Apri</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
