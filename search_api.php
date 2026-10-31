<?php
declare(strict_types=1);

/**
 * MiniCloud – Globale Spotlight-Schnellsuche API (search_api.php)
 * Durchsucht per Tastenkürzel (Strg+K / Cmd+K) in Echtzeit:
 * 1. Cloud-Module & Einstellungen
 * 2. Dateien & Ordner
 * 3. Kollaborative Whiteboards
 * 4. Notizen & Pinnwand
 * 5. Aufgaben & Projekte
 * 6. Kalender-Termine
 * 7. Adressbuch-Kontakte
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/office_functions.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

$userId      = (int)$_SESSION['user_id'];
$pdo         = getDB();
$appSettings = getAppSettings($pdo);
session_write_close();

$q     = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$qLow  = mb_strtolower($q, 'UTF-8');
$items = [];

// 1. Aktive Cloud-Module & Direkt-Funktionen
$catalog = [
    ['title' => 'Dateien & Ordner',               'sub' => 'Cloud-Dateimanager & Freigaben',              'icon' => '📁', 'cat' => 'Modul', 'url' => 'index.php',                'kw' => 'dateien ordner upload download cloud import dropbox nextcloud'],
    ['title' => 'Große Dateien & Speicher-Analyse','sub' => 'Speicherfresser & Duplikate in allen Ordnern', 'icon' => '📊', 'cat' => 'Funktion','url' => 'index.php?large=1&sort=size&dir=desc', 'kw' => 'große dateien speicherfresser speicherplatz voll aufräumen duplikate doppelte dateien größe'],
    ['title' => 'Mein Tag (Cockpit)',             'sub' => 'Tagesübersicht, Termine & Geburtstage',       'icon' => '🏠', 'cat' => 'Modul', 'url' => 'dashboard.php',            'kw' => 'cockpit dashboard mein tag übersicht', 'setting' => 'enable_dashboard'],
    ['title' => 'Whiteboard & Skizzenbuch',       'sub' => 'Kollaboratives Zeichnen, Formen & Haftnotizen','icon' => '🎨', 'cat' => 'Modul', 'url' => 'whiteboard.php',           'kw' => 'whiteboard zeichnen skizze excalidraw miro tafel malen', 'setting' => 'enable_whiteboard'],
    ['title' => 'Office Light & Bild-Studio',     'sub' => 'Word, Excel, PowerPoint, Visio & Foto-Editor','icon' => '📝', 'cat' => 'Modul', 'url' => 'office.php',               'kw' => 'office word excel powerpoint visio bild foto editor', 'setting' => 'enable_office'],
    ['title' => 'Übersetzungs-Studio',            'sub' => 'Neuronale Text- & Dokument-Übersetzung (18 Sprachen)', 'icon' => '🌐', 'cat' => 'Modul', 'url' => 'translate.php',            'kw' => 'übersetzen übersetzer translator sprache englisch deutsch französisch spanisch deepl dokument pdf übersetzung', 'setting' => 'enable_translator'],
    ['title' => 'PDF & Scan-Studio',              'sub' => 'PDF zusammenfügen, teilen & Kamera-Scanner',  'icon' => '📄', 'cat' => 'Modul', 'url' => 'pdf_tools.php',            'kw' => 'pdf scan zusammenfügen trennen komprimieren', 'setting' => 'enable_pdf_tools'],
    ['title' => 'Kalender & Termine',             'sub' => 'Monatsansicht, Serientermine & ICS',          'icon' => '📅', 'cat' => 'Modul', 'url' => 'calendar.php',             'kw' => 'kalender termine geburtstage ics caldav', 'setting' => 'enable_calendar'],
    ['title' => 'Team-Chat & Sprachräume',        'sub' => 'Direktnachrichten & Live-Audio',              'icon' => '💬', 'cat' => 'Modul', 'url' => 'messages.php',             'kw' => 'chat nachrichten telefonie sprachraum', 'setting' => 'enable_chat'],
    ['title' => 'Aufgaben & Kanban-Boards',       'sub' => 'To-Do-Listen, Fälligkeiten & Kanban',         'icon' => '📋', 'cat' => 'Modul', 'url' => 'tasks.php',                'kw' => 'aufgaben todo kanban erledigen', 'setting' => 'enable_tasks'],
    ['title' => 'Projekte & Meilensteine',        'sub' => 'Projektplanung, Gantt & Zeiterfassung',       'icon' => '🚀', 'cat' => 'Modul', 'url' => 'projects.php',             'kw' => 'projekte gantt meilensteine zeiterfassung', 'setting' => 'enable_projects'],
    ['title' => 'Notizen & Pinnwand',             'sub' => 'Farbige Haftnotizen & Checklisten',           'icon' => '📌', 'cat' => 'Modul', 'url' => 'notes.php',                'kw' => 'notizen pinnwand ideen', 'setting' => 'enable_notes'],
    ['title' => 'Kontakte & Adressbuch',          'sub' => 'Telefonnummern, E-Mail & vCard',              'icon' => '📇', 'cat' => 'Modul', 'url' => 'contacts.php',             'kw' => 'kontakte adressbuch telefonbuch vcard', 'setting' => 'enable_contacts'],
    ['title' => 'Schlüsselbund & Passwörter',     'sub' => 'Verschlüsselter Passwort-Tresor',             'icon' => '🔐', 'cat' => 'Modul', 'url' => 'passwords.php',            'kw' => 'passwörter schlüsselbund tresor zugangsdaten', 'setting' => 'enable_passwords'],
    ['title' => 'Passkeys & 2-Faktor-Schutz',     'sub' => 'Fingerabdruck / FaceID (WebAuthn) & TOTP',    'icon' => '🔑', 'cat' => 'Sicherheit', 'url' => 'settings.php#sec-passkeys', 'kw' => 'passkey fingerabdruck faceid touchid windows hello 2fa sicherheit'],
    ['title' => 'Einstellungen & Farbdesign',     'sub' => 'Themes, Passwort, Timeout & Profil',          'icon' => '⚙️', 'cat' => 'System', 'url' => 'settings.php',             'kw' => 'einstellungen design theme darkmode passwort ändern'],
    ['title' => 'Gummigeruch Kart-Cup',           'sub' => 'Rennspiel mit 6 Strecken & Ghost-Duellen',    'icon' => '🏎️', 'cat' => 'Spiel',  'url' => 'games.php?game=gummigeruch','kw' => 'gummigeruch kart autorennen rennspiel ghost duell', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Traffic-Racer (Highway)',  'sub' => 'Autobahn-Raser mit Gegenverkehr & Tuning',    'icon' => '🚘', 'cat' => 'Spiel',  'url' => 'games.php?game=trafficracer','kw' => 'traffic racer autobahn highway auto überholen gegenverkehr polizei tuning', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Darts (Meister-Arena)',    'sub' => '501 Double-Out, Checkout-Rechner & 180-Caller','icon' => '🎯', 'cat' => 'Spiel', 'url' => 'games.php?game=darts',       'kw' => 'darts cloud darts meister arena 501 301 double out bullseye triple 20 180 caller around the clock', 'setting' => 'enable_games'],
    ['title' => 'Pillen-Schnapper (Arcade)',      'sub' => 'Neon-Labyrinth mit 4 Geistern & Power-Pillen','icon' => '🟡', 'cat' => 'Spiel',  'url' => 'games.php?game=pacman',    'kw' => 'pillen schnapper geister rudi trixi zack bumm labyrinth arcade', 'setting' => 'enable_games'],
    ['title' => 'Cloud-Wuseler (Strategie)',      'sub' => 'Zerstörbares Pixel-Gelände & 8 Fähigkeiten', 'icon' => '🐹', 'cat' => 'Spiel',  'url' => 'games.php?game=lemmings',  'kw' => 'cloud wuseler digger builder blocker basher floater bomber strategie', 'setting' => 'enable_games'],
    ['title' => 'Geheimagent Zero (Bunker-Code)', 'sub' => 'Salto-Action, Roboter & 9 Passwort-Lochkarten', 'icon' => '🕵️‍♂️', 'cat' => 'Spiel', 'url' => 'games.php?game=impossible', 'kw' => 'geheimagent zero agent 4125 prof omega salto roboter lochkarten bunker', 'setting' => 'enable_games'],
    ['title' => 'Teich-Hüpfer (Arcade)',          'sub' => '5-spuriger Highway, Baumstämme & Schildkröten',    'icon' => '🐸', 'cat' => 'Spiel', 'url' => 'games.php?game=frogger',    'kw' => 'teich hüpfer frosch straße fluss baumstamm schildkröte krokodil seerose arcade', 'setting' => 'enable_games'],
    ['title' => 'Schatten-Shinobi (Isometrisch)', 'sub' => 'Isometrisches Ninja-Abenteuer auf Kuroshima', 'icon' => '🥷', 'cat' => 'Spiel',  'url' => 'games.php?game=lastninja', 'kw' => 'schatten shinobi ninja kenji kuroda kuroshima katana shuriken nunchaku adventure', 'setting' => 'enable_games'],
    ['title' => 'Dach-Sprinter (Parkour-Runner)', 'sub' => 'Ein-Tasten-Flucht über einstürzende Dächer',  'icon' => '🏃‍♂️', 'cat' => 'Spiel',  'url' => 'games.php?game=canabalt',  'kw' => 'dach sprinter runner parkour dächer wolkenkratzer tauben fenster', 'setting' => 'enable_games'],
    ['title' => 'Galaxis-Abwehr (Space-Arcade)',  'sub' => '55 Pixel-Aliens, 4 Schutzbunker & Bonus-UFO', 'icon' => '👾', 'cat' => 'Spiel',  'url' => 'games.php?game=invaders',  'kw' => 'galaxis abwehr weltraum aliens ufo laser schutzbunker arcade', 'setting' => 'enable_games'],
    ['title' => 'Fass-Kletterer (Plattform)',     'sub' => 'Stahlträger, Leitern, Fässer & Power-Hammer', 'icon' => '🦍', 'cat' => 'Spiel',  'url' => 'games.php?game=kong',      'kw' => 'fass kletterer gorilla fässer leitern hammer stahlträger arcade', 'setting' => 'enable_games'],
    ['title' => 'Bomben-Flieger (8-Bit)',         'sub' => 'Retro-Flieger: Hochhäuser per Bombe abtragen','icon' => '✈️', 'cat' => 'Spiel',  'url' => 'games.php?game=blitz',     'kw' => 'bomben flieger 8-bit flugzeug hochhäuser bombe retro', 'setting' => 'enable_games'],
    ['title' => 'Helden von MiniCloud (RPG)',     'sub' => 'Co-Op Fantasy-Rollenspiel & Boss-Raids',      'icon' => '⚔️', 'cat' => 'Spiel',  'url' => 'games.php?game=wow',       'kw' => 'helden von minicloud rpg rollenspiel fantasy raid', 'setting' => 'enable_games'],
    ['title' => 'Spiele-Zentrale & Pausenraum',   'sub' => '20 Browserspiele (inkl. Vollbild) & Highscores','icon' => '🎮', 'cat' => 'Modul', 'url' => 'games.php',                'kw' => 'spiele games vollbild darts 501 teich hüpfer frosch traffic racer geheimagent zero wuseler schatten shinobi dach sprinter galaxis abwehr fass kletterer bomben flieger pillen schnapper sudoku block puzzle fallblöcke snake minesweeper', 'setting' => 'enable_games'],
    ['title' => 'Mind-Map-Studio',                'sub' => 'Ideennetze & Strukturdiagramme',              'icon' => '🧠', 'cat' => 'Modul', 'url' => 'mindmap.php',              'kw' => 'mindmap ideen struktur', 'setting' => 'enable_mindmap'],
    ['title' => 'Haushaltsplaner & Finanzen',     'sub' => 'Einnahmen, Ausgaben, Abos & Sparziele',       'icon' => '💰', 'cat' => 'Modul', 'url' => 'budget.php',               'kw' => 'haushalt finanzen budget geld ausgaben', 'setting' => 'enable_budget'],
    ['title' => 'Kostenaufteilung & Reisekasse',  'sub' => 'Gruppen-Abrechnung: Wer schuldet wem wie viel?', 'icon' => '🧾', 'cat' => 'Modul', 'url' => 'split.php',             'kw' => 'kostenabrechnung reisekasse splitwise tricount reise projekt auslagen profiteure schulden aufteilen wer schuldet wem', 'setting' => 'enable_split'],
    ['title' => 'Vereinsverwaltung',              'sub' => 'Mitglieder, Beiträge & Vorstands-Portal',     'icon' => '🏛️', 'cat' => 'Modul', 'url' => 'club.php',                 'kw' => 'verein mitglieder beiträge kassenwart', 'setting' => 'enable_club'],
    ['title' => 'Foto-Alben & Galerie',           'sub' => 'Fotowand, Diashow & Gäste-Alben',             'icon' => '🖼️', 'cat' => 'Modul', 'url' => 'gallery.php',              'kw' => 'galerie fotos bilder alben diashow', 'setting' => 'enable_gallery'],
    ['title' => 'Online-Formulare',               'sub' => 'Anmeldebögen, Helferlisten & QR-Code',        'icon' => '📝', 'cat' => 'Modul', 'url' => 'forms.php',                'kw' => 'formulare anmeldung event umfrage', 'setting' => 'enable_forms'],
    ['title' => 'E-Book-Reader (.epub)',          'sub' => 'Digitale Bücherbibliothek',                   'icon' => '📚', 'cat' => 'Modul', 'url' => 'ebooks.php',               'kw' => 'ebooks bücher lesen epub', 'setting' => 'enable_ebooks'],
    ['title' => 'Musik- & Hörbuch-Studio',        'sub' => 'Playlists, Equalizer & Audio-Player',         'icon' => '🎵', 'cat' => 'Modul', 'url' => 'music.php',                'kw' => 'musik player mp3 hörbuch', 'setting' => 'enable_music'],
    ['title' => 'Heimkino & Film-Mediathek',     'sub' => 'Filme schauen, Ambilight, Weiterschauen & Ticket', 'icon' => '🍿', 'cat' => 'Modul', 'url' => 'cinema.php',               'kw' => 'kino heimkino cinema film filme mediathek anschauen streamen streaming netflix ambilight weiterschauen watchlist untertitel srt vtt serie', 'setting' => 'enable_cinema'],
    ['title' => 'Video-Studio & Schnittplatz',    'sub' => 'Videos kürzen, drehen, Bauchbinden & Musik',  'icon' => '🎬', 'cat' => 'Modul', 'url' => 'video_studio.php',         'kw' => 'video studio videoschnitt schneiden trimmen drehen mp4 webm bauchbinde untertitel filter musik screencast standbild', 'setting' => 'enable_video_studio'],
    ['title' => 'Surftagebuch & Quiver-Log',      'sub' => 'Windsurfen, Kiten, Wingfoilen, GPX & Quiver', 'icon' => '🏄‍♂️', 'cat' => 'Modul', 'url' => 'surflog.php',              'kw' => 'surftagebuch surflog windsurfen kitesurfen kiten wingfoil wingfoilen wellenreiten sup spot wind knoten beaufort bft quiver segel board foil gpx speed', 'setting' => 'enable_surflog'],
    ['title' => 'Taschenrechner & Einheiten',     'sub' => 'Wissenschaftlicher Rechner & Finanzrechner',  'icon' => '🧮', 'cat' => 'Modul', 'url' => 'calculator.php',           'kw' => 'rechner taschenrechner prozent zinsen', 'setting' => 'enable_calculator'],
    ['title' => 'Alltags-Werkzeuge & Toolbox',    'sub' => 'WLAN-QR, GiroCode, Glücksrad, Stoppuhr & Diff','icon' => '🧰', 'cat' => 'Modul', 'url' => 'toolbox.php',              'kw' => 'toolbox werkzeuge qr code wlan girocode sepa glückrad auslosung würfel stoppuhr timer weltzeit diff textvergleich farben kontrast passwort generator uuid', 'setting' => 'enable_toolbox'],
    ['title' => 'Umfragen & Terminfinder',        'sub' => 'Abstimmungen im Doodle-Stil',                 'icon' => '📊', 'cat' => 'Modul', 'url' => 'polls.php',                'kw' => 'umfragen abstimmung doodle terminfinder', 'setting' => 'enable_polls'],
    ['title' => 'Live-Mentimeter',                'sub' => 'Interaktive Wortwolken & Live-Votings',       'icon' => '🎤', 'cat' => 'Modul', 'url' => 'menti.php',                'kw' => 'mentimeter präsentation wortwolke', 'setting' => 'enable_menti'],
    ['title' => 'Papierkorb',                     'sub' => 'Gelöschte Dateien wiederherstellen',          'icon' => '🗑️', 'cat' => 'System', 'url' => 'trash.php',                'kw' => 'papierkorb gelöscht wiederherstellen'],
];

$matchedModules = [];
$searchTerms    = splitSearchTerms($q, 8);
$searchTermsLow = array_map(static fn(string $t): string => mb_strtolower($t, 'UTF-8'), $searchTerms);

foreach ($catalog as $mod) {
    if (isset($mod['setting']) && ($appSettings[$mod['setting']] ?? '1') !== '1') {
        continue;
    }
    if ($qLow === '') {
        if (count($matchedModules) < 5) {
            $matchedModules[] = [
                'icon'  => $mod['icon'],
                'title' => $mod['title'],
                'sub'   => $mod['sub'],
                'cat'   => $mod['cat'],
                'url'   => $mod['url'],
            ];
        }
    } else {
        $haystack = mb_strtolower($mod['title'] . ' ' . $mod['sub'] . ' ' . $mod['kw'], 'UTF-8');
        $allModMatched = !empty($searchTermsLow);
        foreach ($searchTermsLow as $termLow) {
            if (mb_strpos($haystack, $termLow) === false) {
                $allModMatched = false;
                break;
            }
        }
        if ($allModMatched && count($matchedModules) < 4) {
            $matchedModules[] = [
                'icon'  => $mod['icon'],
                'title' => $mod['title'],
                'sub'   => $mod['sub'],
                'cat'   => $mod['cat'],
                'url'   => $mod['url'],
            ];
        }
    }
}

// Wenn kein Suchbegriff eingegeben wurde: Zuletzt hochgeladene Dateien + Schnellzugriff-Module anzeigen
if ($qLow === '' || empty($searchTerms)) {
    try {
        $stmtRec = $pdo->prepare(
            "SELECT `id`, `original_name`, `file_size`, `folder_path`, `group_folder_id`, `is_encrypted`
             FROM `files`
             WHERE `user_id` = :uid AND `deleted_at` IS NULL
             ORDER BY `upload_date` DESC
             LIMIT 6"
        );
        $stmtRec->execute([':uid' => $userId]);
        foreach ($stmtRec->fetchAll() as $f) {
            $fId  = (int)$f['id'];
            $name = (string)$f['original_name'];
            $fp   = trim((string)($f['folder_path'] ?? ''));
            $gfId = !empty($f['group_folder_id']) ? (int)$f['group_folder_id'] : 0;
            $isEnc = ((int)($f['is_encrypted'] ?? 0) === 1);

            $folderParams = [];
            if ($gfId > 0) {
                $folderParams['group_folder'] = $gfId;
            }
            if ($fp !== '') {
                $folderParams['folder'] = $fp;
            }
            $folderParams['highlight_id'] = $fId;
            $folderUrl = 'index.php?' . http_build_query($folderParams);

            $openParams = $folderParams;
            unset($openParams['highlight_id']);
            $openParams['preview_id'] = $fId;
            $openUrl = 'index.php?' . http_build_query($openParams);

            $officeType = !$isEnc ? getOfficeEditorType($name) : null;
            $officeUrl  = $officeType !== null ? ('office.php?id=' . $fId) : '';

            $locLabel = $fp !== '' ? (' · 📁 ' . $fp) : ' · 🏠 Hauptverzeichnis';
            $items[] = [
                'icon'         => $isEnc ? '🔒' : '📄',
                'title'        => $name,
                'sub'          => 'Zuletzt hochgeladen · ' . formatBytes((int)$f['file_size']) . $locLabel,
                'cat'          => 'Datei',
                'file_id'      => $fId,
                'url'          => $openUrl,
                'open_url'     => $openUrl,
                'folder_url'   => $folderUrl,
                'office_url'   => $officeUrl,
                'download_url' => !$isEnc ? ('download.php?id=' . $fId) : '',
            ];
        }
    } catch (Throwable) {
    }

    foreach ($matchedModules as $m) {
        $items[] = $m;
    }

    echo json_encode(['ok' => true, 'results' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Ordner durchsuchen (sowohl aus user_folders als auch aus files.folder_path – alle Suchbegriffe per UND verknüpft)
try {
    $foundFolders = [];
    $fldConds     = [];
    $fldParams    = [':uid' => $userId];
    foreach ($searchTerms as $idx => $term) {
        $fldConds[]                 = "`folder_path` LIKE :fq_{$idx}";
        $fldParams[":fq_{$idx}"]    = '%' . $term . '%';
    }
    $fldAndSql = implode(' AND ', $fldConds);

    $stmtFld = $pdo->prepare(
        "SELECT `folder_path`, `group_folder_id`
         FROM `user_folders`
         WHERE `user_id` = :uid AND {$fldAndSql}
         ORDER BY `folder_path` ASC
         LIMIT 8"
    );
    $stmtFld->execute($fldParams);
    foreach ($stmtFld->fetchAll() as $fld) {
        $fp   = trim((string)$fld['folder_path']);
        $gfId = !empty($fld['group_folder_id']) ? (int)$fld['group_folder_id'] : 0;
        if ($fp !== '') {
            $foundFolders[$gfId . ':' . $fp] = ['fp' => $fp, 'gf_id' => $gfId];
        }
    }

    $stmtFldFiles = $pdo->prepare(
        "SELECT DISTINCT `folder_path`, `group_folder_id`
         FROM `files`
         WHERE `user_id` = :uid AND `deleted_at` IS NULL AND `folder_path` != '' AND {$fldAndSql}
         ORDER BY `folder_path` ASC
         LIMIT 8"
    );
    $stmtFldFiles->execute($fldParams);
    foreach ($stmtFldFiles->fetchAll() as $fld) {
        $fp   = trim((string)$fld['folder_path']);
        $gfId = !empty($fld['group_folder_id']) ? (int)$fld['group_folder_id'] : 0;
        if ($fp !== '') {
            $foundFolders[$gfId . ':' . $fp] = ['fp' => $fp, 'gf_id' => $gfId];
        }
    }

    foreach (array_slice(array_values($foundFolders), 0, 6) as $fldInfo) {
        $fp   = $fldInfo['fp'];
        $gfId = $fldInfo['gf_id'];
        $qUrl = [];
        if ($gfId > 0) {
            $qUrl['group_folder'] = $gfId;
        }
        $qUrl['folder'] = $fp;
        $items[] = [
            'icon'  => '📁',
            'title' => $fp,
            'sub'   => 'Ordner im Dateimanager öffnen',
            'cat'   => 'Ordner',
            'url'   => 'index.php?' . http_build_query($qUrl),
        ];
    }
} catch (Throwable) {
}

// 3. Dateien durchsuchen (eigene Dateien + zugängliche Gruppenordner + optionaler Volltext-Suchindex, alle Begriffe per UND verknüpft)
try {
    $accGfs = getAccessibleGroupFolders($pdo, $userId, isAdmin());
    $gfIds  = array_map(static fn(array $g): int => (int)$g['id'], $accGfs);
    $gfCond = '';
    if (!empty($gfIds)) {
        $gfCond = ' OR f.`group_folder_id` IN (' . implode(',', $gfIds) . ')';
    }

    $seenFileIds     = [];
    $fulltextEnabled = (($appSettings['enable_fulltext_search'] ?? '1') === '1');

    // 3a. Direkte Treffer in Dateiname / Ordnerpfad (jeder eingegebene Begriff muss vorkommen)
    $nameConds  = [];
    $nameParams = [':uid1' => $userId];
    foreach ($searchTerms as $idx => $term) {
        $likeVal                     = '%' . $term . '%';
        $nameConds[]                 = "(f.`original_name` LIKE :fn_{$idx} OR f.`folder_path` LIKE :fp_{$idx})";
        $nameParams[":fn_{$idx}"]    = $likeVal;
        $nameParams[":fp_{$idx}"]    = $likeVal;
    }
    $nameAndSql = implode(' AND ', $nameConds);

    $stmtFil = $pdo->prepare(
        "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`, f.`is_encrypted`,
                fsi.`content_text`
         FROM `files` f
         LEFT JOIN `file_search_index` fsi ON fsi.`file_id` = f.`id`
         WHERE f.`deleted_at` IS NULL
           AND (f.`user_id` = :uid1{$gfCond})
           AND ({$nameAndSql})
         ORDER BY f.`upload_date` DESC
         LIMIT 10"
    );
    $stmtFil->execute($nameParams);
    $matchedFileRows = $stmtFil->fetchAll();
    foreach ($matchedFileRows as $r) {
        $seenFileIds[(int)$r['id']] = true;
    }

    // 3b. Volltextsuche im Dokumenten-Inhalt (PDF, Word, Excel, PowerPoint, Visio, Text/Markdown, EPUB)
    //     Strikte UND-Verknüpfung: Jeder eingegebene Suchbegriff muss zwingend in Dateiname, Ordnerpfad oder Dokumenttext vorkommen!
    if ($fulltextEnabled && mb_strlen($q, 'UTF-8') >= 2 && count($matchedFileRows) < 14) {
        $ftBoolQuery = buildFulltextBooleanQuery($q);
        $remLimit    = max(4, 14 - count($matchedFileRows));

        $ftTermConds  = [];
        $ftParams     = [':uid1' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $likeVal                   = '%' . $term . '%';
            $ftTermConds[]             = "(f.`original_name` LIKE :ftn_{$idx} OR f.`folder_path` LIKE :ftfp_{$idx} OR fsi.`content_text` LIKE :ftc_{$idx})";
            $ftParams[":ftn_{$idx}"]   = $likeVal;
            $ftParams[":ftfp_{$idx}"]  = $likeVal;
            $ftParams[":ftc_{$idx}"]   = $likeVal;
        }
        $ftAndTermsSql = implode(' AND ', $ftTermConds);

        if ($ftBoolQuery !== '') {
            $ftParams[':ftq1'] = $ftBoolQuery;
            $stmtFt = $pdo->prepare(
                "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`, f.`is_encrypted`,
                        fsi.`content_text`,
                        MATCH(fsi.`original_name`, fsi.`content_text`) AGAINST(:ftq1 IN BOOLEAN MODE) AS `ft_score`
                 FROM `file_search_index` fsi
                 INNER JOIN `files` f ON f.`id` = fsi.`file_id`
                 WHERE f.`deleted_at` IS NULL
                   AND f.`is_encrypted` = 0
                   AND (f.`user_id` = :uid1{$gfCond})
                   AND ({$ftAndTermsSql})
                 ORDER BY `ft_score` DESC, f.`upload_date` DESC
                 LIMIT {$remLimit}"
            );
        } else {
            $stmtFt = $pdo->prepare(
                "SELECT f.`id`, f.`original_name`, f.`file_size`, f.`folder_path`, f.`group_folder_id`, f.`is_encrypted`,
                        fsi.`content_text`
                 FROM `file_search_index` fsi
                 INNER JOIN `files` f ON f.`id` = fsi.`file_id`
                 WHERE f.`deleted_at` IS NULL
                   AND f.`is_encrypted` = 0
                   AND (f.`user_id` = :uid1{$gfCond})
                   AND ({$ftAndTermsSql})
                 ORDER BY f.`upload_date` DESC
                 LIMIT {$remLimit}"
            );
        }

        $stmtFt->execute($ftParams);
        foreach ($stmtFt->fetchAll() as $ftRow) {
            $ftId = (int)$ftRow['id'];
            if (!isset($seenFileIds[$ftId])) {
                $seenFileIds[$ftId] = true;
                $matchedFileRows[]  = $ftRow;
            }
        }
    }

    foreach ($matchedFileRows as $f) {
        $fId   = (int)$f['id'];
        $name  = (string)$f['original_name'];
        $fp    = trim((string)($f['folder_path'] ?? ''));
        $gfId  = !empty($f['group_folder_id']) ? (int)$f['group_folder_id'] : 0;
        $isEnc = ((int)($f['is_encrypted'] ?? 0) === 1);
        $cText = (!$isEnc && $fulltextEnabled) ? (string)($f['content_text'] ?? '') : '';

        $folderParams = [];
        if ($gfId > 0) {
            $folderParams['group_folder'] = $gfId;
        }
        if ($fp !== '') {
            $folderParams['folder'] = $fp;
        }
        $folderParams['highlight_id'] = $fId;
        $folderUrl = 'index.php?' . http_build_query($folderParams);

        $openParams = $folderParams;
        unset($openParams['highlight_id']);
        $openParams['q']          = $name;
        $openParams['preview_id'] = $fId;
        $openUrl = 'index.php?' . http_build_query($openParams);

        $officeType = !$isEnc ? getOfficeEditorType($name) : null;
        $officeUrl  = $officeType !== null ? ('office.php?id=' . $fId) : '';

        $snippet = '';
        if ($cText !== '') {
            $snippet = buildFulltextSnippet($cText, $q, 115);
        }

        $locLabel = $fp !== '' ? ('📁 ' . $fp) : '🏠 Hauptverzeichnis';
        $items[] = [
            'icon'         => $isEnc ? '🔒' : ($snippet !== '' ? '📑' : '📄'),
            'title'        => $name,
            'sub'          => formatBytes((int)$f['file_size']) . ' · ' . $locLabel,
            'snippet'      => $snippet,
            'cat'          => 'Datei',
            'file_id'      => $fId,
            'url'          => $openUrl,
            'open_url'     => $openUrl,
            'folder_url'   => $folderUrl,
            'office_url'   => $officeUrl,
            'download_url' => !$isEnc ? ('download.php?id=' . $fId) : '',
        ];
    }
} catch (Throwable) {
}

// 4. Whiteboards durchsuchen (nur eigene oder explizit für mich freigegebene – alle Begriffe per UND)
if (($appSettings['enable_whiteboard'] ?? '1') === '1') {
    try {
        $wbConds  = [];
        $wbParams = [':uid' => $userId, ':uid2' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $wbConds[]              = "w.`title` LIKE :wb_{$idx}";
            $wbParams[":wb_{$idx}"] = '%' . $term . '%';
        }
        $wbAndSql = implode(' AND ', $wbConds);

        $stmtWb = $pdo->prepare(
            "SELECT w.`id`, w.`title`, w.`is_shared`
             FROM `whiteboards` w
             WHERE (
                 w.`user_id` = :uid
                 OR EXISTS (
                     SELECT 1 FROM `whiteboard_shares` ws
                     WHERE ws.`board_id` = w.`id` AND ws.`user_id` = :uid2
                 )
             ) AND ({$wbAndSql})
             ORDER BY w.`updated_at` DESC
             LIMIT 5"
        );
        $stmtWb->execute($wbParams);
        foreach ($stmtWb->fetchAll() as $wb) {
            $items[] = [
                'icon'  => '🎨',
                'title' => (string)$wb['title'],
                'sub'   => ((int)$wb['is_shared'] === 1 ? 'Geteiltes Whiteboard' : 'Privates Whiteboard'),
                'cat'   => 'Whiteboard',
                'url'   => 'whiteboard.php?id=' . (int)$wb['id'],
            ];
        }
    } catch (Throwable) {
    }
}

// 5. Notizen durchsuchen (alle Begriffe per UND)
if (($appSettings['enable_notes'] ?? '1') === '1') {
    try {
        $ntConds  = [];
        $ntParams = [':u1' => $userId, ':u2' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $likeVal                 = '%' . $term . '%';
            $ntConds[]               = "(`title` LIKE :ntt_{$idx} OR `content` LIKE :ntc_{$idx})";
            $ntParams[":ntt_{$idx}"] = $likeVal;
            $ntParams[":ntc_{$idx}"] = $likeVal;
        }
        $ntAndSql = implode(' AND ', $ntConds);

        $stmtNt = $pdo->prepare(
            "SELECT `id`, `title`, `content`
             FROM `notes`
             WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
               AND ({$ntAndSql})
             ORDER BY `updated_at` DESC
             LIMIT 5"
        );
        $stmtNt->execute($ntParams);
        foreach ($stmtNt->fetchAll() as $nt) {
            $snippet = mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$nt['content'])) ?: '', 0, 65);
            $items[] = [
                'icon'  => '📌',
                'title' => trim((string)$nt['title']) !== '' ? (string)$nt['title'] : 'Notiz #' . (int)$nt['id'],
                'sub'   => $snippet !== '' ? $snippet : 'Notiz öffnen',
                'cat'   => 'Notiz',
                'url'   => 'notes.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 6. Aufgaben durchsuchen (alle Begriffe per UND)
if (($appSettings['enable_tasks'] ?? '1') === '1') {
    try {
        $tkConds  = [];
        $tkParams = [':u1' => $userId, ':u2' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $likeVal                 = '%' . $term . '%';
            $tkConds[]               = "(`title` LIKE :tkt_{$idx} OR `description` LIKE :tkd_{$idx})";
            $tkParams[":tkt_{$idx}"] = $likeVal;
            $tkParams[":tkd_{$idx}"] = $likeVal;
        }
        $tkAndSql = implode(' AND ', $tkConds);

        $stmtTk = $pdo->prepare(
            "SELECT `id`, `title`, `status`, `due_date`
             FROM `tasks`
             WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
               AND ({$tkAndSql})
             ORDER BY `created_at` DESC
             LIMIT 5"
        );
        $stmtTk->execute($tkParams);
        foreach ($stmtTk->fetchAll() as $tk) {
            $due = !empty($tk['due_date']) ? ' · Fällig: ' . date('d.m.Y', strtotime((string)$tk['due_date']) ?: time()) : '';
            $items[] = [
                'icon'  => $tk['status'] === 'done' ? '✅' : '📋',
                'title' => (string)$tk['title'],
                'sub'   => ($tk['status'] === 'done' ? 'Erledigt' : 'Offene Aufgabe') . $due,
                'cat'   => 'Aufgabe',
                'url'   => 'tasks.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 7. Kalender-Termine durchsuchen (alle Begriffe per UND)
if (($appSettings['enable_calendar'] ?? '1') === '1') {
    try {
        $evConds  = [];
        $evParams = [':u1' => $userId, ':u2' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $likeVal                 = '%' . $term . '%';
            $evConds[]               = "(`title` LIKE :evt_{$idx} OR `location` LIKE :evl_{$idx} OR `description` LIKE :evd_{$idx})";
            $evParams[":evt_{$idx}"] = $likeVal;
            $evParams[":evl_{$idx}"] = $likeVal;
            $evParams[":evd_{$idx}"] = $likeVal;
        }
        $evAndSql = implode(' AND ', $evConds);

        $stmtEv = $pdo->prepare(
            "SELECT `id`, `title`, `start_at`, `location`
             FROM `calendar_events`
             WHERE (`user_id` = :u1 OR (`shared_user_id` = :u2 AND `is_private` = 0))
               AND ({$evAndSql})
             ORDER BY `start_at` DESC
             LIMIT 5"
        );
        $stmtEv->execute($evParams);
        foreach ($stmtEv->fetchAll() as $ev) {
            $dtStr = date('d.m.Y H:i', strtotime((string)$ev['start_at']) ?: time());
            $loc   = trim((string)($ev['location'] ?? '')) !== '' ? ' · 📍 ' . $ev['location'] : '';
            $items[] = [
                'icon'  => '📅',
                'title' => (string)$ev['title'],
                'sub'   => $dtStr . $loc,
                'cat'   => 'Termin',
                'url'   => 'calendar.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 8. Kontakte durchsuchen (alle Begriffe per UND)
if (($appSettings['enable_contacts'] ?? '1') === '1') {
    try {
        $ctConds  = [];
        $ctParams = [':u1' => $userId, ':u2' => $userId];
        foreach ($searchTerms as $idx => $term) {
            $likeVal                 = '%' . $term . '%';
            $ctConds[]               = "(`first_name` LIKE :ctf_{$idx} OR `last_name` LIKE :ctl_{$idx} OR `organization` LIKE :cto_{$idx} OR `email` LIKE :cte_{$idx} OR `phone` LIKE :ctp_{$idx})";
            $ctParams[":ctf_{$idx}"] = $likeVal;
            $ctParams[":ctl_{$idx}"] = $likeVal;
            $ctParams[":cto_{$idx}"] = $likeVal;
            $ctParams[":cte_{$idx}"] = $likeVal;
            $ctParams[":ctp_{$idx}"] = $likeVal;
        }
        $ctAndSql = implode(' AND ', $ctConds);

        $stmtCt = $pdo->prepare(
            "SELECT `id`, `first_name`, `last_name`, `organization`, `email`, `phone`
             FROM `contacts`
             WHERE (`user_id` = :u1 OR `shared_user_id` = :u2)
               AND ({$ctAndSql})
             LIMIT 5"
        );
        $stmtCt->execute($ctParams);
        foreach ($stmtCt->fetchAll() as $ct) {
            $name = trim($ct['first_name'] . ' ' . $ct['last_name']);
            if ($name === '') {
                $name = (string)$ct['organization'];
            }
            $meta = array_filter([(string)($ct['email'] ?? ''), (string)($ct['phone'] ?? '')]);
            $items[] = [
                'icon'  => '📇',
                'title' => $name,
                'sub'   => !empty($meta) ? implode(' · ', $meta) : 'Kontakt im Adressbuch',
                'cat'   => 'Kontakt',
                'url'   => 'contacts.php',
            ];
        }
    } catch (Throwable) {
    }
}

// 9. Passende Cloud-Module anhängen
foreach ($matchedModules as $m) {
    $items[] = $m;
}

echo json_encode([
    'ok'      => true,
    'results' => array_slice($items, 0, 24),
], JSON_UNESCAPED_UNICODE);
