<?php
declare(strict_types=1);

/**
 * MiniCloud - Modul-Übersicht & Schnellstart-Zentrale (modules.php)
 * Übersichtliche Vollbild-Seite aller aktiven Cloud-Module mit
 * ⭐ Favoriten-Pin für die Kopfzeile, ⠿ Drag-&-Drop-Sortierung,
 * Kategorieschnellfiltern und Live-Suche.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$appSettings = getAppSettings();

// Live-Zähler für Badges (Ungelesene Chat-Nachrichten & Papierkorb)
$unreadChatCount = 0;
try {
    $stmtUnread = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = :uid AND is_read = 0 AND deleted_by_receiver = 0');
    $stmtUnread->execute([':uid' => $userId]);
    $unreadChatCount = (int)$stmtUnread->fetchColumn();
} catch (Throwable $e) {
    $unreadChatCount = 0;
}

$trashItemCount = 0;
try {
    $trashItemCount = getTrashCount($pdo, $userId, isAdmin());
} catch (Throwable $e) {
    $trashItemCount = 0;
}

// Alle verfügbaren Module mit Metadaten, Beschreibung & Kategorie
$allModules = [
    [
        'key'      => 'files',
        'enabled'  => true,
        'title'    => 'Dateien & Ordner',
        'icon'     => '📁',
        'url'      => 'index.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Dateien verwalten, Ordner strukturieren, Vorschau öffnen und Freigabe-Links erstellen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'dashboard',
        'enabled'  => ($appSettings['enable_dashboard'] ?? '1') === '1',
        'title'    => 'Mein Tag',
        'icon'     => '🏠',
        'url'      => 'dashboard.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Persönliches Tages-Cockpit mit anstehenden Terminen, offenen Aufgaben, Schnellnotizen und Speicherstatus.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'office',
        'enabled'  => ($appSettings['enable_office'] ?? '1') === '1',
        'title'    => 'Office Light & Bild-Studio',
        'icon'     => '📝',
        'url'      => 'office.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Word (.docx), Excel (.xlsx), PowerPoint (.pptx), Visio (.drawio), Mind-Maps sowie 🎨 Foto- & Bild-Studio (Zuschneiden, Komprimieren, Filter & DSGVO-Pixel) im Browser.',
        'badge'    => '6-in-1',
        'badgeCls' => 'bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300',
    ],
    [
        'key'      => 'translator',
        'enabled'  => ($appSettings['enable_translator'] ?? '1') === '1',
        'title'    => 'Übersetzungs-Studio',
        'icon'     => '🌐',
        'url'      => 'translate.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Neuronale Satz- & Kontext-Übersetzung (NMT & DeepL-Support) für 18 Sprachen inkl. Direkt-Übersetzung von Cloud-Dokumenten (PDF, Word .docx, Text) und 1-Klick-Speichern in der Cloud.',
        'badge'    => '18 Sprachen',
        'badgeCls' => 'bg-sky-100 dark:bg-sky-950/80 text-sky-700 dark:text-sky-300',
    ],
    [
        'key'      => 'whiteboard',
        'enabled'  => ($appSettings['enable_whiteboard'] ?? '1') === '1',
        'title'    => 'Whiteboard & Skizzenbuch',
        'icon'     => '🎨',
        'url'      => 'whiteboard.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Kollaborative Zeichenfläche für Freihand-Skizzen, Flussdiagramme, Klebezettel (Sticky Notes) & Live-Teamwork inklusive PNG- & Cloud-Export.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300',
    ],
    [
        'key'      => 'ebooks',
        'enabled'  => ($appSettings['enable_ebooks'] ?? '1') === '1',
        'title'    => 'E-Book-Reader',
        'icon'     => '📚',
        'url'      => 'ebooks.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'EPUB-Bücher in der Cloud-Bibliothek sammeln, mit Lesezeichen, Kapitelwahl und Lesefortschritt schmökern.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'pdf_tools',
        'enabled'  => ($appSettings['enable_pdf_tools'] ?? '1') === '1',
        'title'    => 'PDF & Scan-Studio',
        'icon'     => '📄',
        'url'      => 'pdf_tools.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'PDF-Dateien zusammenfügen, teilen, Seiten drehen oder Dokumente direkt per Kamera scannen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'calendar',
        'enabled'  => ($appSettings['enable_calendar'] ?? '1') === '1',
        'title'    => 'Kalender',
        'icon'     => '📅',
        'url'      => 'calendar.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Termine, Geburtstage, Feiertage und wiederkehrende Ereignisse inklusive ICS-Import & Export planen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'chat',
        'enabled'  => ($appSettings['enable_chat'] ?? '1') === '1',
        'title'    => 'Team-Chat',
        'icon'     => '💬',
        'url'      => 'messages.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Direktnachrichten und Dateianhänge mit anderen Cloud-Mitgliedern in Echtzeit austauschen.',
        'badge'    => $unreadChatCount > 0 ? ($unreadChatCount . ' neu') : '',
        'badgeCls' => 'bg-red-600 text-white',
    ],
    [
        'key'      => 'tasks',
        'enabled'  => ($appSettings['enable_tasks'] ?? '1') === '1',
        'title'    => 'Aufgaben',
        'icon'     => '📋',
        'url'      => 'tasks.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'To-Do-Listen mit Fälligkeiten, Prioritäten und Kategorien strukturieren und abhaken.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'projects',
        'enabled'  => ($appSettings['enable_projects'] ?? '1') === '1',
        'title'    => 'Projekte & Kanban',
        'icon'     => '🚀',
        'url'      => 'projects.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Gemeinsame Projekte mit Kanban-Board, Meilensteinen und Team-Zuständigkeiten organisieren.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'notes',
        'enabled'  => ($appSettings['enable_notes'] ?? '1') === '1',
        'title'    => 'Notizen & Pinnwand',
        'icon'     => '📌',
        'url'      => 'notes.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Farbige Haftnotizen, Ideen und Checklisten festhalten, anheften und durchsuchen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'budget',
        'enabled'  => ($appSettings['enable_budget'] ?? '1') === '1',
        'title'    => 'Haushaltsplaner',
        'icon'     => '💰',
        'url'      => 'budget.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Haushaltsbuch für Einnahmen, Ausgaben, Fixkosten/Abos, Monats-Budgets, Sparziele und gemeinsamen WG-/Familien-Ausgleich.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300',
    ],
    [
        'key'      => 'split',
        'enabled'  => ($appSettings['enable_split'] ?? '1') === '1',
        'title'    => 'Kostenaufteilung & Reisekasse',
        'icon'     => '🧾',
        'url'      => 'split.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Gruppen-Kostenabrechnung für Reisen, Ausflüge & Projekte: Kostenpunkte (z. B. Busfahrt, Essen) mit beliebigen Profiteuren (Cloud-Nutzer & Freitext-Gäste) erfassen und automatisch berechnen, wer wem wie viel schuldet.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300',
    ],
    [
        'key'      => 'club',
        'enabled'  => ($appSettings['enable_club'] ?? '1') === '1',
        'title'    => 'Vereinsverwaltung',
        'icon'     => '🏛️',
        'url'      => 'club.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Mitgliederdatei, Beitragstarife, Jahresbeitrags-Generator, Kassenwart-Übergabe, Arbeitsstunden, Inventar/Schlüssel und aufteilbare Vorstands-Rollen.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300',
    ],
    [
        'key'      => 'gallery',
        'enabled'  => ($appSettings['enable_gallery'] ?? '1') === '1',
        'title'    => 'Foto-Alben & Galerie',
        'icon'     => '🖼️',
        'url'      => 'gallery.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Fotowand aller Cloud-Bilder, virtuelle Foto-Alben ohne doppelten Speicherverbrauch, Vollbild-Diashow, EXIF-Inspektor und Gäste-Sammelalben per QR-Code.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-pink-100 dark:bg-pink-950/80 text-pink-700 dark:text-pink-300',
    ],
    [
        'key'      => 'forms',
        'enabled'  => ($appSettings['enable_forms'] ?? '1') === '1',
        'title'    => 'Online-Formulare',
        'icon'     => '📝',
        'url'      => 'forms.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Event-Anmeldungen, Helfer-/Mitbringlisten, Online-Mitgliedsanträge und Feedback-Bögen mit Gäste-QR-Code, CSV-Export und 1-Klick-Vereinsübernahme.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-teal-100 dark:bg-teal-950/80 text-teal-700 dark:text-teal-300',
    ],
    [
        'key'      => 'contacts',
        'enabled'  => ($appSettings['enable_contacts'] ?? '1') === '1',
        'title'    => 'Kontakte',
        'icon'     => '📇',
        'url'      => 'contacts.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Adressbuch mit Telefonnummern, E-Mail-Adressen, Geburtstagen und vCard-Import/Export pflegen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'polls',
        'enabled'  => ($appSettings['enable_polls'] ?? '1') === '1',
        'title'    => 'Umfragen & Termine',
        'icon'     => '📊',
        'url'      => 'polls.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Abstimmungen und Terminfindungen im Doodle-Stil für Cloud-Nutzer oder externe Gäste starten.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'menti',
        'enabled'  => ($appSettings['enable_menti'] ?? '1') === '1',
        'title'    => 'Live-Mentimeter',
        'icon'     => '🎤',
        'url'      => 'menti.php',
        'category' => 'org_team',
        'catLabel' => 'Organisation & Team',
        'desc'     => 'Interaktive Live-Abstimmungen, Wortwolken und Fragerunden mit QR-Code & Zugangs-PIN durchführen.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'passwords',
        'enabled'  => ($appSettings['enable_passwords'] ?? '1') === '1',
        'title'    => 'Schlüsselbund',
        'icon'     => '🔐',
        'url'      => 'passwords.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => 'Passwörter, Zugangsdaten und sichere Notizen Ende-zu-Ende verschlüsselt im Tresor aufbewahren.',
        'badge'    => '',
        'badgeCls' => '',
    ],
    [
        'key'      => 'assistant',
        'enabled'  => ($appSettings['enable_assistant'] ?? '1') === '1',
        'title'    => 'KI-Assistent',
        'icon'     => '🤖',
        'url'      => 'assistant.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => 'Lokaler, mehrsprachiger Cloud-Assistent für Dateisuche, Termine, Aufgaben und Fragen zur MiniCloud.',
        'badge'    => '11 Sprachen',
        'badgeCls' => 'bg-brand-100 dark:bg-brand-950/80 text-brand-700 dark:text-brand-300',
    ],
    [
        'key'      => 'music',
        'enabled'  => ($appSettings['enable_music'] ?? '1') === '1',
        'title'    => 'Musik- & Hörbuch-Studio',
        'icon'     => '🎵',
        'url'      => 'music.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => 'MP3/Audio-Player & Hörbuch-Bibliothek mit ID3-Tags, Cover-Erkennung, Frequenz-Visualizer, Equalizer, Hörbuch-Lesezeichen & teilbaren Playlists.',
        'badge'    => 'Audio',
        'badgeCls' => 'bg-rose-100 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300',
    ],
    [
        'key'      => 'video_studio',
        'enabled'  => ($appSettings['enable_video_studio'] ?? '1') === '1',
        'title'    => 'Video-Studio & Schnittplatz',
        'icon'     => '🎬',
        'url'      => 'video_studio.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Videos direkt im Browser sekundengenau kürzen (Trim), um 90°/180° drehen, auf 16:9 / 9:16 mit Kino-Unschärferand anpassen, Bauchbinden & Farbfilter einblenden, Hintergrundmusik darunterlegen oder Standbilder als Foto speichern.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-violet-100 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300',
    ],
    [
        'key'      => 'cinema',
        'enabled'  => ($appSettings['enable_cinema'] ?? '1') === '1',
        'title'    => 'Heimkino & Mediathek',
        'icon'     => '🍿',
        'url'      => 'cinema.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => 'Privates Streaming-Kino für alle Cloud-Videos mit Echtzeit-Ambilight, „Saallicht dimmen“, „Weiterschauen“-Automatik, Kino-Sound-Booster, Untertiteln (.srt/.vtt) & Gäste-Kino-Ticket.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-red-100 dark:bg-red-950/80 text-red-700 dark:text-red-300',
    ],
    [
        'key'      => 'surflog',
        'enabled'  => ($appSettings['enable_surflog'] ?? '1') === '1',
        'title'    => 'Surftagebuch & Quiver-Log',
        'icon'     => '🏄‍♂️',
        'url'      => 'surflog.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => 'Session-Logbuch für Windsurfen, Kiten, Wingfoilen, Wellenreiten & SUP mit Knoten↔Beaufort-Rechner, 16er-Windrose, Quiver-Materialverwaltung (Kosten/Session), GPX-Speed-Heatmap & Foto-/Video-Verknüpfung.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-cyan-100 dark:bg-cyan-950/80 text-cyan-700 dark:text-cyan-300',
    ],
    [
        'key'      => 'calculator',
        'enabled'  => ($appSettings['enable_calculator'] ?? '1') === '1',
        'title'    => 'Taschenrechner & Rechen-Studio',
        'icon'     => '🧮',
        'url'      => 'calculator.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Standard- & Wissenschaftsrechner mit beschreibbarem Rechenstreifen (Bon-Summe Σ & Cloud-Export), MwSt.-/Rabatt-Rechner, Einheiten-Umrechner & Zinsrechner.',
        'badge'    => 'Neu',
        'badgeCls' => 'bg-cyan-100 dark:bg-cyan-950/80 text-cyan-700 dark:text-cyan-300',
    ],
    [
        'key'      => 'toolbox',
        'enabled'  => ($appSettings['enable_toolbox'] ?? '1') === '1',
        'title'    => 'Alltags-Werkzeuge & Toolbox',
        'icon'     => '🧰',
        'url'      => 'toolbox.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => '6-in-1 Schweizer Taschenmesser: Gäste-WLAN-Karten & GiroCode-Überweisungs-QR-Codes, Glücksrad & Team-Auslosung, Stoppuhr & Weltzeituhr, Text-Diff-Checker, Farb-/WCAG-Studio & Passphrasen-Generator.',
        'badge'    => '6-in-1 Neu',
        'badgeCls' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300',
    ],
    [
        'key'      => 'games',
        'enabled'  => ($appSettings['enable_games'] ?? '1') === '1',
        'title'    => 'Spiele-Zentrale',
        'icon'     => '🎮',
        'url'      => 'games.php',
        'category' => 'extra',
        'catLabel' => 'Sicherheit, KI & Freizeit',
        'desc'     => '20 werbefreie Browserspiele im Vollbildmodus (Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Darts Meister-Arena, Teich-Hüpfer, Geheimagent Zero, Cloud-Wuseler, Schatten-Shinobi, Dach-Sprinter, Galaxis-Abwehr, Fass-Kletterer, Bomben-Flieger, Pillen-Schnapper, Helden von MiniCloud RPG, Sudoku, Minesweeper, Memory, Block-Puzzle, Snake, Brick Breaker & Live-Duelle) mit Cloud-Highscores.',
        'badge'    => '20 Spiele',
        'badgeCls' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300',
    ],
    [
        'key'      => 'trash',
        'enabled'  => true,
        'title'    => 'Papierkorb',
        'icon'     => '🗑️',
        'url'      => 'trash.php',
        'category' => 'files_office',
        'catLabel' => 'Dateien & Büro',
        'desc'     => 'Gelöschte Dateien einsehen, bei Bedarf mit einem Klick wiederherstellen oder endgültig bereinigen.',
        'badge'    => $trashItemCount > 0 ? ($trashItemCount . ' Eintr.') : '',
        'badgeCls' => 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200',
    ],
];

$activeModules = array_values(array_filter($allModules, static fn(array $m): bool => !empty($m['enabled'])));

$pageTitle = 'Alle Module';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Kopfbereich & Erklärungen -->
    <div class="mc-card rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs p-5 sm:p-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="space-y-1.5">
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200/60 dark:border-brand-800/60">
                    <span>🧩 Schnellstart-Zentrale</span>
                    <span>·</span>
                    <span><?= count($activeModules) ?> aktive Module</span>
                </div>
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white tracking-tight">
                    Alle Cloud-Module im Überblick
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 max-w-3xl">
                    Klicke auf eine Kachel, um das gewünschte Modul zu öffnen. Mit dem <strong class="text-slate-700 dark:text-slate-200">⭐ Stern-Symbol</strong> heftest du deine wichtigsten Module direkt oben in die Kopfzeile an. Per <strong class="text-slate-700 dark:text-slate-200">⠿ Ziehen &amp; Ablegen</strong> kannst du die Reihenfolge frei sortieren.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5 shrink-0">
                <?php if (hasAdminPermission()): ?>
                    <a
                        href="admin.php"
                        class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-bold text-white <?= isAdmin() ? 'bg-amber-600 hover:bg-amber-700' : 'bg-indigo-600 hover:bg-indigo-700' ?> shadow-2xs transition-colors"
                        title="<?= isAdmin() ? 'System-Administration öffnen' : 'Teil-Administration öffnen' ?>"
                    >
                        <span>🛡️</span>
                        <span><?= isAdmin() ? 'System-Administration' : 'Teil-Administration' ?></span>
                    </a>
                <?php endif; ?>
                <div class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200/80 dark:border-amber-800/60 text-xs font-medium text-amber-800 dark:text-amber-300">
                    <span>⭐ In Kopfzeile angeheftet:</span>
                    <strong id="pinnedModuleCounter" class="font-bold">0</strong>
                </div>
                <button
                    type="button"
                    onclick="resetHeaderNavAndOrder(event)"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/80 dark:hover:bg-slate-700 border border-slate-200/80 dark:border-slate-700 transition-colors cursor-pointer"
                    title="Angeheftete Favoriten und Kachel-Reihenfolge auf Standard zurücksetzen"
                >
                    <span>🔄</span>
                    <span>Standard wiederherstellen</span>
                </button>
            </div>
        </div>

        <!-- Suchfeld & Kategorie-Filter -->
        <div class="mt-5 pt-4 border-t border-slate-200/70 dark:border-slate-800 flex flex-col md:flex-row md:items-center justify-between gap-3">
            <!-- Kategorie-Tabs -->
            <div class="flex items-center gap-1.5 overflow-x-auto pb-1 md:pb-0" id="moduleCategoryTabs">
                <button type="button" data-mod-filter="all" onclick="filterModulesByCategory('all')" class="mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 text-white shadow-2xs transition-colors cursor-pointer shrink-0">
                    Alle (<?= count($activeModules) ?>)
                </button>
                <button type="button" data-mod-filter="pinned" onclick="filterModulesByCategory('pinned')" class="mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0">
                    ⭐ Angeheftet
                </button>
                <button type="button" data-mod-filter="files_office" onclick="filterModulesByCategory('files_office')" class="mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0">
                    📁 Dateien &amp; Büro
                </button>
                <button type="button" data-mod-filter="org_team" onclick="filterModulesByCategory('org_team')" class="mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0">
                    📅 Organisation &amp; Team
                </button>
                <button type="button" data-mod-filter="extra" onclick="filterModulesByCategory('extra')" class="mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0">
                    🤖 Sicherheit, KI &amp; Freizeit
                </button>
            </div>

            <!-- Schnellsuche -->
            <div class="relative w-full md:w-72 shrink-0">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none text-sm">🔍</span>
                <input
                    type="search"
                    id="moduleSearchInput"
                    oninput="applyModulePageFilters()"
                    placeholder="Modul suchen (z.B. Kalender, PDF, Spiele)..."
                    class="w-full pl-9 pr-3 py-2 rounded-xl text-xs sm:text-sm bg-slate-50 dark:bg-slate-800/90 border border-slate-200 dark:border-slate-700 text-slate-800 dark:text-slate-100 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>
        </div>
    </div>

    <!-- Modul-Kacheln Raster (#appLauncherGrid wird auch für Drag & Drop und Reihenfolge genutzt) -->
    <div id="appLauncherGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <?php foreach ($activeModules as $mod): ?>
            <?php
            $mKey = (string)$mod['key'];
            $mTitle = (string)$mod['title'];
            $mIcon = (string)$mod['icon'];
            $mUrl = (string)$mod['url'];
            $mCat = (string)$mod['category'];
            $mCatLabel = (string)$mod['catLabel'];
            $mDesc = (string)$mod['desc'];
            $mBadge = (string)$mod['badge'];
            $mBadgeCls = (string)$mod['badgeCls'];
            ?>
            <div
                data-launcher-item="<?= e($mKey) ?>"
                data-module-category="<?= e($mCat) ?>"
                data-module-search="<?= e(mb_strtolower($mTitle . ' ' . $mDesc . ' ' . $mCatLabel)) ?>"
                draggable="true"
                class="group relative flex flex-col justify-between rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 hover:border-brand-400 dark:hover:border-brand-500/80 shadow-xs hover:shadow-md p-4 transition-all cursor-grab active:cursor-grabbing"
            >
                <!-- Obere Zeile: Drag-Handle + Icon + Titel + Stern-Pin -->
                <div>
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <a href="<?= e($mUrl) ?>" draggable="false" class="flex items-center gap-3 min-w-0 flex-1 group/link">
                            <div class="w-11 h-11 rounded-xl bg-slate-100 dark:bg-slate-800 group-hover/link:bg-brand-50 dark:group-hover/link:bg-brand-950/60 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-center text-2xl shrink-0 transition-colors">
                                <?= $mIcon ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <h2 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white group-hover/link:text-brand-600 dark:group-hover/link:text-brand-400 transition-colors truncate">
                                        <?= e($mTitle) ?>
                                    </h2>
                                    <?php if ($mBadge !== ''): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= e($mBadgeCls) ?>">
                                            <?= e($mBadge) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[11px] font-medium text-slate-400 dark:text-slate-500 truncate">
                                    <?= e($mCatLabel) ?>
                                </p>
                            </div>
                        </a>

                        <!-- Ziehen & Stern-Button -->
                        <div class="flex items-center gap-1 shrink-0">
                            <span
                                class="text-slate-300 dark:text-slate-600 group-hover:text-slate-400 dark:group-hover:text-slate-400 text-sm px-1 py-1 select-none"
                                title="Kachel ziehen, um die Reihenfolge zu ändern"
                            >⠿</span>
                            <button
                                type="button"
                                data-nav-star="<?= e($mKey) ?>"
                                onclick="toggleHeaderNavFavorite('<?= e($mKey) ?>', event)"
                                class="w-8 h-8 rounded-xl bg-slate-50 dark:bg-slate-800 hover:bg-amber-50 dark:hover:bg-amber-950/50 border border-slate-200/80 dark:border-slate-700 flex items-center justify-center text-base hover:scale-110 transition-all cursor-pointer"
                                title="In Kopfzeile anheften / lösen"
                            >☆</button>
                        </div>
                    </div>

                    <!-- Kurzbeschreibung -->
                    <a href="<?= e($mUrl) ?>" draggable="false" class="block">
                        <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed line-clamp-2 mb-4">
                            <?= e($mDesc) ?>
                        </p>
                    </a>
                </div>

                <!-- Fußzeile der Kachel: Status-Badge (Angeheftet) + Öffnen-Link -->
                <div class="pt-3 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between gap-2">
                    <span
                        data-pin-status="<?= e($mKey) ?>"
                        onclick="toggleHeaderNavFavorite('<?= e($mKey) ?>', event)"
                        class="inline-flex items-center gap-1 text-[11px] font-medium text-slate-400 dark:text-slate-500 hover:text-slate-700 dark:hover:text-slate-200 cursor-pointer select-none"
                    >
                        Nicht angeheftet
                    </span>

                    <a
                        href="<?= e($mUrl) ?>"
                        draggable="false"
                        class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 transition-colors"
                    >
                        <span>Öffnen</span>
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Leerzustand bei Suche / Filter -->
    <div id="moduleEmptyState" class="hidden rounded-2xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 p-8 text-center space-y-2">
        <div class="text-3xl">🔍</div>
        <h3 class="text-sm font-bold text-slate-800 dark:text-slate-100">Keine passenden Module gefunden</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400">Bitte passe deinen Suchbegriff an oder wähle die Kategorie „Alle“.</p>
        <button
            type="button"
            onclick="document.getElementById('moduleSearchInput').value = ''; filterModulesByCategory('all');"
            class="mt-2 inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 hover:bg-brand-700 text-white transition-colors cursor-pointer"
        >
            Alle Module anzeigen
        </button>
    </div>
</div>

<script>
let currentModuleCatFilter = 'all';

function filterModulesByCategory(cat) {
    currentModuleCatFilter = cat;
    document.querySelectorAll('#moduleCategoryTabs .mod-filter-btn').forEach(btn => {
        const isCurrent = btn.getAttribute('data-mod-filter') === cat;
        if (isCurrent) {
            btn.className = 'mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-brand-600 text-white shadow-2xs transition-colors cursor-pointer shrink-0';
        } else {
            btn.className = 'mod-filter-btn px-3 py-1.5 rounded-xl text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200/70 dark:hover:bg-slate-700 transition-colors cursor-pointer shrink-0';
        }
    });
    applyModulePageFilters();
}

function applyModulePageFilters() {
    const searchInput = document.getElementById('moduleSearchInput');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const cards = document.querySelectorAll('#appLauncherGrid [data-launcher-item]');
    let visibleCount = 0;

    cards.forEach(card => {
        const key = card.getAttribute('data-launcher-item');
        const cat = card.getAttribute('data-module-category') || '';
        const text = card.getAttribute('data-module-search') || '';
        const starBtn = card.querySelector('[data-nav-star]');
        const isPinned = starBtn && starBtn.textContent.trim() === '⭐';

        let matchCat = true;
        if (currentModuleCatFilter === 'pinned') {
            matchCat = isPinned;
        } else if (currentModuleCatFilter !== 'all') {
            matchCat = (cat === currentModuleCatFilter);
        }

        const matchSearch = query === '' || text.includes(query);
        const show = matchCat && matchSearch;
        card.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });

    const emptyEl = document.getElementById('moduleEmptyState');
    if (emptyEl) {
        emptyEl.classList.toggle('hidden', visibleCount > 0);
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
