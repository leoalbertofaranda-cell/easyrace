<?php
// app/includes/fees.php
declare(strict_types=1);

/**
 * Arrotonda ai multipli di $step (in cents).
 * step=50 => arrotonda a 0,50€
 */
function fee_round(int $cents, int $step = 50): int {
  if ($step <= 0) return $cents;
  $r = $cents % $step;
  if ($r === 0) return $cents;

  $down = $cents - $r;
  $up   = $down + $step;

  // half-up
  return ($r * 2 >= $step) ? $up : $down;
}

/**
 * Calcola fee percentuale in basis points (bp).
 * 100 bp = 1.00%, 250 bp = 2.50%
 */
function fee_percent(int $base_cents, int $bp): int {
  $num = $base_cents * $bp + 5000; // half-up
  return intdiv($num, 10000);
}

/**
 * Legge la fee piattaforma (1 riga) da platform_settings.
 * Ritorna array con: fee_type, fee_value_cents, fee_value_bp, round_to_cents, iban
 */
function get_platform_settings(mysqli $conn): array {
  $sql = "SELECT fee_type, fee_value_cents, fee_value_bp, round_to_cents, iban
          FROM platform_settings
          ORDER BY id ASC
          LIMIT 1";
  $res = $conn->query($sql);
  $row = $res ? $res->fetch_assoc() : null;

  if (!$row) {
    return [
      'fee_type' => 'fixed',
      'fee_value_cents' => 0,
      'fee_value_bp' => null,
      'round_to_cents' => 50,
      'iban' => null,
    ];
  }

  return [
    'fee_type' => (string)$row['fee_type'],
    'fee_value_cents' => (int)$row['fee_value_cents'],
    'fee_value_bp' => ($row['fee_value_bp'] === null ? null : (int)$row['fee_value_bp']),
    'round_to_cents' => (int)$row['round_to_cents'],
    'iban' => $row['iban'],
  ];
}

/**
 * Legge la fee admin per admin_user_id, se non c'è ritorna 0.
 */
function get_admin_settings(mysqli $conn, int $admin_user_id): array {
  $sql = "SELECT fee_type, fee_value_cents, fee_value_bp, round_to_cents, iban
          FROM admin_settings
          WHERE admin_user_id=?
          LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    // fallback safe
    return [
      'fee_type' => 'fixed',
      'fee_value_cents' => 0,
      'fee_value_bp' => null,
      'round_to_cents' => 50,
      'iban' => null,
    ];
  }

  $stmt->bind_param("i", $admin_user_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    return [
      'fee_type' => 'fixed',
      'fee_value_cents' => 0,
      'fee_value_bp' => null,
      'round_to_cents' => 50,
      'iban' => null,
    ];
  }

  return [
    'fee_type' => (string)$row['fee_type'],
    'fee_value_cents' => (int)$row['fee_value_cents'],
    'fee_value_bp' => ($row['fee_value_bp'] === null ? null : (int)$row['fee_value_bp']),
    'round_to_cents' => (int)$row['round_to_cents'],
    'iban' => $row['iban'],
  ];
}

/**
 * Calcola fees + totale (tutto in cents).
 * Usa round_to_cents (di default 50).
 */
function calc_fees_total(
  int $race_fee_cents,
  array $platform_settings,
  array $admin_settings
): array {

  $round_step = (int)($platform_settings['round_to_cents'] ?? 50);
  if ($round_step <= 0) $round_step = 50;

  // PLATFORM
  $platform_fee = 0;
  $ptype = (string)($platform_settings['fee_type'] ?? 'fixed');
  if ($ptype === 'percent') {
    $bp = (int)($platform_settings['fee_value_bp'] ?? 0);
    $platform_fee = fee_percent($race_fee_cents, $bp);
  } else {
    $platform_fee = (int)($platform_settings['fee_value_cents'] ?? 0);
  }
  $platform_fee = fee_round($platform_fee, $round_step);

  // ADMIN
  $admin_fee = 0;
  $atype = (string)($admin_settings['fee_type'] ?? 'fixed');
  if ($atype === 'percent') {
    $bp = (int)($admin_settings['fee_value_bp'] ?? 0);
    $admin_fee = fee_percent($race_fee_cents, $bp);
  } else {
    $admin_fee = (int)($admin_settings['fee_value_cents'] ?? 0);
  }
  $admin_fee = fee_round($admin_fee, $round_step);

  $total = $race_fee_cents + $platform_fee + $admin_fee;

  return [
    'race_fee_cents' => $race_fee_cents,
    'platform_fee_cents' => $platform_fee,
    'admin_fee_cents' => $admin_fee,
    'total_cents' => $total,
  ];



}

/**
 * Converte centesimi in euro formattati (es. 1250 → 12,50)
 */
function cents_to_eur(int $cents): string {
  return number_format($cents / 100, 2, ',', '.');
}
