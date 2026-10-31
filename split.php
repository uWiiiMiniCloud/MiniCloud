<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo         = getDB();
$isLoggedIn  = isLoggedIn();
$userId      = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$username    = $isLoggedIn ? (string)($_SESSION['username'] ?? 'Benutzer') : 'Gast';
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_split'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Modul „Kostenaufteilung & Reisekasse“ ist derzeit deaktiviert.');
    header('Location: ' . ($isLoggedIn ? 'index.php' : 'login.php'));
    exit;
}

/**
 * Stellt sicher, dass die Tabellen und Zusatzspalten für die Gruppen-Kostenabrechnung
 * (inkl. Gäste-Freigabelink, Fremdwährungs-Umrechnung & Beleg-Anhänge) existieren.
 */
function ensureSplitTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mc_split_groups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id INT UNSIGNED NOT NULL,
            title VARCHAR(180) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            icon VARCHAR(16) NOT NULL DEFAULT '✈️',
            currency VARCHAR(12) NOT NULL DEFAULT '€',
            share_token VARCHAR(64) DEFAULT NULL,
            guest_can_add TINYINT(1) NOT NULL DEFAULT 1,
            is_archived TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_owner (owner_id),
            INDEX idx_token (share_token),
            INDEX idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mc_split_participants (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED DEFAULT NULL,
            display_name VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_group (group_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mc_split_expenses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            paid_by_participant_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            amount_cents BIGINT NOT NULL DEFAULT 0,
            original_amount_cents BIGINT NOT NULL DEFAULT 0,
            original_currency VARCHAR(12) NOT NULL DEFAULT '',
            exchange_rate DECIMAL(14,6) NOT NULL DEFAULT 1.000000,
            category VARCHAR(40) NOT NULL DEFAULT 'food',
            split_mode VARCHAR(20) NOT NULL DEFAULT 'equal',
            is_settlement TINYINT(1) NOT NULL DEFAULT 0,
            expense_date DATE NOT NULL,
            note VARCHAR(500) NOT NULL DEFAULT '',
            receipt_file_id INT UNSIGNED DEFAULT NULL,
            receipt_stored_name VARCHAR(180) NOT NULL DEFAULT '',
            receipt_original_name VARCHAR(255) NOT NULL DEFAULT '',
            receipt_mime VARCHAR(100) NOT NULL DEFAULT '',
            created_by_user_id INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_group_date (group_id, expense_date, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS mc_split_shares (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            expense_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            participant_id INT UNSIGNED NOT NULL,
            weight DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            share_cents BIGINT NOT NULL DEFAULT 0,
            INDEX idx_expense (expense_id),
            INDEX idx_group (group_id),
            INDEX idx_participant (participant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Bestehende Installationen nahtlos um neue Spalten erweitern (pro Session gecacht)
    if (empty($_SESSION['_mc_split_schema_v2_ok'])) {
        try {
            $gCols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM mc_split_groups')->fetchAll() as $r) {
                $gCols[strtolower((string)$r['Field'])] = true;
            }
            if (!isset($gCols['share_token'])) {
                $pdo->exec("ALTER TABLE mc_split_groups ADD COLUMN share_token VARCHAR(64) DEFAULT NULL AFTER currency, ADD INDEX idx_token (share_token)");
            }
            if (!isset($gCols['guest_can_add'])) {
                $pdo->exec("ALTER TABLE mc_split_groups ADD COLUMN guest_can_add TINYINT(1) NOT NULL DEFAULT 1 AFTER share_token");
            }

            $eCols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM mc_split_expenses')->fetchAll() as $r) {
                $eCols[strtolower((string)$r['Field'])] = true;
            }
            if (!isset($eCols['original_amount_cents'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN original_amount_cents BIGINT NOT NULL DEFAULT 0 AFTER amount_cents");
            }
            if (!isset($eCols['original_currency'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN original_currency VARCHAR(12) NOT NULL DEFAULT '' AFTER original_amount_cents");
            }
            if (!isset($eCols['exchange_rate'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN exchange_rate DECIMAL(14,6) NOT NULL DEFAULT 1.000000 AFTER original_currency");
            }
            if (!isset($eCols['receipt_file_id'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN receipt_file_id INT UNSIGNED DEFAULT NULL AFTER note");
            }
            if (!isset($eCols['receipt_stored_name'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN receipt_stored_name VARCHAR(180) NOT NULL DEFAULT '' AFTER receipt_file_id");
            }
            if (!isset($eCols['receipt_original_name'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN receipt_original_name VARCHAR(255) NOT NULL DEFAULT '' AFTER receipt_stored_name");
            }
            if (!isset($eCols['receipt_mime'])) {
                $pdo->exec("ALTER TABLE mc_split_expenses ADD COLUMN receipt_mime VARCHAR(100) NOT NULL DEFAULT '' AFTER receipt_original_name");
            }
            $_SESSION['_mc_split_schema_v2_ok'] = 1;
        } catch (Throwable) {
        }
    }
}

ensureSplitTables($pdo);

// ============================================================================
// GÄSTE-TOKEN ODER LOGIN PRÜFEN
// ============================================================================
$guestToken = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenGroup = null;

if ($guestToken !== '') {
    if (!preg_match('/^[a-f0-9]{24,64}$/', $guestToken)) {
        http_response_code(403);
        exit('Ungültiger Freigabe-Link.');
    }
    if (isLoginIpRateLimited($pdo)) {
        http_response_code(429);
        exit('Zu viele fehlgeschlagene Aufrufe. Bitte warten Sie einige Minuten.');
    }
    $stmtTok = $pdo->prepare('SELECT * FROM mc_split_groups WHERE share_token = :tok LIMIT 1');
    $stmtTok->execute([':tok' => $guestToken]);
    $tokenGroup = $stmtTok->fetch() ?: null;

    if ($tokenGroup === null) {
        $_SESSION['login_attempts'][] = time();
        logAuditEvent($pdo, 'login_failed', 'Ungültiger Reisekasse-Gäste-Token aufgerufen', null, 'guest');
        http_response_code(404);
        exit('Dieser Gäste-Link zur Kostenabrechnung ist nicht (mehr) gültig.');
    }
} elseif (!$isLoggedIn) {
    requireLogin();
}

$isGuestMode = ($tokenGroup !== null && !$isLoggedIn);

/**
 * Prüft, ob der aktuelle Benutzer (oder Gast per gültigem Token) Zugriff auf ein Abrechnungs-Projekt hat.
 *
 * @return array<string, mixed>|null
 */
function getAccessibleSplitGroup(PDO $pdo, int $groupId, int $userId, ?array $tokenGroup = null): ?array
{
    if ($tokenGroup !== null && (int)$tokenGroup['id'] === $groupId) {
        return $tokenGroup;
    }
    if ($groupId <= 0 || $userId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT g.*
         FROM mc_split_groups g
         WHERE g.id = :gid
           AND (
               g.owner_id = :uid1
               OR EXISTS (
                   SELECT 1 FROM mc_split_participants p
                   WHERE p.group_id = g.id AND p.user_id = :uid2
               )
           )
         LIMIT 1'
    );
    $stmt->execute([
        ':gid'  => $groupId,
        ':uid1' => $userId,
        ':uid2' => $userId,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ============================================================================
// BELEG-STREAMING (?view_receipt=EXPENSE_ID) – MIT SESSION_WRITE_CLOSE()
// ============================================================================
if (isset($_GET['view_receipt'])) {
    $recExpId = (int)$_GET['view_receipt'];
    $stmtRec = $pdo->prepare('SELECT * FROM mc_split_expenses WHERE id = :eid LIMIT 1');
    $stmtRec->execute([':eid' => $recExpId]);
    $recExp = $stmtRec->fetch() ?: null;

    if (!$recExp) {
        http_response_code(404);
        exit('Beleg nicht gefunden.');
    }

    $recGroup = getAccessibleSplitGroup($pdo, (int)$recExp['group_id'], $userId, $tokenGroup);
    if ($recGroup === null) {
        http_response_code(403);
        exit('Keine Berechtigung für diesen Beleg.');
    }

    $storedFile = '';
    $mimeType   = 'application/octet-stream';
    $origName   = 'Beleg';

    if (!empty($recExp['receipt_file_id'])) {
        $stmtCf = $pdo->prepare('SELECT stored_name, original_name, mime_type, is_encrypted FROM files WHERE id = :fid AND deleted_at IS NULL LIMIT 1');
        $stmtCf->execute([':fid' => (int)$recExp['receipt_file_id']]);
        $cfRow = $stmtCf->fetch() ?: null;
        if ($cfRow && (int)($cfRow['is_encrypted'] ?? 0) === 0) {
            $storedFile = UPLOAD_DIR . '/' . basename((string)$cfRow['stored_name']);
            $mimeType   = (string)$cfRow['mime_type'];
            $origName   = (string)$cfRow['original_name'];
        }
    } elseif (!empty($recExp['receipt_stored_name'])) {
        $storedFile = UPLOAD_DIR . '/' . basename((string)$recExp['receipt_stored_name']);
        $mimeType   = (string)($recExp['receipt_mime'] ?: 'image/jpeg');
        $origName   = (string)($recExp['receipt_original_name'] ?: 'Beleg.jpg');
    }

    if ($storedFile === '' || !is_file($storedFile)) {
        http_response_code(404);
        exit('Beleg-Datei nicht mehr vorhanden.');
    }

    session_write_close();
    $allowedInlineMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    if (!in_array(strtolower($mimeType), $allowedInlineMimes, true)) {
        $mimeType = 'application/octet-stream';
    }
    $safeDispName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName) ?: 'Beleg';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string)filesize($storedFile));
    header('Content-Disposition: inline; filename="' . $safeDispName . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($storedFile);
    exit;
}

/**
 * Findet oder erstellt einen Teilnehmer (registrierter Nutzer oder Freitext-Person) in einem Projekt.
 */
function findOrCreateSplitParticipant(PDO $pdo, int $groupId, ?int $cloudUserId, string $displayName): int
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return 0;
    }
    $displayName = mb_substr($displayName, 0, 110, 'UTF-8');

    if ($cloudUserId !== null && $cloudUserId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM mc_split_participants WHERE group_id = :gid AND user_id = :uid LIMIT 1');
        $stmt->execute([':gid' => $groupId, ':uid' => $cloudUserId]);
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
    }

    $stmtName = $pdo->prepare('SELECT id, user_id FROM mc_split_participants WHERE group_id = :gid AND LOWER(display_name) = LOWER(:name) LIMIT 1');
    $stmtName->execute([':gid' => $groupId, ':name' => $displayName]);
    $existingRow = $stmtName->fetch();
    if ($existingRow) {
        if ($cloudUserId !== null && $cloudUserId > 0 && empty($existingRow['user_id'])) {
            $pdo->prepare('UPDATE mc_split_participants SET user_id = :uid WHERE id = :id')
                ->execute([':uid' => $cloudUserId, ':id' => (int)$existingRow['id']]);
        }
        return (int)$existingRow['id'];
    }

    $stmtIns = $pdo->prepare(
        'INSERT INTO mc_split_participants (group_id, user_id, display_name)
         VALUES (:gid, :uid, :name)'
    );
    $stmtIns->execute([
        ':gid'  => $groupId,
        ':uid'  => ($cloudUserId !== null && $cloudUserId > 0) ? $cloudUserId : null,
        ':name' => $displayName,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Zerlegt eine Freitext-Liste (Komma-, Semikolon- oder Zeilenumbruch-getrennt) in eindeutige Namen.
 *
 * @return array<int, string>
 */
function parseFreetextNamesList(string $raw): array
{
    $parts = preg_split('/[,;\r\n]+/', $raw) ?: [];
    $result = [];
    $seen = [];
    foreach ($parts as $p) {
        $clean = trim($p);
        if ($clean === '') {
            continue;
        }
        $key = mb_strtolower($clean, 'UTF-8');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = mb_substr($clean, 0, 110, 'UTF-8');
        }
    }
    return $result;
}

/**
 * Verteilt einen Gesamtbetrag in Cent exakt (auf den Cent genau ohne Rundungsverlust)
 * anhand von Gewichtungen auf eine Liste von Teilnehmer-IDs.
 *
 * @param int $totalCents
 * @param array<int, float> $weightsMap
 * @return array<int, int>
 */
function distributeCentsByWeights(int $totalCents, array $weightsMap): array
{
    $filtered = [];
    $totalWeight = 0.0;
    foreach ($weightsMap as $pid => $w) {
        $wVal = max(0.0, (float)$w);
        if ($wVal > 0.0) {
            $filtered[(int)$pid] = $wVal;
            $totalWeight += $wVal;
        }
    }
    if (empty($filtered) || $totalWeight <= 0.0) {
        return [];
    }

    $shares = [];
    $remainders = [];
    $allocated = 0;

    foreach ($filtered as $pid => $w) {
        $exact = ($totalCents * $w) / $totalWeight;
        $floorCents = (int)floor($exact + 1e-9);
        $shares[$pid] = $floorCents;
        $remainders[$pid] = $exact - $floorCents;
        $allocated += $floorCents;
    }

    $leftover = $totalCents - $allocated;
    if ($leftover > 0) {
        arsort($remainders);
        foreach (array_keys($remainders) as $pid) {
            if ($leftover <= 0) {
                break;
            }
            $shares[$pid] += 1;
            $leftover--;
        }
    }

    return $shares;
}

/**
 * Berechnet für alle Teilnehmer eines Projekts die Salden sowie den optimalen Ausgleichsplan
 * („Wer schuldet wem wie viel?“ mit minimalen Transaktionen).
 */
function calculateSplitSummary(array $participants, array $expenses, array $sharesByExpense): array
{
    $balances = [];
    foreach ($participants as $p) {
        $pid = (int)$p['id'];
        $balances[$pid] = [
            'participant_id'            => $pid,
            'display_name'              => (string)$p['display_name'],
            'user_id'                   => !empty($p['user_id']) ? (int)$p['user_id'] : null,
            'paid_cents'                => 0,
            'consumed_cents'            => 0,
            'settlement_paid_cents'     => 0,
            'settlement_received_cents' => 0,
            'net_cents'                 => 0,
        ];
    }

    $totalSpentCents = 0;
    $expenseCount    = 0;
    $settlementCount = 0;

    foreach ($expenses as $exp) {
        $eid          = (int)$exp['id'];
        $payerId      = (int)$exp['paid_by_participant_id'];
        $amountCents  = (int)$exp['amount_cents'];
        $isSettlement = ((int)($exp['is_settlement'] ?? 0) === 1);
        $expShares    = $sharesByExpense[$eid] ?? [];

        if ($isSettlement) {
            $settlementCount++;
            if (isset($balances[$payerId])) {
                $balances[$payerId]['settlement_paid_cents'] += $amountCents;
                $balances[$payerId]['net_cents'] += $amountCents;
            }
            foreach ($expShares as $sh) {
                $profId  = (int)$sh['participant_id'];
                $shCents = (int)$sh['share_cents'];
                if (isset($balances[$profId])) {
                    $balances[$profId]['settlement_received_cents'] += $shCents;
                    $balances[$profId]['net_cents'] -= $shCents;
                }
            }
        } else {
            $expenseCount++;
            $totalSpentCents += $amountCents;
            if (isset($balances[$payerId])) {
                $balances[$payerId]['paid_cents'] += $amountCents;
                $balances[$payerId]['net_cents']  += $amountCents;
            }
            foreach ($expShares as $sh) {
                $profId  = (int)$sh['participant_id'];
                $shCents = (int)$sh['share_cents'];
                if (isset($balances[$profId])) {
                    $balances[$profId]['consumed_cents'] += $shCents;
                    $balances[$profId]['net_cents']      -= $shCents;
                }
            }
        }
    }

    $debtors   = [];
    $creditors = [];
    foreach ($balances as $pid => $b) {
        if ($b['net_cents'] < -1) {
            $debtors[] = [
                'id'    => $pid,
                'name'  => $b['display_name'],
                'cents' => -$b['net_cents'],
            ];
        } elseif ($b['net_cents'] > 1) {
            $creditors[] = [
                'id'    => $pid,
                'name'  => $b['display_name'],
                'cents' => $b['net_cents'],
            ];
        }
    }

    usort($debtors, static fn(array $a, array $b): int => $b['cents'] <=> $a['cents']);
    usort($creditors, static fn(array $a, array $b): int => $b['cents'] <=> $a['cents']);

    $settlements = [];
    $i = 0;
    $j = 0;
    while ($i < count($debtors) && $j < count($creditors)) {
        $payCents = min($debtors[$i]['cents'], $creditors[$j]['cents']);
        if ($payCents > 0) {
            $settlements[] = [
                'from_id'      => $debtors[$i]['id'],
                'from_name'    => $debtors[$i]['name'],
                'to_id'        => $creditors[$j]['id'],
                'to_name'      => $creditors[$j]['name'],
                'amount_cents' => $payCents,
            ];
        }
        $debtors[$i]['cents']   -= $payCents;
        $creditors[$j]['cents'] -= $payCents;

        if ($debtors[$i]['cents'] <= 1) {
            $i++;
        }
        if ($creditors[$j]['cents'] <= 1) {
            $j++;
        }
    }

    return [
        'total_spent_cents' => $totalSpentCents,
        'expense_count'     => $expenseCount,
        'settlement_count'  => $settlementCount,
        'balances'          => $balances,
        'settlements'       => $settlements,
    ];
}

function formatSplitMoney(int $cents, string $currency = '€'): string
{
    $abs = abs($cents) / 100.0;
    $prefix = $cents < 0 ? '−' : '';
    return $prefix . number_format($abs, 2, ',', '.') . ' ' . $currency;
}

function parseMoneyToCents(string $input): int
{
    $clean = trim(str_replace([' ', '€', '$', 'CHF', '£', 'Kč', 'zł', 'kr', 'Ft', '¥', '฿', 'TRY'], '', $input));
    if ($clean === '') {
        return 0;
    }
    if (str_contains($clean, ',') && str_contains($clean, '.')) {
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } else {
        $clean = str_replace(',', '.', $clean);
    }
    $val = (float)$clean;
    if (!is_finite($val) || $val <= 0) {
        return 0;
    }
    return (int)round($val * 100);
}

$splitCategories = [
    'transport' => ['label' => 'Fahrt, Bus, Bahn & Tanken', 'icon' => '🚌'],
    'food'      => ['label' => 'Essen, Restaurant & Bar',   'icon' => '🍽️'],
    'lodging'   => ['label' => 'Unterkunft & Hotel',        'icon' => '🏨'],
    'ticket'    => ['label' => 'Eintritt, Kultur & Sport',  'icon' => '🎟️'],
    'groceries' => ['label' => 'Supermarkt & Einkauf',      'icon' => '🛒'],
    'other'     => ['label' => 'Sonstige Ausgaben',         'icon' => '🎁'],
];

$groupIcons = ['✈️', '🚌', '🏕️', '🍽️', '🏔️', '🏖️', '🎉', '🏠', '🚗', '🎿', '🍻', '🧾'];
$currencies = ['€', 'CHF', '$', '£', 'Kč', 'zł', 'kr', 'Ft', 'TRY', '¥', '฿'];

// Richtwerte für Fremdwährungen relativ zu 1 € (für den automatischen Umrechnungs-Vorschlag)
$eurExchangeDefaults = [
    '€'   => 1.0,
    'CHF' => 1.06,
    '$'   => 0.92,
    '£'   => 1.18,
    'Kč'  => 0.0396,
    'zł'  => 0.232,
    'kr'  => 0.088,
    'Ft'  => 0.0025,
    'TRY' => 0.027,
    '¥'   => 0.0061,
    '฿'   => 0.027,
];

$allCloudUsers = [];
if ($isLoggedIn) {
    try {
        $stmtU = $pdo->query('SELECT id, username FROM users WHERE is_locked = 0 ORDER BY username ASC');
        foreach ($stmtU->fetchAll() as $uRow) {
            $allCloudUsers[(int)$uRow['id']] = (string)$uRow['username'];
        }
    } catch (Throwable) {
        $allCloudUsers[$userId] = $username;
    }
}

// ============================================================================
// POST-AKTIONEN VERARBEITEN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF). Bitte versuchen Sie es erneut.');
        header('Location: split.php' . ($guestToken !== '' ? '?token=' . rawurlencode($guestToken) : ''));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 1. Neues Projekt / Neue Reise erstellen (nur für eingeloggte Cloud-Nutzer)
    if ($action === 'create_group') {
        if (!$isLoggedIn) {
            http_response_code(403);
            exit('Nur angemeldete Cloud-Benutzer können neue Projekte anlegen.');
        }
        $title       = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $icon        = trim((string)($_POST['icon'] ?? '✈️'));
        $currency    = trim((string)($_POST['currency'] ?? '€'));
        if (!in_array($icon, $groupIcons, true)) {
            $icon = '✈️';
        }
        if (!in_array($currency, $currencies, true)) {
            $currency = '€';
        }

        if ($title === '') {
            setFlash('error', 'Bitte geben Sie einen Namen für das Projekt bzw. die Reise ein.');
            header('Location: split.php');
            exit;
        }

        $stmtInsG = $pdo->prepare(
            'INSERT INTO mc_split_groups (owner_id, title, description, icon, currency)
             VALUES (:uid, :title, :desc, :icon, :curr)'
        );
        $stmtInsG->execute([
            ':uid'   => $userId,
            ':title' => mb_substr($title, 0, 175, 'UTF-8'),
            ':desc'  => mb_substr($description, 0, 490, 'UTF-8'),
            ':icon'  => $icon,
            ':curr'  => $currency,
        ]);
        $newGroupId = (int)$pdo->lastInsertId();

        findOrCreateSplitParticipant($pdo, $newGroupId, $userId, $username);

        $selectedUserIds = isset($_POST['cloud_user_ids']) && is_array($_POST['cloud_user_ids'])
            ? array_map('intval', $_POST['cloud_user_ids'])
            : [];
        foreach ($selectedUserIds as $cUid) {
            if (isset($allCloudUsers[$cUid])) {
                findOrCreateSplitParticipant($pdo, $newGroupId, $cUid, $allCloudUsers[$cUid]);
            }
        }

        $guestNamesRaw = (string)($_POST['guest_names'] ?? '');
        foreach (parseFreetextNamesList($guestNamesRaw) as $guestName) {
            findOrCreateSplitParticipant($pdo, $newGroupId, null, $guestName);
        }

        setFlash('success', 'Projekt / Reise „' . $title . '“ wurde angelegt. Sie können jetzt Kostenpunkte eintragen!');
        header('Location: split.php?group=' . $newGroupId);
        exit;
    }

    // Alle weiteren Aktionen beziehen sich auf eine bestehende Gruppe
    $groupId = (int)($_POST['group_id'] ?? 0);
    $group   = getAccessibleSplitGroup($pdo, $groupId, $userId, $tokenGroup);
    if ($group === null) {
        setFlash('error', 'Projekt / Reise nicht gefunden oder keine Berechtigung.');
        header('Location: split.php');
        exit;
    }

    $redirectUrl = $guestToken !== ''
        ? 'split.php?token=' . rawurlencode($guestToken)
        : 'split.php?group=' . $groupId;

    // Im Gäste-Modus prüfen, ob Schreibzugriff erlaubt ist
    $canModifyExpenses = $isLoggedIn || ((int)($group['guest_can_add'] ?? 1) === 1);

    // 1b. Öffentlichen Gäste-Link & QR-Code verwalten (nur eingeloggte Projekt-Mitglieder)
    if ($action === 'update_share_settings' && $isLoggedIn) {
        $shareMode = (string)($_POST['share_mode'] ?? 'off');
        if ($shareMode === 'off') {
            $pdo->prepare('UPDATE mc_split_groups SET share_token = NULL WHERE id = :gid')->execute([':gid' => $groupId]);
            setFlash('success', 'Der öffentliche Gäste-Link für dieses Projekt wurde deaktiviert.');
        } else {
            $guestCanAdd = ($shareMode === 'write') ? 1 : 0;
            $curToken    = (string)($group['share_token'] ?? '');
            $regen       = isset($_POST['regenerate_token']) && $_POST['regenerate_token'] === '1';
            if ($curToken === '' || $regen) {
                $curToken = bin2hex(random_bytes(20));
            }
            $pdo->prepare('UPDATE mc_split_groups SET share_token = :tok, guest_can_add = :can WHERE id = :gid')
                ->execute([
                    ':tok' => $curToken,
                    ':can' => $guestCanAdd,
                    ':gid' => $groupId,
                ]);
            setFlash(
                'success',
                $guestCanAdd === 1
                    ? 'Öffentlicher Gäste-Link & QR-Code aktiviert (Gäste dürfen Abrechnung sehen & eigene Auslagen eintragen).'
                    : 'Öffentlicher Gäste-Link & QR-Code aktiviert (Nur Lesezugriff für Gäste).'
            );
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 2. Weitere Teilnehmer hinzufügen
    if ($action === 'add_participants' && $canModifyExpenses) {
        $addedCount = 0;
        if ($isLoggedIn) {
            $selectedUserIds = isset($_POST['cloud_user_ids']) && is_array($_POST['cloud_user_ids'])
                ? array_map('intval', $_POST['cloud_user_ids'])
                : [];
            foreach ($selectedUserIds as $cUid) {
                if (isset($allCloudUsers[$cUid])) {
                    findOrCreateSplitParticipant($pdo, $groupId, $cUid, $allCloudUsers[$cUid]);
                    $addedCount++;
                }
            }
        }

        $guestNamesRaw = (string)($_POST['guest_names'] ?? '');
        foreach (parseFreetextNamesList($guestNamesRaw) as $guestName) {
            findOrCreateSplitParticipant($pdo, $groupId, null, $guestName);
            $addedCount++;
        }

        $pdo->prepare('UPDATE mc_split_groups SET updated_at = NOW() WHERE id = :gid')->execute([':gid' => $groupId]);
        if ($addedCount > 0) {
            setFlash('success', 'Teilnehmerliste wurde aktualisiert.');
        } else {
            setFlash('warning', 'Bitte geben Sie mindestens einen Namen ein.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 3. Kostenpunkt hinzufügen oder bearbeiten (inkl. Fremdwährungs-Umrechner & Kassenbon-Upload)
    if ($action === 'save_expense' && $canModifyExpenses) {
        $expenseId   = (int)($_POST['expense_id'] ?? 0);
        $title       = trim((string)($_POST['title'] ?? ''));
        $rawAmount   = parseMoneyToCents((string)($_POST['amount'] ?? ''));
        $expCurrency = trim((string)($_POST['expense_currency'] ?? (string)$group['currency']));
        $exRateRaw   = str_replace(',', '.', trim((string)($_POST['exchange_rate'] ?? '1')));
        $exRate      = max(0.00001, min(100000.0, (float)$exRateRaw ?: 1.0));
        $groupCurr   = (string)$group['currency'];

        if (!in_array($expCurrency, $currencies, true)) {
            $expCurrency = $groupCurr;
        }

        if ($expCurrency !== $groupCurr) {
            $origAmountCents = $rawAmount;
            $origCurr        = $expCurrency;
            $amountCents     = (int)round($rawAmount * $exRate);
        } else {
            $origAmountCents = 0;
            $origCurr        = '';
            $exRate          = 1.0;
            $amountCents     = $rawAmount;
        }

        $category    = trim((string)($_POST['category'] ?? 'food'));
        $expenseDate = trim((string)($_POST['expense_date'] ?? date('Y-m-d')));
        $note        = trim((string)($_POST['note'] ?? ''));
        $splitMode   = trim((string)($_POST['split_mode'] ?? 'equal'));

        if (!array_key_exists($category, $splitCategories)) {
            $category = 'other';
        }
        if (!in_array($splitMode, ['equal', 'shares', 'exact'], true)) {
            $splitMode = 'equal';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            $expenseDate = date('Y-m-d');
        }

        if ($title === '' || $amountCents <= 0) {
            setFlash('error', 'Bitte geben Sie eine Bezeichnung (z. B. „Busfahrt“) und einen gültigen Betrag größer als 0 ein.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Ausleger bestimmen
        $paidBySelect = trim((string)($_POST['paid_by_participant_id'] ?? ''));
        $newPayerName = trim((string)($_POST['new_payer_freetext'] ?? ''));
        $payerPartId  = 0;

        if ($paidBySelect === 'new_freetext' && $newPayerName !== '') {
            $payerPartId = findOrCreateSplitParticipant($pdo, $groupId, null, $newPayerName);
        } else {
            $payerPartId = (int)$paidBySelect;
            $stmtVerifyPayer = $pdo->prepare('SELECT id FROM mc_split_participants WHERE id = :pid AND group_id = :gid LIMIT 1');
            $stmtVerifyPayer->execute([':pid' => $payerPartId, ':gid' => $groupId]);
            if (!$stmtVerifyPayer->fetchColumn()) {
                $payerPartId = 0;
            }
        }

        if ($payerPartId <= 0) {
            setFlash('error', 'Bitte wählen Sie aus, wer diesen Kostenpunkt ausgelegt hat.');
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Profiteure sammeln
        $checkedProfIds = isset($_POST['profiteur_ids']) && is_array($_POST['profiteur_ids'])
            ? array_map('intval', $_POST['profiteur_ids'])
            : [];

        $extraProfFreetext = (string)($_POST['extra_profiteure_freetext'] ?? '');
        foreach (parseFreetextNamesList($extraProfFreetext) as $extraName) {
            $newProfId = findOrCreateSplitParticipant($pdo, $groupId, null, $extraName);
            if ($newProfId > 0 && !in_array($newProfId, $checkedProfIds, true)) {
                $checkedProfIds[] = $newProfId;
            }
        }

        $stmtValidParts = $pdo->prepare('SELECT id FROM mc_split_participants WHERE group_id = :gid');
        $stmtValidParts->execute([':gid' => $groupId]);
        $validPartIdsMap = array_fill_keys(array_map('intval', $stmtValidParts->fetchAll(PDO::FETCH_COLUMN)), true);

        $finalProfIds = [];
        foreach ($checkedProfIds as $pid) {
            if (isset($validPartIdsMap[$pid]) && !in_array($pid, $finalProfIds, true)) {
                $finalProfIds[] = $pid;
            }
        }

        if (empty($finalProfIds)) {
            setFlash('error', 'Bitte wählen Sie mindestens einen Profiteur aus (oder tragen Sie Freitext-Namen ein).');
            header('Location: ' . $redirectUrl);
            exit;
        }

        // Anteile berechnen
        $weightsInput   = isset($_POST['profiteur_weights']) && is_array($_POST['profiteur_weights']) ? $_POST['profiteur_weights'] : [];
        $exactInput     = isset($_POST['profiteur_exact']) && is_array($_POST['profiteur_exact']) ? $_POST['profiteur_exact'] : [];
        $weightsMap     = [];
        $sharesCentsMap = [];

        if ($splitMode === 'exact') {
            $exactSumCents = 0;
            foreach ($finalProfIds as $pid) {
                $c = parseMoneyToCents((string)($exactInput[$pid] ?? '0'));
                if ($expCurrency !== $groupCurr) {
                    $c = (int)round($c * $exRate);
                }
                if ($c > 0) {
                    $sharesCentsMap[$pid] = $c;
                    $weightsMap[$pid]     = round($c / 100, 2);
                    $exactSumCents       += $c;
                }
            }
            if (empty($sharesCentsMap)) {
                setFlash('error', 'Bitte geben Sie bei „Exakte Einzelbeträge“ für die ausgewählten Profiteure gültige Beträge ein.');
                header('Location: ' . $redirectUrl);
                exit;
            }
            $amountCents = $exactSumCents;
        } elseif ($splitMode === 'shares') {
            foreach ($finalProfIds as $pid) {
                $rawW = str_replace(',', '.', trim((string)($weightsInput[$pid] ?? '1')));
                $w = max(0.1, min(1000.0, (float)$rawW ?: 1.0));
                $weightsMap[$pid] = $w;
            }
            $sharesCentsMap = distributeCentsByWeights($amountCents, $weightsMap);
        } else {
            foreach ($finalProfIds as $pid) {
                $weightsMap[$pid] = 1.0;
            }
            $sharesCentsMap = distributeCentsByWeights($amountCents, $weightsMap);
        }

        // Kassenbon / Beleg verarbeiten (Direkt-Upload oder Cloud-Datei-Verknüpfung)
        $receiptFileId     = null;
        $receiptStoredName = '';
        $receiptOrigName   = '';
        $receiptMime       = '';

        if ($expenseId > 0) {
            $stmtOldRec = $pdo->prepare('SELECT receipt_file_id, receipt_stored_name, receipt_original_name, receipt_mime FROM mc_split_expenses WHERE id = :eid AND group_id = :gid LIMIT 1');
            $stmtOldRec->execute([':eid' => $expenseId, ':gid' => $groupId]);
            if ($oldR = $stmtOldRec->fetch()) {
                $receiptFileId     = !empty($oldR['receipt_file_id']) ? (int)$oldR['receipt_file_id'] : null;
                $receiptStoredName = (string)$oldR['receipt_stored_name'];
                $receiptOrigName   = (string)$oldR['receipt_original_name'];
                $receiptMime       = (string)$oldR['receipt_mime'];
            }
        }

        // a) Cloud-Datei-Verknüpfung (nur eigene unverschlüsselte Dateien des eingeloggten Benutzers)
        if ($isLoggedIn && isset($_POST['receipt_cloud_file_id']) && (int)$_POST['receipt_cloud_file_id'] > 0) {
            $candFid = (int)$_POST['receipt_cloud_file_id'];
            $stmtOwnF = $pdo->prepare('SELECT id FROM files WHERE id = :fid AND user_id = :uid AND is_encrypted = 0 AND deleted_at IS NULL LIMIT 1');
            $stmtOwnF->execute([':fid' => $candFid, ':uid' => $userId]);
            if ($stmtOwnF->fetchColumn()) {
                $receiptFileId     = $candFid;
                $receiptStoredName = '';
                $receiptOrigName   = '';
                $receiptMime       = '';
            }
        }

        // b) Direktes Kassenbon-Foto / PDF per Datei-Upload
        if (isset($_FILES['receipt_upload']) && is_array($_FILES['receipt_upload']) && (int)($_FILES['receipt_upload']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpPath  = (string)$_FILES['receipt_upload']['tmp_name'];
            $upSize   = (int)$_FILES['receipt_upload']['size'];
            $upOrig   = trim((string)$_FILES['receipt_upload']['name']);
            $maxBytes = 15 * 1024 * 1024; // max. 15 MB pro Beleg

            if ($upSize > 0 && $upSize <= $maxBytes && is_uploaded_file($tmpPath)) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detMime = strtolower((string)$finfo->file($tmpPath));
                $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
                if (in_array($detMime, $allowedMimes, true)) {
                    $newStored = generateSafeStoredFilename();
                    $destPath  = UPLOAD_DIR . '/' . $newStored;
                    if (@move_uploaded_file($tmpPath, $destPath)) {
                        @chmod($destPath, 0640);
                        // Alten hochgeladenen Beleg bereinigen, falls ersetzt
                        if ($receiptStoredName !== '' && is_file(UPLOAD_DIR . '/' . basename($receiptStoredName))) {
                            @unlink(UPLOAD_DIR . '/' . basename($receiptStoredName));
                        }
                        $receiptFileId     = null;
                        $receiptStoredName = $newStored;
                        $receiptOrigName   = mb_substr($upOrig !== '' ? $upOrig : 'Kassenbon.jpg', 0, 240, 'UTF-8');
                        $receiptMime       = $detMime;
                    }
                }
            }
        }

        $pdo->beginTransaction();
        try {
            if ($expenseId > 0) {
                $stmtUpdE = $pdo->prepare(
                    'UPDATE mc_split_expenses
                     SET paid_by_participant_id = :payer,
                         title = :title,
                         amount_cents = :cents,
                         original_amount_cents = :ocents,
                         original_currency = :ocurr,
                         exchange_rate = :exrate,
                         category = :cat,
                         split_mode = :mode,
                         expense_date = :dt,
                         note = :note,
                         receipt_file_id = :rfid,
                         receipt_stored_name = :rstored,
                         receipt_original_name = :rorig,
                         receipt_mime = :rmime
                     WHERE id = :eid AND group_id = :gid AND is_settlement = 0'
                );
                $stmtUpdE->execute([
                    ':payer'   => $payerPartId,
                    ':title'   => mb_substr($title, 0, 195, 'UTF-8'),
                    ':cents'   => $amountCents,
                    ':ocents'  => $origAmountCents,
                    ':ocurr'   => $origCurr,
                    ':exrate'  => $exRate,
                    ':cat'     => $category,
                    ':mode'    => $splitMode,
                    ':dt'      => $expenseDate,
                    ':note'    => mb_substr($note, 0, 490, 'UTF-8'),
                    ':rfid'    => $receiptFileId,
                    ':rstored' => $receiptStoredName,
                    ':rorig'   => $receiptOrigName,
                    ':rmime'   => $receiptMime,
                    ':eid'     => $expenseId,
                    ':gid'     => $groupId,
                ]);
                $pdo->prepare('DELETE FROM mc_split_shares WHERE expense_id = :eid AND group_id = :gid')
                    ->execute([':eid' => $expenseId, ':gid' => $groupId]);
                $targetExpId = $expenseId;
            } else {
                $stmtInsE = $pdo->prepare(
                    'INSERT INTO mc_split_expenses
                        (group_id, paid_by_participant_id, title, amount_cents, original_amount_cents, original_currency, exchange_rate, category, split_mode, is_settlement, expense_date, note, receipt_file_id, receipt_stored_name, receipt_original_name, receipt_mime, created_by_user_id)
                     VALUES
                        (:gid, :payer, :title, :cents, :ocents, :ocurr, :exrate, :cat, :mode, 0, :dt, :note, :rfid, :rstored, :rorig, :rmime, :uid)'
                );
                $stmtInsE->execute([
                    ':gid'     => $groupId,
                    ':payer'   => $payerPartId,
                    ':title'   => mb_substr($title, 0, 195, 'UTF-8'),
                    ':cents'   => $amountCents,
                    ':ocents'  => $origAmountCents,
                    ':ocurr'   => $origCurr,
                    ':exrate'  => $exRate,
                    ':cat'     => $category,
                    ':mode'    => $splitMode,
                    ':dt'      => $expenseDate,
                    ':note'    => mb_substr($note, 0, 490, 'UTF-8'),
                    ':rfid'    => $receiptFileId,
                    ':rstored' => $receiptStoredName,
                    ':rorig'   => $receiptOrigName,
                    ':rmime'   => $receiptMime,
                    ':uid'     => $userId,
                ]);
                $targetExpId = (int)$pdo->lastInsertId();
            }

            $stmtInsS = $pdo->prepare(
                'INSERT INTO mc_split_shares (expense_id, group_id, participant_id, weight, share_cents)
                 VALUES (:eid, :gid, :pid, :w, :cents)'
            );
            foreach ($sharesCentsMap as $pid => $sCents) {
                $stmtInsS->execute([
                    ':eid'   => $targetExpId,
                    ':gid'   => $groupId,
                    ':pid'   => $pid,
                    ':w'     => $weightsMap[$pid] ?? 1.0,
                    ':cents' => $sCents,
                ]);
            }

            $pdo->prepare('UPDATE mc_split_groups SET updated_at = NOW() WHERE id = :gid')->execute([':gid' => $groupId]);
            $pdo->commit();

            setFlash(
                'success',
                'Kostenpunkt „' . $title . '“ (' . formatSplitMoney($amountCents, $groupCurr) . ') wurde gespeichert.'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', 'Fehler beim Speichern des Kostenpunkts: ' . $e->getMessage());
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    // 4. Ausgleichszahlung mit 1 Klick als erledigt/bezahlt verbuchen
    if ($action === 'record_settlement' && $canModifyExpenses) {
        $fromId      = (int)($_POST['from_participant_id'] ?? 0);
        $toId        = (int)($_POST['to_participant_id'] ?? 0);
        $amountCents = (int)($_POST['amount_cents'] ?? 0);

        $stmtNames = $pdo->prepare('SELECT id, display_name FROM mc_split_participants WHERE group_id = :gid AND id IN (:f, :t)');
        $stmtNames->execute([':gid' => $groupId, ':f' => $fromId, ':t' => $toId]);
        $nameMap = [];
        foreach ($stmtNames->fetchAll() as $r) {
            $nameMap[(int)$r['id']] = (string)$r['display_name'];
        }

        if ($fromId > 0 && $toId > 0 && $fromId !== $toId && $amountCents > 0 && isset($nameMap[$fromId], $nameMap[$toId])) {
            $pdo->beginTransaction();
            try {
                $title = 'Ausgleichszahlung: ' . $nameMap[$fromId] . ' ➔ ' . $nameMap[$toId];
                $stmtInsSet = $pdo->prepare(
                    'INSERT INTO mc_split_expenses
                        (group_id, paid_by_participant_id, title, amount_cents, category, split_mode, is_settlement, expense_date, note, created_by_user_id)
                     VALUES
                        (:gid, :payer, :title, :cents, :cat, :mode, 1, :dt, :note, :uid)'
                );
                $stmtInsSet->execute([
                    ':gid'   => $groupId,
                    ':payer' => $fromId,
                    ':title' => mb_substr($title, 0, 195, 'UTF-8'),
                    ':cents' => $amountCents,
                    ':cat'   => 'other',
                    ':mode'  => 'equal',
                    ':dt'    => date('Y-m-d'),
                    ':note'  => 'Direkter Schuldenausgleich',
                    ':uid'   => $userId,
                ]);
                $setExpId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO mc_split_shares (expense_id, group_id, participant_id, weight, share_cents)
                     VALUES (:eid, :gid, :pid, 1.00, :cents)'
                )->execute([
                    ':eid'   => $setExpId,
                    ':gid'   => $groupId,
                    ':pid'   => $toId,
                    ':cents' => $amountCents,
                ]);

                $pdo->prepare('UPDATE mc_split_groups SET updated_at = NOW() WHERE id = :gid')->execute([':gid' => $groupId]);
                $pdo->commit();
                setFlash('success', 'Ausgleichszahlung (' . formatSplitMoney($amountCents, (string)$group['currency']) . ' von ' . $nameMap[$fromId] . ' an ' . $nameMap[$toId] . ') wurde verbucht!');
            } catch (Throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('error', 'Ausgleichszahlung konnte nicht gespeichert werden.');
            }
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 5. Kostenpunkt / Ausgleichszahlung löschen
    if ($action === 'delete_expense' && $canModifyExpenses) {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        if ($expenseId > 0) {
            $stmtDelRec = $pdo->prepare('SELECT receipt_stored_name FROM mc_split_expenses WHERE id = :eid AND group_id = :gid LIMIT 1');
            $stmtDelRec->execute([':eid' => $expenseId, ':gid' => $groupId]);
            $delStored = (string)($stmtDelRec->fetchColumn() ?: '');
            if ($delStored !== '' && is_file(UPLOAD_DIR . '/' . basename($delStored))) {
                @unlink(UPLOAD_DIR . '/' . basename($delStored));
            }

            $pdo->prepare('DELETE FROM mc_split_shares WHERE expense_id = :eid AND group_id = :gid')
                ->execute([':eid' => $expenseId, ':gid' => $groupId]);
            $pdo->prepare('DELETE FROM mc_split_expenses WHERE id = :eid AND group_id = :gid')
                ->execute([':eid' => $expenseId, ':gid' => $groupId]);
            $pdo->prepare('UPDATE mc_split_groups SET updated_at = NOW() WHERE id = :gid')
                ->execute([':gid' => $groupId]);
            setFlash('success', 'Eintrag wurde gelöscht und alle Salden neu berechnet.');
        }
        header('Location: ' . $redirectUrl);
        exit;
    }

    // 6. Zusammenfassung als Text/Markdown-Datei direkt in der Cloud speichern (nur eingeloggte Nutzer)
    if ($action === 'save_summary_to_cloud' && $isLoggedIn) {
        $summaryText = trim((string)($_POST['summary_markdown'] ?? ''));
        $folderPath  = normalizeFolderPath((string)($_POST['target_folder'] ?? ''));
        $format      = strtolower(trim((string)($_POST['export_format'] ?? 'md')));
        if (!in_array($format, ['md', 'txt'], true)) {
            $format = 'md';
        }

        if ($summaryText !== '') {
            $safeTitle = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß_-]+/u', '_', (string)$group['title']) ?: 'Kostenabrechnung';
            $origName  = 'Abrechnung_' . trim($safeTitle, '_') . '_' . date('Y-m-d') . '.' . $format;
            $stored    = generateSafeStoredFilename();
            $destPath  = UPLOAD_DIR . '/' . $stored;

            if (@file_put_contents($destPath, $summaryText) !== false) {
                $size = (int)filesize($destPath);
                $mime = $format === 'md' ? 'text/markdown' : 'text/plain';
                if ($folderPath !== '') {
                    registerFolderHierarchy($pdo, $userId, $folderPath);
                }
                $stmtFile = $pdo->prepare(
                    'INSERT INTO files (user_id, folder_path, original_name, stored_name, mime_type, file_size)
                     VALUES (:uid, :folder, :orig, :stored, :mime, :size)'
                );
                $stmtFile->execute([
                    ':uid'    => $userId,
                    ':folder' => $folderPath,
                    ':orig'   => $origName,
                    ':stored' => $stored,
                    ':mime'   => $mime,
                    ':size'   => $size,
                ]);
                setFlash('success', 'Die Abrechnungs-Zusammenfassung wurde als „' . $origName . '“ in Ihren Cloud-Dateien gespeichert!');
            } else {
                setFlash('error', 'Datei konnte nicht im Cloud-Speicher abgelegt werden.');
            }
        }
        header('Location: split.php?group=' . $groupId);
        exit;
    }

    // 7. Ganzes Projekt / Reise löschen (nur Ersteller oder Admin)
    if ($action === 'delete_group' && $isLoggedIn) {
        if ((int)$group['owner_id'] === $userId || isAdmin()) {
            $stmtAllRecs = $pdo->prepare("SELECT receipt_stored_name FROM mc_split_expenses WHERE group_id = :gid AND receipt_stored_name != ''");
            $stmtAllRecs->execute([':gid' => $groupId]);
            foreach ($stmtAllRecs->fetchAll(PDO::FETCH_COLUMN) as $rStored) {
                $fPath = UPLOAD_DIR . '/' . basename((string)$rStored);
                if (is_file($fPath)) {
                    @unlink($fPath);
                }
            }
            $pdo->prepare('DELETE FROM mc_split_shares WHERE group_id = :gid')->execute([':gid' => $groupId]);
            $pdo->prepare('DELETE FROM mc_split_expenses WHERE group_id = :gid')->execute([':gid' => $groupId]);
            $pdo->prepare('DELETE FROM mc_split_participants WHERE group_id = :gid')->execute([':gid' => $groupId]);
            $pdo->prepare('DELETE FROM mc_split_groups WHERE id = :gid')->execute([':gid' => $groupId]);
            setFlash('success', 'Projekt / Reise „' . (string)$group['title'] . '“ wurde vollständig gelöscht.');
            header('Location: split.php');
            exit;
        }
        setFlash('error', 'Nur der Ersteller des Projekts kann die gesamte Abrechnung löschen.');
        header('Location: split.php?group=' . $groupId);
        exit;
    }
}

// ============================================================================
// DATEN FÜR DIE ANSICHT LADEN
// ============================================================================
$userGroups = [];
if ($tokenGroup !== null) {
    $stmtSingle = $pdo->prepare(
        'SELECT g.*,
                (SELECT COUNT(*) FROM mc_split_participants p WHERE p.group_id = g.id) AS participant_count,
                (SELECT COALESCE(SUM(e.amount_cents), 0) FROM mc_split_expenses e WHERE e.group_id = g.id AND e.is_settlement = 0) AS total_spent_cents
         FROM mc_split_groups g
         WHERE g.id = :gid LIMIT 1'
    );
    $stmtSingle->execute([':gid' => (int)$tokenGroup['id']]);
    if ($rowSingle = $stmtSingle->fetch()) {
        $userGroups = [$rowSingle];
    }
} elseif ($isLoggedIn) {
    $stmtGroups = $pdo->prepare(
        'SELECT g.*,
                (SELECT COUNT(*) FROM mc_split_participants p WHERE p.group_id = g.id) AS participant_count,
                (SELECT COALESCE(SUM(e.amount_cents), 0) FROM mc_split_expenses e WHERE e.group_id = g.id AND e.is_settlement = 0) AS total_spent_cents
         FROM mc_split_groups g
         WHERE g.owner_id = :uid1
            OR EXISTS (
                SELECT 1 FROM mc_split_participants p2
                WHERE p2.group_id = g.id AND p2.user_id = :uid2
            )
         ORDER BY g.updated_at DESC, g.id DESC'
    );
    $stmtGroups->execute([':uid1' => $userId, ':uid2' => $userId]);
    $userGroups = $stmtGroups->fetchAll();
}

$activeGroupId = $tokenGroup !== null
    ? (int)$tokenGroup['id']
    : (isset($_GET['group']) ? (int)$_GET['group'] : 0);

if ($activeGroupId <= 0 && !empty($userGroups)) {
    $activeGroupId = (int)$userGroups[0]['id'];
}

$activeGroup       = null;
$participants      = [];
$participantsById  = [];
$expenses          = [];
$sharesByExpense   = [];
$summary           = null;
$summaryMarkdown   = '';
$editExpense       = null;
$editExpenseShares = [];

if ($activeGroupId > 0) {
    foreach ($userGroups as $ug) {
        if ((int)$ug['id'] === $activeGroupId) {
            $activeGroup = $ug;
            break;
        }
    }
}

if ($activeGroup !== null) {
    $stmtP = $pdo->prepare('SELECT * FROM mc_split_participants WHERE group_id = :gid ORDER BY id ASC');
    $stmtP->execute([':gid' => $activeGroupId]);
    $participants = $stmtP->fetchAll();
    foreach ($participants as $p) {
        $participantsById[(int)$p['id']] = $p;
    }

    $stmtE = $pdo->prepare('SELECT * FROM mc_split_expenses WHERE group_id = :gid ORDER BY expense_date DESC, id DESC');
    $stmtE->execute([':gid' => $activeGroupId]);
    $expenses = $stmtE->fetchAll();

    $stmtS = $pdo->prepare('SELECT * FROM mc_split_shares WHERE group_id = :gid ORDER BY id ASC');
    $stmtS->execute([':gid' => $activeGroupId]);
    foreach ($stmtS->fetchAll() as $sh) {
        $sharesByExpense[(int)$sh['expense_id']][] = $sh;
    }

    $summary = calculateSplitSummary($participants, $expenses, $sharesByExpense);

    $editExpId = isset($_GET['edit_expense']) ? (int)$_GET['edit_expense'] : 0;
    if ($editExpId > 0) {
        foreach ($expenses as $exp) {
            if ((int)$exp['id'] === $editExpId && (int)$exp['is_settlement'] === 0) {
                $editExpense = $exp;
                foreach ($sharesByExpense[$editExpId] ?? [] as $sh) {
                    $editExpenseShares[(int)$sh['participant_id']] = $sh;
                }
                break;
            }
        }
    }

    $curr = (string)$activeGroup['currency'];
    $lines = [];
    $lines[] = '# ' . (string)$activeGroup['icon'] . ' Kostenabrechnung: ' . (string)$activeGroup['title'];
    if (trim((string)$activeGroup['description']) !== '') {
        $lines[] = '_' . trim((string)$activeGroup['description']) . '_';
    }
    $lines[] = 'Stand: ' . date('d.m.Y H:i') . ' Uhr | Gesamtausgaben: **' . formatSplitMoney($summary['total_spent_cents'], $curr) . '** (' . $summary['expense_count'] . ' Kostenpunkte)';
    $lines[] = '';
    $lines[] = '## 🤝 Ausgleichszahlungen (Wer schuldet wem wie viel?)';
    if (empty($summary['settlements'])) {
        $lines[] = '- ✅ **Alles ausgeglichen!** Niemand schuldet jemandem Geld.';
    } else {
        foreach ($summary['settlements'] as $st) {
            $lines[] = '- 👉 **' . $st['from_name'] . '** zahlt **' . formatSplitMoney($st['amount_cents'], $curr) . '** an **' . $st['to_name'] . '**';
        }
    }
    $lines[] = '';
    $lines[] = '## 📊 Einzel-Bilanz pro Person';
    foreach ($summary['balances'] as $b) {
        $netSign = $b['net_cents'] > 0 ? '+' : '';
        $lines[] = sprintf(
            '- **%s**: Ausgelegt %s | Eigener Anteil %s | Saldo: **%s%s**',
            $b['display_name'],
            formatSplitMoney($b['paid_cents'], $curr),
            formatSplitMoney($b['consumed_cents'], $curr),
            $netSign,
            formatSplitMoney($b['net_cents'], $curr)
        );
    }
    if (!empty($expenses)) {
        $lines[] = '';
        $lines[] = '## 🧾 Erfasste Kostenpunkte';
        foreach ($expenses as $exp) {
            $payerName = $participantsById[(int)$exp['paid_by_participant_id']]['display_name'] ?? 'Unbekannt';
            $dtFmt = date('d.m.Y', strtotime((string)$exp['expense_date']));
            if ((int)$exp['is_settlement'] === 1) {
                $lines[] = sprintf('- [%s] 💸 %s: **%s**', $dtFmt, (string)$exp['title'], formatSplitMoney((int)$exp['amount_cents'], $curr));
            } else {
                $profNames = [];
                foreach ($sharesByExpense[(int)$exp['id']] ?? [] as $sh) {
                    $pName = $participantsById[(int)$sh['participant_id']]['display_name'] ?? '?';
                    $profNames[] = $pName . ' (' . formatSplitMoney((int)$sh['share_cents'], $curr) . ')';
                }
                $fxNote = '';
                if (!empty($exp['original_currency']) && (int)$exp['original_amount_cents'] > 0) {
                    $fxNote = ' [Original: ' . formatSplitMoney((int)$exp['original_amount_cents'], (string)$exp['original_currency']) . ']';
                }
                $lines[] = sprintf(
                    '- [%s] **%s** – **%s**%s (ausgelegt von *%s* | Profiteure: %s)',
                    $dtFmt,
                    (string)$exp['title'],
                    formatSplitMoney((int)$exp['amount_cents'], $curr),
                    $fxNote,
                    $payerName,
                    implode(', ', $profNames)
                );
            }
        }
    }
    $summaryMarkdown = implode("\n", $lines);
}

$userFolders = $isLoggedIn ? getMergedFoldersList($pdo, $userId) : [];

// Eigene unverschlüsselte Bild-/PDF-Dateien des Benutzers für die optionale Cloud-Beleg-Verknüpfung laden
$userReceiptFiles = [];
if ($isLoggedIn) {
    try {
        $stmtRf = $pdo->prepare(
            "SELECT id, original_name, folder_path, file_size
             FROM files
             WHERE user_id = :uid AND is_encrypted = 0 AND deleted_at IS NULL
               AND (mime_type LIKE 'image/%' OR mime_type = 'application/pdf')
             ORDER BY uploaded_at DESC
             LIMIT 60"
        );
        $stmtRf->execute([':uid' => $userId]);
        $userReceiptFiles = $stmtRf->fetchAll();
    } catch (Throwable) {
        $userReceiptFiles = [];
    }
}

$pageTitle = 'Kostenaufteilung & Reisekasse';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <!-- Kopfbereich -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-start sm:items-center gap-3.5">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white flex items-center justify-center text-2xl shadow-sm shrink-0">
                🧾
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">Kostenaufteilung &amp; Reisekasse</h1>
                    <?php if ($isGuestMode): ?>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 dark:bg-blue-950/80 text-blue-700 dark:text-blue-300 border border-blue-200/70 dark:border-blue-800/70">
                            🔗 Gäste-Zugang (<?= ((int)($activeGroup['guest_can_add'] ?? 1) === 1) ? 'Lesen &amp; Eintragen' : 'Nur Lesen' ?>)
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/70">
                            Splitwise / Tricount-Prinzip &middot; 100 % Privat
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                    Reisen, Ausflüge &amp; Gruppenprojekte abrechnen: Auslagen, Fremdwährungen &amp; Belege erfassen, Profiteure wählen und sehen, <strong>wer wem wie viel schuldet</strong>.
                </p>
            </div>
        </div>
        <?php if ($isLoggedIn): ?>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <button
                    type="button"
                    onclick="document.getElementById('newGroupModal').classList.remove('hidden')"
                    class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold shadow-xs transition-colors cursor-pointer"
                >
                    <span>➕</span>
                    <span>Neues Projekt / Reise</span>
                </button>
                <a
                    href="modules.php"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200/80 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors"
                >
                    &larr; Module
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (empty($userGroups)): ?>
        <!-- Leerzustand: Noch kein Projekt angelegt -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 sm:p-12 text-center shadow-xs max-w-2xl mx-auto">
            <div class="w-16 h-16 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200/70 dark:border-emerald-800/70 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-3xl mx-auto mb-4">
                ✈️
            </div>
            <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white mb-2">Starten Sie Ihre erste Gruppen-Kostenabrechnung</h2>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                Legen Sie eine Reise, ein Event oder ein Projekt an (z.&nbsp;B. <em>„Wochenendtrip Prag“</em>), fügen Sie Cloud-Mitglieder und beliebig viele nicht registrierte Personen per Freitext hinzu und erfassen Sie Kostenpunkte wie <em>„Busfahrt“</em> oder <em>„Essen am Donnerstag“</em>.
            </p>
            <button
                type="button"
                onclick="document.getElementById('newGroupModal').classList.remove('hidden')"
                class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-sm font-bold shadow-sm transition-colors cursor-pointer"
            >
                <span>➕</span>
                <span>Jetzt erstes Projekt / Reise erstellen</span>
            </button>
        </div>
    <?php else: ?>
        <?php if ($isLoggedIn && count($userGroups) > 0): ?>
            <!-- Projekt-/Reise-Auswahlleiste -->
            <div class="flex items-center gap-2 overflow-x-auto pb-1">
                <?php foreach ($userGroups as $grp): ?>
                    <?php $isSelectedGrp = ((int)$grp['id'] === $activeGroupId); ?>
                    <a
                        href="split.php?group=<?= (int)$grp['id'] ?>"
                        class="inline-flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl border text-xs sm:text-sm font-bold shrink-0 transition-all <?= $isSelectedGrp ? 'bg-brand-600 text-white border-brand-600 shadow-xs' : 'mc-card bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-800 hover:border-brand-400' ?>"
                    >
                        <span class="text-base"><?= e((string)$grp['icon']) ?></span>
                        <span><?= e((string)$grp['title']) ?></span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $isSelectedGrp ? 'bg-white/20 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400' ?>">
                            <?= (int)$grp['participant_count'] ?> Pers. &middot; <?= e(formatSplitMoney((int)$grp['total_spent_cents'], (string)$grp['currency'])) ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($activeGroup !== null && $summary !== null): ?>
            <?php
            $curr = (string)$activeGroup['currency'];
            $canModifyThisGroup = $isLoggedIn || ((int)($activeGroup['guest_can_add'] ?? 1) === 1);
            $activeShareToken = trim((string)($activeGroup['share_token'] ?? ''));
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $baseDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/split.php'))), '/');
            $guestShareUrl = $activeShareToken !== ''
                ? $scheme . '://' . $host . $baseDir . '/split.php?token=' . rawurlencode($activeShareToken)
                : '';
            ?>

            <!-- =========================================================================
                 1. PROJEKT-ÜBERSICHT, GÄSTE-LINK/QR-CODE & TEILNEHMER-LEISTE
                 ========================================================================= -->
            <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-4">
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-slate-200/80 dark:border-slate-800">
                    <div>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <span class="text-2xl"><?= e((string)$activeGroup['icon']) ?></span>
                            <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white"><?= e((string)$activeGroup['title']) ?></h2>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300">
                                Gesamtkosten: <?= e(formatSplitMoney($summary['total_spent_cents'], $curr)) ?>
                            </span>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">
                                <?= (int)$summary['expense_count'] ?> Kostenpunkte
                            </span>
                        </div>
                        <?php if (trim((string)$activeGroup['description']) !== ''): ?>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= e((string)$activeGroup['description']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <?php if ($isLoggedIn): ?>
                            <button
                                type="button"
                                onclick="document.getElementById('shareGroupModal').classList.remove('hidden')"
                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl <?= $activeShareToken !== '' ? 'bg-emerald-600 hover:bg-emerald-500 text-white' : 'bg-blue-50 dark:bg-blue-950/60 hover:bg-blue-100 text-blue-700 dark:text-blue-300 border border-blue-200/80 dark:border-blue-800/80' ?> text-xs font-bold transition-colors cursor-pointer"
                                title="Öffentlichen Gäste-Link &amp; QR-Code für nicht registrierte Mitreisende verwalten"
                            >
                                <span>🔗</span>
                                <span><?= $activeShareToken !== '' ? 'Gäste-Link & QR aktiv' : 'Gäste-Link / QR-Code' ?></span>
                            </button>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="copySplitSummaryToClipboard()"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-colors cursor-pointer"
                            title="Komplette Abrechnung in die Zwischenablage kopieren (z. B. für WhatsApp oder Chat)"
                        >
                            <span>📋</span>
                            <span id="copySplitBtnLabel">Zusammenfassung kopieren</span>
                        </button>

                        <?php if ($isLoggedIn): ?>
                            <button
                                type="button"
                                onclick="document.getElementById('cloudSaveModal').classList.remove('hidden')"
                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800/80 text-xs font-bold transition-colors cursor-pointer"
                            >
                                <span>☁️</span>
                                <span>In Cloud speichern</span>
                            </button>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="window.print()"
                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold transition-colors cursor-pointer"
                        >
                            <span>🖨️</span>
                            <span>Drucken / PDF</span>
                        </button>

                        <?php if ($isLoggedIn && ((int)$activeGroup['owner_id'] === $userId || isAdmin())): ?>
                            <form action="split.php" method="post" class="m-0" onsubmit="return confirm('Möchten Sie das gesamte Projekt „<?= e(addslashes((string)$activeGroup['title'])) ?>“ inklusive aller Kostenpunkte wirklich löschen?');">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_group">
                                <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-1 px-2.5 py-2 rounded-xl bg-red-50 dark:bg-red-950/40 hover:bg-red-100 dark:hover:bg-red-900/60 text-red-600 dark:text-red-400 text-xs font-bold transition-colors cursor-pointer"
                                    title="Projekt löschen"
                                >
                                    🗑️
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Teilnehmer-Chips & Schnell-Hinzufügen -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-bold text-slate-500 dark:text-slate-400 mr-1">👥 Teilnehmer (<?= count($participants) ?>):</span>
                        <?php foreach ($participants as $part): ?>
                            <?php $isCloudUser = !empty($part['user_id']); ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold <?= $isCloudUser ? 'bg-brand-50 dark:bg-brand-950/60 text-brand-700 dark:text-brand-300 border border-brand-200/70 dark:border-brand-800/70' : 'bg-amber-50 dark:bg-amber-950/50 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/70' ?>">
                                <span><?= $isCloudUser ? '👤' : '🏷️' ?></span>
                                <span><?= e((string)$part['display_name']) ?></span>
                                <?php if (!$isCloudUser): ?>
                                    <span class="text-[10px] opacity-75">(Freitext)</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canModifyThisGroup): ?>
                        <button
                            type="button"
                            onclick="document.getElementById('addParticipantsBox').classList.toggle('hidden')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold shrink-0 self-start sm:self-auto cursor-pointer"
                        >
                            <span>➕</span>
                            <span>Personen hinzufügen</span>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($canModifyThisGroup): ?>
                    <form id="addParticipantsBox" action="split.php<?= $guestToken !== '' ? '?token=' . rawurlencode($guestToken) : '' ?>" method="post" class="hidden p-4 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="add_participants">
                        <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">
                        <?php if ($guestToken !== ''): ?>
                            <input type="hidden" name="token" value="<?= e($guestToken) ?>">
                        <?php endif; ?>

                        <div class="grid grid-cols-1 <?= $isLoggedIn ? 'md:grid-cols-2' : '' ?> gap-4">
                            <?php if ($isLoggedIn): ?>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                        👤 Registrierte Cloud-Nutzer hinzufügen
                                    </label>
                                    <div class="flex flex-wrap gap-1.5 max-h-28 overflow-y-auto p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700">
                                        <?php foreach ($allCloudUsers as $cUid => $cUname): ?>
                                            <?php
                                            $alreadyIn = false;
                                            foreach ($participants as $pCheck) {
                                                if ((int)($pCheck['user_id'] ?? 0) === $cUid) {
                                                    $alreadyIn = true;
                                                    break;
                                                }
                                            }
                                            ?>
                                            <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-medium cursor-pointer <?= $alreadyIn ? 'opacity-50 bg-slate-100 dark:bg-slate-800 border-slate-200 dark:border-slate-700' : 'bg-slate-50 dark:bg-slate-800/80 border-slate-200 dark:border-slate-700 hover:border-brand-400' ?>">
                                                <input type="checkbox" name="cloud_user_ids[]" value="<?= $cUid ?>" <?= $alreadyIn ? 'disabled checked' : '' ?> class="rounded text-brand-600">
                                                <span><?= e($cUname) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                    🏷️ Nicht registrierte Personen (Freitext, Komma-getrennt)
                                </label>
                                <input
                                    type="text"
                                    name="guest_names"
                                    placeholder="z. B. Max, Lisa, Onkel Bernd, Sarah…"
                                    class="form-control text-xs sm:text-sm"
                                >
                                <p class="text-[11px] text-slate-400 mt-1">
                                    Tipp: Sie können neue Freitext-Namen auch direkt unten beim Eintragen eines Kostenpunkts eingeben!
                                </p>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="submit" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold cursor-pointer">
                                Personen zum Projekt hinzufügen
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- =========================================================================
                 2. HAUPT-GRID: LINKS KOSTENPUNKT ERFASSEN / RECHTS ZUSAMMENFASSUNG & SALDEN
                 ========================================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                <?php if ($canModifyThisGroup): ?>
                    <!-- LINKE SPALTE (5/12): KOSTENPUNKT HINZUFÜGEN / BEARBEITEN -->
                    <div class="lg:col-span-5 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
                        <div class="flex items-center justify-between gap-2 mb-4">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                                <?= $editExpense !== null ? '✏️ Kostenpunkt bearbeiten' : '➕ Neuer Kostenpunkt' ?>
                            </h3>
                            <?php if ($editExpense !== null): ?>
                                <a href="split.php?<?= $guestToken !== '' ? 'token=' . rawurlencode($guestToken) : 'group=' . (int)$activeGroup['id'] ?>" class="text-xs font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-white">
                                    Abbrechen &times;
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php
                        $editOrigCurr = ($editExpense !== null && !empty($editExpense['original_currency']))
                            ? (string)$editExpense['original_currency']
                            : $curr;
                        $editDisplayCents = ($editExpense !== null && (int)($editExpense['original_amount_cents'] ?? 0) > 0)
                            ? (int)$editExpense['original_amount_cents']
                            : ($editExpense !== null ? (int)$editExpense['amount_cents'] : 0);
                        $editExRate = ($editExpense !== null && (float)($editExpense['exchange_rate'] ?? 1.0) > 0)
                            ? (float)$editExpense['exchange_rate']
                            : 1.0;
                        ?>

                        <form action="split.php<?= $guestToken !== '' ? '?token=' . rawurlencode($guestToken) : '' ?>" method="post" enctype="multipart/form-data" class="space-y-4" id="expenseForm">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="save_expense">
                            <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">
                            <?php if ($guestToken !== ''): ?>
                                <input type="hidden" name="token" value="<?= e($guestToken) ?>">
                            <?php endif; ?>
                            <?php if ($editExpense !== null): ?>
                                <input type="hidden" name="expense_id" value="<?= (int)$editExpense['id'] ?>">
                            <?php endif; ?>

                            <!-- Bezeichnung, Betrag & Währungswahl -->
                            <div class="space-y-3">
                                <div>
                                    <label class="form-label">Wofür? (Kostenpunkt) *</label>
                                    <input
                                        type="text"
                                        name="title"
                                        required
                                        value="<?= e((string)($editExpense['title'] ?? '')) ?>"
                                        placeholder="z. B. Busfahrt, Essen am Donnerstag…"
                                        class="form-control"
                                    >
                                </div>

                                <div class="grid grid-cols-3 gap-2.5">
                                    <div class="col-span-2">
                                        <label class="form-label">Betrag *</label>
                                        <input
                                            type="text"
                                            name="amount"
                                            id="expenseAmountInput"
                                            required
                                            inputmode="decimal"
                                            oninput="updateLiveSharePreview()"
                                            value="<?= $editDisplayCents > 0 ? e(number_format($editDisplayCents / 100, 2, ',', '')) : '' ?>"
                                            placeholder="0,00"
                                            class="form-control font-bold"
                                        >
                                    </div>
                                    <div>
                                        <label class="form-label">💱 Währung</label>
                                        <select
                                            name="expense_currency"
                                            id="expenseCurrencySelect"
                                            onchange="onExpenseCurrencyChange()"
                                            class="form-control font-bold"
                                        >
                                            <?php foreach ($currencies as $cSym): ?>
                                                <option value="<?= e($cSym) ?>" <?= $editOrigCurr === $cSym ? 'selected' : '' ?>>
                                                    <?= e($cSym) ?><?= $cSym === $curr ? ' (Standard)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <!-- 💱 Fremdwährungs-Umrechner (erscheint automatisch, wenn Belegwährung != Gruppenwährung) -->
                                <div id="fxConverterBar" class="<?= $editOrigCurr !== $curr ? '' : 'hidden' ?> p-3 rounded-xl bg-amber-50/80 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/70 space-y-2">
                                    <div class="flex items-center justify-between gap-2 text-xs">
                                        <span class="font-bold text-amber-900 dark:text-amber-300">
                                            💱 Fremdwährungs-Umrechnung (<span id="fxFromCurrLabel"><?= e($editOrigCurr) ?></span> &rarr; <?= e($curr) ?>)
                                        </span>
                                        <span id="fxConvertedPreview" class="font-extrabold text-emerald-700 dark:text-emerald-400"></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <label class="text-[11px] text-slate-600 dark:text-slate-300 shrink-0">Kurs (1 <span id="fxRateUnitLabel"><?= e($editOrigCurr) ?></span> =):</label>
                                        <input
                                            type="text"
                                            name="exchange_rate"
                                            id="exchangeRateInput"
                                            inputmode="decimal"
                                            oninput="updateLiveSharePreview()"
                                            value="<?= e(rtrim(rtrim(number_format($editExRate, 5, ',', ''), '0'), ',')) ?>"
                                            class="form-control text-xs font-bold py-1 px-2 w-28"
                                        >
                                        <span class="text-xs font-bold text-slate-600 dark:text-slate-300"><?= e($curr) ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Ausgelegt von (Zahler) -->
                            <div>
                                <label class="form-label">💳 Ausgelegt / bezahlt von *</label>
                                <select
                                    name="paid_by_participant_id"
                                    id="paidBySelect"
                                    onchange="document.getElementById('newPayerFreetextWrap').classList.toggle('hidden', this.value !== 'new_freetext')"
                                    class="form-control"
                                >
                                    <?php
                                    $defaultPayerId = $editExpense !== null ? (int)$editExpense['paid_by_participant_id'] : 0;
                                    if ($defaultPayerId === 0 && $isLoggedIn) {
                                        foreach ($participants as $p) {
                                            if ((int)($p['user_id'] ?? 0) === $userId) {
                                                $defaultPayerId = (int)$p['id'];
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php foreach ($participants as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $defaultPayerId ? 'selected' : '' ?>>
                                            <?= e((string)$p['display_name']) ?><?= !empty($p['user_id']) ? ' (Cloud-Nutzer)' : ' (Freitext)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="new_freetext">➕ Neue Person per Freitext als Zahler eintragen…</option>
                                </select>
                                <div id="newPayerFreetextWrap" class="hidden mt-2">
                                    <input
                                        type="text"
                                        name="new_payer_freetext"
                                        placeholder="Name der Person, die ausgelegt hat (z. B. Lukas)"
                                        class="form-control text-xs"
                                    >
                                </div>
                            </div>

                            <!-- Kategorie & Datum -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="form-label">Kategorie</label>
                                    <select name="category" class="form-control">
                                        <?php $selCat = (string)($editExpense['category'] ?? 'food'); ?>
                                        <?php foreach ($splitCategories as $catKey => $catMeta): ?>
                                            <option value="<?= e($catKey) ?>" <?= $selCat === $catKey ? 'selected' : '' ?>>
                                                <?= e($catMeta['icon'] . ' ' . $catMeta['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Datum</label>
                                    <input
                                        type="date"
                                        name="expense_date"
                                        value="<?= e((string)($editExpense['expense_date'] ?? date('Y-m-d'))) ?>"
                                        class="form-control"
                                    >
                                </div>
                            </div>

                            <!-- Aufteilungs-Modus & Profiteure -->
                            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <label class="text-xs font-bold text-slate-800 dark:text-slate-100">
                                        👥 Profiteure (Auf wen wird aufgeteilt?) *
                                    </label>
                                    <div class="flex items-center gap-1.5 text-[11px]">
                                        <button type="button" onclick="toggleAllProfiteure(true)" class="px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 font-semibold hover:bg-brand-600 hover:text-white transition-colors cursor-pointer">
                                            Alle
                                        </button>
                                        <button type="button" onclick="toggleAllProfiteure(false)" class="px-2 py-0.5 rounded bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 font-semibold hover:bg-slate-300 transition-colors cursor-pointer">
                                            Keine
                                        </button>
                                    </div>
                                </div>

                                <?php $curMode = (string)($editExpense['split_mode'] ?? 'equal'); ?>
                                <div class="grid grid-cols-3 gap-1.5 p-1 rounded-xl bg-slate-200/70 dark:bg-slate-900">
                                    <button
                                        type="button"
                                        data-split-mode-btn="equal"
                                        onclick="setSplitMode('equal')"
                                        class="py-1.5 px-2 rounded-lg text-[11px] font-bold transition-all cursor-pointer <?= $curMode === 'equal' ? 'bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 shadow-2xs' : 'text-slate-600 dark:text-slate-400' ?>"
                                    >
                                        ⚖️ Gleichmäßig
                                    </button>
                                    <button
                                        type="button"
                                        data-split-mode-btn="shares"
                                        onclick="setSplitMode('shares')"
                                        class="py-1.5 px-2 rounded-lg text-[11px] font-bold transition-all cursor-pointer <?= $curMode === 'shares' ? 'bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 shadow-2xs' : 'text-slate-600 dark:text-slate-400' ?>"
                                    >
                                        🔢 Anteile (1x, 2x)
                                    </button>
                                    <button
                                        type="button"
                                        data-split-mode-btn="exact"
                                        onclick="setSplitMode('exact')"
                                        class="py-1.5 px-2 rounded-lg text-[11px] font-bold transition-all cursor-pointer <?= $curMode === 'exact' ? 'bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 shadow-2xs' : 'text-slate-600 dark:text-slate-400' ?>"
                                    >
                                        💶 Exakte Beträge
                                    </button>
                                </div>
                                <input type="hidden" name="split_mode" id="splitModeInput" value="<?= e($curMode) ?>">

                                <div class="space-y-1.5 max-h-60 overflow-y-auto pr-1" id="profiteureListContainer">
                                    <?php foreach ($participants as $p): ?>
                                        <?php
                                        $pid = (int)$p['id'];
                                        $isChecked = $editExpense !== null
                                            ? isset($editExpenseShares[$pid])
                                            : true;
                                        $weightVal = isset($editExpenseShares[$pid])
                                            ? rtrim(rtrim(number_format((float)$editExpenseShares[$pid]['weight'], 2, '.', ''), '0'), '.')
                                            : '1';
                                        $exactVal = isset($editExpenseShares[$pid])
                                            ? number_format(((int)$editExpenseShares[$pid]['share_cents']) / 100, 2, ',', '')
                                            : '';
                                        ?>
                                        <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-700/80">
                                            <label class="flex items-center gap-2.5 min-w-0 flex-1 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    name="profiteur_ids[]"
                                                    value="<?= $pid ?>"
                                                    data-prof-checkbox="<?= $pid ?>"
                                                    onchange="updateLiveSharePreview()"
                                                    <?= $isChecked ? 'checked' : '' ?>
                                                    class="w-4 h-4 rounded text-brand-600 focus:ring-brand-500"
                                                >
                                                <span class="text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-100 truncate">
                                                    <?= e((string)$p['display_name']) ?>
                                                </span>
                                                <?php if (empty($p['user_id'])): ?>
                                                    <span class="text-[10px] px-1.5 py-0.2 rounded bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300">Freitext</span>
                                                <?php endif; ?>
                                            </label>

                                            <div data-mode-field="shares" class="<?= $curMode === 'shares' ? 'flex' : 'hidden' ?> items-center gap-1 shrink-0">
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    min="0.5"
                                                    name="profiteur_weights[<?= $pid ?>]"
                                                    data-prof-weight="<?= $pid ?>"
                                                    oninput="updateLiveSharePreview()"
                                                    value="<?= e($weightVal) ?>"
                                                    class="w-16 px-2 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-bold text-right"
                                                >
                                                <span class="text-[11px] text-slate-400">×</span>
                                            </div>

                                            <div data-mode-field="exact" class="<?= $curMode === 'exact' ? 'flex' : 'hidden' ?> items-center gap-1 shrink-0">
                                                <input
                                                    type="text"
                                                    inputmode="decimal"
                                                    name="profiteur_exact[<?= $pid ?>]"
                                                    data-prof-exact="<?= $pid ?>"
                                                    oninput="updateLiveSharePreview()"
                                                    value="<?= e($exactVal) ?>"
                                                    placeholder="0,00"
                                                    class="w-20 px-2 py-1 rounded-lg border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs font-bold text-right"
                                                >
                                                <span class="text-[11px] text-slate-400"><?= e($curr) ?></span>
                                            </div>

                                            <span
                                                data-prof-preview="<?= $pid ?>"
                                                class="text-xs font-bold text-emerald-700 dark:text-emerald-400 shrink-0 min-w-[62px] text-right"
                                            ></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="pt-2 border-t border-slate-200/70 dark:border-slate-700/70">
                                    <label class="block text-[11px] font-bold text-slate-600 dark:text-slate-300 mb-1">
                                        ➕ Weitere Profiteure direkt per Freitext ergänzen (auch nicht registrierte Nutzer):
                                    </label>
                                    <input
                                        type="text"
                                        name="extra_profiteure_freetext"
                                        id="extraProfiteureInput"
                                        oninput="updateLiveSharePreview()"
                                        placeholder="Namen mit Komma trennen, z. B. Max, Lisa, Tom…"
                                        class="form-control text-xs"
                                    >
                                </div>
                            </div>

                            <!-- 📎 Kassenbon / Beleg-Foto oder PDF anhängen -->
                            <div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 space-y-2.5">
                                <label class="block text-xs font-bold text-slate-800 dark:text-slate-100">
                                    📎 Kassenbon / Quittung anhängen (optional)
                                </label>
                                <div>
                                    <label class="block text-[11px] text-slate-500 dark:text-slate-400 mb-1">
                                        📷 Foto aufnehmen oder Bild/PDF hochladen (max. 15 MB):
                                    </label>
                                    <input
                                        type="file"
                                        name="receipt_upload"
                                        accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                                        class="block w-full text-xs text-slate-600 dark:text-slate-300 file:mr-2.5 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-brand-50 dark:file:bg-brand-950/80 file:text-brand-700 dark:file:text-brand-300 hover:file:bg-brand-100 cursor-pointer"
                                    >
                                </div>
                                <?php if ($isLoggedIn && !empty($userReceiptFiles)): ?>
                                    <div>
                                        <label class="block text-[11px] text-slate-500 dark:text-slate-400 mb-1">
                                            ☁️ Oder bestehendes Foto/PDF aus Ihrer Cloud verknüpfen:
                                        </label>
                                        <select name="receipt_cloud_file_id" class="form-control text-xs py-1.5">
                                            <option value="0">— Keine Cloud-Datei verknüpfen —</option>
                                            <?php $selRecFid = (int)($editExpense['receipt_file_id'] ?? 0); ?>
                                            <?php foreach ($userReceiptFiles as $rf): ?>
                                                <option value="<?= (int)$rf['id'] ?>" <?= $selRecFid === (int)$rf['id'] ? 'selected' : '' ?>>
                                                    📄 <?= e((string)$rf['original_name']) ?> <?= $rf['folder_path'] !== '' ? '(' . e((string)$rf['folder_path']) . ')' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Optionale Notiz -->
                            <div>
                                <label class="form-label">Notiz / Details (optional)</label>
                                <input
                                    type="text"
                                    name="note"
                                    value="<?= e((string)($editExpense['note'] ?? '')) ?>"
                                    placeholder="z. B. Gruppenticket inkl. Sitzplatzreservierung"
                                    class="form-control text-xs"
                                >
                            </div>

                            <button
                                type="submit"
                                class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-sm font-bold shadow-xs transition-colors cursor-pointer"
                            >
                                <?= $editExpense !== null ? '💾 Änderungen am Kostenpunkt speichern' : '➕ Kostenpunkt eintragen & berechnen' ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- RECHTE SPALTE: WER SCHULDET WEM WIE VIEL + EINZEL-SALDEN + KOSTENLISTE -->
                <div class="<?= $canModifyThisGroup ? 'lg:col-span-7' : 'lg:col-span-12' ?> space-y-6">

                    <!-- A) ZUSAMMENFASSUNG: WER SCHULDET WEM WIE VIEL? -->
                    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border-2 border-emerald-500/40 dark:border-emerald-500/30 p-5 sm:p-6 shadow-xs">
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <div>
                                <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>🤝</span>
                                    <span>Endabrechnung: Wer schuldet wem wie viel?</span>
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Automatisch optimiert auf die minimal mögliche Anzahl an Ausgleichszahlungen.
                                </p>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= empty($summary['settlements']) ? 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300' : 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300' ?>">
                                <?= empty($summary['settlements']) ? '✅ Alles ausgeglichen' : count($summary['settlements']) . ' Ausgleichszahlung(en) offen' ?>
                            </span>
                        </div>

                        <?php if (empty($summary['settlements'])): ?>
                            <div class="p-4 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/70 dark:border-emerald-800/60 text-center">
                                <p class="text-sm font-bold text-emerald-800 dark:text-emerald-300">
                                    🎉 Aktuell sind alle Konten ausgeglichen – niemand schuldet jemandem Geld!
                                </p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-2.5">
                                <?php foreach ($summary['settlements'] as $st): ?>
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700">
                                        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm">
                                            <span class="font-bold text-red-600 dark:text-red-400 px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/50 border border-red-200/60 dark:border-red-800/60">
                                                <?= e($st['from_name']) ?>
                                            </span>
                                            <span class="text-slate-400 font-semibold">schuldet / zahlt an</span>
                                            <span class="font-bold text-emerald-700 dark:text-emerald-300 px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200/60 dark:border-emerald-800/60">
                                                <?= e($st['to_name']) ?>
                                            </span>
                                        </div>

                                        <div class="flex items-center justify-between sm:justify-end gap-3">
                                            <span class="text-base sm:text-lg font-extrabold text-slate-900 dark:text-white">
                                                <?= e(formatSplitMoney($st['amount_cents'], $curr)) ?>
                                            </span>
                                            <?php if ($canModifyThisGroup): ?>
                                                <form action="split.php<?= $guestToken !== '' ? '?token=' . rawurlencode($guestToken) : '' ?>" method="post" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                    <input type="hidden" name="action" value="record_settlement">
                                                    <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">
                                                    <?php if ($guestToken !== ''): ?>
                                                        <input type="hidden" name="token" value="<?= e($guestToken) ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="from_participant_id" value="<?= (int)$st['from_id'] ?>">
                                                    <input type="hidden" name="to_participant_id" value="<?= (int)$st['to_id'] ?>">
                                                    <input type="hidden" name="amount_cents" value="<?= (int)$st['amount_cents'] ?>">
                                                    <button
                                                        type="submit"
                                                        title="Markiert diese Schuld als beglichen und trägt eine Ausgleichszahlung ein"
                                                        class="px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-bold shadow-2xs transition-colors cursor-pointer"
                                                    >
                                                        ✅ Als bezahlt verbuchen
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- B) EINZEL-SALDEN ALLER TEILNEHMER -->
                    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
                        <h3 class="text-base font-bold text-slate-900 dark:text-white mb-1">📊 Einzel-Bilanz pro Teilnehmer</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                            Gegenüberstellung: <strong>Selbst ausgelegt</strong> minus <strong>eigener Anteil als Profiteur</strong> ergibt den Netto-Saldo.
                        </p>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 dark:border-slate-800 text-[11px] font-bold uppercase text-slate-400">
                                        <th class="py-2 pr-3">Person</th>
                                        <th class="py-2 px-3 text-right">Ausgelegt</th>
                                        <th class="py-2 px-3 text-right">Eigener Anteil</th>
                                        <th class="py-2 pl-3 text-right">Netto-Saldo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                                    <?php foreach ($summary['balances'] as $b): ?>
                                        <?php
                                        $net = $b['net_cents'];
                                        $isPositive = $net > 1;
                                        $isNegative = $net < -1;
                                        ?>
                                        <tr>
                                            <td class="py-2.5 pr-3 font-bold text-slate-900 dark:text-white">
                                                <div class="flex items-center gap-1.5">
                                                    <span><?= $b['user_id'] !== null ? '👤' : '🏷️' ?></span>
                                                    <span><?= e($b['display_name']) ?></span>
                                                </div>
                                            </td>
                                            <td class="py-2.5 px-3 text-right font-medium text-slate-700 dark:text-slate-300">
                                                <?= e(formatSplitMoney($b['paid_cents'], $curr)) ?>
                                            </td>
                                            <td class="py-2.5 px-3 text-right font-medium text-slate-700 dark:text-slate-300">
                                                <?= e(formatSplitMoney($b['consumed_cents'], $curr)) ?>
                                            </td>
                                            <td class="py-2.5 pl-3 text-right font-extrabold">
                                                <?php if ($isPositive): ?>
                                                    <span class="px-2 py-0.5 rounded-lg bg-emerald-100 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300">
                                                        +<?= e(formatSplitMoney($net, $curr)) ?> (bekommt)
                                                    </span>
                                                <?php elseif ($isNegative): ?>
                                                    <span class="px-2 py-0.5 rounded-lg bg-red-100 dark:bg-red-950/70 text-red-700 dark:text-red-300">
                                                        <?= e(formatSplitMoney($net, $curr)) ?> (schuldet)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-slate-400">±0,00 <?= e($curr) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- C) CHRONOLOGISCHE LISTE ALLER KOSTENPUNKTE -->
                    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
                        <div class="flex items-center justify-between gap-2 mb-4">
                            <h3 class="text-base font-bold text-slate-900 dark:text-white">
                                🧾 Erfasste Kostenpunkte (<?= count($expenses) ?>)
                            </h3>
                        </div>

                        <?php if (empty($expenses)): ?>
                            <div class="py-8 text-center text-xs sm:text-sm text-slate-400">
                                Noch keine Kostenpunkte eingetragen. Fügen Sie links Ihren ersten Posten (z.&nbsp;B. <em>„Busfahrt“</em> oder <em>„Essen am Donnerstag“</em>) hinzu.
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($expenses as $exp): ?>
                                    <?php
                                    $eid          = (int)$exp['id'];
                                    $isSettlement = ((int)$exp['is_settlement'] === 1);
                                    $catKey       = (string)$exp['category'];
                                    $catIcon      = $isSettlement ? '💸' : ($splitCategories[$catKey]['icon'] ?? '🧾');
                                    $payerName    = $participantsById[(int)$exp['paid_by_participant_id']]['display_name'] ?? 'Unbekannt';
                                    $expShares    = $sharesByExpense[$eid] ?? [];
                                    $hasReceipt   = !empty($exp['receipt_file_id']) || !empty($exp['receipt_stored_name']);
                                    $hasFx        = !empty($exp['original_currency']) && (int)$exp['original_amount_cents'] > 0;
                                    $recUrl       = 'split.php?view_receipt=' . $eid . ($guestToken !== '' ? '&token=' . rawurlencode($guestToken) : '');
                                    ?>
                                    <div class="p-3.5 rounded-xl border <?= $isSettlement ? 'bg-emerald-50/40 dark:bg-emerald-950/20 border-emerald-200/70 dark:border-emerald-800/60' : 'bg-slate-50/70 dark:bg-slate-800/50 border-slate-200/80 dark:border-slate-700/80' ?> flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div class="flex items-start gap-3 min-w-0">
                                            <div class="w-10 h-10 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 flex items-center justify-center text-lg shrink-0">
                                                <?= e($catIcon) ?>
                                            </div>
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-bold text-slate-900 dark:text-white"><?= e((string)$exp['title']) ?></span>
                                                    <span class="text-[11px] text-slate-400"><?= e(date('d.m.Y', strtotime((string)$exp['expense_date']))) ?></span>
                                                    <?php if ($hasFx): ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300">
                                                            💱 Original: <?= e(formatSplitMoney((int)$exp['original_amount_cents'], (string)$exp['original_currency'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($hasReceipt): ?>
                                                        <button
                                                            type="button"
                                                            onclick="openReceiptModal('<?= e(addslashes($recUrl)) ?>', '<?= e(addslashes((string)$exp['title'])) ?>')"
                                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 dark:bg-indigo-950/80 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-200 transition-colors cursor-pointer"
                                                        >
                                                            📎 Beleg ansehen
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                                <p class="text-xs text-slate-600 dark:text-slate-300 mt-0.5">
                                                    Ausgelegt von <strong class="text-brand-600 dark:text-brand-400"><?= e($payerName) ?></strong>
                                                    <?php if (trim((string)$exp['note']) !== ''): ?>
                                                        &middot; <span class="italic text-slate-500"><?= e((string)$exp['note']) ?></span>
                                                    <?php endif; ?>
                                                </p>

                                                <?php if (!$isSettlement && !empty($expShares)): ?>
                                                    <div class="flex flex-wrap items-center gap-1 mt-2">
                                                        <span class="text-[10px] font-bold uppercase text-slate-400 mr-1">Profiteure (<?= count($expShares) ?>):</span>
                                                        <?php foreach ($expShares as $sh): ?>
                                                            <?php $profName = $participantsById[(int)$sh['participant_id']]['display_name'] ?? '?'; ?>
                                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-[11px] font-medium text-slate-700 dark:text-slate-200">
                                                                <span><?= e($profName) ?>:</span>
                                                                <strong class="text-slate-900 dark:text-white"><?= e(formatSplitMoney((int)$sh['share_cents'], $curr)) ?></strong>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between sm:justify-end gap-2.5 shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-slate-200/60 dark:border-slate-700/60">
                                            <span class="text-base font-extrabold text-slate-900 dark:text-white">
                                                <?= e(formatSplitMoney((int)$exp['amount_cents'], $curr)) ?>
                                            </span>
                                            <?php if ($canModifyThisGroup): ?>
                                                <div class="flex items-center gap-1">
                                                    <?php if (!$isSettlement): ?>
                                                        <a
                                                            href="split.php?<?= $guestToken !== '' ? 'token=' . rawurlencode($guestToken) : 'group=' . (int)$activeGroup['id'] ?>&amp;edit_expense=<?= $eid ?>#expenseForm"
                                                            class="p-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs"
                                                            title="Kostenpunkt bearbeiten"
                                                        >
                                                            ✏️
                                                        </a>
                                                    <?php endif; ?>
                                                    <form action="split.php<?= $guestToken !== '' ? '?token=' . rawurlencode($guestToken) : '' ?>" method="post" class="m-0" onsubmit="return confirm('Diesen Eintrag wirklich löschen?');">
                                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                        <input type="hidden" name="action" value="delete_expense">
                                                        <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">
                                                        <?php if ($guestToken !== ''): ?>
                                                            <input type="hidden" name="token" value="<?= e($guestToken) ?>">
                                                        <?php endif; ?>
                                                        <input type="hidden" name="expense_id" value="<?= $eid ?>">
                                                        <button
                                                            type="submit"
                                                            class="p-1.5 rounded-lg bg-white dark:bg-slate-900 hover:bg-red-50 dark:hover:bg-red-950/50 border border-slate-200 dark:border-slate-700 text-xs cursor-pointer"
                                                            title="Löschen"
                                                        >
                                                            🗑️
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <textarea id="splitSummaryRawText" class="hidden" readonly><?= e($summaryMarkdown) ?></textarea>

            <!-- MODAL: Kassenbon / Beleg-Vorschau -->
            <div id="receiptPreviewModal" class="hidden modal-overlay" onclick="if(event.target===this)this.classList.add('hidden')">
                <div class="modal-box max-w-2xl">
                    <div class="modal-header">
                        <h3 id="receiptModalTitle" class="text-base font-bold text-slate-900 dark:text-white truncate">📎 Kassenbon / Beleg</h3>
                        <button type="button" class="modal-close" onclick="document.getElementById('receiptPreviewModal').classList.add('hidden')">&times;</button>
                    </div>
                    <div class="flex flex-col items-center justify-center bg-slate-100 dark:bg-slate-950 rounded-xl p-3 max-h-[75vh] overflow-auto">
                        <img id="receiptModalImg" src="" alt="Beleg" class="max-w-full max-h-[68vh] object-contain rounded-lg hidden">
                        <iframe id="receiptModalFrame" src="" class="w-full h-[65vh] rounded-lg border-0 hidden"></iframe>
                    </div>
                    <div class="flex justify-between items-center pt-3">
                        <a id="receiptModalDirectLink" href="#" target="_blank" rel="noopener" class="text-xs font-bold text-brand-600 dark:text-brand-400 hover:underline">
                            ↗️ In neuem Tab öffnen / Herunterladen
                        </a>
                        <button type="button" onclick="document.getElementById('receiptPreviewModal').classList.add('hidden')" class="btn btn-outline">Schließen</button>
                    </div>
                </div>
            </div>

            <?php if ($isLoggedIn): ?>
                <!-- MODAL: Öffentlicher Gäste-Link & QR-Code (Tricount / Splitwise-Freigabe) -->
                <div id="shareGroupModal" class="hidden modal-overlay" onclick="if(event.target===this)this.classList.add('hidden')">
                    <div class="modal-box max-w-lg">
                        <div class="modal-header">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">🔗 Gäste-Link &amp; QR-Code für Mitreisende</h3>
                            <button type="button" class="modal-close" onclick="document.getElementById('shareGroupModal').classList.add('hidden')">&times;</button>
                        </div>

                        <div class="space-y-4">
                            <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                Teilen Sie diese Reisekasse mit Freunden oder Mitreisenden, die <strong>kein eigenes MiniCloud-Konto</strong> besitzen. Über den geheimen Link oder QR-Code können Gäste die Abrechnung live auf dem Smartphone öffnen.
                            </p>

                            <?php if ($guestShareUrl !== ''): ?>
                                <div class="p-4 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/70 space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold text-emerald-800 dark:text-emerald-300">✅ Gäste-Link ist aktiv</span>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-600 text-white">
                                            <?= ((int)($activeGroup['guest_can_add'] ?? 1) === 1) ? '✏️ Lesen & Auslagen eintragen' : '👁️ Nur Lesezugriff' ?>
                                        </span>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            id="guestShareUrlInput"
                                            readonly
                                            value="<?= e($guestShareUrl) ?>"
                                            class="form-control text-xs font-mono"
                                        >
                                        <button
                                            type="button"
                                            onclick="navigator.clipboard.writeText(document.getElementById('guestShareUrlInput').value); this.textContent='✅ Kopiert!';"
                                            class="px-3 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-bold shrink-0 cursor-pointer"
                                        >
                                            📋 Kopieren
                                        </button>
                                    </div>

                                    <!-- 100 % lokal erzeugter QR-Code -->
                                    <div class="flex flex-col items-center justify-center pt-2">
                                        <div class="p-3 bg-white rounded-2xl shadow-xs border border-slate-200">
                                            <?= renderQrCodeSvg($guestShareUrl, 168) ?>
                                        </div>
                                        <span class="text-[11px] text-slate-500 dark:text-slate-400 mt-1.5">
                                            📱 Einfach mit der Smartphone-Kamera am Tisch scannen
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form action="split.php" method="post" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="action" value="update_share_settings">
                                <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">

                                <?php
                                $curShareMode = $activeShareToken === ''
                                    ? 'off'
                                    : (((int)($activeGroup['guest_can_add'] ?? 1) === 1) ? 'write' : 'read');
                                ?>
                                <div>
                                    <label class="form-label">Zugriffs-Modus für den Gäste-Link</label>
                                    <select name="share_mode" class="form-control text-xs sm:text-sm">
                                        <option value="write" <?= $curShareMode === 'write' ? 'selected' : '' ?>>
                                            ✏️ Lese- &amp; Schreibrecht (Gäste dürfen auch eigene Auslagen eintragen)
                                        </option>
                                        <option value="read" <?= $curShareMode === 'read' ? 'selected' : '' ?>>
                                            👁️ Nur Lesezugriff (Gäste sehen Salden, Kostenpunkte &amp; Belege)
                                        </option>
                                        <option value="off" <?= $curShareMode === 'off' ? 'selected' : '' ?>>
                                            🔒 Gäste-Link deaktiviert (Nur eingeloggte Projekt-Mitglieder)
                                        </option>
                                    </select>
                                </div>

                                <?php if ($activeShareToken !== ''): ?>
                                    <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300 cursor-pointer">
                                        <input type="checkbox" name="regenerate_token" value="1" class="rounded text-brand-600">
                                        <span>🔄 Neuen Link-Schlüssel generieren (bisheriger Link wird ungültig)</span>
                                    </label>
                                <?php endif; ?>

                                <div class="flex justify-end gap-2 pt-2">
                                    <button type="button" onclick="document.getElementById('shareGroupModal').classList.add('hidden')" class="btn btn-outline">Schließen</button>
                                    <button type="submit" class="btn btn-primary">💾 Einstellung speichern</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- MODAL: Zusammenfassung in der Cloud speichern -->
                <div id="cloudSaveModal" class="hidden modal-overlay" onclick="if(event.target===this)this.classList.add('hidden')">
                    <div class="modal-box max-w-md">
                        <div class="modal-header">
                            <h3 class="text-base font-bold text-slate-900 dark:text-white">☁️ Abrechnung in Cloud speichern</h3>
                            <button type="button" class="modal-close" onclick="document.getElementById('cloudSaveModal').classList.add('hidden')">&times;</button>
                        </div>
                        <form action="split.php" method="post" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="save_summary_to_cloud">
                            <input type="hidden" name="group_id" value="<?= (int)$activeGroup['id'] ?>">
                            <textarea name="summary_markdown" class="hidden"><?= e($summaryMarkdown) ?></textarea>

                            <div>
                                <label class="form-label">Dateiformat</label>
                                <select name="export_format" class="form-control">
                                    <option value="md">📝 Markdown-Bericht (.md – ideal für Office Light)</option>
                                    <option value="txt">📄 Reiner Text (.txt)</option>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Zielordner in Ihrer Cloud</label>
                                <select name="target_folder" class="form-control">
                                    <option value="">📁 Hauptverzeichnis (Meine Dateien)</option>
                                    <?php foreach ($userFolders as $fRow): ?>
                                        <option value="<?= e((string)$fRow['folder_path']) ?>">📁 <?= e((string)$fRow['folder_path']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="flex justify-end gap-2 pt-2">
                                <button type="button" onclick="document.getElementById('cloudSaveModal').classList.add('hidden')" class="btn btn-outline">Abbrechen</button>
                                <button type="submit" class="btn btn-primary">☁️ Jetzt speichern</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($isLoggedIn): ?>
    <!-- MODAL: Neues Projekt / Neue Reise erstellen -->
    <div id="newGroupModal" class="hidden modal-overlay" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="modal-box max-w-lg">
            <div class="modal-header">
                <h3 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">➕ Neues Projekt / Neue Reise anlegen</h3>
                <button type="button" class="modal-close" onclick="document.getElementById('newGroupModal').classList.add('hidden')">&times;</button>
            </div>
            <form action="split.php" method="post" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_group">

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-3">
                        <label class="form-label">Projekt- / Reisename *</label>
                        <input
                            type="text"
                            name="title"
                            required
                            placeholder="z. B. Wochenendtrip Prag, Sommerurlaub, JGA…"
                            class="form-control"
                        >
                    </div>
                    <div>
                        <label class="form-label">Hauptwährung</label>
                        <select name="currency" class="form-control font-bold">
                            <?php foreach ($currencies as $cSym): ?>
                                <option value="<?= e($cSym) ?>"><?= e($cSym) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="form-label">Symbol wählen</label>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($groupIcons as $idx => $gIcon): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="icon" value="<?= e($gIcon) ?>" class="peer sr-only" <?= $idx === 0 ? 'checked' : '' ?>>
                                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 peer-checked:bg-brand-600 peer-checked:text-white peer-checked:border-brand-600 text-lg transition-all">
                                    <?= e($gIcon) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="form-label">Beschreibung / Zeitraum (optional)</label>
                    <input
                        type="text"
                        name="description"
                        placeholder="z. B. 14.05. – 18.05.2026"
                        class="form-control text-xs"
                    >
                </div>

                <div>
                    <label class="form-label">👤 Registrierte Cloud-Nutzer beteiligen (Sie selbst sind automatisch dabei)</label>
                    <div class="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700">
                        <?php foreach ($allCloudUsers as $cUid => $cUname): ?>
                            <label class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-xs font-medium cursor-pointer">
                                <input type="checkbox" name="cloud_user_ids[]" value="<?= $cUid ?>" <?= $cUid === $userId ? 'checked disabled' : '' ?> class="rounded text-brand-600">
                                <span><?= e($cUname) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="form-label">🏷️ Nicht registrierte Teilnehmer per Freitext (beliebig viele, Komma-getrennt)</label>
                    <input
                        type="text"
                        name="guest_names"
                        placeholder="z. B. Max, Lisa, Lukas, Sarah…"
                        class="form-control"
                    >
                    <p class="text-[11px] text-slate-400 mt-1">
                        Sie können auch später jederzeit weitere Personen hinzufügen oder den 🔗 Gäste-Link aktivieren.
                    </p>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('newGroupModal').classList.add('hidden')" class="btn btn-outline">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">➕ Projekt / Reise anlegen</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
(function() {
    const activeCurr = <?= json_encode((string)($activeGroup['currency'] ?? '€')) ?>;
    const eurRates   = <?= json_encode($eurExchangeDefaults) ?>;

    function parseLocalAmount(val) {
        if (!val) return 0;
        let s = String(val).trim();
        if (s.includes(',') && s.includes('.')) {
            s = s.replace(/\./g, '').replace(',', '.');
        } else {
            s = s.replace(',', '.');
        }
        const n = parseFloat(s);
        return (isFinite(n) && n > 0) ? n : 0;
    }

    function fmtMoney(val, currSym) {
        return val.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (currSym || activeCurr);
    }

    window.onExpenseCurrencyChange = function() {
        const sel = document.getElementById('expenseCurrencySelect');
        const bar = document.getElementById('fxConverterBar');
        const rateInp = document.getElementById('exchangeRateInput');
        if (!sel || !bar || !rateInp) return;

        const chosen = sel.value;
        if (chosen === activeCurr) {
            bar.classList.add('hidden');
            rateInp.value = '1';
        } else {
            bar.classList.remove('hidden');
            const fromLbl = document.getElementById('fxFromCurrLabel');
            const unitLbl = document.getElementById('fxRateUnitLabel');
            if (fromLbl) fromLbl.textContent = chosen;
            if (unitLbl) unitLbl.textContent = chosen;

            // Automatischen Richtkurs zwischen chosen und activeCurr vorschlagen
            const chosenToEur = eurRates[chosen] || 1.0;
            const activeToEur = eurRates[activeCurr] || 1.0;
            const suggestedRate = chosenToEur / activeToEur;
            rateInp.value = suggestedRate.toFixed(4).replace('.', ',');
        }
        updateLiveSharePreview();
    };

    window.setSplitMode = function(mode) {
        const inp = document.getElementById('splitModeInput');
        if (inp) inp.value = mode;

        document.querySelectorAll('[data-split-mode-btn]').forEach(btn => {
            const isAct = btn.getAttribute('data-split-mode-btn') === mode;
            btn.className = 'py-1.5 px-2 rounded-lg text-[11px] font-bold transition-all cursor-pointer ' + (
                isAct
                    ? 'bg-white dark:bg-slate-800 text-brand-600 dark:text-brand-400 shadow-2xs'
                    : 'text-slate-600 dark:text-slate-400'
            );
        });

        document.querySelectorAll('[data-mode-field="shares"]').forEach(el => {
            el.classList.toggle('hidden', mode !== 'shares');
            el.classList.toggle('flex', mode === 'shares');
        });
        document.querySelectorAll('[data-mode-field="exact"]').forEach(el => {
            el.classList.toggle('hidden', mode !== 'exact');
            el.classList.toggle('flex', mode === 'exact');
        });

        updateLiveSharePreview();
    };

    window.toggleAllProfiteure = function(state) {
        document.querySelectorAll('[data-prof-checkbox]').forEach(cb => {
            cb.checked = !!state;
        });
        updateLiveSharePreview();
    };

    window.updateLiveSharePreview = function() {
        const modeEl = document.getElementById('splitModeInput');
        const mode = modeEl ? modeEl.value : 'equal';
        const rawAmount = parseLocalAmount(document.getElementById('expenseAmountInput')?.value || '0');
        const selCurr = document.getElementById('expenseCurrencySelect')?.value || activeCurr;
        const exRate = selCurr !== activeCurr
            ? parseLocalAmount(document.getElementById('exchangeRateInput')?.value || '1')
            : 1.0;
        const totalAmount = rawAmount * (exRate > 0 ? exRate : 1.0);

        const fxPrev = document.getElementById('fxConvertedPreview');
        if (fxPrev && selCurr !== activeCurr) {
            fxPrev.textContent = '≈ ' + fmtMoney(totalAmount, activeCurr);
        }

        const extraRaw = document.getElementById('extraProfiteureInput')?.value || '';
        const extraCount = extraRaw.split(/[,;\n]+/).map(s => s.trim()).filter(Boolean).length;

        const checkedIds = [];
        document.querySelectorAll('[data-prof-checkbox]').forEach(cb => {
            const pid = cb.getAttribute('data-prof-checkbox');
            if (cb.checked) {
                checkedIds.push(pid);
            } else {
                const prev = document.querySelector('[data-prof-preview="' + pid + '"]');
                if (prev) prev.textContent = '—';
            }
        });

        const totalParticipantsCount = checkedIds.length + extraCount;
        if (totalParticipantsCount === 0) return;

        if (mode === 'exact') {
            let sumExact = 0;
            checkedIds.forEach(pid => {
                const exInp = document.querySelector('[data-prof-exact="' + pid + '"]');
                const v = parseLocalAmount(exInp ? exInp.value : '0') * (exRate > 0 ? exRate : 1.0);
                sumExact += v;
                const prev = document.querySelector('[data-prof-preview="' + pid + '"]');
                if (prev) prev.textContent = fmtMoney(v, activeCurr);
            });
            return;
        }

        if (mode === 'shares') {
            let totalWeight = extraCount * 1.0;
            const weights = {};
            checkedIds.forEach(pid => {
                const wInp = document.querySelector('[data-prof-weight="' + pid + '"]');
                const w = Math.max(0.1, parseFloat(wInp?.value || '1') || 1);
                weights[pid] = w;
                totalWeight += w;
            });
            checkedIds.forEach(pid => {
                const share = totalWeight > 0 ? (totalAmount * (weights[pid] / totalWeight)) : 0;
                const prev = document.querySelector('[data-prof-preview="' + pid + '"]');
                if (prev) prev.textContent = fmtMoney(share, activeCurr);
            });
            return;
        }

        const perPerson = totalAmount / totalParticipantsCount;
        checkedIds.forEach(pid => {
            const prev = document.querySelector('[data-prof-preview="' + pid + '"]');
            if (prev) prev.textContent = fmtMoney(perPerson, activeCurr);
        });
    };

    window.openReceiptModal = function(url, title) {
        const modal = document.getElementById('receiptPreviewModal');
        const img   = document.getElementById('receiptModalImg');
        const frame = document.getElementById('receiptModalFrame');
        const lbl   = document.getElementById('receiptModalTitle');
        const link  = document.getElementById('receiptModalDirectLink');
        if (!modal || !img || !frame) return;

        if (lbl) lbl.textContent = '📎 Beleg: ' + (title || 'Kostenpunkt');
        if (link) link.href = url;

        // Prüfen ob Bild oder PDF geladen wird (Image-Tag mit Fallback auf Iframe bei PDF)
        img.classList.remove('hidden');
        frame.classList.add('hidden');
        img.onerror = function() {
            img.classList.add('hidden');
            frame.classList.remove('hidden');
            frame.src = url;
        };
        img.src = url;
        modal.classList.remove('hidden');
    };

    window.copySplitSummaryToClipboard = function() {
        const raw = document.getElementById('splitSummaryRawText');
        const lbl = document.getElementById('copySplitBtnLabel');
        if (!raw) return;
        navigator.clipboard.writeText(raw.value).then(() => {
            if (lbl) {
                const orig = lbl.textContent;
                lbl.textContent = '✅ Kopiert!';
                setTimeout(() => { lbl.textContent = orig; }, 2000);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', updateLiveSharePreview);
    updateLiveSharePreview();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
