<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/**
 * MiniCloud - Authentifizierungs-, Session-, Rollen- und Sicherheits-Helfer
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    $currentSavePath = session_save_path();
    if ($currentSavePath === '' || !is_dir($currentSavePath) || !is_writable($currentSavePath)) {
        session_save_path(sys_get_temp_dir());
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '86400');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Sicherheitsrelevante HTTP-Header setzen
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self), payment=(), usb=()');
}

/**
 * Stellt sicher, dass die Spalten für Kontosperrung (`is_locked`, `lock_reason`)
 * und delegierte Admin-Einzelrechte (`admin_permissions`) in der Tabelle `users` existieren.
 * Wird pro Sitzung gecacht, um unnötige SHOW-COLUMNS-Abfragen bei jedem Seitenaufruf zu vermeiden.
 */
function ensureUserSecurityColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked || (!empty($_SESSION['_mc_user_sec_cols_v2_ok']) && $_SESSION['_mc_user_sec_cols_v2_ok'] === 1)) {
        $checked = true;
        return;
    }
    $checked = true;

    try {
        $cols = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM `users`');
        foreach ($stmt->fetchAll() as $r) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
        if (!isset($cols['is_locked'])) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_admin`");
        }
        if (!isset($cols['lock_reason'])) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `lock_reason` VARCHAR(255) NOT NULL DEFAULT '' AFTER `is_locked`");
        }
        if (!isset($cols['admin_permissions'])) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `admin_permissions` VARCHAR(255) NOT NULL DEFAULT '' AFTER `lock_reason`");
        }
        if (!isset($cols['ui_style'])) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `ui_style` VARCHAR(24) NOT NULL DEFAULT 'modern' AFTER `theme`");
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_mc_user_sec_cols_v2_ok'] = 1;
        }
    } catch (Throwable) {
        // Ignorieren falls DB noch im Aufbau
    }
}

/**
 * Liefert alle einzeln übertragbaren Administrations-Rechte (Teil-Admin-Bereiche).
 *
 * @return array<string, array{label: string, short: string, icon: string, desc: string}>
 */
function getAvailableAdminPermissions(): array
{
    return [
        'users' => [
            'label' => 'Benutzer, Gruppen & Kontingente',
            'short' => 'Benutzer & Gruppen',
            'icon'  => '👥',
            'desc'  => 'Benutzer anlegen, Quotas ändern, Konten sperren, Selbstregistrierung, Gruppen & Gruppenordner verwalten',
        ],
        'modules' => [
            'label' => 'Module & Nutzungsstatistik',
            'short' => 'Module',
            'icon'  => '🧩',
            'desc'  => 'Cloud-Module aktivieren/deaktivieren und anonymisierte Nutzungsstatistik einsehen',
        ],
        'branding' => [
            'label' => 'Darstellung, Logo & Impressum',
            'short' => 'Branding',
            'icon'  => '🎨',
            'desc'  => 'Anwendungstitel, Untertitel, eigenes Logo und Impressum bearbeiten',
        ],
        'mail' => [
            'label' => 'E-Mail & SMTP-Konfiguration',
            'short' => 'E-Mail',
            'icon'  => '✉️',
            'desc'  => 'E-Mail-Versand (PHP mail / SMTP) einrichten und Test-E-Mails senden',
        ],
        'system' => [
            'label' => 'System, Updates, Backup & Audit-Log',
            'short' => 'System & Log',
            'icon'  => '🖥️',
            'desc'  => 'GitHub-Updates, lokale Sicherungspunkte, Datenbank-Backup, Server-Status & Sicherheitsprotokoll',
        ],
    ];
}

/**
 * Zerlegt den gespeicherten Berechtigungs-String in gültige Berechtigungsschlüssel.
 *
 * @return array<int, string>
 */
function parseAdminPermissionsString(string $raw): array
{
    $validKeys = array_keys(getAvailableAdminPermissions());
    $parts = array_filter(array_map('trim', explode(',', strtolower($raw))));
    return array_values(array_unique(array_intersect($parts, $validKeys)));
}

/**
 * Prüft, ob ein Benutzer eingeloggt ist, synchronisiert Rolle, Sperrstatus, Teil-Admin-Rechte,
 * Theme & Timeout aus der Datenbank und prüft das vom Benutzer gewählte Inaktivitäts-Timeout.
 */
function isLoggedIn(): bool
{
    if (!isset($_SESSION['user_id'], $_SESSION['username']) || !is_int($_SESSION['user_id']) || $_SESSION['user_id'] <= 0) {
        return false;
    }

    static $verifiedThisRequest = false;
    if (!$verifiedThisRequest) {
        $verifiedThisRequest = true;
        try {
            $pdo = getDB();
            ensureUserSecurityColumns($pdo);
            $stmt = $pdo->prepare(
                'SELECT username, is_admin, is_locked, lock_reason, admin_permissions, theme, ui_style, session_timeout, flash_duration, chat_notify_mode
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch();
            if ($row) {
                if ((int)($row['is_locked'] ?? 0) === 1) {
                    $reason = trim((string)($row['lock_reason'] ?? ''));
                    $_SESSION = [];
                    setFlash(
                        'error',
                        'Ihr Benutzerkonto wurde vom Administrator gesperrt' . ($reason !== '' ? ' (Grund: ' . $reason . ')' : '') . '.'
                    );
                    return false;
                }

                $timeoutMin     = isset($row['session_timeout']) ? (int)$row['session_timeout'] : 30;
                $flashSec       = isset($row['flash_duration']) ? max(0, min(300, (int)$row['flash_duration'])) : 10;
                $chatNotifyMode = isset($row['chat_notify_mode']) ? max(0, min(3, (int)$row['chat_notify_mode'])) : 1;
                if ($timeoutMin > 0 && isset($_SESSION['last_activity']) && is_int($_SESSION['last_activity'])) {
                    if ((time() - $_SESSION['last_activity']) > ($timeoutMin * 60)) {
                        $_SESSION = [];
                        setFlash('error', 'Ihre Sitzung wurde nach Inaktivität automatisch beendet. Bitte melden Sie sich erneut an.');
                        return false;
                    }
                }
                $_SESSION['username']          = (string)$row['username'];
                $_SESSION['is_admin']          = ((int)$row['is_admin'] === 1);
                $_SESSION['admin_permissions'] = parseAdminPermissionsString((string)($row['admin_permissions'] ?? ''));
                $_SESSION['theme']             = (string)($row['theme'] ?: 'light');
                $_SESSION['ui_style']          = (string)($row['ui_style'] ?? 'modern') ?: 'modern';
                $_SESSION['session_timeout']   = $timeoutMin;
                $_SESSION['flash_duration']    = $flashSec;
                $_SESSION['chat_notify_mode']  = $chatNotifyMode;
                $_SESSION['last_activity']     = time();
                if (function_exists('recordModuleUsage')) {
                    recordModuleUsage($pdo);
                }
            } else {
                // Benutzer wurde gelöscht
                $_SESSION = [];
                return false;
            }
        } catch (Throwable) {
            $_SESSION['is_admin']          = $_SESSION['is_admin'] ?? false;
            $_SESSION['admin_permissions'] = $_SESSION['admin_permissions'] ?? [];
            $_SESSION['theme']             = $_SESSION['theme'] ?? 'light';
            $_SESSION['ui_style']          = $_SESSION['ui_style'] ?? 'modern';
            $_SESSION['session_timeout']   = $_SESSION['session_timeout'] ?? 30;
            $_SESSION['flash_duration']    = $_SESSION['flash_duration'] ?? 10;
            $_SESSION['chat_notify_mode']  = $_SESSION['chat_notify_mode'] ?? 1;
            $_SESSION['last_activity']     = time();
        }
    }

    return true;
}

/**
 * Liefert die erlaubten Werte für das automatische Sitzungs-Timeout (in Minuten, 0 = Kein Timeout).
 *
 * @return array<int, string>
 */
function getAllowedSessionTimeouts(): array
{
    return [
        5   => '5 Minuten',
        15  => '15 Minuten',
        30  => '30 Minuten (Standard)',
        60  => '60 Minuten (1 Stunde)',
        120 => '120 Minuten (2 Stunden)',
        240 => '240 Minuten (4 Stunden)',
        480 => '480 Minuten (8 Stunden)',
        0   => 'Kein Timeout (Angemeldet bleiben)',
    ];
}

/**
 * Liefert das aktuell für den Benutzer eingestellte Sitzungs-Timeout in Minuten (0 = deaktiviert).
 */
function getCurrentSessionTimeout(): int
{
    if (!isLoggedIn()) {
        return 30;
    }
    return isset($_SESSION['session_timeout']) ? (int)$_SESSION['session_timeout'] : 30;
}

/**
 * Liefert die vom Benutzer eingestellte Anzeigedauer für Statusmeldungen in Sekunden (0 = nicht automatisch ausblenden).
 */
function getCurrentFlashDuration(): int
{
    if (!isLoggedIn()) {
        return 10;
    }
    return isset($_SESSION['flash_duration']) ? max(0, min(300, (int)$_SESSION['flash_duration'])) : 10;
}

/**
 * Liefert den vom Benutzer gewählten Benachrichtigungs-Modus für neue Chat-Nachrichten:
 * 1 = Nur System-/Browser-Benachrichtigung (Standard)
 * 2 = System-Benachrichtigung + In-App-Banner
 * 3 = Nur In-App-Banner
 * 0 = Deaktiviert (Nur roter Zähler)
 */
function getCurrentChatNotifyMode(): int
{
    if (!isLoggedIn()) {
        return 0;
    }
    return isset($_SESSION['chat_notify_mode']) ? max(0, min(3, (int)$_SESSION['chat_notify_mode'])) : 1;
}

/**
 * Prüft, ob der aktuell eingeloggte Benutzer Voll-Administrator (`is_admin = 1`) ist.
 */
function isAdmin(): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    return !empty($_SESSION['is_admin']);
}

/**
 * Alias für Voll-Administrator-Prüfung.
 */
function isFullAdmin(): bool
{
    return isAdmin();
}

/**
 * Prüft, ob der aktuelle Benutzer entweder Voll-Admin ist oder ein bestimmtes
 * delegiertes Einzelrecht (`users`, `modules`, `branding`, `mail`, `system`) besitzt.
 * Wird `$perm = null` übergeben, gibt die Funktion `true` zurück, sobald mindestens ein Admin-Recht vorliegt.
 */
function hasAdminPermission(?string $perm = null): bool
{
    if (!isLoggedIn()) {
        return false;
    }
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    $perms = isset($_SESSION['admin_permissions']) && is_array($_SESSION['admin_permissions'])
        ? $_SESSION['admin_permissions']
        : [];
    if ($perm === null || $perm === '') {
        return !empty($perms);
    }
    return in_array($perm, $perms, true);
}

/**
 * Schützt Seiten strikt vor unautorisiertem Zugriff.
 * Leitet nicht eingeloggte Benutzer sofort auf login.php um.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Schützt Administrationsseiten strikt vor Benutzern ohne Admin- bzw. Teil-Admin-Rechte.
 */
function requireAdmin(?string $requiredPerm = null): void
{
    requireLogin();
    if (!hasAdminPermission($requiredPerm)) {
        setFlash('error', 'Zugriff verweigert: Für diesen Bereich sind Administrator-Rechte erforderlich.');
        header('Location: index.php');
        exit;
    }
}

/**
 * Leitet bereits eingeloggte Benutzer zum Dashboard weiter.
 */
function redirectIfLoggedIn(): void
{
    if (isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Liefert die Liste aller unterstützten Farbschemata.
 *
 * @return array<int, string>
 */
function getAllowedThemes(): array
{
    return [
        'light', 'sand', 'sage', 'nordic', 'lavender',
        'dusk', 'mocha', 'petrol', 'plum',
        'dark', 'ocean', 'forest', 'obsidian',
    ];
}

/**
 * Liefert das aktuell gewählte Farbschema.
 */
function getCurrentTheme(): string
{
    $allowed = getAllowedThemes();
    if (isLoggedIn() && isset($_SESSION['theme']) && in_array($_SESSION['theme'], $allowed, true)) {
        return (string)$_SESSION['theme'];
    }
    if (isset($_COOKIE['mc_theme']) && in_array($_COOKIE['mc_theme'], $allowed, true)) {
        return (string)$_COOKIE['mc_theme'];
    }
    return 'light';
}

/**
 * Liefert alle unterstützten Design-Stile (UI-Skins / Formensprachen).
 *
 * @return array<int, string>
 */
function getAllowedUiStyles(): array
{
    return ['modern', 'sharp', 'glass', 'brutalist', 'pill'];
}

/**
 * Liefert die Beschreibungen und Metadaten der 5 Design-Stile (UI-Skins).
 *
 * @return array<string, array{label: string, short: string, icon: string, desc: string, preview_radius: string}>
 */
function getUiStyleDefinitions(): array
{
    return [
        'modern' => [
            'label'          => '✨ Modern Soft (Standard)',
            'short'          => 'Modern',
            'icon'           => '✨',
            'desc'           => 'Harmonisch abgerundete Karten (16 px), dezente Schatten und weiche Übergänge.',
            'preview_radius' => '14px',
        ],
        'sharp' => [
            'label'          => '📐 Enterprise Sharp (Business / Bootstrap)',
            'short'          => 'Sharp',
            'icon'           => '📐',
            'desc'           => 'Präzise, kantige Ecken (4 px), klare Rahmenlinien und sachliche Business-Optik.',
            'preview_radius' => '3px',
        ],
        'glass' => [
            'label'          => '🔮 Glassmorphism (Vision / macOS)',
            'short'          => 'Glass',
            'icon'           => '🔮',
            'desc'           => 'Halbtransparentes Milchglas mit Tiefenunschärfe, feinen Lichtkanten und Atmosphäre.',
            'preview_radius' => '18px',
        ],
        'brutalist' => [
            'label'          => '⬛ Neo-Kontrast (Klar & Markant)',
            'short'          => 'Kontrast',
            'icon'           => '⬛',
            'desc'           => 'Scharfe Kanten (0 px), kräftige 2-px-Konturen und markante Offset-Schatten.',
            'preview_radius' => '0px',
        ],
        'pill' => [
            'label'          => '🫧 Pill & Lounge (Ultra-Soft)',
            'short'          => 'Pill',
            'icon'           => '🫧',
            'desc'           => 'Kapselrunde Buttons, extra weiche Lounge-Karten (26 px) und luftige Formen.',
            'preview_radius' => '999px',
        ],
    ];
}

/**
 * Liefert den aktuell gewählten Design-Stil (UI-Skin).
 */
function getCurrentUiStyle(): string
{
    $allowed = getAllowedUiStyles();
    if (isLoggedIn() && isset($_SESSION['ui_style']) && in_array($_SESSION['ui_style'], $allowed, true)) {
        return (string)$_SESSION['ui_style'];
    }
    if (isset($_COOKIE['mc_ui_style']) && in_array($_COOKIE['mc_ui_style'], $allowed, true)) {
        return (string)$_COOKIE['mc_ui_style'];
    }
    return 'modern';
}

/**
 * Erzeugt oder liefert das aktuelle CSRF-Token der Session.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Überprüft ein übergebenes CSRF-Token zeitkonstant.
 */
function verifyCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Speichert eine Flash-Nachricht für den nächsten Seitenaufruf.
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Liest und löscht alle vorhandenen Flash-Nachrichten.
 *
 * @return array<int, array{type: string, message: string}>
 */
function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flashes) ? $flashes : [];
}
