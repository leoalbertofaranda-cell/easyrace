<?php
// app/includes/categories.php
declare(strict_types=1);

/**
 * Calcola la categoria atleta usando:
 * - rulebook_seasons (id = $season_id)
 * - rulebook_categories (range birth_year_from/to)
 *
 * $season_id è l'ID di rulebook_seasons (NON l'anno 2026).
 */
function get_category_for_athlete_by_season(mysqli $conn, int $season_id, string $birth_date, string $gender): ?string
{
  $birth_year = (int)substr($birth_date, 0, 4);
  if ($birth_year <= 0) return null;

  $gender = strtoupper(trim($gender));
  if (!in_array($gender, ['M','F'], true)) return null;

  // 1) ricavo rulebook_id e season_year dalla tabella rulebook_seasons
  $stmt = $conn->prepare("SELECT rulebook_id, season_year FROM rulebook_seasons WHERE id = ? LIMIT 1");
  if (!$stmt) return null;

  $stmt->bind_param("i", $season_id);
  $stmt->execute();
  $season = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$season) return null;

  $rulebook_id = (int)$season['rulebook_id'];
  $season_year = (int)$season['season_year'];

  // 2) match su rulebook_categories
  $sql = "
    SELECT code
    FROM rulebook_categories
    WHERE rulebook_id = ?
      AND season_year = ?
      AND gender = ?
      AND ? BETWEEN birth_year_from AND birth_year_to
    ORDER BY sort_order ASC
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return null;

  $stmt->bind_param("iisi", $rulebook_id, $season_year, $gender, $birth_year);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  return $row['code'] ?? null;
}
