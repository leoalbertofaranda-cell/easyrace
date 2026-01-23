<?php
// app/includes/audit.php
declare(strict_types=1);


/**
 * Hard limits
 */
const AUDIT_META_MAX_BYTES = 16000; // 16KB meta JSON
const AUDIT_UA_MAX_LEN     = 255;
const AUDIT_MSG_MAX_LEN    = 500;

/**
 * Allowlist (se arriva roba diversa -> UNKNOWN/system)
 */
function audit_allowed_actions(): array {
  return [
    'REG_SET_PENDING',
    'RACE_OPEN','RACE_CLOSE',
    'RACE_EDIT','EVENT_EDIT',
    'REG_CONFIRM','REG_CANCEL','REG_MARK_PAID','REG_MARK_UNPAID',
    'REG_SET_BIB',
    'EXPORT_RACE_REGS','EXPORT_RACE_BIBS',
    'LOGIN','LOGOUT',
    'UNKNOWN',
  ];
}

function audit_allowed_entities(): array {
  return ['race','event','registration','export','auth','system'];
}

/**
 * Request-id: unico per request (per correlare più audit nella stessa chiamata)
 * (lo mettiamo in meta, senza dipendere dal DB)
 */
function audit_request_id(): string {
  static $rid = null;
  if ($rid !== null) return $rid;

  try {
    $rid = bin2hex(random_bytes(16)); // 32 hex
  } catch (\Throwable $e) {
    $rid = sha1((string)microtime(true) . '|' . (string)mt_rand());
  }
  return $rid;
}

/**
 * Redaction meta sensibile
 */
function audit_redact(array $meta): array {
  $deny_keys = [
    'password','pass','pwd','token','csrf','session','cookie',
    'authorization','auth','secret',
  ];

  $mask_keys = [
    'cf','codice_fiscale','fiscal_code',
    'email','phone','mobile',
    'primary_membership_number','membership_number',
  ];

  $walk = function($v) use (&$walk, $deny_keys, $mask_keys) {
    if (is_array($v)) {
      $out = [];
      foreach ($v as $k => $vv) {
        $ks = strtolower((string)$k);

        if (in_array($ks, $deny_keys, true)) {
          $out[$k] = '[REDACTED]';
          continue;
        }

        if (in_array($ks, $mask_keys, true)) {
          $s = (string)$vv;
          if ($s === '') { $out[$k] = ''; continue; }
          $out[$k] = mb_substr($s, 0, 2) . str_repeat('*', max(0, mb_strlen($s) - 4)) . mb_substr($s, -2);
          continue;
        }

        $out[$k] = $walk($vv);
      }
      return $out;
    }
    return $v;
  };

  return $walk($meta);
}

function audit_normalize_meta(array $meta): array {
  ksort($meta);
  return $meta;
}

/**
 * API unica
 */
function audit_log(
  mysqli $conn,
  string $action,
  string $entity_type,
  int $entity_id = 0,
  ?string $message = null,
  array $meta = [],
  ?array $actor = null
): void {

  // allowlist
  if (!in_array($action, audit_allowed_actions(), true)) {
    $action = 'UNKNOWN';
  }
  if (!in_array($entity_type, audit_allowed_entities(), true)) {
    $entity_type = 'system';
  }

// actor
if ($actor === null) {
  $u = function_exists('auth_user') ? auth_user() : ($_SESSION['auth'] ?? null);

  if (function_exists('actor_from_auth')) {
    $raw = actor_from_auth($u);

    // caso: ritorna [id, role]
    if (is_array($raw) && array_key_exists(0, $raw) && array_key_exists(1, $raw)) {
      $actor = [
        'actor_user_id' => (int)$raw[0],
        'actor_role'    => (string)$raw[1],
      ];
    } elseif (is_array($raw)) {
      $actor = $raw; // caso: già associativo
    }
  }
}
if (!is_array($actor)) $actor = [];


if (!is_array($actor)) $actor = [];



  $actor_user_id = (int)($actor['actor_user_id'] ?? 0);     // 0 = guest/system
  $actor_role    = (string)($actor['actor_role'] ?? 'guest');
  $org_id        = (int)($actor['organization_id'] ?? 0);

  $ip = (string)($actor['ip'] ?? (string)($_SERVER['REMOTE_ADDR'] ?? ''));
  $ua = (string)($actor['ua'] ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  if ($ua !== '') $ua = mb_substr($ua, 0, AUDIT_UA_MAX_LEN);

  // message
  if ($message !== null) {
    $message = trim($message);
    if ($message === '') $message = null;
    else $message = mb_substr($message, 0, AUDIT_MSG_MAX_LEN);
  }

  // meta: request_id + redact + normalize
  $meta['request_id'] = audit_request_id();
  $meta = audit_redact($meta);
  $meta = audit_normalize_meta($meta);

  $meta_json = null;
  if (!empty($meta)) {
    $meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($meta_json) && strlen($meta_json) > AUDIT_META_MAX_BYTES) {
      $meta_json = substr($meta_json, 0, AUDIT_META_MAX_BYTES);
    }
  }

  // DB: best-effort, non rompe il flusso
  $stmt = $conn->prepare("
    INSERT INTO audit_log
      (actor_user_id, actor_role, organization_id, action, entity_type, entity_id, ip, user_agent, message, meta)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  if (!$stmt) return;

  // Tipi: i s i s s i s s s s  -> "isis sis sss"? NO. Esatto è:
  // i s i s s i s s s s -> "isis sissss" senza spazi => "isis sissss" non valido.
  // Lo scrivo diretto corretto: "isis sissss" NON usare spazi.
  // Sequenza: i s i s s i s s s s => "isis sissss" -> senza spazi: "isississss"
  $types = "isississss";

  $stmt->bind_param(
    $types,
    $actor_user_id, // i
    $actor_role,    // s
    $org_id,        // i
    $action,        // s
    $entity_type,   // s
    $entity_id,     // i
    $ip,            // s
    $ua,            // s
    $message,       // s (null ok)
    $meta_json      // s (null ok)
  );

  try {
    $stmt->execute();
  } catch (\Throwable $e) {
    // opzionale: error_log("AUDIT_FAIL: ".$e->getMessage());
  }

  $stmt->close();
}
