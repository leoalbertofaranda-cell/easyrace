<?php

function round_up_to_50_cents(int $cents): int {
  $step = 50;
  return (int)(ceil($cents / $step) * $step);
}

function calc_fee_cents(string $type, int $value, int $base_cents): int {
  $type = strtolower(trim($type));
  if ($type === 'percent') {
    // value = percent * 100 (es 250 = 2.50%)
    return (int) round($base_cents * ($value / 10000));
  }
  // fixed: value in cents
  return max(0, (int)$value);
}

function cents_to_eur(int $cents): string {
  $eur = $cents / 100;
  return number_format($eur, 2, ',', '.');
}
