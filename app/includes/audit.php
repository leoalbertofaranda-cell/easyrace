<?php
// app/includes/audit.php
declare(strict_types=1);

function audit_log(
  mysqli $conn,
  string $action,
  string $entity_type,
  int $entity_id = 0,
  ?int $actor_user_id = null,
  ?string $actor_role = null,
  ?string $message = null,
  array $meta = []
): void {

  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  if ($ua !== '') $ua = mb_substr($ua, 0, 255);

  // Se nei meta arriva organization_id, lo mettiamo anche nella colonna dedicata
  $org_id = null;
  if (isset($meta['organization_id'])) {
    $org_id = (int)$meta['organization_id'];
  }

  $meta_json = null;
  if (!empty($meta)) {
    $meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  $stmt = $conn->prepare("
    INSERT INTO audit_log
      (actor_user_id, actor_role, organization_id, action, entity_type, entity_id, ip, user_agent, message, meta)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) return;

  $stmt->bind_param(
    "isississss",
    $actor_user_id,   // i
    $actor_role,      // s
    $org_id,          // i
    $action,          // s
    $entity_type,     // s
    $entity_id,       // i
    $ip,              // s
    $ua,              // s
    $message,         // s
    $meta_json        // s (JSON string o null)
  );

  $stmt->execute();
  $stmt->close();
}
