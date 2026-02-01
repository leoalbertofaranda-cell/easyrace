<?php
declare(strict_types=1);

// app/includes/fees.php

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
    SELECT fee_type, fee_value_cents, fee_value_bp, round_to_cents, iban
    FROM platform_settings
    ORDER BY id ASC
    LIMIT 1
  ";

  $res = $conn->query($sql);
  $row = $res ? $res->fetch_assoc() : null;

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
 */
function calc_fees_total(int $race_fee_cents, array $platform_settings, array $admin_settings): array {
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

  return [
    'race_fee_cents'       => $race_fee_cents,
    'platform_fee_cents'   => $platform_fee,
    'admin_fee_cents'      => $admin_fee_raw,
    'total_cents'          => $total_rounded,
    'total_raw_cents'      => $total_raw,
    'rounding_delta_cents' => $rounding_delta,
    'round_step_cents'     => $round_step,
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
 * Verifica se una tessera è presente nel roster del campionato (import Excel).
 */
function championship_is_member(mysqli $conn, int $championship_id, string $membership_number): bool {
  $membership_number = trim($membership_number);
  if ($championship_id <= 0 || $membership_number === '') return false;

  $sql = "
    SELECT 1
    FROM championship_roster
    WHERE championship_id = ?
      AND membership_number = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) return false;

  $stmt->bind_param('is', $championship_id, $membership_number);
  $stmt->execute();
  $stmt->store_result();
  $ok = $stmt->num_rows > 0;
  $stmt->close();

  return $ok;
}

/**
 * Ritorna la quota finale per una gara, considerando:
 * - scaglioni data (early/regular/late)
 * - eventuale quota campionato per tesserati (tabella championship_races.fee_member_*)
 *
 * Return: [tier_code, tier_label, fee_cents, is_member]
 */
function race_fee_final(
  mysqli $conn,
  array $race,
  ?int $championship_id = null,
  ?string $membership_number = null
): array {

  // 1) quota normale in base alla data
  [$tier_code, $tier_label, $fee_cents] = race_fee_pick_tier($race);

  // Se non c'è campionato o tessera, ritorna quota normale
  $membership_number = $membership_number !== null ? trim($membership_number) : '';
  if (!$championship_id || $membership_number === '') {
    return [$tier_code, $tier_label, $fee_cents, false];
  }

  // 2) verifica tessera nel roster
  $is_member = championship_is_member($conn, (int)$championship_id, $membership_number);
  if (!$is_member) {
    return [$tier_code, $tier_label, $fee_cents, false];
  }

  // 3) recupera quota tesserato da championship_races (per quella gara)
  $sql = "
    SELECT
      fee_member_early_cents,
      fee_member_regular_cents,
      fee_member_late_cents
    FROM championship_races
    WHERE championship_id = ?
      AND race_id = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    // membro sì, ma non troviamo la riga: fallback quota normale
    return [$tier_code, $tier_label, $fee_cents, true];
  }

  $race_id = (int)($race['id'] ?? 0);
  $stmt->bind_param("ii", $championship_id, $race_id);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  if (!$row) {
    return [$tier_code, $tier_label, $fee_cents, true];
  }

  // Applica quota tesserato sullo stesso tier
  if ($tier_code === 'early') {
    $fee_cents = (int)$row['fee_member_early_cents'];
  } elseif ($tier_code === 'late') {
    $fee_cents = (int)$row['fee_member_late_cents'];
  } else {
    $fee_cents = (int)$row['fee_member_regular_cents'];
  }

  return [$tier_code, $tier_label, $fee_cents, true];
}


/**
 * Calcola fee per race_public.php (usata in fase di iscrizione).
 * Ritorna le chiavi che race_public.php si aspetta:
 * tier_code, tier_label, race_fee_cents, platform_fee_cents, admin_fee_cents,
 * total_cents, rounding_delta_cents
 */
function compute_fees_for_race(mysqli $conn, array $race, int $user_id, int $admin_user_id = 0): array {
  // 1) capisco se la race appartiene a un campionato
  $race_id = (int)($race['id'] ?? 0);
  $championship_id = null;

  if ($race_id > 0) {
    $stmtC = $conn->prepare("SELECT championship_id FROM championship_races WHERE race_id = ? LIMIT 1");
    if ($stmtC) {
      $stmtC->bind_param("i", $race_id);
      $stmtC->execute();
      $rowC = $stmtC->get_result()->fetch_assoc();
      $stmtC->close();
      if (!empty($rowC['championship_id'])) {
        $championship_id = (int)$rowC['championship_id'];
      }
    }
  }

  // 2) tessera (arriva dal form)
  $membership_number = isset($_POST['membership_number']) ? trim((string)$_POST['membership_number']) : null;

  // 3) quota base (tier + eventuale quota tesserato)
  [$tier_code, $tier_label, $race_fee_cents, $is_member] =
    race_fee_final($conn, $race, $championship_id, $membership_number);

  // 4) settings fee
  $platform_settings = get_platform_settings($conn);
  $admin_settings    = ($admin_user_id > 0)
    ? get_admin_settings($conn, $admin_user_id)
    : [
        'fee_type'        => 'fixed',
        'fee_value_cents' => 0,
        'fee_value_bp'    => null,
        'round_to_cents'  => (int)($platform_settings['round_to_cents'] ?? 50),
        'iban'            => null,
      ];

  // 5) totale
  $tot = calc_fees_total((int)$race_fee_cents, $platform_settings, $admin_settings);

  return [
    'tier_code'           => $tier_code,
    'tier_label'          => $tier_label,
    'race_fee_cents'      => (int)$tot['race_fee_cents'],
    'platform_fee_cents'  => (int)$tot['platform_fee_cents'],
    'admin_fee_cents'     => (int)$tot['admin_fee_cents'],
    'rounding_delta_cents'=> (int)$tot['rounding_delta_cents'],
    'total_cents'         => (int)$tot['total_cents'],
    // se ti serve dopo:
    'is_member'           => $is_member ? 1 : 0,
    'membership_number'   => $membership_number,
    'championship_id'     => $championship_id,
  ];
}
