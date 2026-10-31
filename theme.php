<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(403);
    exit('Ungültiges CSRF-Token.');
}

$allowedThemes = getAllowedThemes();
$allowedStyles = getAllowedUiStyles();

$theme   = getCurrentTheme();
$uiStyle = getCurrentUiStyle();

if (isset($_POST['theme']) && is_string($_POST['theme'])) {
    $reqTheme = trim($_POST['theme']);
    if (in_array($reqTheme, $allowedThemes, true)) {
        $theme = $reqTheme;
        setcookie('mc_theme', $theme, [
            'expires'  => time() + 86400 * 365,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        if (isLoggedIn()) {
            $_SESSION['theme'] = $theme;
            $pdo = getDB();
            $stmt = $pdo->prepare('UPDATE users SET theme = :theme WHERE id = :id');
            $stmt->execute([
                ':theme' => $theme,
                ':id'    => (int)$_SESSION['user_id'],
            ]);
        }
    }
}

if (isset($_POST['ui_style']) && is_string($_POST['ui_style'])) {
    $reqStyle = trim($_POST['ui_style']);
    if (in_array($reqStyle, $allowedStyles, true)) {
        $uiStyle = $reqStyle;
        setcookie('mc_ui_style', $uiStyle, [
            'expires'  => time() + 86400 * 365,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
        if (isLoggedIn()) {
            $_SESSION['ui_style'] = $uiStyle;
            $pdo = getDB();
            ensureUserSecurityColumns($pdo);
            $stmt = $pdo->prepare('UPDATE users SET ui_style = :s WHERE id = :id');
            $stmt->execute([
                ':s'  => $uiStyle,
                ':id' => (int)$_SESSION['user_id'],
            ]);
        }
    }
}

$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'));

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'theme' => $theme, 'ui_style' => $uiStyle]);
    exit;
}

$referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$safeRedirect = 'index.php';
if ($referer !== '') {
    $parsed = parse_url($referer);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    if (is_array($parsed) && (!isset($parsed['host']) || strcasecmp((string)$parsed['host'], $currentHost) === 0)) {
        $path = basename((string)($parsed['path'] ?? 'index.php'));
        if (preg_match('/^[a-zA-Z0-9_-]+\.php$/', $path)) {
            $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';
            $safeRedirect = $path . $query;
        }
    }
}
header('Location: ' . $safeRedirect);
exit;
