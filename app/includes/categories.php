<?php

function get_category_for_athlete(
    int $rulebook_id,
    int $season_year,
    string $gender,
    int $birth_year,
    mysqli $conn
): ?array {

    $stmt = $conn->prepare("
        SELECT
            id, code, name, gender,
            birth_year_from, birth_year_to
        FROM rulebook_categories
        WHERE rulebook_id = ?
          AND season_year = ?
          AND gender IN (?, 'X')
          AND birth_year_from <= ?
          AND birth_year_to >= ?
        ORDER BY sort_order ASC
        LIMIT 1
    ");

    $stmt->bind_param(
        "iisii",
        $rulebook_id,
        $season_year,
        $gender,
        $birth_year,
        $birth_year
    );

    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $res ?: null;
}
