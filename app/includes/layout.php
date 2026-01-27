<?php
// app/includes/layout.php

if (!function_exists('h')) {
  function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

function current_role(): string {
  // Fonte più affidabile: auth_user() se esiste
  if (function_exists('auth_user')) {
    $u = auth_user();
    if (!empty($u['role'])) return (string)$u['role'];
  }

  // fallback sessioni
  $role = $_SESSION['auth']['role'] ?? ($_SESSION['role'] ?? '');

  if (!$role && !empty($_SESSION['admin_logged'])) {
    $role = $_SESSION['admin_role'] ?? 'admin';
  }
  if (!$role && !empty($_SESSION['user_logged'])) {
    $role = 'athlete';
  }

  return (string)$role;
}

function page_header(string $title = 'EasyRace'): void {
  $role = current_role();

  // costruzione menu (PRIMA)
  $items = [];

  if ($role) {

    // ATHLETE: menu “pulito”
    if ($role === 'athlete') {
      $items[] = ['my_registrations.php', 'Le mie iscrizioni'];
      $items[] = ['athlete_profile.php', 'Profilo atleta'];
      $items[] = ['logout.php', 'Esci'];

      // GESTIONE: organizer (pulito)
    } elseif ($role === 'organizer') {
      $items[] = ['dashboard.php', 'Dashboard'];
      $items[] = ['organizer_profile.php', 'Profilo organizzatore'];
      $items[] = ['events.php', 'Eventi (gestione)'];
      $items[] = ['logout.php', 'Esci'];

    // GESTIONE: piattaforma (superuser/admin/procacciatore)
    } elseif (in_array($role, ['superuser','admin','procacciatore'], true)) {
      $items[] = ['dashboard.php', 'Dashboard'];
      $items[] = ['organizer_profile.php', 'Profilo organizzatore'];
      $items[] = ['events.php', 'Eventi (gestione)'];
      $items[] = ['organizations.php', 'Organizzazioni'];
      $items[] = ['event_new.php', '+ Evento'];
      $items[] = ['organization_new.php', '+ Organizzazione'];
      $items[] = ['race_new.php', '+ Gara'];

      if ($role === 'superuser') {
        $items[] = ['su_rulebooks.php', 'Regolamenti'];
      }

      $items[] = ['logout.php', 'Esci'];

    }
  }

  // OUTPUT HTML (DOPO)
  echo '<!doctype html><html lang="it"><head>';
  echo '<meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '</head><body style="font-family:system-ui;max-width:1100px;margin:0 auto;padding:16px;">';

  echo '<header style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:16px;">';
  echo '<div style="font-weight:700;">EasyRace</div>';

  echo '<nav style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';

  // link pubblico sempre visibile
  echo '<a href="calendar.php" style="text-decoration:none;padding:6px 10px;border:1px solid #ddd;border-radius:10px;">Calendario</a>';

  if ($role) {
    foreach ($items as $it) {
      $href  = $it[0];
      $label = $it[1];
      echo '<a href="' . h($href) . '" style="text-decoration:none;padding:6px 10px;border:1px solid #ddd;border-radius:10px;">' . h($label) . '</a>';
    }
  } else {
    echo '<a href="login.php" style="text-decoration:none;padding:6px 10px;border:1px solid #ddd;border-radius:10px;">Accedi</a>';
  }

  echo '</nav>';
  echo '</header>';

  echo '<main>';
  echo '<h1 style="font-size:20px;margin:0 0 12px 0;">' . h($title) . '</h1>';
}

function page_footer(): void {
  echo '</main>';
  echo '<footer style="margin-top:24px;padding-top:12px;border-top:1px solid #eee;font-size:12px;opacity:.7;">';
  echo 'EasyRace';
  echo '</footer>';
  echo '</body></html>';
}
