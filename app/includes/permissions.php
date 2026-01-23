<?php
// app/includes/permissions.php
declare(strict_types=1);

/**
 * Verifica se l'utente corrente ha un permesso su una organizzazione.
 * Se non autorizzato → exit secco.
 *
 * $perm: 'manage_races' | 'view_reports'
 */
function require_org_permission(
    mysqli $conn,
    int $organization_id,
    string $perm
): void {

    // --- utente ---
    if (!function_exists('auth_user') || !($u = auth_user())) {
        http_response_code(403);
        exit('Accesso negato');
    }

    $user_id = (int)$u['id'];
    $role    = (string)($u['role'] ?? '');

    // --- superuser: bypass ---
    if ($role === 'superuser') {
        return;
    }

    // --- atleta: mai ---
    if ($role === 'athlete') {
        http_response_code(403);
       header("Location: events.php");
    exit('Non sei autorizzato a gestire questa gara.');
    }

    // --- mappa permessi → colonna ---
    $perm_column = match ($perm) {
        'manage_races' => 'can_manage_races',
        'view_reports' => 'can_view_reports',
        default => null
    };

    if ($perm_column === null) {
        http_response_code(500);
        exit('Permesso non valido');
    }

    // --- verifica membership organizzazione ---
    $stmt = $conn->prepare("
        SELECT {$perm_column}
        FROM organization_users
        WHERE organization_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        exit('Errore DB');
    }

    $stmt->bind_param("ii", $organization_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || (int)$row[$perm_column] !== 1) {
        http_response_code(403);
        exit('Permessi insufficienti');
    }
}
