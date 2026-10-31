<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/i18n.php';

$currentLang = getCurrentLang();
if ($currentLang !== 'de') {
    ob_start('translateHtmlBuffer');
}

$appSettings = getAppSettings();
$appTitle    = $appSettings['app_title'];
$appSubtitle = $appSettings['app_subtitle'];
$appLogoUri  = $appSettings['logo_data_uri'];

$pageTitle = isset($pageTitle) ? $pageTitle . ' – ' . $appTitle : $appTitle . ' – Sicherer Cloud-Speicher';
$currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$flashes = getFlashes();
$currentTheme = getCurrentTheme();
$currentUiStyle = getCurrentUiStyle();
$isDarkLike = in_array($currentTheme, ['dusk', 'mocha', 'petrol', 'plum', 'dark', 'ocean', 'forest', 'obsidian'], true);

$headerStorageStats = null;
$headerBarColor = 'bg-brand-600';
if (isLoggedIn()) {
    recordModuleUsage(getDB());
    $headerStorageStats = isset($storageStats) && is_array($storageStats)
        ? $storageStats
        : getUserStorageStats(getDB(), (int)$_SESSION['user_id']);
    if ($headerStorageStats['percent'] >= 90) {
        $headerBarColor = 'bg-red-600';
    } elseif ($headerStorageStats['percent'] >= 75) {
        $headerBarColor = 'bg-amber-500';
    }
}
?>
<?php
$pwaLogoHash = substr(hash('sha256', (string)$appLogoUri), 0, 10);
?>
<!DOCTYPE html>
<html lang="de" class="h-full <?= $isDarkLike ? 'dark' : '' ?> theme-<?= e($currentTheme) ?> ui-style-<?= e($currentUiStyle) ?>" data-theme="<?= e($currentTheme) ?>" data-ui-style="<?= e($currentUiStyle) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= e($appTitle) ?>">
    <title><?= e($pageTitle) ?></title>
    <link rel="manifest" href="manifest.php?v=<?= e($pwaLogoHash) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="app-icon.php?size=180&amp;v=<?= e($pwaLogoHash) ?>">
    <?php if ($appLogoUri !== ''): ?>
        <link rel="icon" href="<?= e($appLogoUri) ?>">
    <?php else: ?>
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%232563eb'/%3E%3Cstop offset='100%25' stop-color='%234338ca'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='url(%23g)'/%3E%3Cpath d='M18 40a8 8 0 008 8h18a10 10 0 10-.2-19.998 10.004 10.004 0 10-19.56 4.192A8.002 8.002 0 0018 40z' fill='none' stroke='%23ffffff' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
    <?php endif; ?>
    <!-- Tailwind CSS (Lokal eingebunden – 100 % DSGVO-konform ohne externe Serveraufrufe) -->
    <script src="assets/tailwind.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                            950: '#172554',
                        }
                    }
                }
            }
        };
    </script>
    <style>
        /* --- Gedämpfte, angenehm abgedunkelte helle Themes (kein grelles Weiß) --- */

        /* 0. Klassisch Hell (abgedunkelt auf sanftes Schiefergrau) */
        html.theme-light { --bg-card: #e2e8f0; --border: #b8c4d4; --text: #1e293b; --text-muted: #475569; }
        html.theme-light body { background-color: #cbd5e1 !important; color: #1e293b !important; }
        html.theme-light header,
        html.theme-light footer,
        html.theme-light .mc-card,
        html.theme-light .card,
        html.theme-light .contact-card,
        html.theme-light .modal-box,
        html.theme-light main .bg-white.rounded-2xl { background-color: #e2e8f0 !important; border-color: #b8c4d4 !important; }
        html.theme-light thead tr,
        html.theme-light #dropZone { background-color: #d5deea !important; border-color: #b8c4d4 !important; }
        html.theme-light tbody tr:hover { background-color: #d8e1ed !important; }
        html.theme-light input:not([type="checkbox"]):not([type="radio"]),
        html.theme-light select,
        html.theme-light textarea { background-color: #edf2f7 !important; border-color: #b0bfd1 !important; color: #1e293b !important; }

        /* 1. Sand / Warmbeige (deutlich abgedunkelt) */
        html.theme-sand { --bg-card: #dfd4c0; --border: #bba98b; --text: #241e18; --text-muted: #574c3f; }
        html.theme-sand body { background-color: #cfc2ab !important; color: #241e18 !important; }
        html.theme-sand header,
        html.theme-sand footer,
        html.theme-sand .mc-card,
        html.theme-sand .card,
        html.theme-sand .contact-card,
        html.theme-sand .modal-box,
        html.theme-sand main .bg-white.rounded-2xl { background-color: #dfd4c0 !important; border-color: #bba98b !important; }
        html.theme-sand thead tr,
        html.theme-sand #dropZone { background-color: #d5c8b1 !important; border-color: #bba98b !important; }
        html.theme-sand tbody tr:hover { background-color: #d6c9b2 !important; }
        html.theme-sand input:not([type="checkbox"]):not([type="radio"]),
        html.theme-sand select,
        html.theme-sand textarea { background-color: #e8dfd0 !important; border-color: #b5a283 !important; color: #241e18 !important; }

        /* 2. Salbei / Sanftes Naturgrün (deutlich abgedunkelt) */
        html.theme-sage { --bg-card: #cad9d0; --border: #9ab3a4; --text: #16261e; --text-muted: #3d5649; }
        html.theme-sage body { background-color: #b6c8bd !important; color: #16261e !important; }
        html.theme-sage header,
        html.theme-sage footer,
        html.theme-sage .mc-card,
        html.theme-sage .card,
        html.theme-sage .contact-card,
        html.theme-sage .modal-box,
        html.theme-sage main .bg-white.rounded-2xl { background-color: #cad9d0 !important; border-color: #9ab3a4 !important; }
        html.theme-sage thead tr,
        html.theme-sage #dropZone { background-color: #bfd0c6 !important; border-color: #9ab3a4 !important; }
        html.theme-sage tbody tr:hover { background-color: #c0d1c7 !important; }
        html.theme-sage input:not([type="checkbox"]):not([type="radio"]),
        html.theme-sage select,
        html.theme-sage textarea { background-color: #d5e2da !important; border-color: #93ad9d !important; color: #16261e !important; }

        /* 3. Nordisch / Gedämpftes Blaugrau (deutlich abgedunkelt) */
        html.theme-nordic { --bg-card: #c9d6e4; --border: #9bb0c6; --text: #172232; --text-muted: #41536b; }
        html.theme-nordic body { background-color: #b5c4d4 !important; color: #172232 !important; }
        html.theme-nordic header,
        html.theme-nordic footer,
        html.theme-nordic .mc-card,
        html.theme-nordic .card,
        html.theme-nordic .contact-card,
        html.theme-nordic .modal-box,
        html.theme-nordic main .bg-white.rounded-2xl { background-color: #c9d6e4 !important; border-color: #9bb0c6 !important; }
        html.theme-nordic thead tr,
        html.theme-nordic #dropZone { background-color: #beccd9 !important; border-color: #9bb0c6 !important; }
        html.theme-nordic tbody tr:hover { background-color: #bfcedc !important; }
        html.theme-nordic input:not([type="checkbox"]):not([type="radio"]),
        html.theme-nordic select,
        html.theme-nordic textarea { background-color: #d5e0ec !important; border-color: #93a9c0 !important; color: #172232 !important; }

        /* 4. Lavendel / Sanftes Flieder-Grau (deutlich abgedunkelt) */
        html.theme-lavender { --bg-card: #d4cde3; --border: #ab9fc2; --text: #1f1730; --text-muted: #50436b; }
        html.theme-lavender body { background-color: #c3bad4 !important; color: #1f1730 !important; }
        html.theme-lavender header,
        html.theme-lavender footer,
        html.theme-lavender .mc-card,
        html.theme-lavender .card,
        html.theme-lavender .contact-card,
        html.theme-lavender .modal-box,
        html.theme-lavender main .bg-white.rounded-2xl { background-color: #d4cde3 !important; border-color: #ab9fc2 !important; }
        html.theme-lavender thead tr,
        html.theme-lavender #dropZone { background-color: #cac2db !important; border-color: #ab9fc2 !important; }
        html.theme-lavender tbody tr:hover { background-color: #cbc3dc !important; }
        html.theme-lavender input:not([type="checkbox"]):not([type="radio"]),
        html.theme-lavender select,
        html.theme-lavender textarea { background-color: #ded8ea !important; border-color: #a396bc !important; color: #1f1730 !important; }

        /* --- Halbdunkle Themes (Angenehme Mittelton-Dunkelheit, kein tiefes Schwarz) --- */

        /* 5. Dämmerung / Schiefer-Graublau (Halbdunkel) */
        html.theme-dusk.dark { --bg-card: #3e4e63; --border: #52647c; --text: #f1f5f9; --text-muted: #cbd5e1; }
        html.theme-dusk.dark body { background-color: #334155 !important; color: #f1f5f9 !important; }
        html.theme-dusk.dark header,
        html.theme-dusk.dark footer,
        html.theme-dusk.dark .mc-card,
        html.theme-dusk.dark .card,
        html.theme-dusk.dark .contact-card,
        html.theme-dusk.dark .modal-box,
        html.theme-dusk.dark main .bg-white.rounded-2xl { background-color: #3e4e63 !important; border-color: #52647c !important; }
        html.theme-dusk.dark thead tr,
        html.theme-dusk.dark #dropZone { background-color: #38475b !important; border-color: #52647c !important; }
        html.theme-dusk.dark tbody tr:hover { background-color: #465870 !important; }
        html.theme-dusk.dark input:not([type="checkbox"]):not([type="radio"]),
        html.theme-dusk.dark select,
        html.theme-dusk.dark textarea { background-color: #2d3a4b !important; border-color: #566a84 !important; color: #f8fafc !important; }

        /* 6. Mokka / Warmes Espresso-Grau (Halbdunkel) */
        html.theme-mocha.dark { --bg-card: #493f38; --border: #63564c; --text: #f5f0eb; --text-muted: #d6ccc2; }
        html.theme-mocha.dark body { background-color: #3b332d !important; color: #f5f0eb !important; }
        html.theme-mocha.dark header,
        html.theme-mocha.dark footer,
        html.theme-mocha.dark .mc-card,
        html.theme-mocha.dark .card,
        html.theme-mocha.dark .contact-card,
        html.theme-mocha.dark .modal-box,
        html.theme-mocha.dark main .bg-white.rounded-2xl { background-color: #493f38 !important; border-color: #63564c !important; }
        html.theme-mocha.dark thead tr,
        html.theme-mocha.dark #dropZone { background-color: #423932 !important; border-color: #63564c !important; }
        html.theme-mocha.dark tbody tr:hover { background-color: #52473f !important; }
        html.theme-mocha.dark input:not([type="checkbox"]):not([type="radio"]),
        html.theme-mocha.dark select,
        html.theme-mocha.dark textarea { background-color: #332c26 !important; border-color: #695b51 !important; color: #faf6f2 !important; }

        /* 7. Petrol / Gedämpftes Blaugrün (Halbdunkel) */
        html.theme-petrol.dark { --bg-card: #2c4c57; --border: #3f6674; --text: #ecfeff; --text-muted: #bae6fd; }
        html.theme-petrol.dark body { background-color: #243e47 !important; color: #ecfeff !important; }
        html.theme-petrol.dark header,
        html.theme-petrol.dark footer,
        html.theme-petrol.dark .mc-card,
        html.theme-petrol.dark .card,
        html.theme-petrol.dark .contact-card,
        html.theme-petrol.dark .modal-box,
        html.theme-petrol.dark main .bg-white.rounded-2xl { background-color: #2c4c57 !important; border-color: #3f6674 !important; }
        html.theme-petrol.dark thead tr,
        html.theme-petrol.dark #dropZone { background-color: #28454f !important; border-color: #3f6674 !important; }
        html.theme-petrol.dark tbody tr:hover { background-color: #335763 !important; }
        html.theme-petrol.dark input:not([type="checkbox"]):not([type="radio"]),
        html.theme-petrol.dark select,
        html.theme-petrol.dark textarea { background-color: #1e353d !important; border-color: #456e7d !important; color: #f0fdfa !important; }

        /* 8. Pflaume / Sanftes Violett-Schiefer (Halbdunkel) */
        html.theme-plum.dark { --bg-card: #463b56; --border: #5e5073; --text: #f5f3ff; --text-muted: #ddd6fe; }
        html.theme-plum.dark body { background-color: #393046 !important; color: #f5f3ff !important; }
        html.theme-plum.dark header,
        html.theme-plum.dark footer,
        html.theme-plum.dark .mc-card,
        html.theme-plum.dark .card,
        html.theme-plum.dark .contact-card,
        html.theme-plum.dark .modal-box,
        html.theme-plum.dark main .bg-white.rounded-2xl { background-color: #463b56 !important; border-color: #5e5073 !important; }
        html.theme-plum.dark thead tr,
        html.theme-plum.dark #dropZone { background-color: #3f354e !important; border-color: #5e5073 !important; }
        html.theme-plum.dark tbody tr:hover { background-color: #4f4361 !important; }
        html.theme-plum.dark input:not([type="checkbox"]):not([type="radio"]),
        html.theme-plum.dark select,
        html.theme-plum.dark textarea { background-color: #2f273b !important; border-color: #65567b !important; color: #faf5ff !important; }

        /* --- Dunkle Themes (Dark, Ocean, Forest & Obsidian/Mitternacht) --- */
        html.theme-ocean.dark { --bg-card: #0c2544; --border: #193b66; --text: #e2e8f0; --text-muted: #94a3b8; }
        html.theme-ocean.dark body { background-color: #07192f !important; color: #e2e8f0; }
        html.theme-ocean.dark header,
        html.theme-ocean.dark footer,
        html.theme-ocean.dark .mc-card,
        html.theme-ocean.dark .card,
        html.theme-ocean.dark .contact-card,
        html.theme-ocean.dark .modal-box,
        html.theme-ocean.dark main .bg-white.rounded-2xl { background-color: #0c2544 !important; border-color: #193b66 !important; }
        html.theme-forest.dark { --bg-card: #0c2e24; --border: #164e3d; --text: #e2e8f0; --text-muted: #94a3b8; }
        html.theme-forest.dark body { background-color: #071f18 !important; color: #e2e8f0; }
        html.theme-forest.dark header,
        html.theme-forest.dark footer,
        html.theme-forest.dark .mc-card,
        html.theme-forest.dark .card,
        html.theme-forest.dark .contact-card,
        html.theme-forest.dark .modal-box,
        html.theme-forest.dark main .bg-white.rounded-2xl { background-color: #0c2e24 !important; border-color: #164e3d !important; }
        html.theme-obsidian.dark { --bg-card: #16102c; --border: #2e2252; --text: #ede9fe; --text-muted: #a78bfa; }
        html.theme-obsidian.dark body { background-color: #0b0719 !important; color: #ede9fe; }
        html.theme-obsidian.dark header,
        html.theme-obsidian.dark footer,
        html.theme-obsidian.dark .mc-card,
        html.theme-obsidian.dark .card,
        html.theme-obsidian.dark .contact-card,
        html.theme-obsidian.dark .modal-box,
        html.theme-obsidian.dark main .bg-white.rounded-2xl { background-color: #16102c !important; border-color: #2e2252 !important; }
        html.theme-obsidian.dark input:not([type="checkbox"]):not([type="radio"]),
        html.theme-obsidian.dark select,
        html.theme-obsidian.dark textarea { background-color: #110c22 !important; border-color: #36285f !important; color: #f5f3ff !important; }

        /* --- Mobile-Optimierungen (Verhindert ungewollten Auto-Zoom unter iOS/Android & verbessert Touch-Bedienung) --- */
        @media (max-width: 640px) {
            input:not([type="checkbox"]):not([type="radio"]),
            select,
            textarea {
                font-size: 16px !important;
            }
            #themeSelect {
                font-size: 13px !important;
            }
        }
        button, a, select, input, label {
            touch-action: manipulation;
        }

        /* --- Globale Komponenten-Klassen für Aufgaben-, Notiz- und Kontakt-Module --- */
        :root {
            --bg-card: #e2e8f0;
            --bg-hover: rgba(100, 116, 139, 0.12);
            --border: #cbd5e1;
            --radius: 1rem;
            --radius-sm: 0.75rem;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 6px 16px rgba(0, 0, 0, 0.12);
            --primary: #2563eb;
            --primary-light: rgba(37, 99, 235, 0.14);
            --text: #0f172a;
            --text-muted: #475569;
        }
        html.dark {
            --bg-card: #0f172a;
            --bg-hover: rgba(148, 163, 184, 0.12);
            --border: #1e293b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
        }
        .page-title { font-size: 1.5rem; font-weight: 700; }
        .page-subtitle { font-size: 0.85rem; color: var(--text-muted); }
        .text-muted { color: var(--text-muted); }
        .card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; padding: 0.5rem 0.95rem; border-radius: 0.75rem; font-size: 0.84rem; font-weight: 600; cursor: pointer; transition: all 0.15s ease; border: 1px solid transparent; text-decoration: none; }
        .btn-xs { padding: 0.25rem 0.55rem; font-size: 0.75rem; border-radius: 0.55rem; }
        .btn-primary { background: #2563eb; color: #ffffff !important; border-color: #2563eb; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-outline { background: rgba(148, 163, 184, 0.12); color: inherit; border-color: var(--border); }
        .btn-outline:hover, .btn-outline.active { border-color: #3b82f6; background: rgba(59, 130, 246, 0.14); }
        .btn-danger { background: rgba(239, 68, 68, 0.15); color: #ef4444; border-color: rgba(239, 68, 68, 0.35); }
        .btn-danger:hover { background: #ef4444; color: #ffffff !important; }
        .form-group { margin-bottom: 0.85rem; }
        .form-label { display: block; font-size: 0.8rem; font-weight: 600; margin-bottom: 0.3rem; }
        .form-control { width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.75rem; border: 1px solid var(--border); background: var(--bg-card); color: inherit; font-size: 0.875rem; }
        .form-control:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25); }
        .badge { display: inline-flex; align-items: center; padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; }
        .modal-overlay { position: fixed; inset: 0; z-index: 60; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .modal-box { width: 100%; background: var(--bg-card); border: 1px solid var(--border); border-radius: 1rem; padding: 1.35rem; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 1rem; padding-bottom: 0.65rem; border-bottom: 1px solid var(--border); }
        .modal-close { background: transparent; border: none; font-size: 1.1rem; cursor: pointer; color: var(--text-muted); padding: 0.2rem 0.45rem; border-radius: 0.5rem; }
        .modal-close:hover { background: var(--bg-hover); color: inherit; }

        /* ============================================================================
           🎨 DESIGN-STILE / UI-SKINS (Zentral umschaltbare Formensprache)
           ============================================================================ */

        /* 1. 📐 ENTERPRISE SHARP (Business / Klassischer Bootstrap-Look) */
        html.ui-style-sharp {
            --radius: 0.25rem;
            --radius-sm: 0.2rem;
        }
        html.ui-style-sharp .rounded-3xl,
        html.ui-style-sharp .rounded-2xl,
        html.ui-style-sharp .mc-card,
        html.ui-style-sharp .card,
        html.ui-style-sharp .contact-card,
        html.ui-style-sharp .modal-box {
            border-radius: 0.25rem !important;
        }
        html.ui-style-sharp .rounded-xl,
        html.ui-style-sharp .rounded-lg,
        html.ui-style-sharp .btn,
        html.ui-style-sharp .form-control,
        html.ui-style-sharp input:not([type="checkbox"]):not([type="radio"]),
        html.ui-style-sharp select,
        html.ui-style-sharp textarea,
        html.ui-style-sharp button:not(.rounded-full) {
            border-radius: 0.2rem !important;
        }
        html.ui-style-sharp .bg-gradient-to-r,
        html.ui-style-sharp .bg-gradient-to-br {
            background-image: none !important;
        }

        /* 2. 🔮 GLASSMORPHISM (Vision / macOS Milchglas-Optik) */
        html.ui-style-glass body {
            background-attachment: fixed !important;
            background-image:
                radial-gradient(circle at 12% 18%, rgba(59, 130, 246, 0.20), transparent 42%),
                radial-gradient(circle at 88% 15%, rgba(139, 92, 246, 0.18), transparent 40%),
                radial-gradient(circle at 52% 85%, rgba(16, 185, 129, 0.14), transparent 46%) !important;
        }
        html.ui-style-glass header {
            background-color: color-mix(in srgb, var(--bg-card) 68%, transparent) !important;
            backdrop-filter: blur(20px) saturate(170%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(170%) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.22) !important;
        }
        html.ui-style-glass .mc-card,
        html.ui-style-glass .card,
        html.ui-style-glass .contact-card,
        html.ui-style-glass .modal-box,
        html.ui-style-glass main .bg-white.rounded-2xl {
            background-color: color-mix(in srgb, var(--bg-card) 72%, transparent) !important;
            backdrop-filter: blur(18px) saturate(165%) !important;
            -webkit-backdrop-filter: blur(18px) saturate(165%) !important;
            border: 1px solid rgba(255, 255, 255, 0.34) !important;
            box-shadow: 0 12px 32px -6px rgba(15, 23, 42, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.28) !important;
            border-radius: 1.25rem !important;
        }
        html.ui-style-glass.dark .mc-card,
        html.ui-style-glass.dark .card,
        html.ui-style-glass.dark .contact-card,
        html.ui-style-glass.dark .modal-box,
        html.ui-style-glass.dark main .bg-white.rounded-2xl {
            border: 1px solid rgba(255, 255, 255, 0.13) !important;
            box-shadow: 0 14px 36px -6px rgba(0, 0, 0, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.10) !important;
        }

        /* 3. ⬛ NEO-KONTRAST (Brutalist / Gestochen scharfe 2px-Konturen & Offset-Schatten) */
        html.ui-style-brutalist .rounded-3xl,
        html.ui-style-brutalist .rounded-2xl,
        html.ui-style-brutalist .rounded-xl,
        html.ui-style-brutalist .rounded-lg,
        html.ui-style-brutalist .mc-card,
        html.ui-style-brutalist .card,
        html.ui-style-brutalist .contact-card,
        html.ui-style-brutalist .modal-box,
        html.ui-style-brutalist .btn,
        html.ui-style-brutalist .form-control,
        html.ui-style-brutalist input:not([type="checkbox"]):not([type="radio"]),
        html.ui-style-brutalist select,
        html.ui-style-brutalist textarea,
        html.ui-style-brutalist button {
            border-radius: 0px !important;
        }
        html.ui-style-brutalist .mc-card,
        html.ui-style-brutalist .card,
        html.ui-style-brutalist .contact-card,
        html.ui-style-brutalist .modal-box,
        html.ui-style-brutalist main .bg-white.rounded-2xl {
            border-width: 2px !important;
            border-color: rgba(15, 23, 42, 0.75) !important;
            box-shadow: 4px 4px 0px rgba(15, 23, 42, 0.35) !important;
        }
        html.ui-style-brutalist.dark .mc-card,
        html.ui-style-brutalist.dark .card,
        html.ui-style-brutalist.dark .contact-card,
        html.ui-style-brutalist.dark .modal-box,
        html.ui-style-brutalist.dark main .bg-white.rounded-2xl {
            border-color: rgba(148, 163, 184, 0.65) !important;
            box-shadow: 4px 4px 0px rgba(0, 0, 0, 0.65) !important;
        }
        html.ui-style-brutalist header {
            border-bottom-width: 2px !important;
            border-bottom-color: rgba(15, 23, 42, 0.75) !important;
        }
        html.ui-style-brutalist.dark header {
            border-bottom-color: rgba(148, 163, 184, 0.65) !important;
        }

        /* 4. 🫧 PILL & LOUNGE (Ultra-Abgerundet / Organisch weich) */
        html.ui-style-pill .mc-card,
        html.ui-style-pill .card,
        html.ui-style-pill .contact-card,
        html.ui-style-pill .modal-box,
        html.ui-style-pill main .bg-white.rounded-2xl {
            border-radius: 1.75rem !important;
            box-shadow: 0 10px 28px -8px rgba(15, 23, 42, 0.12) !important;
        }
        html.ui-style-pill .btn,
        html.ui-style-pill button:not([role="switch"]),
        html.ui-style-pill a.rounded-xl,
        html.ui-style-pill a.rounded-lg,
        html.ui-style-pill input:not([type="checkbox"]):not([type="radio"]),
        html.ui-style-pill select {
            border-radius: 9999px !important;
        }
        html.ui-style-pill textarea {
            border-radius: 1.35rem !important;
        }
    </style>
</head>
<body class="min-h-full flex flex-col overflow-x-hidden bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 antialiased selection:bg-brand-600 selection:text-white transition-colors duration-200">

<!-- Top Navigation (Full Responsive) -->
<header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 sticky top-0 z-30 shadow-xs transition-colors">
    <div class="max-w-7xl mx-auto px-2.5 sm:px-6 lg:px-8 h-14 sm:h-16 flex items-center justify-between gap-1.5 sm:gap-2">
        <!-- Brand Logo & Application Title -->
        <a href="index.php" class="flex items-center gap-2 sm:gap-3 group min-w-0 shrink">
            <?php if ($appLogoUri !== ''): ?>
                <img
                    src="<?= e($appLogoUri) ?>"
                    alt="<?= e($appTitle) ?> Logo"
                    class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl object-contain bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 p-1 shadow-xs group-hover:scale-105 transition-transform shrink-0"
                >
            <?php else: ?>
                <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-700 flex items-center justify-center text-white shadow-sm group-hover:scale-105 transition-transform shrink-0">
                    <svg class="w-4 h-4 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                    </svg>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <span class="block text-sm sm:text-lg font-bold tracking-tight text-slate-900 dark:text-white truncate max-w-[105px] sm:max-w-none"><?= e($appTitle) ?></span>
                <?php if (trim($appSubtitle) !== ''): ?>
                    <span class="hidden md:inline-block ml-1.5 text-xs font-medium px-2 py-0.5 rounded-full bg-brand-50 dark:bg-brand-900/50 text-brand-700 dark:text-brand-300 border border-brand-100 dark:border-brand-800"><?= e($appSubtitle) ?></span>
                <?php endif; ?>
            </div>
        </a>

        <!-- Right Navigation & Controls -->
        <nav class="flex items-center gap-1 sm:gap-2 shrink-0 relative">
            <?php if (isLoggedIn()): ?>
                <?php
                $headerUnreadChat = (($appSettings['enable_chat'] ?? '1') === '1')
                    ? getUnreadMessagesCount(getDB(), (int)$_SESSION['user_id'])
                    : 0;
                $trashCountBadge = ($headerStorageStats !== null) ? (int)($headerStorageStats['trash_count'] ?? 0) : 0;
                ?>

                <!-- Dynamisch angepinnte Favoriten-Module in der Kopfzeile -->
                <div id="headerPinnedNav" class="flex items-center gap-1 sm:gap-1.5">
                    <a
                        href="index.php"
                        data-nav-item="files"
                        class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        title="Meine Dateien"
                    >
                        <span>📁</span>
                        <span class="hidden md:inline">Dateien</span>
                    </a>

                    <?php if (($appSettings['enable_calendar'] ?? '1') === '1'): ?>
                        <a
                            href="calendar.php"
                            data-nav-item="calendar"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Kalender &amp; Termine"
                        >
                            <span>📅</span>
                            <span class="hidden md:inline">Kalender</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_chat'] ?? '1') === '1'): ?>
                        <a
                            href="messages.php"
                            data-nav-item="chat"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors relative"
                            title="Chat &amp; Nachrichten"
                        >
                            <span>💬</span>
                            <span class="hidden md:inline">Chat</span>
                            <span
                                id="headerUnreadChatBadge"
                                class="<?= $headerUnreadChat > 0 ? 'inline-flex' : 'hidden' ?> items-center justify-center px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-red-600 text-white shadow-2xs"
                            >
                                <?= $headerUnreadChat ?>
                            </span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_tasks'] ?? '1') === '1'): ?>
                        <a
                            href="tasks.php"
                            data-nav-item="tasks"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Aufgaben &amp; Kanban-Board"
                        >
                            <span>📋</span>
                            <span class="hidden md:inline">Aufgaben</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_notes'] ?? '1') === '1'): ?>
                        <a
                            href="notes.php"
                            data-nav-item="notes"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Notizwand &amp; Pinboard"
                        >
                            <span>📌</span>
                            <span class="hidden md:inline">Notizen</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_contacts'] ?? '1') === '1'): ?>
                        <a
                            href="contacts.php"
                            data-nav-item="contacts"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Adressbuch &amp; Kontakte"
                        >
                            <span>📇</span>
                            <span class="hidden md:inline">Kontakte</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_dashboard'] ?? '1') === '1'): ?>
                        <a
                            href="dashboard.php"
                            data-nav-item="dashboard"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Persönliches Tages-Cockpit (Mein Tag)"
                        >
                            <span>🏠</span>
                            <span class="hidden md:inline">Cockpit</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_polls'] ?? '1') === '1'): ?>
                        <a
                            href="polls.php"
                            data-nav-item="polls"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Terminfinder &amp; Umfragen"
                        >
                            <span>📊</span>
                            <span class="hidden md:inline">Umfragen</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_menti'] ?? '1') === '1'): ?>
                        <a
                            href="menti.php"
                            data-nav-item="menti"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Live-Mentimeter &amp; Interaktive Präsentationen"
                        >
                            <span>🎤</span>
                            <span class="hidden md:inline">Mentimeter</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_passwords'] ?? '1') === '1'): ?>
                        <a
                            href="passwords.php"
                            data-nav-item="passwords"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Passwort-Manager &amp; Schlüsselbund"
                        >
                            <span>🔐</span>
                            <span class="hidden md:inline">Schlüsselbund</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_pdf_tools'] ?? '1') === '1'): ?>
                        <a
                            href="pdf_tools.php"
                            data-nav-item="pdf_tools"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="PDF-Werkzeugkasten &amp; Dokumenten-Scanner"
                        >
                            <span>📄</span>
                            <span class="hidden md:inline">PDF &amp; Scan</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_projects'] ?? '1') === '1'): ?>
                        <a
                            href="projects.php"
                            data-nav-item="projects"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Projektmanagement (Meilensteine, Gantt-Zeitstrahl &amp; Zeiterfassung)"
                        >
                            <span>🚀</span>
                            <span class="hidden md:inline">Projekte</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_office'] ?? '1') === '1'): ?>
                        <a
                            href="office.php"
                            data-nav-item="office"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="MiniCloud Office Light (Word, Excel, PowerPoint, Visio &amp; Mind-Maps)"
                        >
                            <span>📝</span>
                            <span class="hidden md:inline">Office Light</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_assistant'] ?? '1') === '1'): ?>
                        <a
                            href="assistant.php"
                            data-nav-item="assistant"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Lokaler KI- &amp; Wissens-Assistent"
                        >
                            <span>🤖</span>
                            <span class="hidden md:inline">Assistent</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_ebooks'] ?? '1') === '1'): ?>
                        <a
                            href="ebooks.php"
                            data-nav-item="ebooks"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="E-Book-Reader &amp; Bibliothek (.epub)"
                        >
                            <span>📚</span>
                            <span class="hidden md:inline">E-Books</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_games'] ?? '1') === '1'): ?>
                        <a
                            href="games.php"
                            data-nav-item="games"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Browserspiele &amp; Pausenraum"
                        >
                            <span>🎮</span>
                            <span class="hidden md:inline">Spiele</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_mindmap'] ?? '1') === '1'): ?>
                        <a
                            href="mindmap.php"
                            data-nav-item="mindmap"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Mind-Map-Editor &amp; Ideensammler"
                        >
                            <span>🧠</span>
                            <span class="hidden md:inline">Mind-Maps</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_budget'] ?? '1') === '1'): ?>
                        <a
                            href="budget.php"
                            data-nav-item="budget"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Haushaltsplaner &amp; Finanz-Übersicht"
                        >
                            <span>💰</span>
                            <span class="hidden md:inline">Haushalt</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_club'] ?? '1') === '1'): ?>
                        <a
                            href="club.php"
                            data-nav-item="club"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Vereinsverwaltung &amp; Vorstands-Portal"
                        >
                            <span>🏛️</span>
                            <span class="hidden md:inline">Verein</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_gallery'] ?? '1') === '1'): ?>
                        <a
                            href="gallery.php"
                            data-nav-item="gallery"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Foto-Alben &amp; Medien-Galerie"
                        >
                            <span>🖼️</span>
                            <span class="hidden md:inline">Galerie</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_forms'] ?? '1') === '1'): ?>
                        <a
                            href="forms.php"
                            data-nav-item="forms"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Online-Formulare &amp; Veranstaltungs-Anmeldung"
                        >
                            <span>📝</span>
                            <span class="hidden md:inline">Formulare</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_music'] ?? '1') === '1'): ?>
                        <a
                            href="music.php"
                            data-nav-item="music"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Musik-, Podcast- &amp; Hörbuch-Studio"
                        >
                            <span>🎵</span>
                            <span class="hidden md:inline">Musik</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_calculator'] ?? '1') === '1'): ?>
                        <a
                            href="calculator.php"
                            data-nav-item="calculator"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Taschenrechner &amp; Rechen-Studio"
                        >
                            <span>🧮</span>
                            <span class="hidden md:inline">Rechner</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_toolbox'] ?? '1') === '1'): ?>
                        <a
                            href="toolbox.php"
                            data-nav-item="toolbox"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Alltags-Werkzeuge &amp; Toolbox (WLAN-QR, GiroCode, Glücksrad, Stoppuhr, Text-Diff &amp; Farben)"
                        >
                            <span>🧰</span>
                            <span class="hidden md:inline">Toolbox</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_video_studio'] ?? '1') === '1'): ?>
                        <a
                            href="video_studio.php"
                            data-nav-item="video_studio"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Video-Studio &amp; Schnittplatz (Trimmen, Drehen, Bauchbinden, Filter &amp; Musik)"
                        >
                            <span>🎬</span>
                            <span class="hidden md:inline">Video-Studio</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_surflog'] ?? '1') === '1'): ?>
                        <a
                            href="surflog.php"
                            data-nav-item="surflog"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Surftagebuch &amp; Quiver-Log (Windsurfen, Kiten, Wingfoilen, Wellenreiten &amp; SUP)"
                        >
                            <span>🏄‍♂️</span>
                            <span class="hidden md:inline">Surftagebuch</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_cinema'] ?? '1') === '1'): ?>
                        <a
                            href="cinema.php"
                            data-nav-item="cinema"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Heimkino &amp; Film-Mediathek (Kinosaal, Echtzeit-Ambilight, Weiterschauen &amp; Gäste-Ticket)"
                        >
                            <span>🍿</span>
                            <span class="hidden md:inline">Heimkino</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_whiteboard'] ?? '1') === '1'): ?>
                        <a
                            href="whiteboard.php"
                            data-nav-item="whiteboard"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Kollaboratives Whiteboard &amp; Skizzenbuch"
                        >
                            <span>🎨</span>
                            <span class="hidden md:inline">Whiteboard</span>
                        </a>
                    <?php endif; ?>

                    <?php if (($appSettings['enable_translator'] ?? '1') === '1'): ?>
                        <a
                            href="translate.php"
                            data-nav-item="translator"
                            style="display:none;"
                            class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                            title="Übersetzungs-Studio &amp; Dokument-Übersetzer (18 Sprachen)"
                        >
                            <span>🌐</span>
                            <span class="hidden md:inline">Übersetzer</span>
                        </a>
                    <?php endif; ?>

                    <a
                        href="trash.php"
                        data-nav-item="trash"
                        style="display:none;"
                        class="inline-flex items-center gap-1.5 px-2 sm:px-2.5 py-1.5 rounded-xl text-xs sm:text-sm font-medium text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        title="Papierkorb (30 Tage Aufbewahrung)"
                    >
                        <span>🗑️</span>
                        <span class="hidden md:inline">Papierkorb</span>
                        <?php if ($trashCountBadge > 0): ?>
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200">
                                <?= $trashCountBadge ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>

                <!-- 🔍 Globale Spotlight-Schnellsuche (Strg+K / Cmd+K) -->
                <button
                    type="button"
                    onclick="openGlobalSearchModal()"
                    class="inline-flex items-center justify-center gap-1.5 p-2 sm:px-2.5 sm:py-1.5 rounded-xl text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/70 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700/80 transition-colors cursor-pointer"
                    title="Globale Schnellsuche über Dateien, Module, Notizen, Termine &amp; Kontakte (Strg+K)"
                    aria-label="Schnellsuche"
                >
                    <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <span class="hidden lg:inline text-xs font-medium text-slate-500 dark:text-slate-400">Suche…</span>
                    <kbd class="hidden md:inline-block px-1.5 py-0.5 text-[10px] font-mono font-bold bg-white dark:bg-slate-900 text-slate-500 dark:text-slate-400 rounded border border-slate-300 dark:border-slate-700">Strg+K</kbd>
                </button>

                <!-- ⊞ Modul-Übersicht (eigene Seite modules.php + ⭐ Favoriten-Pin für die Kopfzeile) -->
                <a
                    href="modules.php"
                    id="appLauncherBtn"
                    class="inline-flex items-center justify-center gap-1.5 p-2 sm:px-2.5 sm:py-1.5 rounded-xl <?= $currentScript === 'modules.php' ? 'bg-brand-600 text-white shadow-2xs border border-brand-600' : 'text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/70 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700/80' ?> transition-colors"
                    title="Alle Cloud-Module anzeigen &amp; Kopfzeilen-Favoriten (⭐) verwalten"
                    aria-label="Alle Module"
                >
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                    <span class="hidden sm:inline text-xs font-semibold">Module</span>
                </a>

                <?php if (hasAdminPermission()): ?>
                    <!-- 🛡️ Direkt-Button zur System- bzw. Teil-Administration -->
                    <a
                        href="admin.php"
                        class="inline-flex items-center justify-center gap-1.5 px-2.5 py-1.5 rounded-xl text-xs font-semibold transition-colors <?= $currentScript === 'admin.php' ? 'bg-indigo-600 text-white shadow-2xs border border-indigo-600' : (isAdmin() ? 'bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/80' : 'bg-indigo-50 dark:bg-indigo-950/70 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800/80') ?>"
                        title="<?= isAdmin() ? 'System-Administration öffnen' : 'Teil-Administration öffnen (übertragene Verwaltungsbereiche)' ?>"
                    >
                        <span>🛡️</span>
                        <span class="hidden md:inline"><?= isAdmin() ? 'Admin' : 'Teil-Administration' ?></span>
                    </a>
                <?php endif; ?>

                <!-- 👤 Konto, Speicherplatz, Einstellungen, Admin & Abmelden -->
                <div class="relative">
                    <button
                        type="button"
                        id="userMenuBtn"
                        onclick="toggleHeaderPopover('userMenuDropdown')"
                        class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/70 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700/80 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-medium transition-colors cursor-pointer"
                        title="Konto, Speicherplatz &amp; Einstellungen"
                    >
                        <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                        <span class="hidden sm:inline truncate max-w-[100px]"><?= e((string)$_SESSION['username']) ?></span>
                        <svg class="w-4 h-4 text-slate-500 dark:text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div
                        id="userMenuDropdown"
                        class="hidden fixed sm:absolute right-2 sm:right-0 top-14 sm:top-full sm:mt-2 w-[calc(100vw-1rem)] max-w-[290px] rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-3.5 z-50 space-y-3"
                    >
                        <div class="flex items-center justify-between gap-2 pb-2.5 border-b border-slate-200/80 dark:border-slate-800">
                            <div class="min-w-0">
                                <p class="text-xs font-bold text-slate-900 dark:text-white truncate">👤 <?= e((string)$_SESSION['username']) ?></p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400"><?= isAdmin() ? '🛡️ Administrator' : (hasAdminPermission() ? '🛡️ Teil-Administrator' : 'Cloud-Benutzer') ?></p>
                            </div>
                            <?php if (hasAdminPermission()): ?>
                                <a href="admin.php" class="text-[11px] font-bold uppercase px-2 py-1 rounded-lg <?= isAdmin() ? 'bg-brand-600 hover:bg-brand-700' : 'bg-indigo-600 hover:bg-indigo-700' ?> text-white transition-colors">
                                    <?= isAdmin() ? 'Admin' : 'Teil-Admin' ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if ($headerStorageStats !== null): ?>
                            <a href="index.php?large=1&amp;sort=size&amp;dir=desc"
                               title="Klicken, um große Dateien, Speicherfresser &amp; Duplikate anzuzeigen"
                               class="block p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 hover:bg-violet-50/70 dark:hover:bg-slate-800 border border-slate-200/70 dark:border-slate-700/70 transition-colors">
                                <div class="flex items-center justify-between gap-2 text-[11px] font-medium text-slate-600 dark:text-slate-300 mb-1.5">
                                    <span>💾 <?= e(formatBytes($headerStorageStats['used'], 1)) ?> / <?= e(formatBytes($headerStorageStats['quota'], 0)) ?></span>
                                    <span class="font-bold text-slate-800 dark:text-slate-100"><?= e(number_format($headerStorageStats['percent'], 0, ',', '.')) ?>%</span>
                                </div>
                                <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div
                                        class="h-full <?= $headerBarColor ?> rounded-full transition-all duration-300"
                                        style="width: <?= e((string)max($headerStorageStats['used'] > 0 ? 3 : 0, $headerStorageStats['percent'])) ?>%"
                                    ></div>
                                </div>
                                <div class="flex items-center justify-between mt-1">
                                    <span class="text-[10px] text-slate-400 dark:text-slate-500">Noch <?= e(formatBytes($headerStorageStats['free'])) ?> frei</span>
                                    <span class="text-[10px] font-semibold text-brand-600 dark:text-brand-400">📊 Große Dateien &rarr;</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <div class="space-y-1">
                            <?php if (hasAdminPermission()): ?>
                                <a href="admin.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold <?= isAdmin() ? 'text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/50' : 'text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-950/50' ?> transition-colors">
                                    <span>🛡️</span>
                                    <span><?= isAdmin() ? 'System-Administration' : 'Teil-Administration' ?></span>
                                </a>
                            <?php endif; ?>
                            <a href="settings.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                <span>⚙️</span>
                                <span>Einstellungen &amp; Design</span>
                            </a>
                            <a href="help.php" class="flex items-center gap-2.5 px-3 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                <span>❓</span>
                                <span>Hilfe &amp; Handbuch</span>
                            </a>
                        </div>

                        <!-- 🎨 Live Design-Stil / UI-Skin Schnellwechsler -->
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-800">
                            <div class="flex items-center justify-between mb-1.5 px-1">
                                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500">🎨 Design-Stil (Live)</span>
                                <a href="settings.php#sec-ui-style" class="text-[10px] font-semibold text-brand-600 dark:text-brand-400 hover:underline">Alle &rarr;</a>
                            </div>
                            <div class="grid grid-cols-5 gap-1" id="headerUiStylePicker">
                                <?php foreach (getUiStyleDefinitions() as $stKey => $stMeta): ?>
                                    <?php $isStActive = ($currentUiStyle === $stKey); ?>
                                    <button
                                        type="button"
                                        data-ui-style-btn="<?= e($stKey) ?>"
                                        onclick="window.switchMcUiStyle('<?= e($stKey) ?>', '<?= e(getCsrfToken()) ?>')"
                                        title="<?= e($stMeta['label'] . ' – ' . $stMeta['desc']) ?>"
                                        class="flex flex-col items-center justify-center py-1.5 px-1 rounded-lg border text-[10px] font-bold transition-all cursor-pointer <?= $isStActive ? 'bg-brand-600 text-white border-brand-600 shadow-2xs' : 'bg-slate-50 dark:bg-slate-800/70 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400' ?>"
                                    >
                                        <span class="text-xs leading-none mb-0.5"><?= e($stMeta['icon']) ?></span>
                                        <span class="truncate max-w-full leading-tight"><?= e($stMeta['short']) ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-800">
                            <form action="logout.php" method="post" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <button
                                    type="submit"
                                    class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold text-red-600 dark:text-red-400 bg-red-50/70 dark:bg-red-950/30 hover:bg-red-100 dark:hover:bg-red-950/60 transition-colors cursor-pointer"
                                >
                                    <span>🚪</span>
                                    <span>Abmelden</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <script>
                (function() {
                    const storageKey = 'mc_pinned_header_nav_u<?= (int)$_SESSION['user_id'] ?>';
                    const orderKey   = 'mc_launcher_order_u<?= (int)$_SESSION['user_id'] ?>';
                    const defaultPinned = ['files', 'chat'];
                    const defaultOrder  = ['files', 'office', 'translator', 'ebooks', 'music', 'cinema', 'video_studio', 'surflog', 'mindmap', 'budget', 'toolbox', 'calculator', 'games', 'assistant', 'calendar', 'chat', 'tasks', 'projects', 'notes', 'contacts', 'dashboard', 'polls', 'menti', 'passwords', 'pdf_tools', 'trash'];

                    function getPinnedNav() {
                        try {
                            const raw = localStorage.getItem(storageKey);
                            if (raw) {
                                const parsed = JSON.parse(raw);
                                if (Array.isArray(parsed)) return parsed;
                            }
                        } catch (e) {}
                        return defaultPinned.slice();
                    }

                    function savePinnedNav(list) {
                        try {
                            localStorage.setItem(storageKey, JSON.stringify(list));
                        } catch (e) {}
                    }

                    function getLauncherOrder() {
                        try {
                            const raw = localStorage.getItem(orderKey);
                            if (raw) {
                                const parsed = JSON.parse(raw);
                                if (Array.isArray(parsed) && parsed.length > 0) return parsed;
                            }
                        } catch (e) {}
                        return defaultOrder.slice();
                    }

                    function saveLauncherOrder(list) {
                        try {
                            localStorage.setItem(orderKey, JSON.stringify(list));
                        } catch (e) {}
                    }

                    function applyPinnedNav() {
                        const pinned = getPinnedNav();
                        const navBar = document.getElementById('headerPinnedNav');

                        // 1. Sichtbarkeit & Sterne aktualisieren
                        document.querySelectorAll('[data-nav-item]').forEach(el => {
                            const key = el.getAttribute('data-nav-item');
                            el.setAttribute('draggable', 'true');
                            el.classList.add('cursor-grab', 'active:cursor-grabbing');
                            el.style.display = pinned.includes(key) ? '' : 'none';
                        });

                        // 2. Reihenfolge in der oberen Kopfleiste exakt gemäß pinned-Array sortieren
                        if (navBar) {
                            pinned.forEach(key => {
                                const el = navBar.querySelector(`[data-nav-item="${key}"]`);
                                if (el) {
                                    navBar.appendChild(el);
                                }
                            });
                        }

                        document.querySelectorAll('[data-nav-star]').forEach(btn => {
                            const key = btn.getAttribute('data-nav-star');
                            const isFav = pinned.includes(key);
                            btn.textContent = isFav ? '⭐' : '☆';
                            btn.title = isFav ? 'Aus Kopfzeile lösen' : 'Oben in Kopfzeile anheften';
                        });

                        document.querySelectorAll('[data-pin-status]').forEach(el => {
                            const key = el.getAttribute('data-pin-status');
                            const isFav = pinned.includes(key);
                            el.textContent = isFav ? '⭐ In Kopfzeile angeheftet' : '☆ Oben anheften';
                            el.className = isFav
                                ? 'inline-flex items-center gap-1 text-[11px] font-semibold text-amber-600 dark:text-amber-400 hover:underline cursor-pointer select-none'
                                : 'inline-flex items-center gap-1 text-[11px] font-medium text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer select-none';
                        });

                        const countEl = document.getElementById('pinnedModuleCounter');
                        if (countEl) {
                            let visiblePinnedCount = 0;
                            if (navBar) {
                                pinned.forEach(k => {
                                    if (navBar.querySelector(`[data-nav-item="${k}"]`)) visiblePinnedCount++;
                                });
                            } else {
                                visiblePinnedCount = pinned.length;
                            }
                            countEl.textContent = String(visiblePinnedCount);
                        }

                        if (typeof window.applyModulePageFilters === 'function') {
                            window.applyModulePageFilters();
                        }
                    }

                    function applyLauncherOrder() {
                        const order = getLauncherOrder();
                        const grid = document.getElementById('appLauncherGrid');
                        if (!grid) return;
                        order.forEach(key => {
                            const item = grid.querySelector(`[data-launcher-item="${key}"]`);
                            if (item) {
                                grid.appendChild(item);
                            }
                        });
                    }

                    // Drag & Drop für die angepinnten Buttons in der oberen Kopfleiste (#headerPinnedNav)
                    function initPinnedNavDragAndDrop() {
                        const navBar = document.getElementById('headerPinnedNav');
                        if (!navBar) return;
                        let draggedNavKey = null;

                        navBar.addEventListener('dragstart', function(e) {
                            const item = e.target.closest('[data-nav-item]');
                            if (!item) return;
                            draggedNavKey = item.getAttribute('data-nav-item');
                            item.classList.add('opacity-50');
                            if (e.dataTransfer) {
                                e.dataTransfer.effectAllowed = 'move';
                                e.dataTransfer.setData('text/plain', draggedNavKey);
                            }
                        });

                        navBar.addEventListener('dragend', function(e) {
                            const item = e.target.closest('[data-nav-item]');
                            if (item) item.classList.remove('opacity-50');
                            navBar.querySelectorAll('[data-nav-item]').forEach(el => {
                                el.classList.remove('ring-2', 'ring-brand-500');
                            });
                            draggedNavKey = null;
                        });

                        navBar.addEventListener('dragover', function(e) {
                            if (!draggedNavKey) return;
                            const overItem = e.target.closest('[data-nav-item]');
                            if (!overItem) return;
                            e.preventDefault();
                            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                            navBar.querySelectorAll('[data-nav-item]').forEach(el => {
                                el.classList.toggle('ring-2', el === overItem);
                                el.classList.toggle('ring-brand-500', el === overItem);
                            });
                        });

                        navBar.addEventListener('drop', function(e) {
                            if (!draggedNavKey) return;
                            const targetItem = e.target.closest('[data-nav-item]');
                            if (!targetItem) return;
                            e.preventDefault();
                            const targetKey = targetItem.getAttribute('data-nav-item');
                            if (!targetKey || targetKey === draggedNavKey) return;

                            const pinned = getPinnedNav();
                            const fromIdx = pinned.indexOf(draggedNavKey);
                            const toIdx   = pinned.indexOf(targetKey);
                            if (fromIdx === -1 || toIdx === -1) return;

                            pinned.splice(fromIdx, 1);
                            pinned.splice(toIdx, 0, draggedNavKey);
                            savePinnedNav(pinned);
                            applyPinnedNav();
                        });
                    }

                    // Drag & Drop für die Modul-Kacheln im ⊞ Module-Menü (#appLauncherGrid)
                    function initLauncherGridDragAndDrop() {
                        const grid = document.getElementById('appLauncherGrid');
                        if (!grid) return;
                        let draggedLaunchKey = null;

                        grid.addEventListener('dragstart', function(e) {
                            const card = e.target.closest('[data-launcher-item]');
                            if (!card) return;
                            draggedLaunchKey = card.getAttribute('data-launcher-item');
                            card.classList.add('opacity-50');
                            if (e.dataTransfer) {
                                e.dataTransfer.effectAllowed = 'move';
                                e.dataTransfer.setData('text/plain', draggedLaunchKey);
                            }
                        });

                        grid.addEventListener('dragend', function(e) {
                            const card = e.target.closest('[data-launcher-item]');
                            if (card) card.classList.remove('opacity-50');
                            grid.querySelectorAll('[data-launcher-item]').forEach(el => {
                                el.classList.remove('ring-2', 'ring-brand-500');
                            });
                            draggedLaunchKey = null;
                        });

                        grid.addEventListener('dragover', function(e) {
                            if (!draggedLaunchKey) return;
                            const overCard = e.target.closest('[data-launcher-item]');
                            if (!overCard) return;
                            e.preventDefault();
                            if (e.dataTransfer) e.dataTransfer.dropEffect = 'move';
                            grid.querySelectorAll('[data-launcher-item]').forEach(el => {
                                el.classList.toggle('ring-2', el === overCard);
                                el.classList.toggle('ring-brand-500', el === overCard);
                            });
                        });

                        grid.addEventListener('drop', function(e) {
                            if (!draggedLaunchKey) return;
                            const targetCard = e.target.closest('[data-launcher-item]');
                            if (!targetCard) return;
                            e.preventDefault();
                            const targetKey = targetCard.getAttribute('data-launcher-item');
                            if (!targetKey || targetKey === draggedLaunchKey) return;

                            const currentCards = Array.from(grid.querySelectorAll('[data-launcher-item]')).map(el => el.getAttribute('data-launcher-item'));
                            const fromIdx = currentCards.indexOf(draggedLaunchKey);
                            const toIdx   = currentCards.indexOf(targetKey);
                            if (fromIdx === -1 || toIdx === -1) return;

                            currentCards.splice(fromIdx, 1);
                            currentCards.splice(toIdx, 0, draggedLaunchKey);
                            saveLauncherOrder(currentCards);
                            applyLauncherOrder();
                        });
                    }

                    window.toggleHeaderNavFavorite = function(key, ev) {
                        if (ev) {
                            ev.preventDefault();
                            ev.stopPropagation();
                        }
                        const pinned = getPinnedNav();
                        const idx = pinned.indexOf(key);
                        if (idx !== -1) {
                            pinned.splice(idx, 1);
                        } else {
                            pinned.push(key);
                        }
                        savePinnedNav(pinned);
                        applyPinnedNav();
                    };

                    window.resetHeaderNavAndOrder = function(ev) {
                        if (ev) {
                            ev.preventDefault();
                            ev.stopPropagation();
                        }
                        savePinnedNav(defaultPinned.slice());
                        saveLauncherOrder(defaultOrder.slice());
                        applyLauncherOrder();
                        applyPinnedNav();
                    };

                    window.toggleHeaderPopover = function(id) {
                        ['appLauncherMenu', 'userMenuDropdown'].forEach(menuId => {
                            const el = document.getElementById(menuId);
                            if (!el) return;
                            if (menuId === id) {
                                el.classList.toggle('hidden');
                            } else {
                                el.classList.add('hidden');
                            }
                        });
                    };

                    window.switchMcUiStyle = function(style, csrf) {
                        const allowed = ['modern', 'sharp', 'glass', 'brutalist', 'pill'];
                        if (!allowed.includes(style)) return;
                        const html = document.documentElement;
                        allowed.forEach(s => html.classList.remove('ui-style-' + s));
                        html.classList.add('ui-style-' + style);
                        html.setAttribute('data-ui-style', style);

                        document.querySelectorAll('[data-ui-style-btn]').forEach(btn => {
                            const isAct = btn.getAttribute('data-ui-style-btn') === style;
                            btn.className = 'flex flex-col items-center justify-center py-1.5 px-1 rounded-lg border text-[10px] font-bold transition-all cursor-pointer ' + (
                                isAct
                                    ? 'bg-brand-600 text-white border-brand-600 shadow-2xs'
                                    : 'bg-slate-50 dark:bg-slate-800/70 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:border-brand-400'
                            );
                        });

                        const fd = new URLSearchParams();
                        fd.set('ui_style', style);
                        fd.set('csrf_token', csrf || '');
                        fetch('theme.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json'
                            },
                            body: fd.toString()
                        }).catch(() => {});
                    };

                    document.addEventListener('click', function(e) {
                        const launcherBtn = document.getElementById('appLauncherBtn');
                        const launcherMenu = document.getElementById('appLauncherMenu');
                        const userBtn = document.getElementById('userMenuBtn');
                        const userMenu = document.getElementById('userMenuDropdown');
                        if (launcherMenu && !launcherMenu.classList.contains('hidden')) {
                            if (!launcherMenu.contains(e.target) && (!launcherBtn || !launcherBtn.contains(e.target))) {
                                launcherMenu.classList.add('hidden');
                            }
                        }
                        if (userMenu && !userMenu.classList.contains('hidden')) {
                            if (!userMenu.contains(e.target) && (!userBtn || !userBtn.contains(e.target))) {
                                userMenu.classList.add('hidden');
                            }
                        }
                    });

                    applyLauncherOrder();
                    applyPinnedNav();
                    initPinnedNavDragAndDrop();
                    initLauncherGridDragAndDrop();

                    document.addEventListener('DOMContentLoaded', function() {
                        applyLauncherOrder();
                        applyPinnedNav();
                        initLauncherGridDragAndDrop();
                    });
                })();

                // =========================================================================
                // 🔍 GLOBALE SPOTLIGHT-SCHNELLSUCHE (Strg+K / Cmd+K)
                // =========================================================================
                (function() {
                    let gsResults = [];
                    let gsSelectedIndex = 0;
                    let gsDebounceTimer = null;

                    window.openGlobalSearchModal = function() {
                        const modal = document.getElementById('globalSearchModal');
                        const inp   = document.getElementById('globalSearchInput');
                        if (!modal) return;
                        modal.classList.remove('hidden');
                        if (inp) {
                            inp.focus();
                            inp.select();
                            fetchGlobalSearch(inp.value);
                        }
                    };

                    window.closeGlobalSearchModal = function() {
                        const modal = document.getElementById('globalSearchModal');
                        if (modal) modal.classList.add('hidden');
                    };

                    window.onGlobalSearchInput = function(val) {
                        if (gsDebounceTimer) clearTimeout(gsDebounceTimer);
                        gsDebounceTimer = setTimeout(() => fetchGlobalSearch(val), 140);
                    };

                    async function fetchGlobalSearch(q) {
                        try {
                            const r = await fetch('search_api.php?q=' + encodeURIComponent(q || ''));
                            const d = await r.json();
                            if (d && d.ok && Array.isArray(d.results)) {
                                gsResults = d.results;
                                gsSelectedIndex = 0;
                                renderGlobalSearchResults();
                            }
                        } catch (e) {}
                    }

                    function escapeGsHtml(str) {
                        return String(str ?? '')
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;');
                    }

                    window.openGsResultItem = function(idx, ev) {
                        const item = gsResults[idx];
                        if (!item) return;
                        if (ev && ev.target && ev.target.closest('[data-gs-subaction]')) {
                            return;
                        }
                        if (ev) ev.preventDefault();

                        // Wenn es eine Datei ist und die Datei bereits auf der aktuellen index.php-Seite geladen ist -> sofort Vorschau öffnen!
                        if (item.file_id && typeof window.openPreviewModal === 'function' && typeof previewItems !== 'undefined' && Array.isArray(previewItems)) {
                            const fIdx = previewItems.findIndex(p => Number(p.id) === Number(item.file_id));
                            if (fIdx !== -1) {
                                closeGlobalSearchModal();
                                window.openPreviewModal(fIdx);
                                return;
                            }
                        }

                        if (item.url) {
                            closeGlobalSearchModal();
                            window.location.href = item.url;
                        }
                    };

                    function updateGsSelectionHighlight() {
                        const box = document.getElementById('globalSearchResultsList');
                        if (!box) return;
                        box.querySelectorAll('[data-gs-row]').forEach((row) => {
                            const idx = Number(row.getAttribute('data-gs-row'));
                            const active = idx === gsSelectedIndex;
                            row.className = 'flex items-center justify-between gap-2.5 px-3.5 py-2.5 rounded-xl transition-colors cursor-pointer ' + (
                                active
                                    ? 'bg-brand-600 text-white shadow-xs'
                                    : 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-100'
                            );
                            const subEl = row.querySelector('[data-gs-sub]');
                            if (subEl) {
                                subEl.className = 'text-[11px] truncate ' + (active ? 'text-brand-100' : 'text-slate-400 dark:text-slate-400');
                            }
                            const snipEl = row.querySelector('[data-gs-snippet]');
                            if (snipEl) {
                                snipEl.className = 'text-[11px] italic truncate mt-0.5 ' + (active ? 'text-amber-200 font-medium' : 'text-indigo-600 dark:text-indigo-400');
                            }
                            const catEl = row.querySelector('[data-gs-cat]');
                            if (catEl) {
                                catEl.className = 'px-2 py-0.5 rounded-md text-[10px] font-bold shrink-0 ' + (
                                    active
                                        ? 'bg-white/20 text-white'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'
                                );
                            }
                        });
                    }

                    function renderGlobalSearchResults() {
                        const box = document.getElementById('globalSearchResultsList');
                        if (!box) return;
                        if (!gsResults.length) {
                            box.innerHTML = '<div class="py-8 text-center text-xs text-slate-400">Keine passenden Module, Dateien, Notizen oder Kontakte gefunden.</div>';
                            return;
                        }
                        box.innerHTML = gsResults.map((item, idx) => {
                            const active = idx === gsSelectedIndex;
                            let actionsHtml = '';
                            if (item.cat === 'Datei') {
                                actionsHtml = `
                                    <div class="flex items-center gap-1 shrink-0" data-gs-subaction="1">
                                        <button type="button"
                                                onclick="window.openGsResultItem(${idx}, null)"
                                                title="Datei direkt in der Vorschau öffnen"
                                                class="px-2 py-1 rounded-lg text-[11px] font-bold bg-violet-600 hover:bg-violet-500 text-white shadow-2xs transition-colors cursor-pointer">
                                            👁️ Öffnen
                                        </button>
                                        ${item.office_url ? `
                                            <a href="${escapeGsHtml(item.office_url)}"
                                               title="Direkt in Office Light / Bild-Studio bearbeiten"
                                               class="px-2 py-1 rounded-lg text-[11px] font-bold bg-indigo-600 hover:bg-indigo-500 text-white shadow-2xs transition-colors">
                                                ✏️ Office
                                            </a>
                                        ` : ''}
                                        ${item.folder_url ? `
                                            <a href="${escapeGsHtml(item.folder_url)}"
                                               title="Speicherort / Ordner im Dateimanager anzeigen"
                                               class="px-2 py-1 rounded-lg text-[11px] font-bold bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-100 transition-colors">
                                                📁 Ordner
                                            </a>
                                        ` : ''}
                                    </div>
                                `;
                            } else {
                                actionsHtml = `<span data-gs-cat="1" class="px-2 py-0.5 rounded-md text-[10px] font-bold shrink-0 ${active ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400'}">${escapeGsHtml(item.cat || 'Treffer')}</span>`;
                            }
                            return `
                                <div data-gs-row="${idx}"
                                     onclick="window.openGsResultItem(${idx}, event)"
                                     onmouseenter="window.setGsIndex(${idx})"
                                     class="flex items-center justify-between gap-2.5 px-3.5 py-2.5 rounded-xl transition-colors cursor-pointer ${active ? 'bg-brand-600 text-white shadow-xs' : 'hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-800 dark:text-slate-100'}">
                                    <a href="${escapeGsHtml(item.url || '#')}"
                                       onclick="window.openGsResultItem(${idx}, event)"
                                       class="flex items-center gap-3 min-w-0 flex-1">
                                        <span class="text-xl shrink-0">${escapeGsHtml(item.icon || '🔎')}</span>
                                        <div class="min-w-0">
                                            <div class="text-xs sm:text-sm font-bold truncate">${escapeGsHtml(item.title)}</div>
                                            <div data-gs-sub="1" class="text-[11px] truncate ${active ? 'text-brand-100' : 'text-slate-400 dark:text-slate-400'}">${escapeGsHtml(item.sub || '')}</div>
                                            ${item.snippet ? `<div data-gs-snippet="1" class="text-[11px] italic truncate mt-0.5 ${active ? 'text-amber-200 font-medium' : 'text-indigo-600 dark:text-indigo-400'}">🔎 „${escapeGsHtml(item.snippet)}“</div>` : ''}
                                        </div>
                                    </a>
                                    ${actionsHtml}
                                </div>
                            `;
                        }).join('');
                    }

                    window.setGsIndex = function(idx) {
                        if (gsSelectedIndex === idx) return;
                        gsSelectedIndex = idx;
                        updateGsSelectionHighlight();
                    };

                    window.addEventListener('keydown', function(e) {
                        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            const modal = document.getElementById('globalSearchModal');
                            if (modal && !modal.classList.contains('hidden')) {
                                closeGlobalSearchModal();
                            } else {
                                openGlobalSearchModal();
                            }
                            return;
                        }

                        const modal = document.getElementById('globalSearchModal');
                        if (!modal || modal.classList.contains('hidden')) return;

                        if (e.key === 'Escape') {
                            e.preventDefault();
                            closeGlobalSearchModal();
                        } else if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            if (gsResults.length > 0) {
                                gsSelectedIndex = (gsSelectedIndex + 1) % gsResults.length;
                                updateGsSelectionHighlight();
                            }
                        } else if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            if (gsResults.length > 0) {
                                gsSelectedIndex = (gsSelectedIndex - 1 + gsResults.length) % gsResults.length;
                                updateGsSelectionHighlight();
                            }
                        } else if (e.key === 'Enter') {
                            if (gsResults[gsSelectedIndex]) {
                                e.preventDefault();
                                window.openGsResultItem(gsSelectedIndex, null);
                            }
                        }
                    });
                })();
                </script>

                <!-- 🔍 SPOTLIGHT-SCHNELLSUCHE MODAL -->
                <div id="globalSearchModal" class="hidden fixed inset-0 z-50 flex items-start justify-center pt-14 sm:pt-20 px-3 bg-slate-950/60 backdrop-blur-xs" onclick="if(event.target===this)closeGlobalSearchModal()">
                    <div class="modal-box w-full max-w-xl rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden">
                        <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-200 dark:border-slate-800">
                            <span class="text-lg">🔍</span>
                            <input
                                type="text"
                                id="globalSearchInput"
                                oninput="onGlobalSearchInput(this.value)"
                                placeholder="Dateien & Dokument-Inhalte (Volltext), Ordner, Module, Notizen oder Kontakte suchen…"
                                class="flex-1 bg-transparent border-0 text-sm font-semibold text-slate-900 dark:text-white focus:outline-none"
                                autocomplete="off"
                            >
                            <button type="button" onclick="closeGlobalSearchModal()" class="px-2 py-1 rounded-lg text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-800 dark:hover:text-white cursor-pointer">
                                ESC
                            </button>
                        </div>
                        <div id="globalSearchResultsList" class="max-h-[62vh] overflow-y-auto p-2 space-y-1"></div>
                        <div class="px-4 py-2 bg-slate-50 dark:bg-slate-950/60 border-t border-slate-200/80 dark:border-slate-800 flex items-center justify-between text-[11px] text-slate-400">
                            <span>⬆️⬇️ Navigieren &middot; <strong>Enter</strong> Öffnen &middot; <strong>Esc</strong> Schließen</span>
                            <span>⌨️ <strong>Strg + K</strong> überall in der Cloud</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <a
                    href="settings.php"
                    class="inline-flex items-center justify-center p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/70 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700/80 transition-colors"
                    title="Einstellungen"
                >⚙️</a>
                <a href="login.php" class="text-xs sm:text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 px-3 py-1.5 sm:px-3.5 sm:py-2 rounded-xl shadow-xs transition-colors">Anmelden</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- Main Container -->
<main class="flex-1 max-w-7xl w-full mx-auto px-2.5 sm:px-6 lg:px-8 py-4 sm:py-8">
    <?php if (!empty($flashes)): ?>
        <div id="flashContainer" class="space-y-3 mb-6" role="region" aria-label="Benachrichtigungen">
            <?php foreach ($flashes as $flash): ?>
                <?php
                $isError = ($flash['type'] === 'error');
                $isWarning = ($flash['type'] === 'warning');
                $boxClasses = $isError
                    ? 'bg-red-50 dark:bg-red-950/60 border-red-200 dark:border-red-800/80 text-red-800 dark:text-red-200'
                    : ($isWarning
                        ? 'bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800/80 text-amber-800 dark:text-amber-200'
                        : 'bg-emerald-50 dark:bg-emerald-950/60 border-emerald-200 dark:border-emerald-800/80 text-emerald-800 dark:text-emerald-200');
                ?>
                <div class="mc-flash-msg flex items-start justify-between gap-3 p-4 rounded-xl border <?= $boxClasses ?> shadow-xs transition-all duration-300">
                    <div class="flex items-center gap-2.5 text-sm font-medium">
                        <?php if ($isError): ?>
                            <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        <?php elseif ($isWarning): ?>
                            <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        <?php else: ?>
                            <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        <?php endif; ?>
                        <span><?= e($flash['message']) ?></span>
                    </div>
                    <button type="button" onclick="const box = this.closest('.mc-flash-msg'); const wrap = document.getElementById('flashContainer'); if (box) box.remove(); if (wrap && wrap.querySelectorAll('.mc-flash-msg').length === 0) wrap.remove();" class="text-current opacity-60 hover:opacity-100 p-1 cursor-pointer" aria-label="Schließen">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php $flashDurationSec = getCurrentFlashDuration(); ?>
        <?php if ($flashDurationSec > 0): ?>
            <script>
                setTimeout(() => {
                    const wrap = document.getElementById('flashContainer');
                    if (!wrap) return;
                    wrap.querySelectorAll('.mc-flash-msg').forEach((msg) => {
                        msg.classList.add('opacity-0', '-translate-y-1');
                    });
                    setTimeout(() => {
                        wrap.remove();
                    }, 300);
                }, <?= (int)($flashDurationSec * 1000) ?>);
            </script>
        <?php endif; ?>
    <?php endif; ?>
