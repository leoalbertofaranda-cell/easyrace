<?php
// app/includes/permissions.php (oppure helpers.php)

declare(strict_types=1);

function current_user(): array {
  $u = auth_user();
  if (!$u) return [];
  return $u;
}

function role(): string {
  $u = current_user();
  return (string)($u['role'] ?? '');
}

function is_superuser(): bool {
  return role() === 'superuser';
}

function is_admin(): bool {
  // se tu usi 'admin' o 'procacciatore', qui li normalizzi
  return in_array(role(), ['admin','procacciatore'], true);
}

function is_organizer(): bool {
  return role() === 'organizer';
}

function require_manage_org(mysqli $conn, int $org_id): void {
  $u = current_user();
  $uid = (int)($u['id'] ?? 0);
  if ($uid <= 0) { header("HTTP/1.1 403 Forbidden"); exit("Accesso negato."); }

  if (is_superuser()) return;

  // membership su organization_users
  $stmt = $conn->prepare("
    SELECT 1
    FROM organization_users
    WHERE organization_id=? AND user_id=?
    LIMIT 1
  ");
  $stmt->bind_param("ii", $org_id, $uid);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();

  if (!$ok) { header("HTTP/1.1 403 Forbidden"); exit("Accesso negato."); }
}

/**
 * Validazione Codice Fiscale italiano (base + checksum).
 * - Accetta 16 caratteri A-Z0-9
 * - Verifica carattere di controllo finale
 * NOTA: omocodie gestite perché la tabella "odd/even" include lettere e numeri.
 */
function validate_tax_code(string $cf): bool {
  $cf = strtoupper(trim($cf));
  $cf = preg_replace('/\s+/', '', $cf);

  if (!preg_match('/^[A-Z0-9]{16}$/', $cf)) {
    return false;
  }

  $odd = [
    '0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
    'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
    'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
    'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23,
  ];

  $even = [
    '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
    'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,
    'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,
    'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25,
  ];

  $sum = 0;

  // posizioni 1..15 (0..14)
  for ($i = 0; $i < 15; $i++) {
    $ch = $cf[$i];
    // In CF: posizioni DISPARI (1,3,5...) usano tabella "odd"
    // In indice 0-based: i=0 (pos 1) è dispari -> odd
    $sum += ($i % 2 === 0) ? $odd[$ch] : $even[$ch];
  }

  $check = chr(($sum % 26) + ord('A'));
  return $check === $cf[15];
}

/**
 * Audit log
 * Tabella: audit_logs
 */
if (!function_exists('audit_log')) {
  function audit_log(mysqli $conn, string $action, string $entity_type, int $entity_id, ?int $org_id, array $payload = []): void {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      throw new RuntimeException("audit_log: json_encode fallito");
    }

    $stmt = $conn->prepare("
      INSERT INTO audit_logs (action, entity_type, entity_id, org_id, payload)
      VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
      throw new RuntimeException("audit_log prepare: " . $conn->error);
    }

    // org_id può essere NULL
    $org_id_db = ($org_id !== null && $org_id > 0) ? $org_id : null;

    $stmt->bind_param("ssiis", $action, $entity_type, $entity_id, $org_id_db, $json);

    if (!$stmt->execute()) {
      $err = $stmt->error ?: $conn->error;
      $stmt->close();
      throw new RuntimeException("audit_log execute: " . $err);
    }

    $stmt->close();
  }
}


function actor_from_auth(?array $u): array {
  $actor_id = (int)($u['id'] ?? 0);

  $role = $u['role'] ?? '';
  if (is_array($role)) {
    $role = $role['role'] ?? ($role['name'] ?? '');
  }
  $actor_role = (string)$role;

  return [$actor_id, $actor_role];
}


if (!function_exists('eur_to_cents')) {
  function eur_to_cents(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $v = str_replace(',', '.', $v);
    if (!is_numeric($v)) return 0;
    return (int) round(((float)$v) * 100);
  }
}
