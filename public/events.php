<?php
// public/events.php (MANAGE)
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/audit.php';

require_login();
require_manage();

$u = auth_user();
$conn = db($config);

function it_event_status(string $s): string {
  return match ($s) {
    'draft'     => 'Bozza',
    'published' => 'Pubblicato',
    'archived'  => 'Archiviato',
    default     => $s,
  };
}

$flash_ok = '';
$flash_err = '';

/**
 * ======================================================
 * ORG dell’utente
 * ======================================================
 */
$stmt = $conn->prepare("
  SELECT o.id, o.name
  FROM organization_users ou
  JOIN organizations o ON o.id = ou.organization_id
  WHERE ou.user_id = ?
  ORDER BY o.name ASC
");
$stmt->bind_param("i", $u['id']);
$stmt->execute();
$orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$org_id = (int)($_GET['org_id'] ?? 0);
if ($org_id <= 0 && $orgs) $org_id = (int)$orgs[0]['id'];

// sicurezza: org_id deve essere tra quelle dell’utente
$allowed = array_map(static fn($r)=> (int)$r['id'], $orgs);
if ($org_id > 0 && !in_array($org_id, $allowed, true)) {
  $org_id = $orgs ? (int)$orgs[0]['id'] : 0;
}

/**
 * ======================================================
 * POST: ELIMINA EVENTO (solo se vuoto)
 * ======================================================
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'delete_event') {
    $event_id = (int)($_POST['event_id'] ?? 0);
    $post_org_id = (int)($_POST['org_id'] ?? 0);

    // safety: must match selected org (evita giochi)
    if ($event_id <= 0 || $post_org_id <= 0 || $post_org_id !== $org_id) {
      $flash_err = "Richiesta non valida.";
    } else {

      // evento deve appartenere a questa org
      $stmt = $conn->prepare("
        SELECT id, title, organization_id
        FROM events
        WHERE id=? AND organization_id=?
        LIMIT 1
      ");
      $stmt->bind_param("ii", $event_id, $post_org_id);
      $stmt->execute();
      $ev = $stmt->get_result()->fetch_assoc();
      $stmt->close();

      if (!$ev) {
        $flash_err = "Evento non trovato o accesso negato.";
      } else {

        // blocco se esistono gare
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM races WHERE event_id=?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $races_c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        // blocco se esistono iscrizioni (via join races->registrations)
        $stmt = $conn->prepare("
          SELECT COUNT(*) AS c
          FROM registrations rg
          JOIN races r ON r.id = rg.race_id
          WHERE r.event_id=?
        ");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $regs_c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        if ($races_c > 0 || $regs_c > 0) {
          $flash_err = "Non puoi eliminare l’evento perché contiene gare o iscrizioni (gare: {$races_c}, iscrizioni: {$regs_c}).";
        } else {

          // delete
          $stmt = $conn->prepare("DELETE FROM events WHERE id=? AND organization_id=? LIMIT 1");
          $stmt->bind_param("ii", $event_id, $post_org_id);
          $ok = $stmt->execute();
          $stmt->close();

          if ($ok) {
            if (function_exists('audit_log')) {
              audit_log(
                $conn,
                'EVENT_DELETE',
                'event',
                (int)$event_id,
                null,
                [
                  'organization_id' => (int)$post_org_id,
                  'event_id'        => (int)$event_id,
                  'title'           => (string)($ev['title'] ?? ''),
                ]
              );
            }
            $flash_ok = "Evento eliminato.";
          } else {
            $flash_err = "Errore durante l’eliminazione dell’evento.";
          }
        }
      }
    }
  }
}

/**
 * ======================================================
 * Load events
 * ======================================================
 */
$events = [];
if ($org_id > 0) {
  $stmt = $conn->prepare("
    SELECT id, title, starts_on, ends_on, status
    FROM events
    WHERE organization_id=?
    ORDER BY
      CASE WHEN starts_on IS NULL OR starts_on='' THEN 1 ELSE 0 END,
      starts_on DESC,
      id DESC
  ");
  $stmt->bind_param("i", $org_id);
  $stmt->execute();
  $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

page_header('Eventi');
?>

<?php if (!$orgs): ?>
  <p>Prima crea un’organizzazione.</p>
<?php else: ?>

  <?php if ($flash_ok): ?>
    <div style="padding:12px;border:1px solid #9fe3b6;background:#e8fff1;border-radius:12px;margin:10px 0;">
      <?php echo h($flash_ok); ?>
    </div>
  <?php endif; ?>

  <?php if ($flash_err): ?>
    <div style="padding:12px;border:1px solid #ffb3b3;background:#ffecec;border-radius:12px;margin:10px 0;">
      <?php echo h($flash_err); ?>
    </div>
  <?php endif; ?>

  <form method="get" style="margin: 16px 0;">
    <label><b>Organizzazione</b></label><br>
    <select name="org_id" style="padding:10px;min-width:320px" onchange="this.form.submit()">
      <?php foreach ($orgs as $o): ?>
        <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$o['id'] === $org_id ? 'selected' : ''); ?>>
          <?php echo h($o['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" style="padding:10px 14px;">Vai</button></noscript>
  </form>

  <p>
    <a href="event_new.php?org_id=<?php echo (int)$org_id; ?>">+ Nuovo evento</a>
  </p>

  <?php if (!$events): ?>
    <p>Nessun evento per questa organizzazione.</p>
  <?php else: ?>
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
      <thead>
        <tr>
          <th>Titolo</th>
          <th>Dal</th>
          <th>Al</th>
          <th>Stato</th>
          <th>Azioni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($events as $e): ?>
          <tr>
            <td><?php echo h((string)($e['title'] ?? '')); ?></td>
            <td><?php echo h(it_date($e['starts_on'] ?? null)); ?></td>
            <td><?php echo h(it_date($e['ends_on'] ?? null)); ?></td>
            <td><?php echo h(it_event_status((string)($e['status'] ?? ''))); ?></td>
            <td>
              <a href="event_detail.php?id=<?php echo (int)$e['id']; ?>">Apri</a>

<?php
  $u = auth_user();
  $role = (string)($u['role'] ?? '');
  $can_platform_manage = in_array($role, ['superuser','admin','procacciatore'], true);
?>

<?php if ($can_platform_manage && (($e['status'] ?? '') === 'published')): ?>
  · <a href="event_public.php?id=<?php echo (int)$e['id']; ?>" target="_blank" rel="noopener">Pubblico</a>
<?php endif; ?>


              <form method="post" style="display:inline;" onsubmit="return confirm('Eliminare definitivamente questo evento?');">
                <input type="hidden" name="action" value="delete_event">
                <input type="hidden" name="org_id" value="<?php echo (int)$org_id; ?>">
                <input type="hidden" name="event_id" value="<?php echo (int)$e['id']; ?>">
                <button type="submit" style="padding:2px 8px;">Elimina</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

<?php endif; ?>

<?php page_footer(); ?>
