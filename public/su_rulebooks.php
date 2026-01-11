<?php
require_once __DIR__ . '/../app/includes/bootstrap.php';
require_once __DIR__ . '/../app/includes/layout.php';

require_login();
if (current_role() !== 'superuser') {
  header("HTTP/1.1 403 Forbidden");
  exit("Accesso negato.");
}

$conn = db($config);
$error = '';
$success = '';

// toggle attivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $stmt = $conn->prepare("UPDATE rulebooks SET is_active = IF(is_active=1,0,1) WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: su_rulebooks.php");
    exit;
  }
}

// aggiungi/aggiorna
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
  $id = (int)($_POST['id'] ?? 0);
  $code = strtoupper(trim((string)($_POST['code'] ?? '')));
  $name = trim((string)($_POST['name'] ?? ''));
  $basis = trim((string)($_POST['category_basis'] ?? 'birth_year'));
  $cutoff = trim((string)($_POST['cutoff_mmdd'] ?? '12-31'));
  $sort = (int)($_POST['sort_order'] ?? 0);

  if ($code === '' || $name === '') {
    $error = "Code e Nome sono obbligatori.";
  } elseif (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
    $error = "Code non valido. Usa solo A-Z 0-9 _ (2-20 char).";
  } elseif (!in_array($basis, ['birth_year','age_on_date'], true)) {
    $error = "category_basis non valido.";
  } elseif (!preg_match('/^\d{2}-\d{2}$/', $cutoff)) {
    $error = "cutoff_mmdd non valido (usa MM-DD, es. 12-31).";
  } else {
    if ($id > 0) {
      $stmt = $conn->prepare("
        UPDATE rulebooks
        SET code=?, name=?, category_basis=?, cutoff_mmdd=?, sort_order=?
        WHERE id=? LIMIT 1
      ");
      $stmt->bind_param("ssssii", $code, $name, $basis, $cutoff, $sort, $id);
      $stmt->execute();
      $stmt->close();
      header("Location: su_rulebooks.php");
      exit;
    } else {
      $stmt = $conn->prepare("
        INSERT INTO rulebooks (code,name,is_active,category_basis,cutoff_mmdd,sort_order)
        VALUES (?,?,1,?,?,?)
      ");
      $stmt->bind_param("ssssi", $code, $name, $basis, $cutoff, $sort);
      try {
        $stmt->execute();
        $stmt->close();
        header("Location: su_rulebooks.php");
        exit;
      } catch (Throwable $e) {
        $error = "Impossibile salvare (forse code duplicato).";
      }
    }
  }
}

// lista
$rows = [];
$res = $conn->query("SELECT * FROM rulebooks ORDER BY sort_order ASC, name ASC");
if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);

page_header('Superuser · Regolamenti');
?>

<p>
  <a href="dashboard.php">Dashboard</a>
</p>

<?php if ($error): ?>
  <div style="padding:12px;background:#ffecec;border:1px solid #ffb3b3;margin:12px 0;"><?php echo h($error); ?></div>
<?php endif; ?>

<div style="border:1px solid #ddd;border-radius:12px;padding:12px;margin:12px 0;">
  <b>Aggiungi regolamento</b>
  <form method="post" style="margin-top:10px;display:grid;gap:10px;grid-template-columns:1fr 2fr 1fr 1fr 1fr;align-items:end;">
    <input type="hidden" name="action" value="save">
    <div>
      <label>Code</label><br>
      <input name="code" placeholder="FCI" style="width:100%;padding:10px;">
    </div>
    <div>
      <label>Nome</label><br>
      <input name="name" placeholder="Federazione ..." style="width:100%;padding:10px;">
    </div>
    <div>
      <label>Base categorie</label><br>
      <select name="category_basis" style="width:100%;padding:10px;">
        <option value="birth_year" selected>Anno nascita</option>
        <option value="age_on_date">Età su data</option>
      </select>
    </div>
    <div>
      <label>Cutoff</label><br>
      <input name="cutoff_mmdd" value="12-31" style="width:100%;padding:10px;">
    </div>
    <div>
      <label>Ordine</label><br>
      <input type="number" name="sort_order" value="0" style="width:100%;padding:10px;">
    </div>

    <div style="grid-column:1 / -1;">
      <button type="submit" style="padding:10px 14px;">Salva</button>
    </div>
  </form>
</div>

<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;width:100%;">
  <thead>
    <tr>
      <th>Attivo</th>
      <th>Code</th>
      <th>Nome</th>
      <th>Base</th>
      <th>Cutoff</th>
      <th>Ordine</th>
      <th>Stagioni</th>
      <th>Azioni</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo ((int)$r['is_active']===1?'✅':'—'); ?></td>
        <td><?php echo h($r['code']); ?></td>
        <td><?php echo h($r['name']); ?></td>
        <td><?php echo h($r['category_basis']); ?></td>
        <td><?php echo h($r['cutoff_mmdd']); ?></td>
        <td><?php echo h($r['sort_order']); ?></td>
        <td>
          <a href="su_seasons.php?rulebook_id=<?php echo (int)$r['id']; ?>">Gestisci</a>
        </td>
        <td>
          <form method="post" style="display:inline;">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <button type="submit"><?php echo ((int)$r['is_active']===1?'Disattiva':'Attiva'); ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php page_footer(); ?>
