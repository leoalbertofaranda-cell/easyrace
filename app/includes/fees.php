<?php
// app/includes/fees.php
declare(strict_types=1);

$publicRegs = [];

/**
 * Arrotonda ai multipli di $step (in cents).
 * step=50 => arrotonda a 0,50€
 */
function fee_round(int $cents, int $step = 50): int {
  $cents = max(0, $cents);
  if ($step <= 0) return $cents;
  return (int)(ceil($cents / $step) * $step);
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
 * Ritorna: fee_type, fee_value_cents, fee_value_bp, round_to_cents, iban
 */
function get_platform_settings(mysqli $conn): array {
  $sql = "
    SELECT
      fee_type,
      fee_value_cents,
      fee_value_bp,
      round_to_cents,
      iban
    FROM platform_settings
    ORDER BY id ASC
    LIMIT 1
  ";

  $res = $conn->query($sql);
  $row = $res ? $res->fetch_assoc() : null;

  // fallback sicuro
  if (!$row) {
    return [
      'fee_type'        => 'fixed',
      'fee_value_cents' => 0,
      'fee_value_bp'    => null,
      'round_to_cents'  => 50,
      'iban'            => null,
    ];
  }

  return [
    'fee_type'        => (string)$row['fee_type'],
    'fee_value_cents' => (int)$row['fee_value_cents'],
    'fee_value_bp'    => $row['fee_value_bp'] !== null ? (int)$row['fee_value_bp'] : null,
    'round_to_cents'  => (int)$row['round_to_cents'],
    'iban'            => $row['iban'],
  ];
}

/**
 * Legge la fee admin per admin_user_id, se non c'è ritorna 0.
 */
function get_admin_settings(mysqli $conn, int $admin_user_id): array {
  $sql = "
    SELECT fee_type, fee_value_cents, fee_value_bp, round_to_cents, iban
    FROM admin_settings
    WHERE admin_user_id=?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    return [
      'fee_type'        => 'fixed',
      'fee_value_cents' => 0,
      'fee_value_bp'    => null,
      'round_to_cents'  => 50,
      'iban'            => null,
    ];
  }

  $stmt->bind_param("i", $admin_user_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    return [
      'fee_type'        => 'fixed',
      'fee_value_cents' => 0,
      'fee_value_bp'    => null,
      'round_to_cents'  => 50,
      'iban'            => null,
    ];
  }

  return [
    'fee_type'        => (string)$row['fee_type'],
    'fee_value_cents' => (int)$row['fee_value_cents'],
    'fee_value_bp'    => $row['fee_value_bp'] !== null ? (int)$row['fee_value_bp'] : null,
    'round_to_cents'  => (int)$row['round_to_cents'],
    'iban'            => $row['iban'],
  ];
}

/**
 * Calcola fees + totale (tutto in cents) con rounding sul TOTALE.
 * Ritorna anche rounding_delta_cents (total_rounded - total_raw).
 *
 * Nota: la rounding_delta viene assegnata alla platform_fee per chiudere i conti.
 */
function calc_fees_total(
  int $race_fee_cents,
  array $platform_settings,
  array $admin_settings
): array {

  $round_step = (int)($platform_settings['round_to_cents'] ?? 50);
  if ($round_step <= 0) $round_step = 50;

  // PLATFORM (raw)
  $platform_fee_raw = 0;
  $ptype = (string)($platform_settings['fee_type'] ?? 'fixed');
  if ($ptype === 'percent') {
    $bp = (int)($platform_settings['fee_value_bp'] ?? 0);
    $platform_fee_raw = fee_percent($race_fee_cents, $bp);
  } else {
    $platform_fee_raw = (int)($platform_settings['fee_value_cents'] ?? 0);
  }

  // ADMIN (raw)
  $admin_fee_raw = 0;
  $atype = (string)($admin_settings['fee_type'] ?? 'fixed');
  if ($atype === 'percent') {
    $bp = (int)($admin_settings['fee_value_bp'] ?? 0);
    $admin_fee_raw = fee_percent($race_fee_cents, $bp);
  } else {
    $admin_fee_raw = (int)($admin_settings['fee_value_cents'] ?? 0);
  }

  $total_raw = $race_fee_cents + $platform_fee_raw + $admin_fee_raw;

  $total_rounded  = fee_round($total_raw, $round_step);
  $rounding_delta = $total_rounded - $total_raw;

  // assegna delta alla piattaforma per chiudere i conti
  $platform_fee = $platform_fee_raw + $rounding_delta;
  $admin_fee    = $admin_fee_raw;

  return [
    'race_fee_cents'        => $race_fee_cents,
    'platform_fee_cents'    => $platform_fee,
    'admin_fee_cents'       => $admin_fee,
    'total_cents'           => $total_rounded,
    'total_raw_cents'       => $total_raw,
    'rounding_delta_cents'  => $rounding_delta,
    'round_step_cents'      => $round_step,
  ];
}

/**
 * Converte centesimi in euro formattati (es. 1250 → 12,50)
 */
function cents_to_eur(int $cents): string {
  return number_format($cents / 100, 2, ',', '.');
}

/**
 * Sceglie il tier tariffario e la quota base in base alla data.
 * Ritorna: [tier_code, tier_label, fee_cents]
 */
function race_fee_pick_tier(array $race, ?string $today = null): array {
  $today = $today ?: date('Y-m-d');

  $early_until = trim((string)($race['fee_early_until'] ?? ''));
  $late_from   = trim((string)($race['fee_late_from'] ?? ''));

  $early_cents   = (int)($race['fee_early_cents'] ?? 0);
  $regular_cents = (int)($race['fee_regular_cents'] ?? 0);
  $late_cents    = (int)($race['fee_late_cents'] ?? 0);

  // fallback: se regular non è settato, usa fee_cents / base_fee_cents
  if ($regular_cents <= 0) {
    if (isset($race['fee_cents'])) {
      $regular_cents = (int)$race['fee_cents'];
    } elseif (isset($race['base_fee_cents'])) {
      $regular_cents = (int)$race['base_fee_cents'];
    }
  }

 

  // valida formato date base
  $is_date = static function(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
  };
  if ($early_until !== '' && !$is_date($early_until)) $early_until = '';
  if ($late_from !== '' && !$is_date($late_from)) $late_from = '';

  // se invertite, ignora early (resta regular/late)
  if ($early_until !== '' && $late_from !== '' && $early_until >= $late_from) {
    $early_until = '';
  }

  // precedence: late > early > regular
  if ($late_from !== '' && $today >= $late_from && $late_cents > 0) {
    return ['late', 'Late', $late_cents];
  }

  if ($early_until !== '' && $today <= $early_until && $early_cents > 0) {
    return ['early', 'Early', $early_cents];
  }

  return ['regular', 'Regular', max(0, $regular_cents)];
}


/**
 * Se l'atleta è iscritto (active) a un campionato che include questa gara,
 * ritorna la tariffa alternativa del TIER corrente (early/regular/late).
 *
 * Se più campionati coincidono, prende la MIN per evitare sorprese.
 */
function championship_fee_override(mysqli $conn, int $race_id, int $user_id, string $tier_code): ?int {
  if ($race_id <= 0 || $user_id <= 0) return null;

  // normalizza tier
  $tier_code = strtolower(trim($tier_code));
  if (!in_array($tier_code, ['early','regular','late'], true)) {
    $tier_code = 'regular';
  }

   // colonne reali in championship_races (Chat 22): fee_early/regular/late_cents
  $col = 'fee_regular_cents';
  if ($tier_code === 'early') $col = 'fee_early_cents';
  if ($tier_code === 'late')  $col = 'fee_late_cents';

  $sql = "
    SELECT MIN(cr.$col) AS fee_cents
    FROM championship_races cr
    JOIN championship_memberships cm
      ON cm.championship_id = cr.championship_id
     AND cm.user_id = ?
     AND cm.status = 'active'
    WHERE cr.race_id = ?
      AND cr.$col > 0
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) return null;

  $stmt->bind_param('ii', $user_id, $race_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $cents = (int)($row['fee_cents'] ?? 0);
  return ($cents > 0) ? $cents : null;
}



/**
 * Calcola le fee COMPLETE per una gara e un atleta:
 * - tier early/regular/late
 * - override campionato (se applicabile)
 * - fee piattaforma + fee admin + rounding
 *
 * Ritorna array con: tier_code, tier_label, base_fee_cents, ... + output calc_fees_total
 */
function compute_fees_for_race(mysqli $conn, array $race, int $user_id, int $admin_user_id = 0, ?string $today = null): array {
  $today = $today ?: date('Y-m-d');

  // 1) tier standard
  [$tier_code, $tier_label, $base_fee_cents] = race_fee_pick_tier($race, $today);

  // 2) override campionato (solo se race ∈ campionato e atleta ∈ campionato)
  $race_id = (int)($race['id'] ?? 0);
   $champ_cents = championship_fee_override($conn, $race_id, $user_id, $tier_code);
  if ($champ_cents !== null) {
    // NON cambiare tier_code: deve restare early/regular/late
    $tier_label = $tier_label . ' (Campionato)';
    $base_fee_cents = $champ_cents;
  }


  // 3) settings
  $platform_settings = get_platform_settings($conn);
  $admin_settings    = ($admin_user_id > 0) ? get_admin_settings($conn, $admin_user_id) : [
    'fee_type'        => 'fixed',
    'fee_value_cents' => 0,
    'fee_value_bp'    => null,
    'round_to_cents'  => (int)($platform_settings['round_to_cents'] ?? 50),
    'iban'            => null,
  ];

  // 4) totale
  $tot = calc_fees_total($base_fee_cents, $platform_settings, $admin_settings);

  return array_merge([
    'tier_code'      => $tier_code,
    'tier_label'     => $tier_label,
    'base_fee_cents' => $base_fee_cents,
  ], $tot);
}
