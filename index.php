<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/office_functions.php';

// Strikter Session-Schutz: Nicht eingeloggte Nutzer werden sofort auf login.php umgeleitet
requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = isAdmin();
$pdo     = getDB();

$storageStats = getUserStorageStats($pdo, $userId);
$phpLimits    = getPhpUploadLimits();

// 1. Gruppenordner prüfen
$activeGroupFolderId = isset($_GET['group_folder']) ? (int)$_GET['group_folder'] : 0;
$activeGroupFolder   = null;
$canWriteActiveGf    = false;

$accessibleGroupFolders = getAccessibleGroupFolders($pdo, $userId, $isAdmin);
foreach ($accessibleGroupFolders as $gfItem) {
    if ((int)$gfItem['id'] === $activeGroupFolderId) {
        $activeGroupFolder = $gfItem;
        $canWriteActiveGf  = ((int)$gfItem['can_write'] === 1);
        break;
    }
}
if ($activeGroupFolderId > 0 && !$activeGroupFolder) {
    setFlash('error', 'Sie haben keinen Zugriff auf den angeforderten Gruppenordner.');
    header('Location: index.php');
    exit;
}

// 2. Von anderem Benutzer freigegebenen Ordner prüfen (?shared_folder=ID)
$activeSharedFolderId  = isset($_GET['shared_folder']) ? (int)$_GET['shared_folder'] : 0;
$activeSharedFolder    = null;
$canWriteSharedFolder  = false;
$canDeleteSharedFolder = false;
if ($activeSharedFolderId > 0 && $activeGroupFolder === null) {
    $stmtSf = $pdo->prepare(
        'SELECT us.id, us.owner_id, us.folder_path, us.can_write, us.can_delete, us.created_at, u.username AS owner_username
         FROM user_shares us
         INNER JOIN users u ON u.id = us.owner_id
         WHERE us.id = :id AND us.shared_with_user_id = :uid AND us.folder_path IS NOT NULL
         LIMIT 1'
    );
    $stmtSf->execute([
        ':id'  => $activeSharedFolderId,
        ':uid' => $userId,
    ]);
    $activeSharedFolder = $stmtSf->fetch() ?: null;
    if (!$activeSharedFolder) {
        setFlash('error', 'Der angeforderte freigegebene Ordner wurde nicht gefunden.');
        header('Location: index.php');
        exit;
    }
    $canWriteSharedFolder  = ((int)($activeSharedFolder['can_write'] ?? 0) === 1);
    $canDeleteSharedFolder = ((int)($activeSharedFolder['can_delete'] ?? 0) === 1);
}

$rawFolderParam = isset($_GET['folder']) && is_string($_GET['folder']) ? trim($_GET['folder']) : '';
$largeFilesMode = isset($_GET['large']) && $_GET['large'] === '1';
$minSizeMb      = isset($_GET['min_mb']) ? max(0, (int)$_GET['min_mb']) : 0;
$onlyDuplicates = isset($_GET['dups']) && $_GET['dups'] === '1';
$showAllFolders = ($rawFolderParam === '__all__' || $largeFilesMode);
$currentFolder  = (!$showAllFolders && $rawFolderParam !== '')
    ? extractSanitizedFolderPath($rawFolderParam . '/placeholder')
    : '';
$searchQuery      = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$onlyFavorites    = isset($_GET['fav']) && $_GET['fav'] === '1';
$sharedWithMeMode = isset($_GET['shared_with_me']) && $_GET['shared_with_me'] === '1' && $activeGroupFolder === null && $activeSharedFolder === null;

// Sortier-Parameter für die Haupttabelle (Standard: Datum absteigend; im Große-Dateien-Modus: Größe absteigend)
$allowedSortCols = [
    'name'   => 'f.original_name',
    'folder' => 'f.folder_path',
    'size'   => 'f.file_size',
    'date'   => 'f.upload_date',
];
$defaultSortCol = $largeFilesMode ? 'size' : 'date';
$rawSort = isset($_GET['sort']) && is_string($_GET['sort']) ? strtolower(trim($_GET['sort'])) : $defaultSortCol;
$sortBy  = array_key_exists($rawSort, $allowedSortCols) ? $rawSort : $defaultSortCol;
$defaultSortDir = ($sortBy === 'date' || $sortBy === 'size') ? 'desc' : 'asc';
$rawDir  = isset($_GET['dir']) && is_string($_GET['dir']) ? strtolower(trim($_GET['dir'])) : $defaultSortDir;
$sortDir = ($rawDir === 'asc' || $rawDir === 'desc') ? $rawDir : $defaultSortDir;
$sqlSortDir = strtoupper($sortDir);

// Alle Ordner (sowohl explizit angelegte leere Ordner als auch Ordner mit Dateien) ermitteln
$userFolders = getMergedFoldersList(
    $pdo,
    $userId,
    $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : null
);

// Persönliche Ordner & beschreibbare Gruppenordner (samt Unterordnern) für Verschieben/Kopieren bereitstellen
$personalFoldersList = $activeGroupFolder === null
    ? $userFolders
    : getMergedFoldersList($pdo, $userId, null);

$writableGroupFolders      = [];
$groupFoldersSubfoldersMap = [];
foreach ($accessibleGroupFolders as $gfEntry) {
    if ((int)$gfEntry['can_write'] === 1) {
        $gfEntryId = (int)$gfEntry['id'];
        $writableGroupFolders[] = $gfEntry;
        $groupFoldersSubfoldersMap[$gfEntryId] = ($activeGroupFolder !== null && (int)$activeGroupFolder['id'] === $gfEntryId)
            ? $userFolders
            : getMergedFoldersList($pdo, $userId, $gfEntryId);
    }
}

$renderFolderTransferOptions = static function (string $srcFolder, ?int $srcGfId, bool $canMoveSource) use (
    $personalFoldersList,
    $writableGroupFolders,
    $groupFoldersSubfoldersMap
): string {
    $srcParent = str_contains($srcFolder, '/') ? dirname($srcFolder) : '';
    ob_start();
    ?>
    <option value="">⇄ Verschieben / 📋 Kopieren...</option>
    <?php if ($canMoveSource): ?>
        <optgroup label="⇄ Verschieben – Meine Dateien">
            <?php if ($srcGfId !== null || $srcParent !== ''): ?>
                <option value="move|personal:__root__">⇄ 🏠 Mein Hauptverzeichnis</option>
            <?php endif; ?>
            <?php foreach ($personalFoldersList as $pfRow): ?>
                <?php
                $pfPath = (string)$pfRow['folder_path'];
                if ($srcGfId === null && ($pfPath === $srcFolder || $pfPath === $srcParent || str_starts_with($pfPath, $srcFolder . '/'))) {
                    continue;
                }
                ?>
                <option value="<?= e('move|personal:' . $pfPath) ?>">⇄ 📁 <?= e($pfPath) ?></option>
            <?php endforeach; ?>
        </optgroup>

        <?php if (!empty($writableGroupFolders)): ?>
            <optgroup label="⇄ Verschieben – Gruppenordner">
                <?php foreach ($writableGroupFolders as $wgf): ?>
                    <?php
                    $wgfId   = (int)$wgf['id'];
                    $wgfName = (string)$wgf['name'];
                    $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                    ?>
                    <?php if ($srcGfId !== $wgfId || $srcParent !== ''): ?>
                        <option value="move|gf:<?= $wgfId ?>:__root__">⇄ 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                    <?php endif; ?>
                    <?php foreach ($wgfSubs as $gfSubRow): ?>
                        <?php
                        $gfSubPath = (string)$gfSubRow['folder_path'];
                        if ($srcGfId === $wgfId && ($gfSubPath === $srcFolder || $gfSubPath === $srcParent || str_starts_with($gfSubPath, $srcFolder . '/'))) {
                            continue;
                        }
                        ?>
                        <option value="<?= e('move|gf:' . $wgfId . ':' . $gfSubPath) ?>">⇄ 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    <?php endif; ?>

    <optgroup label="📋 Kopieren – Meine Dateien">
        <option value="copy|personal:__root__">📋 🏠 Mein Hauptverzeichnis</option>
        <?php foreach ($personalFoldersList as $pfRow): ?>
            <?php
            $pfPath = (string)$pfRow['folder_path'];
            if ($srcGfId === null && ($pfPath === $srcFolder || str_starts_with($pfPath, $srcFolder . '/'))) {
                continue;
            }
            ?>
            <option value="<?= e('copy|personal:' . $pfPath) ?>">📋 📁 <?= e($pfPath) ?></option>
        <?php endforeach; ?>
    </optgroup>

    <?php if (!empty($writableGroupFolders)): ?>
        <optgroup label="📋 Kopieren – Gruppenordner">
            <?php foreach ($writableGroupFolders as $wgf): ?>
                <?php
                $wgfId   = (int)$wgf['id'];
                $wgfName = (string)$wgf['name'];
                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                ?>
                <option value="copy|gf:<?= $wgfId ?>:__root__">📋 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                <?php foreach ($wgfSubs as $gfSubRow): ?>
                    <?php
                    $gfSubPath = (string)$gfSubRow['folder_path'];
                    if ($srcGfId === $wgfId && ($gfSubPath === $srcFolder || str_starts_with($gfSubPath, $srcFolder . '/'))) {
                        continue;
                    }
                    ?>
                    <option value="<?= e('copy|gf:' . $wgfId . ':' . $gfSubPath) ?>">📋 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
};

$renderFileTransferOptions = static function (string $fileFolderPath, ?int $fileGfId, bool $canMoveFile, string $currentLocLabel) use (
    $personalFoldersList,
    $writableGroupFolders,
    $groupFoldersSubfoldersMap
): string {
    ob_start();
    ?>
    <option value=""><?= e($currentLocLabel) ?></option>
    <?php if ($canMoveFile): ?>
        <optgroup label="⇄ Verschieben – Meine Dateien">
            <?php if ($fileGfId !== null || $fileFolderPath !== ''): ?>
                <option value="move|personal:__root__">⇄ 🏠 Mein Hauptverzeichnis</option>
            <?php endif; ?>
            <?php foreach ($personalFoldersList as $pfRow): ?>
                <?php
                $pfPath = (string)$pfRow['folder_path'];
                if ($fileGfId === null && $pfPath === $fileFolderPath) {
                    continue;
                }
                ?>
                <option value="<?= e('move|personal:' . $pfPath) ?>">⇄ 📁 <?= e($pfPath) ?></option>
            <?php endforeach; ?>
        </optgroup>

        <?php if (!empty($writableGroupFolders)): ?>
            <optgroup label="⇄ Verschieben – Gruppenordner">
                <?php foreach ($writableGroupFolders as $wgf): ?>
                    <?php
                    $wgfId   = (int)$wgf['id'];
                    $wgfName = (string)$wgf['name'];
                    $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                    ?>
                    <?php if ($fileGfId !== $wgfId || $fileFolderPath !== ''): ?>
                        <option value="move|gf:<?= $wgfId ?>:__root__">⇄ 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                    <?php endif; ?>
                    <?php foreach ($wgfSubs as $gfSubRow): ?>
                        <?php
                        $gfSubPath = (string)$gfSubRow['folder_path'];
                        if ($fileGfId === $wgfId && $gfSubPath === $fileFolderPath) {
                            continue;
                        }
                        ?>
                        <option value="<?= e('move|gf:' . $wgfId . ':' . $gfSubPath) ?>">⇄ 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    <?php endif; ?>

    <optgroup label="📋 Kopieren – Meine Dateien">
        <option value="copy|personal:__root__">📋 🏠 Mein Hauptverzeichnis</option>
        <?php foreach ($personalFoldersList as $pfRow): ?>
            <?php $pfPath = (string)$pfRow['folder_path']; ?>
            <option value="<?= e('copy|personal:' . $pfPath) ?>">📋 📁 <?= e($pfPath) ?></option>
        <?php endforeach; ?>
    </optgroup>

    <?php if (!empty($writableGroupFolders)): ?>
        <optgroup label="📋 Kopieren – Gruppenordner">
            <?php foreach ($writableGroupFolders as $wgf): ?>
                <?php
                $wgfId   = (int)$wgf['id'];
                $wgfName = (string)$wgf['name'];
                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                ?>
                <option value="copy|gf:<?= $wgfId ?>:__root__">📋 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                <?php foreach ($wgfSubs as $gfSubRow): ?>
                    <?php $gfSubPath = (string)$gfSubRow['folder_path']; ?>
                    <option value="<?= e('copy|gf:' . $wgfId . ':' . $gfSubPath) ?>">📋 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </optgroup>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
};

// Anzahl & Größe der Dateien direkt im Hauptverzeichnis (folder_path = '') ermitteln
if ($activeGroupFolder !== null) {
    $stmtRootStats = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz
         FROM files
         WHERE group_folder_id = :gf_id AND deleted_at IS NULL AND folder_path = ''"
    );
    $stmtRootStats->execute([':gf_id' => (int)$activeGroupFolder['id']]);
} else {
    $stmtRootStats = $pdo->prepare(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz
         FROM files
         WHERE user_id = :uid AND group_folder_id IS NULL AND deleted_at IS NULL AND folder_path = ''"
    );
    $stmtRootStats->execute([':uid' => $userId]);
}
$rootStatsRow   = $stmtRootStats->fetch();
$rootFileCount  = (int)($rootStatsRow['cnt'] ?? 0);
$rootFolderSize = (int)($rootStatsRow['sz'] ?? 0);

// Share-Zähler für persönliche Ordner ermitteln (damit man sieht, welche Ordner geteilt sind)
$folderShareCounts = [];
if ($activeGroupFolder === null && !empty($userFolders)) {
    $stmtFolderLinks = $pdo->prepare(
        'SELECT folder_path, COUNT(*) AS cnt FROM shares WHERE user_id = :uid AND folder_path IS NOT NULL GROUP BY folder_path'
    );
    $stmtFolderLinks->execute([':uid' => $userId]);
    foreach ($stmtFolderLinks->fetchAll() as $r) {
        $fp = (string)$r['folder_path'];
        $folderShareCounts[$fp]['links'] = (int)$r['cnt'];
    }

    $stmtFolderUsers = $pdo->prepare(
        'SELECT folder_path, COUNT(*) AS cnt FROM user_shares WHERE owner_id = :uid AND folder_path IS NOT NULL GROUP BY folder_path'
    );
    $stmtFolderUsers->execute([':uid' => $userId]);
    foreach ($stmtFolderUsers->fetchAll() as $r) {
        $fp = (string)$r['folder_path'];
        $folderShareCounts[$fp]['users'] = (int)$r['cnt'];
    }
}

// Im Modus "📊 Große Dateien & Speicher-Analyse": Kategorie-Verteilung, Größenklassen & Duplikate (SHA-256 + Name/Größe) vorab berechnen,
// damit die Hauptabfrage direkt per SQL LIMIT 100 nur die Top-100-Dateien laden muss.
$storageAnalysis  = null;
$duplicateIds     = [];
$duplicateHashIds = [];
if ($largeFilesMode) {
    $stmtAllScope = $pdo->prepare(
        $activeGroupFolder !== null
            ? 'SELECT id, filename, original_name, folder_path, file_size, file_hash, mime_type, is_encrypted FROM files WHERE group_folder_id = :sc_id AND deleted_at IS NULL'
            : 'SELECT id, filename, original_name, folder_path, file_size, file_hash, mime_type, is_encrypted FROM files WHERE user_id = :sc_id AND group_folder_id IS NULL AND deleted_at IS NULL'
    );
    $stmtAllScope->execute([':sc_id' => $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : $userId]);
    $allScopeFiles = $stmtAllScope->fetchAll();

    // Fehlende SHA-256-Hashes für Bestandsdateien schrittweise im Hintergrund nachberechnen (max. 25 pro Aufruf)
    $hashedInThisRequest = 0;
    $stmtUpdHash = $pdo->prepare('UPDATE files SET file_hash = :fh WHERE id = :id');
    foreach ($allScopeFiles as &$sfRef) {
        if ($hashedInThisRequest >= 25) {
            break;
        }
        $szRef = (int)$sfRef['file_size'];
        if (empty($sfRef['file_hash']) && (int)($sfRef['is_encrypted'] ?? 0) === 0 && $szRef > 0 && $szRef <= 150 * 1048576) {
            $physPath = UPLOAD_DIR . basename((string)$sfRef['filename']);
            if (is_file($physPath) && is_readable($physPath)) {
                $calcHash = @hash_file('sha256', $physPath);
                if (is_string($calcHash) && strlen($calcHash) === 64) {
                    $sfRef['file_hash'] = $calcHash;
                    $stmtUpdHash->execute([':fh' => $calcHash, ':id' => (int)$sfRef['id']]);
                    $hashedInThisRequest++;
                }
            }
        }
    }
    unset($sfRef);

    $catBuckets = [
        'video' => ['label' => '🎬 Videos',          'bytes' => 0, 'count' => 0, 'bar' => 'bg-violet-500'],
        'image' => ['label' => '🖼️ Fotos & Bilder',  'bytes' => 0, 'count' => 0, 'bar' => 'bg-pink-500'],
        'audio' => ['label' => '🎵 Musik & Audio',   'bytes' => 0, 'count' => 0, 'bar' => 'bg-rose-500'],
        'zip'   => ['label' => '📦 Archive & Backups','bytes' => 0, 'count' => 0, 'bar' => 'bg-amber-500'],
        'doc'   => ['label' => '📄 Dokumente & PDF', 'bytes' => 0, 'count' => 0, 'bar' => 'bg-blue-500'],
        'other' => ['label' => '📁 Sonstiges',       'bytes' => 0, 'count' => 0, 'bar' => 'bg-slate-400'],
    ];
    $totalScopeBytes = 0;
    $cnt10Mb  = 0;
    $cnt50Mb  = 0;
    $cnt100Mb = 0;
    $sigMap   = [];
    $hashMap  = [];

    foreach ($allScopeFiles as $sf) {
        $sz   = (int)$sf['file_size'];
        $name = strtolower(trim((string)$sf['original_name']));
        $ext  = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $mime = strtolower((string)$sf['mime_type']);
        $fHash = trim((string)($sf['file_hash'] ?? ''));
        $totalScopeBytes += $sz;

        if ($sz >= 10 * 1048576) {
            $cnt10Mb++;
        }
        if ($sz >= 50 * 1048576) {
            $cnt50Mb++;
        }
        if ($sz >= 100 * 1048576) {
            $cnt100Mb++;
        }

        if ($sz > 0) {
            if ($fHash !== '') {
                $hashMap[$fHash][] = (int)$sf['id'];
            }
            $sig = $name . '|' . $sz;
            $sigMap[$sig][] = (int)$sf['id'];
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'mkv', 'avi', 'm4v', 'ogv'], true)) {
            $catBuckets['video']['bytes'] += $sz;
            $catBuckets['video']['count']++;
        } elseif (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'svg', 'bmp'], true)) {
            $catBuckets['image']['bytes'] += $sz;
            $catBuckets['image']['count']++;
        } elseif (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'm4a', 'wav', 'flac', 'ogg', 'aac', 'opus'], true)) {
            $catBuckets['audio']['bytes'] += $sz;
            $catBuckets['audio']['count']++;
        } elseif (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'iso', 'sql'], true)) {
            $catBuckets['zip']['bytes'] += $sz;
            $catBuckets['zip']['count']++;
        } elseif ($mime === 'application/pdf' || in_array($ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'epub'], true)) {
            $catBuckets['doc']['bytes'] += $sz;
            $catBuckets['doc']['count']++;
        } else {
            $catBuckets['other']['bytes'] += $sz;
            $catBuckets['other']['count']++;
        }
    }

    foreach ($hashMap as $ids) {
        if (count($ids) > 1) {
            foreach ($ids as $dId) {
                $duplicateIds[$dId]     = true;
                $duplicateHashIds[$dId] = true;
            }
        }
    }
    foreach ($sigMap as $ids) {
        if (count($ids) > 1) {
            foreach ($ids as $dId) {
                $duplicateIds[$dId] = true;
            }
        }
    }

    $topFolders = $userFolders;
    usort($topFolders, static fn(array $a, array $b): int => ((int)$b['folder_size']) <=> ((int)$a['folder_size']));
    $topFolders = array_values(array_filter(array_slice($topFolders, 0, 3), static fn(array $fl): bool => (int)$fl['folder_size'] > 0));

    $storageAnalysis = [
        'total_bytes'   => $totalScopeBytes,
        'total_files'   => count($allScopeFiles),
        'buckets'       => $catBuckets,
        'cnt_10mb'      => $cnt10Mb,
        'cnt_50mb'      => $cnt50Mb,
        'cnt_100mb'     => $cnt100Mb,
        'dup_count'     => count($duplicateIds),
        'top_folders'   => $topFolders,
    ];
}

// Dateien für die Haupttabelle abrufen (ohne teure korrelierte Subqueries pro Zeile; Zähler werden anschließend per Batch geladen)
$params = [];
$appSettingsForIndex = getAppSettings($pdo);
$fulltextEnabledInIndex = (($appSettingsForIndex['enable_fulltext_search'] ?? '1') === '1');
$joinSearchIndex = ($searchQuery !== '' && $fulltextEnabledInIndex)
    ? ' LEFT JOIN file_search_index fsi ON fsi.file_id = f.id '
    : ' ';

if ($activeGroupFolder !== null) {
    $sqlWhere = 'FROM files f INNER JOIN users u ON u.id = f.user_id' . $joinSearchIndex . 'WHERE f.group_folder_id = :gf_id AND f.deleted_at IS NULL';
    $params[':gf_id'] = (int)$activeGroupFolder['id'];
} elseif ($activeSharedFolder !== null) {
    $sharedFp = (string)$activeSharedFolder['folder_path'];
    $sqlWhere = 'FROM files f INNER JOIN users u ON u.id = f.user_id' . $joinSearchIndex . '
                 WHERE f.user_id = :owner_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL
                   AND (f.folder_path = :sf_exact OR f.folder_path LIKE :sf_prefix)';
    $params[':owner_id']  = (int)$activeSharedFolder['owner_id'];
    $params[':sf_exact']  = $sharedFp;
    $params[':sf_prefix'] = $sharedFp . '/%';
} else {
    $sqlWhere = 'FROM files f INNER JOIN users u ON u.id = f.user_id' . $joinSearchIndex . 'WHERE f.user_id = :user_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL';
    $params[':user_id'] = $userId;
}

if ($activeSharedFolder === null && $searchQuery === '' && !$showAllFolders && !$onlyFavorites) {
    if ($currentFolder !== '') {
        // Nur Dateien im ausgewählten Ordner anzeigen
        $sqlWhere .= ' AND f.folder_path = :folder_exact';
        $params[':folder_exact'] = $currentFolder;
    } else {
        // Nur Dateien direkt im Hauptverzeichnis anzeigen (nicht aus Unterordnern)
        $sqlWhere .= " AND f.folder_path = ''";
    }
}

if ($onlyFavorites) {
    $sqlWhere .= ' AND f.is_favorite = 1';
}

if ($minSizeMb > 0) {
    $sqlWhere .= ' AND f.file_size >= :min_size_bytes';
    $params[':min_size_bytes'] = $minSizeMb * 1048576;
}

if ($largeFilesMode && $onlyDuplicates) {
    if (empty($duplicateIds)) {
        $sqlWhere .= ' AND 1 = 0';
    } else {
        $dupIntIds = array_map('intval', array_keys($duplicateIds));
        $sqlWhere .= ' AND f.id IN (' . implode(',', $dupIntIds) . ')';
    }
}

if ($searchQuery !== '') {
    $searchTermsIdx = splitSearchTerms($searchQuery, 8);
    if (empty($searchTermsIdx)) {
        $searchTermsIdx = [$searchQuery];
    }
    foreach ($searchTermsIdx as $tIdx => $termVal) {
        $likeVal = '%' . $termVal . '%';
        $params[':s_name_' . $tIdx]   = $likeVal;
        $params[':s_folder_' . $tIdx] = $likeVal;
        if ($fulltextEnabledInIndex) {
            $params[':s_content_' . $tIdx] = $likeVal;
            $sqlWhere .= " AND (
                f.original_name LIKE :s_name_{$tIdx}
                OR f.folder_path LIKE :s_folder_{$tIdx}
                OR (f.is_encrypted = 0 AND fsi.content_text LIKE :s_content_{$tIdx})
            )";
        } else {
            $sqlWhere .= " AND (
                f.original_name LIKE :s_name_{$tIdx}
                OR f.folder_path LIKE :s_folder_{$tIdx}
            )";
        }
    }
}

// Gesamtanzahl für Paginierung bestimmen (außer bei ?large=1, das fest auf Top 100 begrenzt ist)
$perPage            = $largeFilesMode ? 100 : 200;
$currentPage        = max(1, (int)($_GET['page'] ?? 1));
$totalMatchingFiles = 0;
$totalPages         = 1;

if (!$largeFilesMode) {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) ' . $sqlWhere);
    $stmtCount->execute($params);
    $totalMatchingFiles = (int)$stmtCount->fetchColumn();
    $totalPages         = max(1, (int)ceil($totalMatchingFiles / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
}

$extraSelectCols = ($searchQuery !== '' && $fulltextEnabledInIndex) ? ', fsi.content_text AS fsi_content_text' : '';
$sqlFiles = 'SELECT f.*, u.username AS uploader_name' . $extraSelectCols . ',
                    0 AS link_share_count, 0 AS user_share_count, 0 AS version_count, 0 AS comment_count ' . $sqlWhere;

$orderByColumn = $allowedSortCols[$sortBy] ?? 'f.upload_date';
if ($largeFilesMode && $sortBy === 'size') {
    $sqlFiles .= sprintf(' ORDER BY %s %s, f.id DESC', $orderByColumn, $sqlSortDir);
} else {
    $sqlFiles .= sprintf(' ORDER BY f.is_favorite DESC, %s %s, f.id DESC', $orderByColumn, $sqlSortDir);
}

if ($largeFilesMode) {
    $sqlFiles .= ' LIMIT 100';
} else {
    $offset = ($currentPage - 1) * $perPage;
    $sqlFiles .= sprintf(' LIMIT %d OFFSET %d', $perPage, $offset);
}

// Für mich freigegebene Ordner (von anderen Cloud-Benutzern via user_shares)
$stmtSharedFoldersWithMe = $pdo->prepare(
    'SELECT us.id AS user_share_id, us.owner_id, us.folder_path, us.can_write, us.can_delete, us.created_at AS shared_at,
            u.username AS owner_username,
            (SELECT COUNT(*) FROM files f
             WHERE f.user_id = us.owner_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL
               AND (f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, \'/%\'))) AS file_count,
            (SELECT COALESCE(SUM(f.file_size), 0) FROM files f
             WHERE f.user_id = us.owner_id AND f.group_folder_id IS NULL AND f.deleted_at IS NULL
               AND (f.folder_path = us.folder_path OR f.folder_path LIKE CONCAT(us.folder_path, \'/%\'))) AS folder_size
     FROM user_shares us
     INNER JOIN users u ON u.id = us.owner_id
     WHERE us.shared_with_user_id = :uid AND us.folder_path IS NOT NULL
     ORDER BY us.created_at DESC'
);
$stmtSharedFoldersWithMe->execute([':uid' => $userId]);
$sharedWithMeFolders = $stmtSharedFoldersWithMe->fetchAll();

// Für mich freigegebene Einzeldateien (von anderen Cloud-Benutzern via user_shares, nur aktive Dateien)
$stmtSharedWithMe = $pdo->prepare(
    'SELECT f.*, u.username AS uploader_name, u.username AS owner_username,
            us.can_write AS us_can_write, us.can_delete AS us_can_delete, us.created_at AS shared_at,
            0 AS link_share_count, 0 AS user_share_count, 0 AS version_count, 0 AS comment_count
     FROM user_shares us
     INNER JOIN files f ON f.id = us.file_id
     INNER JOIN users u ON u.id = us.owner_id
     WHERE us.shared_with_user_id = :uid AND us.file_id IS NOT NULL AND f.deleted_at IS NULL
     ORDER BY us.created_at DESC'
);
$stmtSharedWithMe->execute([':uid' => $userId]);
$sharedWithMeFiles = $stmtSharedWithMe->fetchAll();

$totalSharedWithMeCount = count($sharedWithMeFolders) + count($sharedWithMeFiles);
$totalSharedWithMeSize  = 0;
foreach ($sharedWithMeFolders as $swfRow) {
    $totalSharedWithMeSize += (int)($swfRow['folder_size'] ?? 0);
}
foreach ($sharedWithMeFiles as $swFileRow) {
    $totalSharedWithMeSize += (int)($swFileRow['file_size'] ?? 0);
}

$showSharedVirtualFolder = (
    $activeGroupFolder === null
    && $activeSharedFolder === null
    && !$sharedWithMeMode
    && $currentFolder === ''
    && !$largeFilesMode
    && !$onlyFavorites
    && !$showAllFolders
    && $searchQuery === ''
);

// Im virtuellen Ordner "👥 Für mich freigegeben" (?shared_with_me=1) werden die für mich freigegebenen Einzeldateien direkt in der Haupttabelle geladen
if ($sharedWithMeMode) {
    $files              = $sharedWithMeFiles;
    $totalMatchingFiles = count($files);
    $totalPages         = 1;
    $currentPage        = 1;
} else {
    $stmtFiles = $pdo->prepare($sqlFiles);
    $stmtFiles->execute($params);
    $files = $stmtFiles->fetchAll();
}

// Batch-Aggregation für Freigaben, Dateiversionen & Kommentare (nur für die tatsächlich angezeigten <= 200 Dateien)
if (!empty($files)) {
    $visibleIdsSql = implode(',', array_map(static fn(array $r): int => (int)$r['id'], $files));
    $shareCntMap   = [];
    $uShareCntMap  = [];
    $verCntMap     = [];
    $comCntMap     = [];

    if ($activeSharedFolder === null && !$sharedWithMeMode) {
        foreach ($pdo->query("SELECT file_id, COUNT(*) AS c FROM shares WHERE file_id IN ({$visibleIdsSql}) GROUP BY file_id")->fetchAll() as $cr) {
            $shareCntMap[(int)$cr['file_id']] = (int)$cr['c'];
        }
        foreach ($pdo->query("SELECT file_id, COUNT(*) AS c FROM user_shares WHERE file_id IN ({$visibleIdsSql}) GROUP BY file_id")->fetchAll() as $cr) {
            $uShareCntMap[(int)$cr['file_id']] = (int)$cr['c'];
        }
    }
    foreach ($pdo->query("SELECT file_id, COUNT(*) AS c FROM file_versions WHERE file_id IN ({$visibleIdsSql}) GROUP BY file_id")->fetchAll() as $cr) {
        $verCntMap[(int)$cr['file_id']] = (int)$cr['c'];
    }
    foreach ($pdo->query("SELECT file_id, COUNT(*) AS c FROM file_comments WHERE file_id IN ({$visibleIdsSql}) GROUP BY file_id")->fetchAll() as $cr) {
        $comCntMap[(int)$cr['file_id']] = (int)$cr['c'];
    }

    foreach ($files as &$fRowRef) {
        $fidKey = (int)$fRowRef['id'];
        $fRowRef['link_share_count'] = $shareCntMap[$fidKey]  ?? 0;
        $fRowRef['user_share_count'] = $uShareCntMap[$fidKey] ?? 0;
        $fRowRef['version_count']    = $verCntMap[$fidKey]    ?? 0;
        $fRowRef['comment_count']    = $comCntMap[$fidKey]    ?? 0;
    }
    unset($fRowRef);
}

// Direkte Unterordner des aktuell geöffneten Ordners ermitteln (oder bei globaler Suche alle passenden Ordner)
$directSubfolders = [];
if ($activeSharedFolder === null && !$sharedWithMeMode && !$showAllFolders && !$onlyFavorites) {
    foreach ($userFolders as $ufItem) {
        $fPath = (string)$ufItem['folder_path'];
        $parentOfF = str_contains($fPath, '/') ? dirname($fPath) : '';
        if ($searchQuery !== '') {
            if (stripos($fPath, $searchQuery) !== false) {
                $directSubfolders[] = $ufItem;
            }
        } elseif ($parentOfF === $currentFolder) {
            $directSubfolders[] = $ufItem;
        }
    }

    if (count($directSubfolders) > 1) {
        usort($directSubfolders, static function (array $a, array $b) use ($sortBy, $sortDir): int {
            if ($sortBy === 'size') {
                $cmp = ((int)$a['folder_size']) <=> ((int)$b['folder_size']);
                if ($cmp === 0) {
                    $cmp = strnatcasecmp(basename((string)$a['folder_path']), basename((string)$a['folder_path']));
                }
            } else {
                $cmp = strnatcasecmp(basename((string)$a['folder_path']), basename((string)$b['folder_path']));
            }
            if ($sortBy === 'name' || $sortBy === 'folder' || $sortBy === 'size') {
                return $sortDir === 'desc' ? -$cmp : $cmp;
            }
            return $cmp;
        });
    }
}

// Liste anderer Cloud-Benutzer für das Teilen-Modal (nur Benutzer, die ihre Sichtbarkeit für Direktfreigaben aktiviert haben)
$stmtOtherUsers = $pdo->prepare('SELECT id, username FROM users WHERE id <> :uid AND allow_user_sharing = 1 ORDER BY username ASC');
$stmtOtherUsers->execute([':uid' => $userId]);
$otherCloudUsers = $stmtOtherUsers->fetchAll();

$canUploadHere = $sharedWithMeMode
    ? false
    : ($activeSharedFolder !== null
        ? $canWriteSharedFolder
        : (($activeGroupFolder === null) || $canWriteActiveGf));

$pageTitle = $activeGroupFolder
    ? 'Gruppenordner: ' . $activeGroupFolder['name']
    : ($activeSharedFolder
        ? 'Geteilter Ordner: ' . $activeSharedFolder['folder_path']
        : ($sharedWithMeMode ? 'Für mich freigegeben' : 'Dashboard'));
require_once __DIR__ . '/includes/header.php';
?>

<?php
$activeFolderMeta = null;
if ($currentFolder !== '') {
    foreach ($userFolders as $ufItem) {
        if ((string)$ufItem['folder_path'] === $currentFolder) {
            $activeFolderMeta = $ufItem;
            break;
        }
    }
}
$baseGfQuery = $activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] : '';
?>

<!-- Haupt-Dateitabelle (Eigene Dateien, aktiver Gruppenordner oder geöffneter freigegebener Ordner) -->
<section id="mainFilesSection" class="mc-card relative bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden mb-8 transition-all">
    <div class="px-4 py-3 sm:px-5 sm:py-3.5 border-b border-slate-200 dark:border-slate-800 flex flex-col lg:flex-row lg:items-center justify-between gap-2.5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                    <?php if ($largeFilesMode): ?>
                        📊 Speicher-Analyse &amp; Große Dateien <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(ordnerübergreifend)</span>
                    <?php elseif ($onlyFavorites): ?>
                        ⭐ Meine Favoriten
                    <?php elseif ($searchQuery !== ''): ?>
                        🔎 Globale Suche: <span class="text-brand-600 dark:text-brand-400">„<?= e($searchQuery) ?>“</span>
                    <?php elseif ($activeGroupFolder !== null): ?>
                        Gruppenordner: <span class="text-indigo-600 dark:text-indigo-400"><?= e((string)$activeGroupFolder['name']) ?></span>
                    <?php elseif ($sharedWithMeMode): ?>
                        👥 <span class="text-blue-600 dark:text-blue-400">Für mich freigegeben</span>
                        <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(Von Cloud-Benutzern)</span>
                    <?php elseif ($activeSharedFolder !== null): ?>
                        Freigegebener Ordner: <span class="text-blue-600 dark:text-blue-400">📁 <?= e((string)$activeSharedFolder['folder_path']) ?></span>
                        <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(von <?= e((string)$activeSharedFolder['owner_username']) ?>)</span>
                    <?php elseif ($currentFolder !== ''): ?>
                        Ordner: <span class="text-brand-600 dark:text-brand-400">📁 <?= e($currentFolder) ?></span>
                    <?php else: ?>
                        Meine hochgeladenen Dateien
                    <?php endif; ?>
                </h2>
                <?php
                $visibleFolderCountBadge = $sharedWithMeMode ? count($sharedWithMeFolders) : count($directSubfolders);
                ?>
                <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                    <?= $visibleFolderCountBadge > 0 ? $visibleFolderCountBadge . ' Ordner &bull; ' : '' ?><?= count($files) ?> <?= count($files) === 1 ? 'Datei' : 'Dateien' ?>
                </span>

                <?php if ($activeSharedFolder === null && $currentFolder !== ''): ?>
                    <!-- Kompakte Ordner-Aktionen für den aktuell geöffneten Ordner -->
                    <div class="inline-flex flex-wrap items-center gap-1 ml-1">
                        <?php if ($activeGroupFolder === null && $activeFolderMeta !== null && (int)$activeFolderMeta['file_count'] > 0): ?>
                            <a
                                href="download.php?folder=<?= rawurlencode($currentFolder) ?>"
                                title="Diesen Ordner als ZIP herunterladen"
                                aria-label="Ordner als ZIP herunterladen"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 border border-sky-200/70 dark:border-sky-800/60 transition-colors"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                </svg>
                            </a>
                        <?php endif; ?>

                        <?php if ($canUploadHere): ?>
                            <button
                                type="button"
                                onclick="openRenameModal('folder', 0, <?= e((string)json_encode(basename($currentFolder))) ?>, <?= e((string)json_encode($currentFolder)) ?>)"
                                title="Diesen Ordner umbenennen"
                                aria-label="Ordner umbenennen"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors cursor-pointer"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="openTransferModal('folder', 0, <?= e((string)json_encode($currentFolder)) ?>, <?= e((string)json_encode($currentFolder)) ?>, <?= $canUploadHere ? 'true' : 'false' ?>)"
                            title="Diesen Ordner verschieben oder kopieren"
                            aria-label="Ordner verschieben oder kopieren"
                            class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors cursor-pointer"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                        </button>

                        <?php if ($activeGroupFolder === null): ?>
                            <button
                                type="button"
                                onclick="openShareModal(0, <?= e((string)json_encode($currentFolder)) ?>, <?= e((string)json_encode($currentFolder)) ?>)"
                                title="Diesen Ordner teilen"
                                aria-label="Ordner teilen"
                                class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors cursor-pointer"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                </svg>
                            </button>
                        <?php endif; ?>

                        <?php if ($canUploadHere && $activeGroupFolder === null): ?>
                            <form action="delete.php" method="post" class="inline" onsubmit="return confirm('Möchten Sie den Ordner &quot;<?= e(addslashes($currentFolder)) ?>&quot; <?= ($activeFolderMeta && (int)$activeFolderMeta['file_count'] > 0) ? 'inklusive aller darin enthaltenen Dateien ' : '' ?>in den Papierkorb verschieben?');">
                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                <input type="hidden" name="delete_folder" value="<?= e($currentFolder) ?>">
                                <button
                                    type="submit"
                                    title="Diesen Ordner löschen"
                                    aria-label="Ordner löschen"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 border border-red-200/70 dark:border-red-800/60 transition-colors cursor-pointer"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($currentFolder !== '' || $searchQuery !== '' || $activeGroupFolder !== null || $activeSharedFolder !== null || $sharedWithMeMode || $showAllFolders || $onlyFavorites): ?>
                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <a
                        href="index.php<?= $activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] : '' ?>"
                        data-drop-folder=""
                        data-drop-folder-label="Hauptverzeichnis"
                        class="text-brand-600 dark:text-brand-400 hover:underline font-semibold rounded px-1 -mx-1 transition-all"
                    >🏠 Hauptverzeichnis</a>
                    <?php if ($sharedWithMeMode): ?>
                        <span class="text-slate-400">/</span>
                        <span class="font-semibold text-blue-600 dark:text-blue-400">👥 Für mich freigegeben</span>
                    <?php elseif ($activeSharedFolder !== null): ?>
                        <span class="text-slate-400">/</span>
                        <a href="index.php?shared_with_me=1" class="text-blue-600 dark:text-blue-400 hover:underline font-semibold">👥 Für mich freigegeben</a>
                        <span class="text-slate-400">/</span>
                        <span class="font-semibold text-slate-700 dark:text-slate-200">📁 <?= e((string)$activeSharedFolder['folder_path']) ?></span>
                    <?php elseif ($currentFolder !== ''): ?>
                        <?php
                        $crumbParts = explode('/', $currentFolder);
                        $crumbAccum = '';
                        foreach ($crumbParts as $cIdx => $cSegment):
                            $crumbAccum = $crumbAccum === '' ? $cSegment : $crumbAccum . '/' . $cSegment;
                            $crumbUrl = 'index.php?' . ($baseGfQuery !== '' ? $baseGfQuery . '&' : '') . 'folder=' . rawurlencode($crumbAccum);
                            $isLastCrumb = ($cIdx === count($crumbParts) - 1);
                        ?>
                            <span class="text-slate-400">/</span>
                            <?php if ($isLastCrumb): ?>
                                <span class="font-semibold text-slate-700 dark:text-slate-200"><?= e($cSegment) ?></span>
                            <?php else: ?>
                                <a
                                    href="<?= e($crumbUrl) ?>"
                                    data-drop-folder="<?= e($crumbAccum) ?>"
                                    data-drop-folder-label="<?= e($crumbAccum) ?>"
                                    class="text-brand-600 dark:text-brand-400 hover:underline font-medium rounded px-1 -mx-1 transition-all"
                                ><?= e($cSegment) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span>&bull;</span>
                        <span><?= $searchQuery !== '' ? 'Ordnerübergreifende Suche aktiv' : 'Gefilterte Ansicht aktiv' ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="hidden sm:block mt-0.5 text-[11px] text-slate-400 dark:text-slate-500 truncate">
                    💡 Tipp: Dateien oder Ordner einfach per Drag &amp; Drop direkt auf einen Ordner (oder in diese Liste) ziehen.
                </p>
            <?php endif; ?>
        </div>

        <!-- Rechts: Kompakte, gruppierte Funktionsleiste (Upload/Erstellen + Filter/Ansicht) -->
        <div class="flex flex-wrap items-center gap-1.5 w-full lg:w-auto lg:justify-end">
            <?php if ($canUploadHere): ?>
                <form id="uploadForm" action="upload.php" method="post" enctype="multipart/form-data" class="inline-flex flex-wrap items-center gap-1">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" id="target_folder" name="target_folder" value="<?= e($currentFolder) ?>">
                    <?php if ($activeSharedFolder !== null): ?>
                        <input type="hidden" name="shared_folder_id" value="<?= (int)$activeSharedFolder['id'] ?>">
                    <?php elseif ($activeGroupFolder !== null): ?>
                        <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
                    <?php endif; ?>
                    <input type="file" id="fileInput" name="files[]" multiple class="sr-only">
                    <input type="file" id="folderInput" webkitdirectory directory multiple class="sr-only">

                    <!-- Haupt-Aktion: Dateien hochladen -->
                    <button
                        type="button"
                        id="btnSelectFiles"
                        title="Dateien in <?= e($currentFolder !== '' ? '„' . $currentFolder . '“' : 'das aktuelle Verzeichnis') ?> hochladen (Frei: <?= e(formatBytes($storageStats['free'])) ?>)"
                        class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                    >
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M12 12V4m0 0L8.5 7.5M12 4l3.5 3.5"/>
                        </svg>
                        <span>+ Dateien</span>
                    </button>

                    <!-- Gruppierte Sekundär-Aktionen: Ordner-Upload, Neuer Ordner, Office, Cloud-Import -->
                    <div class="inline-flex items-center rounded-lg bg-slate-100/90 dark:bg-slate-800/90 p-0.5 border border-slate-200/90 dark:border-slate-700/90 gap-0.5">
                        <button
                            type="button"
                            id="btnSelectFolder"
                            title="Kompletten Ordner samt Unterordnern vom Gerät hochladen"
                            class="inline-flex items-center justify-center gap-1 px-2 py-1 rounded-md text-amber-800 dark:text-amber-300 hover:bg-white dark:hover:bg-slate-700 hover:shadow-2xs text-[11px] font-semibold transition-all cursor-pointer"
                        >
                            <span>📁↑</span>
                            <span class="hidden sm:inline">Ordner</span>
                        </button>

                        <?php if ($activeSharedFolder === null): ?>
                            <button
                                type="button"
                                onclick="toggleNewFolderBar()"
                                title="Neuen Ordner im aktuellen Verzeichnis anlegen"
                                class="inline-flex items-center justify-center gap-1 px-2 py-1 rounded-md text-amber-700 dark:text-amber-300 hover:bg-white dark:hover:bg-slate-700 hover:shadow-2xs text-[11px] font-semibold transition-all cursor-pointer"
                            >
                                <span>📁+</span>
                                <span class="hidden sm:inline">Neu</span>
                            </button>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="openCreateOfficeModal()"
                            title="Neues Word-Dokument (.docx), Excel-Tabelle (.xlsx), Präsentation oder Textnotiz direkt in der Cloud erstellen"
                            aria-label="Office Light – Neues Dokument erstellen"
                            class="inline-flex items-center justify-center gap-1 px-2 py-1 rounded-md text-indigo-700 dark:text-indigo-300 hover:bg-white dark:hover:bg-slate-700 hover:shadow-2xs text-[11px] font-semibold transition-all cursor-pointer"
                        >
                            <span>📄+</span>
                            <span class="hidden md:inline">Office</span>
                        </button>

                        <?php if ($activeSharedFolder === null): ?>
                            <button
                                type="button"
                                onclick="toggleCloudImportBar()"
                                title="Dropbox-/Nextcloud-Ordner oder Datei per Freigabelink direkt von Server zu Server in die MiniCloud importieren"
                                class="inline-flex items-center justify-center gap-1 px-2 py-1 rounded-md text-sky-700 dark:text-sky-300 hover:bg-white dark:hover:bg-slate-700 hover:shadow-2xs text-[11px] font-semibold transition-all cursor-pointer"
                            >
                                <span>☁️↓</span>
                                <span class="hidden sm:inline">Import</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>

            <?php
            $favFilterParams = [];
            if ($activeGroupFolder !== null) {
                $favFilterParams['group_folder'] = (int)$activeGroupFolder['id'];
            }
            if (!$onlyFavorites) {
                $favFilterParams['fav'] = '1';
            } elseif ($currentFolder !== '') {
                $favFilterParams['folder'] = $currentFolder;
            }
            $favFilterUrl = 'index.php' . (!empty($favFilterParams) ? '?' . http_build_query($favFilterParams) : '');

            $largeFilterParams = [];
            if ($activeGroupFolder !== null) {
                $largeFilterParams['group_folder'] = (int)$activeGroupFolder['id'];
            }
            if (!$largeFilesMode) {
                $largeFilterParams['large'] = '1';
                $largeFilterParams['sort']  = 'size';
                $largeFilterParams['dir']   = 'desc';
            } elseif ($currentFolder !== '') {
                $largeFilterParams['folder'] = $currentFolder;
            }
            $largeFilterUrl = 'index.php' . (!empty($largeFilterParams) ? '?' . http_build_query($largeFilterParams) : '');
            ?>

            <!-- Kompakte Filter- & Ansichtsleiste (Favoriten, Speicher-Analyse, Liste/Kacheln) -->
            <div class="inline-flex items-center rounded-lg bg-slate-100/90 dark:bg-slate-800/90 p-0.5 border border-slate-200/90 dark:border-slate-700/90 gap-0.5">
                <a
                    href="<?= e($favFilterUrl) ?>"
                    title="<?= $onlyFavorites ? 'Alle Dateien anzeigen' : 'Nur Favoriten (⭐) anzeigen' ?>"
                    aria-label="Favoriten filtern"
                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold transition-all <?= $onlyFavorites ? 'bg-amber-400/25 dark:bg-amber-500/25 text-amber-800 dark:text-amber-300 shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 hover:text-amber-600 dark:hover:text-amber-300' ?>"
                >
                    <span><?= $onlyFavorites ? '⭐' : '☆' ?></span>
                    <span class="hidden 2xl:inline">Favoriten</span>
                </a>

                <a
                    href="<?= e($largeFilterUrl) ?>"
                    title="<?= $largeFilesMode ? 'Zurück zur normalen Ordneransicht' : 'Große Dateien, Speicherfresser & Duplikate über alle Ordner hinweg aufspüren' ?>"
                    aria-label="Große Dateien & Speicheranalyse"
                    class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold transition-all <?= $largeFilesMode ? 'bg-violet-500/20 dark:bg-violet-500/30 text-violet-800 dark:text-violet-200 shadow-2xs' : 'text-slate-600 dark:text-slate-300 hover:bg-white dark:hover:bg-slate-700 hover:text-violet-600 dark:hover:text-violet-300' ?>"
                >
                    <span>📊</span>
                    <span class="hidden 2xl:inline">Große Dateien</span>
                </a>

                <span class="w-px h-3.5 bg-slate-300/80 dark:bg-slate-700 mx-0.5"></span>

                <button
                    type="button"
                    id="btnViewList"
                    onclick="setFileViewMode('list')"
                    title="Tabellen-/Listenansicht"
                    aria-label="Listenansicht"
                    class="px-2 py-1 rounded-md text-[11px] font-semibold transition-all cursor-pointer bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-2xs"
                >
                    📋
                </button>
                <button
                    type="button"
                    id="btnViewGrid"
                    onclick="setFileViewMode('grid')"
                    title="Kachel- & Foto-Galerieansicht"
                    aria-label="Kachelansicht"
                    class="px-2 py-1 rounded-md text-[11px] font-semibold transition-all cursor-pointer text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white"
                >
                    🖼️
                </button>
            </div>
        </div>
    </div>

    <?php if ($canUploadHere && $activeSharedFolder === null): ?>
        <!-- Ausklappbare Schnellleiste: Neuen Ordner / Unterordner anlegen -->
        <div id="newFolderInlineBar" class="hidden px-4 sm:px-6 py-3 bg-amber-50/70 dark:bg-amber-950/30 border-b border-amber-200/80 dark:border-amber-800/70">
            <form action="folder_action.php" method="post" class="flex flex-col sm:flex-row sm:items-center gap-2">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_folder">
                <?php if ($activeGroupFolder !== null): ?>
                    <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="parent_folder" value="<?= e($currentFolder) ?>">

                <span class="text-xs font-bold text-amber-900 dark:text-amber-300 shrink-0">
                    📁 <?= $currentFolder !== '' ? 'Neuer Unterordner in „' . e($currentFolder) . '“:' : 'Neuen Ordner anlegen:' ?>
                </span>
                <input
                    type="text"
                    id="newFolderInlineInput"
                    name="folder_name"
                    required
                    placeholder="<?= $currentFolder !== '' ? 'Name des Unterordners...' : 'Ordnername (z.B. Projekte, Rechnungen)...' ?>"
                    class="flex-1 sm:max-w-xs px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-amber-300 dark:border-amber-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                >
                <div class="flex items-center gap-1.5">
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-semibold text-xs sm:text-sm shadow-2xs transition-colors cursor-pointer"
                    >
                        <span>Anlegen</span>
                    </button>
                    <button
                        type="button"
                        onclick="toggleNewFolderBar(false)"
                        class="px-2.5 py-1.5 rounded-xl bg-white/80 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-medium transition-colors cursor-pointer"
                    >
                        Abbrechen
                    </button>
                </div>
            </form>
        </div>
        <script>
        function toggleNewFolderBar(forceShow) {
            const bar = document.getElementById('newFolderInlineBar');
            const inp = document.getElementById('newFolderInlineInput');
            if (!bar) return;
            const show = typeof forceShow === 'boolean' ? forceShow : bar.classList.contains('hidden');
            bar.classList.toggle('hidden', !show);
            if (show) {
                toggleCloudImportBar(false);
                if (inp) setTimeout(() => inp.focus(), 30);
            }
        }
        </script>

        <!-- Ausklappbare Schnellleiste: Dropbox- / Cloud-Link Server-zu-Server Import -->
        <div id="cloudImportInlineBar" class="hidden px-4 sm:px-6 py-3.5 bg-sky-50/80 dark:bg-sky-950/35 border-b border-sky-200/80 dark:border-sky-800/70">
            <form id="cloudImportForm" action="folder_action.php" method="post" onsubmit="return submitCloudUrlImport(event)" class="space-y-2.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="import_cloud_url">
                <?php if ($activeGroupFolder !== null): ?>
                    <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="parent_folder" value="<?= e($currentFolder) ?>">

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-xs font-bold text-sky-950 dark:text-sky-200">
                        ☁️↓ Dropbox-, Nextcloud- &amp; Cloud-Umzug (Server-zu-Server Direktimport<?= $currentFolder !== '' ? ' in „' . e($currentFolder) . '“' : '' ?>)
                    </span>
                    <span class="text-[11px] text-sky-700 dark:text-sky-300">
                        Tipp: In Dropbox / Nextcloud auf „Link kopieren“ klicken (Ordner oder Datei) &amp; hier einfügen
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-2">
                    <div class="md:col-span-5">
                        <input
                            type="url"
                            id="cloudImportUrlInput"
                            name="import_url"
                            required
                            placeholder="https://www.dropbox.com/scl/fo/... oder Nextcloud-Link"
                            class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-sky-300 dark:border-sky-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                    </div>
                    <div class="md:col-span-2">
                        <input
                            type="password"
                            name="import_password"
                            autocomplete="new-password"
                            placeholder="🔒 Passwort (optional)"
                            title="Optionales Passwort für passwortgeschützte Nextcloud-Freigaben oder geschützte ZIP-Archive"
                            class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-sky-300 dark:border-sky-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                    </div>
                    <div class="md:col-span-2">
                        <input
                            type="text"
                            name="target_subfolder"
                            placeholder="Ziel-Unterordner (opt.)"
                            class="w-full px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 border border-sky-300 dark:border-sky-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-sky-500"
                        >
                    </div>
                    <div class="md:col-span-3 flex items-center gap-1.5">
                        <button
                            type="submit"
                            id="cloudImportSubmitBtn"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-700 text-white font-bold text-xs sm:text-sm shadow-2xs transition-colors cursor-pointer"
                        >
                            <span>🚀 Importieren</span>
                        </button>
                        <button
                            type="button"
                            onclick="toggleCloudImportBar(false)"
                            class="px-2.5 py-1.5 rounded-xl bg-white/80 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-xs font-medium transition-colors cursor-pointer"
                        >
                            ✕
                        </button>
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 text-xs text-sky-900 dark:text-sky-200 cursor-pointer select-none">
                    <input type="checkbox" name="auto_unpack" value="1" checked class="rounded border-sky-300 text-sky-600 focus:ring-sky-500">
                    <span>📦 <strong>Dropbox-/Nextcloud-Ordner &amp; ZIP-Archive automatisch entpacken</strong> und alle Unterordner 1:1 wiederherstellen</span>
                </label>

                <div id="cloudImportProgressBox" class="hidden pt-1 space-y-1.5">
                    <div class="flex items-center justify-between text-xs font-semibold text-sky-900 dark:text-sky-200">
                        <span id="cloudImportProgressLabel">⏳ Verbinde mit Quell-Cloud...</span>
                        <span id="cloudImportProgressStats" class="font-mono text-[11px] text-sky-700 dark:text-sky-300">0 MB</span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-sky-200/80 dark:bg-sky-900/70 overflow-hidden">
                        <div id="cloudImportProgressBar" class="h-full bg-sky-600 dark:bg-sky-400 transition-all duration-300" style="width: 8%;"></div>
                    </div>
                </div>
            </form>
        </div>
        <script>
        function toggleCloudImportBar(forceShow) {
            const bar = document.getElementById('cloudImportInlineBar');
            const inp = document.getElementById('cloudImportUrlInput');
            if (!bar) return;
            const show = typeof forceShow === 'boolean' ? forceShow : bar.classList.contains('hidden');
            bar.classList.toggle('hidden', !show);
            if (show) {
                const nfBar = document.getElementById('newFolderInlineBar');
                if (nfBar) nfBar.classList.add('hidden');
                if (inp) setTimeout(() => inp.focus(), 30);
            }
        }

        function formatImportBytesJs(bytes) {
            const b = Number(bytes) || 0;
            if (b >= 1073741824) return (b / 1073741824).toFixed(2).replace('.', ',') + ' GB';
            if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
            if (b >= 1024) return Math.round(b / 1024) + ' KB';
            return b + ' B';
        }

        function submitCloudUrlImport(ev) {
            if (ev) ev.preventDefault();
            const form = document.getElementById('cloudImportForm');
            if (!form) return true;

            const btn   = document.getElementById('cloudImportSubmitBtn');
            const box   = document.getElementById('cloudImportProgressBox');
            const lbl   = document.getElementById('cloudImportProgressLabel');
            const stats = document.getElementById('cloudImportProgressStats');
            const bar   = document.getElementById('cloudImportProgressBar');

            const jobId = 'imp_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
            const fd    = new FormData(form);
            fd.set('ajax', '1');
            fd.set('job_id', jobId);

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '⏳ Läuft...';
            }
            if (box) box.classList.remove('hidden');
            if (lbl) lbl.textContent = '⏳ Verbinde direkt von Server zu Server...';
            if (stats) stats.textContent = '0 MB';
            if (bar) bar.style.width = '6%';

            let finished = false;
            const csrfToken = fd.get('csrf_token') || '';

            const pollTimer = setInterval(async () => {
                if (finished) return;
                try {
                    const pfd = new FormData();
                    pfd.set('csrf_token', csrfToken);
                    pfd.set('action', 'import_cloud_status');
                    pfd.set('job_id', jobId);
                    const res = await fetch('folder_action.php', { method: 'POST', body: pfd, credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (!data || finished) return;

                    if (data.phase === 'downloading') {
                        const dl = Number(data.downloaded_bytes) || 0;
                        const tot = Number(data.total_bytes) || 0;
                        const fn = data.filename ? (' („' + data.filename + '“)') : '';
                        if (lbl) lbl.textContent = '📥 Lade direkt auf den MiniCloud-Server' + fn + '...';
                        if (tot > 0 && tot >= dl) {
                            const pct = Math.min(92, Math.max(8, Math.round((dl / tot) * 88)));
                            if (bar) bar.style.width = pct + '%';
                            if (stats) stats.textContent = formatImportBytesJs(dl) + ' / ' + formatImportBytesJs(tot) + ' (' + Math.round((dl / tot) * 100) + ' %)';
                        } else {
                            if (bar) bar.style.width = '55%';
                            if (stats) stats.textContent = formatImportBytesJs(dl) + ' empfangen';
                        }
                    } else if (data.phase === 'unpacking') {
                        const ext = Number(data.extracted_files) || 0;
                        const totF = Number(data.total_files) || 1;
                        const pct = Math.min(99, Math.max(88, 88 + Math.round((ext / totF) * 11)));
                        if (lbl) lbl.textContent = '📦 Entpacke Ordner & Dateien 1:1 in die MiniCloud...';
                        if (bar) bar.style.width = pct + '%';
                        if (stats) stats.textContent = ext + ' / ' + totF + ' Dateien';
                    } else if (data.phase === 'done') {
                        finished = true;
                        clearInterval(pollTimer);
                        if (bar) bar.style.width = '100%';
                        if (lbl) lbl.textContent = data.message || '✅ Import abgeschlossen! Lade Ordner...';
                        window.location.href = data.redirect_url || window.location.href;
                    } else if (data.phase === 'error') {
                        finished = true;
                        clearInterval(pollTimer);
                        if (lbl) lbl.textContent = '❌ ' + (data.message || 'Fehler beim Import.');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '🚀 Importieren';
                        }
                    }
                } catch (e) {
                    // Netzwerkfehler beim Polling ignorieren, nächster Tick folgt
                }
            }, 950);

            fetch('folder_action.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(async (resp) => {
                    if (finished) return;
                    // Falls ein Proxy nach 60s mit 504 antwortet, läuft PHP dank ignore_user_abort im Hintergrund weiter -> Poller übernimmt!
                    if (resp.status === 504 || resp.status === 502) {
                        if (lbl) lbl.textContent = '📥 Großer Datenimport läuft im Hintergrund auf dem Server weiter...';
                        return;
                    }
                    const json = await resp.json().catch(() => null);
                    if (json && (json.phase === 'done' || json.ok === true)) {
                        finished = true;
                        clearInterval(pollTimer);
                        if (bar) bar.style.width = '100%';
                        if (lbl) lbl.textContent = json.message || '✅ Import abgeschlossen!';
                        window.location.href = json.redirect_url || window.location.href;
                    } else if (json && json.phase === 'error') {
                        finished = true;
                        clearInterval(pollTimer);
                        if (lbl) lbl.textContent = '❌ ' + (json.message || 'Import fehlgeschlagen.');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '🚀 Importieren';
                        }
                    }
                })
                .catch(() => {
                    // Bei Verbindungsabbruch pollt pollTimer automatisch den Server-Status weiter
                });

            return false;
        }
        </script>
    <?php endif; ?>

    <?php if ($largeFilesMode && $storageAnalysis !== null): ?>
        <?php
        $baseLargeQ = $activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] . '&' : '';
        $totB = max(1, (int)$storageAnalysis['total_bytes']);
        ?>
        <!-- Dezent eingeblendeter Speicher-Röntgenblick (nur sichtbar wenn "📊 Große Dateien" aktiv ist) -->
        <div class="px-4 sm:px-6 py-4 bg-violet-50/60 dark:bg-slate-800/70 border-b border-violet-200/80 dark:border-slate-700/80 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-extrabold text-violet-950 dark:text-violet-200">
                        📊 Speicher-Röntgenblick (<?= (int)$storageAnalysis['total_files'] ?> Dateien · <?= e(formatBytes((int)$storageAnalysis['total_bytes'])) ?> gesamt)
                    </span>
                    <span class="px-2 py-0.5 rounded-md bg-violet-100 dark:bg-violet-950/80 text-violet-800 dark:text-violet-300 border border-violet-300/70 dark:border-violet-700/70 text-[11px] font-bold">
                        ⚡ Top <?= count($files) ?> <?= $onlyDuplicates ? 'Duplikate' : 'größte Dateien' ?> (max. 100)
                    </span>
                </div>
                <a href="index.php<?= $activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] : '' ?>"
                   class="text-xs font-semibold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white">
                    ✕ Analyse schließen
                </a>
            </div>

            <!-- Farbiger Verteilungs-Balken nach Dateityp -->
            <div class="w-full h-2.5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden flex">
                <?php foreach ($storageAnalysis['buckets'] as $bKey => $bData): ?>
                    <?php if ($bData['bytes'] > 0): ?>
                        <?php $pctW = max(1.5, round(($bData['bytes'] / $totB) * 100, 1)); ?>
                        <div class="h-full <?= $bData['bar'] ?>" style="width: <?= $pctW ?>%"
                             title="<?= e($bData['label'] . ': ' . formatBytes($bData['bytes']) . ' (' . $bData['count'] . ' Dateien)') ?>"></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Legende der Dateitypen -->
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-600 dark:text-slate-300">
                <?php foreach ($storageAnalysis['buckets'] as $bData): ?>
                    <?php if ($bData['bytes'] > 0): ?>
                        <div class="inline-flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-xs <?= $bData['bar'] ?>"></span>
                            <span class="font-semibold"><?= e($bData['label']) ?>:</span>
                            <span><?= e(formatBytes($bData['bytes'])) ?></span>
                            <span class="text-slate-400">(<?= (int)$bData['count'] ?>)</span>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Schnellfilter-Pills für Mindestgröße & Duplikate + Top-3 größte Ordner -->
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-2.5 pt-2 border-t border-violet-200/60 dark:border-slate-700/70">
                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="text-[11px] font-bold text-slate-500 dark:text-slate-400 mr-1">Größenfilter:</span>
                    <a href="index.php?<?= $baseLargeQ ?>large=1&amp;sort=size&amp;dir=desc"
                       class="px-2.5 py-1 rounded-lg font-semibold transition-colors <?= ($minSizeMb === 0 && !$onlyDuplicates) ? 'bg-violet-600 text-white shadow-2xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-violet-50' ?>">
                        Alle (nach Größe ↓)
                    </a>
                    <a href="index.php?<?= $baseLargeQ ?>large=1&amp;min_mb=10&amp;sort=size&amp;dir=desc"
                       class="px-2.5 py-1 rounded-lg font-semibold transition-colors <?= ($minSizeMb === 10 && !$onlyDuplicates) ? 'bg-violet-600 text-white shadow-2xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-violet-50' ?>">
                        &ge; 10 MB (<?= (int)$storageAnalysis['cnt_10mb'] ?>)
                    </a>
                    <a href="index.php?<?= $baseLargeQ ?>large=1&amp;min_mb=50&amp;sort=size&amp;dir=desc"
                       class="px-2.5 py-1 rounded-lg font-semibold transition-colors <?= ($minSizeMb === 50 && !$onlyDuplicates) ? 'bg-violet-600 text-white shadow-2xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-violet-50' ?>">
                        &ge; 50 MB (<?= (int)$storageAnalysis['cnt_50mb'] ?>)
                    </a>
                    <a href="index.php?<?= $baseLargeQ ?>large=1&amp;min_mb=100&amp;sort=size&amp;dir=desc"
                       class="px-2.5 py-1 rounded-lg font-semibold transition-colors <?= ($minSizeMb === 100 && !$onlyDuplicates) ? 'bg-violet-600 text-white shadow-2xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-violet-50' ?>">
                        &ge; 100 MB (<?= (int)$storageAnalysis['cnt_100mb'] ?>)
                    </a>
                    <a href="index.php?<?= $baseLargeQ ?>large=1&amp;dups=1&amp;sort=size&amp;dir=desc"
                       class="px-2.5 py-1 rounded-lg font-semibold transition-colors <?= $onlyDuplicates ? 'bg-amber-500 text-slate-950 font-extrabold shadow-2xs' : 'bg-white dark:bg-slate-900 text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 hover:bg-amber-50' ?>"
                       title="Dateien mit identischem Namen und identischer Dateigröße in verschiedenen Ordnern anzeigen">
                        🔍 Mögliche Duplikate (<?= (int)$storageAnalysis['dup_count'] ?>)
                    </a>
                </div>

                <?php if (!empty($storageAnalysis['top_folders'])): ?>
                    <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
                        <span class="font-bold text-slate-500 dark:text-slate-400">Größte Ordner:</span>
                        <?php foreach ($storageAnalysis['top_folders'] as $tf): ?>
                            <a href="index.php?<?= $baseLargeQ ?>folder=<?= rawurlencode((string)$tf['folder_path']) ?>&amp;sort=size&amp;dir=desc"
                               class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 hover:border-violet-400">
                                <span>📁 <?= e((string)$tf['folder_path']) ?></span>
                                <span class="font-bold text-violet-600 dark:text-violet-400">(<?= e(formatBytes((int)$tf['folder_size'])) ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canUploadHere): ?>
        <!-- Schlanker Drag-&-Drop-Hinweis & Fortschrittsbalken (erscheint nur bei aktivem Drag & Drop oder Upload) -->
        <div id="globalDropHint" class="hidden px-4 sm:px-6 py-2.5 bg-brand-50/90 dark:bg-brand-950/60 border-b border-brand-200 dark:border-brand-800 text-xs sm:text-sm font-semibold text-brand-700 dark:text-brand-300 items-center justify-between gap-2">
            <span id="globalDropHintText">📥 Dateien oder Ordner hier ablegen, um sie direkt in <?= e($currentFolder !== '' ? '„' . $currentFolder . '“' : 'das aktuelle Verzeichnis') ?> hochzuladen</span>
            <span class="text-[11px] font-normal text-brand-600 dark:text-brand-400">Frei: <?= e(formatBytes($storageStats['free'])) ?></span>
        </div>
        <div id="uploadProgressWrapper" class="hidden px-4 sm:px-6 py-3 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 space-y-1.5">
            <div class="flex justify-between text-xs font-semibold text-slate-700 dark:text-slate-200">
                <span id="uploadProgressText">Dateien werden hochgeladen...</span>
                <span id="uploadProgressPercent">0%</span>
            </div>
            <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                <div id="uploadProgressBar" class="h-full bg-brand-600 transition-all duration-150" style="width: 0%"></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($files) && empty($directSubfolders) && !$showSharedVirtualFolder && (!$sharedWithMeMode || empty($sharedWithMeFolders)) && $currentFolder === ''): ?>
        <div
            class="p-10 sm:p-12 text-center <?= $canUploadHere ? 'cursor-pointer hover:bg-brand-50/30 dark:hover:bg-brand-950/20 transition-colors' : '' ?>"
            <?php if ($canUploadHere): ?>onclick="document.getElementById('fileInput')?.click()"<?php endif; ?>
        >
            <div class="mx-auto w-12 h-12 rounded-2xl bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200">
                <?= $sharedWithMeMode ? 'Noch keine Ordner oder Dateien für Sie freigegeben' : 'Keine Dateien oder Ordner vorhanden' ?>
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                <?php if ($sharedWithMeMode): ?>
                    Aktuell wurden noch keine Ordner oder Dateien von anderen Cloud-Benutzern direkt mit Ihnen geteilt.
                    <span class="block mt-2">
                        <a href="index.php" class="text-brand-600 dark:text-brand-400 font-semibold hover:underline">&larr; Zurück zum Hauptverzeichnis</a>
                    </span>
                <?php else: ?>
                    <?= $canUploadHere ? 'Ziehen Sie Dateien oder Ordner einfach per Drag & Drop hierher oder klicken Sie auf „+ Dateien“.' : 'In diesem Ordner befinden sich aktuell keine Dateien.' ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <?php
        $buildSortHeaderUrl = static function (string $col) use (
            $activeGroupFolder,
            $activeSharedFolder,
            $showAllFolders,
            $largeFilesMode,
            $minSizeMb,
            $onlyDuplicates,
            $currentFolder,
            $searchQuery,
            $onlyFavorites,
            $sortBy,
            $sortDir
        ): string {
            $nextDir = ($sortBy === $col && $sortDir === 'asc') ? 'desc' : 'asc';
            if (($col === 'date' && $sortBy !== 'date') || ($col === 'size' && $sortBy !== 'size')) {
                $nextDir = 'desc';
            }
            $qParams = [];
            if ($activeGroupFolder !== null) {
                $qParams['group_folder'] = (int)$activeGroupFolder['id'];
            }
            if ($activeSharedFolder !== null) {
                $qParams['shared_folder'] = (int)$activeSharedFolder['id'];
            }
            if ($largeFilesMode) {
                $qParams['large'] = 1;
                if ($minSizeMb > 0) {
                    $qParams['min_mb'] = $minSizeMb;
                }
                if ($onlyDuplicates) {
                    $qParams['dups'] = 1;
                }
            } elseif ($showAllFolders) {
                $qParams['folder'] = '__all__';
            } elseif ($currentFolder !== '') {
                $qParams['folder'] = $currentFolder;
            }
            if ($searchQuery !== '') {
                $qParams['q'] = $searchQuery;
            }
            if ($onlyFavorites) {
                $qParams['fav'] = 1;
            }
            $qParams['sort'] = $col;
            $qParams['dir']  = $nextDir;
            return 'index.php?' . http_build_query($qParams);
        };
        ?>
        <div id="fileListView" class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 select-none">
                        <th class="py-3.5 pl-4 pr-2 sm:pl-6 w-9">
                            <?php if (!empty($files) || !empty($directSubfolders)): ?>
                                <input
                                    type="checkbox"
                                    id="selectAllFilesCheckbox"
                                    onchange="toggleSelectAllFiles(this)"
                                    class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500 cursor-pointer align-middle"
                                    title="Alle Ordner &amp; Dateien auf dieser Seite auswählen"
                                    aria-label="Alle Ordner &amp; Dateien auswählen"
                                >
                            <?php endif; ?>
                        </th>
                        <th class="py-3.5 px-2 sm:px-4">
                            <a
                                href="<?= e($buildSortHeaderUrl('name')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'name' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Name sortieren (<?= ($sortBy === 'name' && $sortDir === 'asc') ? 'Z–A' : 'A–Z' ?>)"
                            >
                                <span>Name</span>
                                <span class="text-[11px] <?= $sortBy === 'name' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'name' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 hidden md:table-cell">
                            <a
                                href="<?= e($buildSortHeaderUrl('folder')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'folder' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Ordner sortieren"
                            >
                                <span>Ordner</span>
                                <span class="text-[11px] <?= $sortBy === 'folder' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'folder' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 hidden sm:table-cell">
                            <a
                                href="<?= e($buildSortHeaderUrl('size')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'size' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Größe sortieren"
                            >
                                <span>Größe</span>
                                <span class="text-[11px] <?= $sortBy === 'size' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'size' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 hidden lg:table-cell">
                            <a
                                href="<?= e($buildSortHeaderUrl('date')) ?>"
                                class="inline-flex items-center gap-1.5 hover:text-brand-600 dark:hover:text-brand-400 transition-colors <?= $sortBy === 'date' ? 'text-brand-600 dark:text-brand-400 font-bold' : '' ?>"
                                title="Nach Datum sortieren"
                            >
                                <span>Typ / Datum</span>
                                <span class="text-[11px] <?= $sortBy === 'date' ? 'opacity-100' : 'opacity-40' ?>">
                                    <?= $sortBy === 'date' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' ?>
                                </span>
                            </a>
                        </th>
                        <th class="py-3.5 px-4 sm:px-6 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-sm">
                    <?php if (($currentFolder !== '' && $activeSharedFolder === null) || $sharedWithMeMode || $activeSharedFolder !== null): ?>
                        <?php
                        if ($sharedWithMeMode) {
                            $upParent = '';
                            $upUrl    = 'index.php';
                            $upLabel  = 'Hauptverzeichnis';
                        } elseif ($activeSharedFolder !== null) {
                            $upParent = '';
                            $upUrl    = 'index.php?shared_with_me=1';
                            $upLabel  = '👥 Für mich freigegeben';
                        } else {
                            $upParent = str_contains($currentFolder, '/') ? dirname($currentFolder) : '';
                            $upUrl = 'index.php'
                                . ($activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] . ($upParent !== '' ? '&amp;folder=' . rawurlencode($upParent) : '') : ($upParent !== '' ? '?folder=' . rawurlencode($upParent) : ''));
                            $upLabel = $upParent !== '' ? $upParent : 'Hauptverzeichnis';
                        }
                        ?>
                        <tr
                            <?php if (!$sharedWithMeMode && $activeSharedFolder === null): ?>
                                data-drop-folder="<?= e($upParent) ?>"
                                data-drop-folder-label="<?= e($upLabel) ?>"
                            <?php endif; ?>
                            class="bg-slate-50/40 dark:bg-slate-800/20 hover:bg-slate-100/70 dark:hover:bg-slate-800/50 transition-all"
                        >
                            <td class="py-2.5 px-4 sm:px-6" colspan="6">
                                <div class="flex items-center justify-between gap-2">
                                    <a href="<?= $upUrl ?>" class="inline-flex items-center gap-2.5 text-xs sm:text-sm font-semibold text-slate-600 dark:text-slate-300 hover:text-brand-600 dark:hover:text-brand-400">
                                        <span class="inline-flex items-center justify-center w-10 h-7 rounded-lg bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-bold">
                                            &crarr; ..
                                        </span>
                                        <span>Übergeordneter Ordner (<?= e($upLabel) ?>)</span>
                                    </a>
                                    <?php if (!$sharedWithMeMode && $activeSharedFolder === null): ?>
                                        <span class="hidden sm:inline text-[11px] text-slate-400 dark:text-slate-500">
                                            📥 Dateien hierher ziehen zum Ablegen in <?= e($upLabel) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <!-- 0b. Freigegebene Ordner innerhalb von "👥 Für mich freigegeben" (?shared_with_me=1) -->
                    <?php if ($sharedWithMeMode): ?>
                        <?php foreach ($sharedWithMeFolders as $sFolder): ?>
                            <?php
                            $usId         = (int)$sFolder['user_share_id'];
                            $sfPath       = (string)$sFolder['folder_path'];
                            $sfOwner      = (string)$sFolder['owner_username'];
                            $sfDate       = date('d.m.Y H:i', strtotime((string)$sFolder['shared_at']));
                            $sfCount      = (int)$sFolder['file_count'];
                            $sfSize       = (int)$sFolder['folder_size'];
                            $sfCanWrite   = ((int)($sFolder['can_write'] ?? 0) === 1);
                            $sfCanDelete  = ((int)($sFolder['can_delete'] ?? 0) === 1);
                            $sfPermLabel  = $sfCanDelete ? 'Lesen, Schreiben & Löschen' : ($sfCanWrite ? 'Lesen & Schreiben' : 'Nur Lesen');
                            $sfPermIcon   = $sfCanDelete ? '✏️🗑️' : ($sfCanWrite ? '✏️' : '👁️');
                            ?>
                            <tr class="bg-blue-50/25 dark:bg-blue-950/15 hover:bg-blue-50/60 dark:hover:bg-blue-950/35 transition-all">
                                <td class="py-3 pl-2.5 pr-1 sm:pl-6 sm:pr-2 w-8 sm:w-9 align-middle text-center">
                                    <span class="text-xs text-blue-500">📁</span>
                                </td>
                                <td class="py-3 px-1.5 sm:px-4 max-w-[140px] sm:max-w-none overflow-hidden">
                                    <div class="flex items-start sm:items-center gap-2 sm:gap-3 min-w-0">
                                        <a href="index.php?shared_folder=<?= $usId ?>" class="inline-flex items-center justify-center w-8 h-7 sm:w-10 sm:h-8 rounded-lg bg-blue-100 dark:bg-blue-900/80 text-blue-600 dark:text-blue-300 shrink-0 mt-0.5 sm:mt-0" title="Freigegebenen Ordner öffnen">
                                            <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                            </svg>
                                        </a>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                                <a href="index.php?shared_folder=<?= $usId ?>" class="font-bold text-slate-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 truncate max-w-[130px] sm:max-w-xs md:max-w-md block">
                                                    <?= e($sfPath) ?>
                                                </a>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                                                    👤 von <?= e($sfOwner) ?>
                                                </span>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" title="<?= e($sfPermLabel) ?>">
                                                    <?= $sfPermIcon ?> <?= e($sfPermLabel) ?>
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate max-w-[130px] sm:max-w-none">
                                                <?= $sfCount ?> <?= $sfCount === 1 ? 'Datei' : 'Dateien' ?> &bull; <?= e(formatBytes($sfSize)) ?>
                                            </p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap hidden md:table-cell text-xs text-blue-700 dark:text-blue-400 font-medium">
                                    👤 <?= e($sfOwner) ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-slate-600 dark:text-slate-300 font-medium hidden sm:table-cell">
                                    <?= e(formatBytes($sfSize)) ?>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-xs text-slate-500 dark:text-slate-400 hidden lg:table-cell">
                                    <?= e($sfDate) ?> Uhr
                                </td>
                                <td class="py-3 pl-1 pr-2.5 sm:px-6 whitespace-nowrap text-right align-middle">
                                    <div class="inline-flex items-center justify-end gap-1.5">
                                        <a
                                            href="index.php?shared_folder=<?= $usId ?>"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs transition-colors"
                                        >
                                            <span>Öffnen</span>
                                        </a>
                                        <?php if ($sfCount > 0): ?>
                                            <a
                                                href="download.php?user_share_id=<?= $usId ?>"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                                title="Als ZIP herunterladen"
                                                aria-label="Als ZIP herunterladen"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- 1. Direkte Unterordner in diesem Verzeichnis -->
                    <?php foreach ($directSubfolders as $subFolder): ?>
                        <?php
                        $subPath = (string)$subFolder['folder_path'];
                        $subDisplayName = basename($subPath);
                        $subFileCount = (int)$subFolder['file_count'];
                        $subSize = (int)$subFolder['folder_size'];
                        $subUrl = 'index.php?' . ($activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] . '&amp;' : '') . 'folder=' . rawurlencode($subPath);
                        $subLinkShares = (int)($folderShareCounts[$subPath]['links'] ?? 0);
                        $subUserShares = (int)($folderShareCounts[$subPath]['users'] ?? 0);
                        $subParent = str_contains($subPath, '/') ? dirname($subPath) : '';

                        $subMoveTargets = [];
                        foreach ($userFolders as $ufCand) {
                            $candP = (string)$ufCand['folder_path'];
                            if ($candP !== $subPath && $candP !== $subParent && !str_starts_with($candP, $subPath . '/')) {
                                $subMoveTargets[] = $candP;
                            }
                        }
                        $subGfIdOrNull = $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : null;
                        ?>
                        <tr
                            data-drop-folder="<?= e($subPath) ?>"
                            data-drop-folder-label="<?= e($subDisplayName) ?>"
                            oncontextmenu="if (!event.target.closest('a, input')) { event.preventDefault(); openMobileFolderMenu(<?= e((string)json_encode($subDisplayName)) ?>, <?= e((string)json_encode($subPath)) ?>, <?= $subFileCount ?>, <?= ($activeGroupFolder === null && $subFileCount > 0) ? 'true' : 'false' ?>, <?= $activeGroupFolder === null ? 'true' : 'false' ?>, <?= ($canUploadHere && $activeGroupFolder === null) ? 'true' : 'false' ?>); }"
                            class="bg-amber-50/20 dark:bg-amber-950/10 hover:bg-amber-50/60 dark:hover:bg-amber-950/25 transition-all"
                        >
                            <td class="py-3 pl-2.5 pr-1 sm:pl-6 sm:pr-2 w-8 sm:w-9 align-middle" onclick="event.stopPropagation()">
                                <input
                                    type="checkbox"
                                    value="<?= e($subPath) ?>"
                                    data-folder-path="<?= e($subPath) ?>"
                                    data-can-manage="<?= $canUploadHere ? '1' : '0' ?>"
                                    data-can-delete="<?= $canUploadHere ? '1' : '0' ?>"
                                    onchange="updateBatchSelection()"
                                    class="folder-select-checkbox w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500 cursor-pointer align-middle"
                                    aria-label="Ordner <?= e($subDisplayName) ?> auswählen"
                                >
                            </td>
                            <td class="py-3 px-1.5 sm:px-4 max-w-[140px] sm:max-w-none overflow-hidden">
                                <div class="flex items-start sm:items-center gap-2 sm:gap-3 min-w-0">
                                    <a href="<?= $subUrl ?>" class="inline-flex items-center justify-center w-8 h-7 sm:w-10 sm:h-8 rounded-lg bg-amber-100 dark:bg-amber-950/80 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5 sm:mt-0" title="Ordner öffnen (oder Dateien per Drag & Drop hier ablegen)">
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                        </svg>
                                    </a>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1 sm:gap-1.5 min-w-0">
                                            <a href="<?= $subUrl ?>" class="font-bold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-[115px] sm:max-w-xs md:max-w-md block" title="Ordner <?= e($subPath) ?> öffnen (oder Dateien direkt hierher ziehen)">
                                                <?= e($searchQuery !== '' ? $subPath : $subDisplayName) ?>
                                            </a>
                                            <?php if ($subLinkShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300" title="<?= $subLinkShares ?> öffentliche(r) Freigabelink(s)">
                                                    🔗 <?= $subLinkShares ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($subUserShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300" title="Mit <?= $subUserShares ?> Cloud-Benutzer(n) geteilt">
                                                    👤 <?= $subUserShares ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate max-w-[120px] sm:max-w-none">
                                            <?= $subFileCount ?> <?= $subFileCount === 1 ? 'Datei' : 'Dateien' ?>
                                            <span class="sm:hidden"> &bull; <?= e(formatBytes($subSize)) ?></span>
                                            <span class="hidden md:inline text-amber-700/70 dark:text-amber-400/70"> &bull; 📥 Drop-Zone</span>
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap hidden md:table-cell text-xs text-amber-700 dark:text-amber-400 font-medium">
                                📁 <?= e($subParent !== '' ? $subParent : 'Hauptverzeichnis') ?>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap text-slate-600 dark:text-slate-300 font-medium hidden sm:table-cell">
                                <?= e(formatBytes($subSize)) ?>
                            </td>

                            <td class="py-3 px-4 whitespace-nowrap text-xs text-amber-700 dark:text-amber-400 font-medium hidden lg:table-cell">
                                📁 Unterordner
                            </td>

                            <td class="py-3 pl-1 pr-2.5 sm:px-6 whitespace-nowrap text-right align-middle">
                                <div class="inline-flex items-center justify-end gap-1 sm:gap-1.5">
                                    <?php if ($activeGroupFolder === null && $subFileCount > 0): ?>
                                        <a
                                            href="download.php?folder=<?= rawurlencode($subPath) ?>"
                                            data-file-action="download"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                            title="Ordner als ZIP herunterladen"
                                            aria-label="Ordner als ZIP herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canUploadHere && $activeSharedFolder === null): ?>
                                        <!-- ORDNER UMBENENNEN BUTTON (Anheftbar über ⭐) -->
                                        <button
                                            type="button"
                                            data-file-action="rename"
                                            onclick="openRenameModal('folder', 0, <?= e((string)json_encode($subDisplayName)) ?>, <?= e((string)json_encode($subPath)) ?>)"
                                            class="hidden items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 transition-colors cursor-pointer"
                                            title="Ordner umbenennen"
                                            aria-label="Ordner umbenennen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <!-- VERSCHIEBEN / KOPIEREN BUTTON (Nur wenn als ⭐ Favorit angeheftet) -->
                                    <button
                                        type="button"
                                        data-file-action="move"
                                        onclick="openTransferModal('folder', 0, <?= e((string)json_encode($subDisplayName)) ?>, <?= e((string)json_encode($subPath)) ?>, <?= $canUploadHere ? 'true' : 'false' ?>)"
                                        class="hidden items-center justify-center w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 transition-colors cursor-pointer"
                                        title="Ordner verschieben oder kopieren"
                                        aria-label="Ordner verschieben oder kopieren"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                        </svg>
                                    </button>

                                    <?php if ($activeGroupFolder === null): ?>
                                        <button
                                            type="button"
                                            data-file-action="share"
                                            onclick="openShareModal(0, <?= e((string)json_encode($subPath)) ?>, <?= e((string)json_encode($subPath)) ?>)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 transition-colors cursor-pointer"
                                            title="Ordner teilen"
                                            aria-label="Ordner teilen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canUploadHere && $activeGroupFolder === null): ?>
                                        <form
                                            action="delete.php"
                                            method="post"
                                            data-file-action="delete"
                                            class="hidden"
                                            onsubmit="return confirm('Möchten Sie den Ordner &quot;<?= e(addslashes($subPath)) ?>&quot; <?= $subFileCount > 0 ? 'inklusive aller darin enthaltenen Dateien ' : '' ?>in den Papierkorb verschieben?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="delete_folder" value="<?= e($subPath) ?>">
                                            <?php if ($currentFolder !== ''): ?>
                                                <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                                            <?php endif; ?>
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 transition-colors cursor-pointer"
                                                title="Ordner löschen"
                                                aria-label="Ordner löschen"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- ⋯ Alle Ordner-Aktionen & Favoriten-Icons verwalten -->
                                    <button
                                        type="button"
                                        onclick="openMobileFolderMenu(<?= e((string)json_encode($subDisplayName)) ?>, <?= e((string)json_encode($subPath)) ?>, <?= $subFileCount ?>, <?= ($activeGroupFolder === null && $subFileCount > 0) ? 'true' : 'false' ?>, <?= $activeGroupFolder === null ? 'true' : 'false' ?>, <?= ($canUploadHere && $activeGroupFolder === null) ? 'true' : 'false' ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                                        title="Alle Ordner-Aktionen &amp; Favoriten-Icons anpassen (⋯)"
                                        aria-label="Ordner-Aktionen"
                                    >
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- 2. Dateien in diesem Verzeichnis -->
                    <?php
                    $previewItemsList = [];
                    foreach ($files as $fileIdx => $file):
                        $fId = (int)$file['id'];
                        $origName = (string)$file['original_name'];
                        $mimeType = (string)$file['mime_type'];
                        $badge = getFileTypeBadge($origName, $mimeType);
                        $folderPath = (string)$file['folder_path'];
                        $uploadTimestamp = strtotime((string)$file['upload_date']);
                        $formattedDate = $uploadTimestamp ? date('d.m.Y H:i', $uploadTimestamp) : (string)$file['upload_date'];
                        $formattedSize = formatBytes((int)$file['file_size']);
                        $linkShares = (int)($file['link_share_count'] ?? 0);
                        $userShares = (int)($file['user_share_count'] ?? 0);
                        $versionCount = (int)($file['version_count'] ?? 0);
                        $commentCount = (int)($file['comment_count'] ?? 0);
                        $isEncrypted = ((int)($file['is_encrypted'] ?? 0) === 1);
                        $isFavorite  = ((int)($file['is_favorite'] ?? 0) === 1);
                        $canManageThisFile = $sharedWithMeMode
                            ? ((int)($file['us_can_write'] ?? 0) === 1)
                            : ($activeSharedFolder !== null
                                ? $canWriteSharedFolder
                                : (((int)$file['user_id'] === $userId) || $canWriteActiveGf));
                        $canDeleteThisFile = $sharedWithMeMode
                            ? ((int)($file['us_can_delete'] ?? 0) === 1)
                            : ($activeSharedFolder !== null
                                ? $canDeleteSharedFolder
                                : $canManageThisFile);

                        $officeEditorType = getOfficeEditorType($origName);
                        $officeEditParams = ['id' => $fId];
                        if ($activeSharedFolder !== null) {
                            $officeEditParams['return_sf'] = (int)$activeSharedFolder['id'];
                        } elseif ($activeGroupFolder !== null) {
                            $officeEditParams['return_gf'] = (int)$activeGroupFolder['id'];
                        }
                        if ($currentFolder !== '') {
                            $officeEditParams['return_folder'] = $currentFolder;
                        }
                        $officeEditUrl = ($officeEditorType !== null && !$isEncrypted)
                            ? ('office.php?' . http_build_query($officeEditParams))
                            : '';

                        $previewItemsList[] = [
                            'id'           => $fId,
                            'name'         => $origName,
                            'mime'         => strtolower(trim($mimeType)),
                            'ext'          => strtolower(pathinfo($origName, PATHINFO_EXTENSION)),
                            'size'         => $formattedSize,
                            'date'         => $formattedDate . ' Uhr',
                            'folder'       => $folderPath !== '' ? $folderPath : 'Hauptverzeichnis',
                            'rawFolder'    => $folderPath,
                            'badgeLabel'   => $badge['label'],
                            'badgeBg'      => $badge['bg'],
                            'badgeText'    => $badge['text'],
                            'isEncrypted'  => $isEncrypted,
                            'isFavorite'   => $isFavorite,
                            'canManage'    => $canManageThisFile,
                            'versionCount' => $versionCount,
                            'commentCount' => $commentCount,
                            'officeUrl'    => $officeEditUrl,
                            'downloadUrl'  => 'download.php?id=' . $fId,
                            'inlineUrl'    => 'download.php?id=' . $fId . '&inline=1',
                            'textUrl'      => 'download.php?id=' . $fId . '&inline=1&as_text=1',
                        ];
                        $fileGfIdOrNull = !empty($file['group_folder_id']) ? (int)$file['group_folder_id'] : null;
                        if ($sharedWithMeMode) {
                            $currentLocLabel = '👤 von ' . (string)($file['uploader_name'] ?? '');
                            $folderJumpUrl   = 'index.php?shared_with_me=1';
                        } elseif ($fileGfIdOrNull !== null && $activeGroupFolder !== null) {
                            $currentLocLabel = $folderPath !== ''
                                ? '👥 ' . (string)$activeGroupFolder['name'] . ' / 📁 ' . $folderPath
                                : '👥 ' . (string)$activeGroupFolder['name'] . ' (Hauptverz.)';
                            $folderJumpUrl = 'index.php?group_folder=' . (int)$activeGroupFolder['id'] . ($folderPath !== '' ? '&folder=' . rawurlencode($folderPath) : '');
                        } else {
                            $currentLocLabel = $folderPath !== '' ? '📁 ' . $folderPath : '🏠 Hauptverzeichnis';
                            $folderJumpUrl = $folderPath !== '' ? ('index.php?folder=' . rawurlencode($folderPath)) : 'index.php';
                        }
                    ?>
                        <tr
                            class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors <?= $canManageThisFile ? 'cursor-grab active:cursor-grabbing' : '' ?>"
                            data-file-row-id="<?= $fId ?>"
                            oncontextmenu="if (!event.target.closest('a, input')) { event.preventDefault(); openMobileFileMenu(<?= (int)$fileIdx ?>, <?= $canDeleteThisFile ? 'true' : 'false' ?>); }"
                            <?php if ($canManageThisFile): ?>
                                draggable="true"
                                data-drag-file-id="<?= $fId ?>"
                                data-drag-file-name="<?= e($origName) ?>"
                            <?php endif; ?>
                        >
                            <td class="py-3.5 pl-2.5 pr-1 sm:pl-6 sm:pr-2 w-8 sm:w-9 align-middle">
                                <input
                                    type="checkbox"
                                    value="<?= $fId ?>"
                                    data-file-id="<?= $fId ?>"
                                    data-can-manage="<?= $canManageThisFile ? '1' : '0' ?>"
                                    data-can-delete="<?= $canDeleteThisFile ? '1' : '0' ?>"
                                    onchange="updateBatchSelection()"
                                    class="file-select-checkbox w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-brand-600 focus:ring-brand-500 cursor-pointer align-middle"
                                    aria-label="Datei <?= e($origName) ?> auswählen"
                                >
                            </td>
                            <td class="py-3.5 px-1.5 sm:px-4 max-w-[140px] sm:max-w-none overflow-hidden">
                                <div class="flex items-start sm:items-center gap-2 sm:gap-3 min-w-0">
                                    <?php if ($canManageThisFile): ?>
                                        <button
                                            type="button"
                                            onclick="toggleFavorite(<?= $fId ?>, this)"
                                            data-fav-btn-id="<?= $fId ?>"
                                            data-fav="<?= $isFavorite ? '1' : '0' ?>"
                                            class="hidden sm:inline-block text-base leading-none shrink-0 cursor-pointer transition-transform hover:scale-110 mt-1 sm:mt-0 <?= $isFavorite ? 'text-amber-400' : 'text-slate-300 dark:text-slate-600 hover:text-amber-400' ?>"
                                            title="<?= $isFavorite ? 'Aus Favoriten entfernen' : 'Als Favorit markieren' ?>"
                                            aria-label="Favorit umschalten"
                                        >
                                            <?= $isFavorite ? '⭐' : '☆' ?>
                                        </button>
                                    <?php endif; ?>
                                    <button
                                        type="button"
                                        onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                                        title="Vorschau öffnen"
                                        class="inline-flex items-center justify-center w-8 h-7 sm:w-10 sm:h-8 rounded-lg text-[9px] sm:text-[10px] font-bold tracking-tight <?= e($badge['bg']) ?> <?= e($badge['text']) ?> shrink-0 cursor-pointer hover:opacity-80 transition-opacity mt-0.5 sm:mt-0"
                                    >
                                        <?= e($badge['label']) ?>
                                    </button>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1 sm:gap-1.5 min-w-0">
                                            <button
                                                type="button"
                                                onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                                                class="font-medium text-left text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate max-w-[115px] sm:max-w-xs md:max-w-md block cursor-pointer transition-colors"
                                                title="<?= e($origName) ?> (Klicken für Vorschau)"
                                            >
                                                <?= e($origName) ?>
                                            </button>
                                            <?php if ($largeFilesMode && !empty($duplicateIds[$fId])): ?>
                                                <?php if (!empty($duplicateHashIds[$fId])): ?>
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 dark:bg-rose-950 text-rose-800 dark:text-rose-300 border border-rose-300/70 dark:border-rose-800/70" title="Exakt identischer Dateiinhalt (SHA-256 Hash-Übereinstimmung)">
                                                        🧬 SHA-256 Duplikat
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300" title="Potenzielles Duplikat (gleicher Dateiname &amp; gleiche Dateigröße)">
                                                        👯 Duplikat
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($isEncrypted): ?>
                                                <button
                                                    type="button"
                                                    onclick="openVaultModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, true, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 border border-amber-300/70 dark:border-amber-800/70 hover:bg-amber-200/70 cursor-pointer transition-colors"
                                                    title="Tresor-geschützte Datei (AES-256 Zero-Knowledge)"
                                                >
                                                    🔒 Tresor
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($linkShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300" title="<?= $linkShares ?> öffentliche(r) Freigabelink(s)">
                                                    🔗 <?= $linkShares ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($userShares > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300" title="Mit <?= $userShares ?> Cloud-Benutzer(n) geteilt">
                                                    👤 <?= $userShares ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($versionCount > 0): ?>
                                                <button
                                                    type="button"
                                                    onclick="openFileMetaModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, 'versions')"
                                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 hover:bg-purple-200 dark:hover:bg-purple-900 cursor-pointer transition-colors"
                                                    title="<?= $versionCount ?> frühere Dateiversion(en) verfügbar (Klicken für Zeitreise / Wiederherstellung)"
                                                >
                                                    🕓 <?= $versionCount ?>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($commentCount > 0): ?>
                                                <button
                                                    type="button"
                                                    onclick="openFileMetaModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, 'comments')"
                                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300 hover:bg-teal-200 dark:hover:bg-teal-900 cursor-pointer transition-colors"
                                                    title="<?= $commentCount ?> Datei-Kommentar(e) / Notizen"
                                                >
                                                    💬 <?= $commentCount ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-[11px] text-slate-400 flex flex-wrap items-center gap-1 sm:gap-1.5 mt-0.5 min-w-0">
                                            <span class="sm:hidden truncate max-w-[115px]"><?= e($formattedSize) ?> &bull; <?= e($formattedDate) ?></span>
                                            <span class="hidden sm:inline truncate max-w-[220px]"><?= e($mimeType) ?></span>
                                            <?php if ($searchQuery !== '' || $onlyFavorites): ?>
                                                <a href="<?= e($folderJumpUrl) ?>" class="md:hidden inline-flex items-center gap-1 text-brand-600 dark:text-brand-400 hover:underline font-medium truncate max-w-[115px]">
                                                    &bull; <?= e($currentLocLabel) ?>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($activeGroupFolder !== null || $activeSharedFolder !== null || $sharedWithMeMode): ?>
                                                <span class="truncate max-w-[115px] sm:max-w-none">&bull; 👤 von <?= e((string)$file['uploader_name']) ?></span>
                                            <?php endif; ?>
                                        </p>
                                        <?php if ($searchQuery !== '' && !$isEncrypted && !empty($file['fsi_content_text'])): ?>
                                            <?php $ftSnip = buildFulltextSnippet((string)$file['fsi_content_text'], $searchQuery, 120); ?>
                                            <?php if ($ftSnip !== ''): ?>
                                                <p class="text-[11px] italic text-indigo-600 dark:text-indigo-400 truncate max-w-[200px] sm:max-w-md mt-0.5" title="<?= e($ftSnip) ?>">
                                                    🔎 „<?= e($ftSnip) ?>“
                                                </p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td class="py-3.5 px-4 whitespace-nowrap hidden md:table-cell text-xs text-slate-600 dark:text-slate-300 font-medium">
                                <a href="<?= e($folderJumpUrl) ?>" class="truncate block max-w-[180px] hover:text-brand-600 dark:hover:text-brand-400 transition-colors" title="Zum Ordner wechseln: <?= e($currentLocLabel) ?>">
                                    <?= e($currentLocLabel) ?>
                                </a>
                            </td>

                            <td class="py-3.5 px-4 whitespace-nowrap text-slate-600 dark:text-slate-300 font-medium hidden sm:table-cell">
                                <?= e($formattedSize) ?>
                            </td>

                            <td class="py-3.5 px-4 whitespace-nowrap text-slate-500 dark:text-slate-400 text-xs hidden lg:table-cell">
                                <?= e($formattedDate) ?> Uhr
                            </td>

                            <td class="py-3.5 pl-1 pr-2.5 sm:px-6 whitespace-nowrap text-right align-middle">
                                <!-- Aufgeräumte Aktionsleiste: Zeigt nur als ⭐ Favorit markierte Aktions-Icons + den ⋯ Menü-Button -->
                                <div class="inline-flex items-center justify-end gap-1 sm:gap-1.5">
                                    <!-- VORSCHAU-BUTTON -->
                                    <button
                                        type="button"
                                        data-file-action="preview"
                                        onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-violet-50 dark:bg-violet-950/60 hover:bg-violet-100 dark:hover:bg-violet-900/70 text-violet-700 dark:text-violet-300 transition-colors cursor-pointer"
                                        title="Vorschau"
                                        aria-label="Vorschau"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                    </button>

                                    <?php if ($officeEditUrl !== ''): ?>
                                        <!-- OFFICE LIGHT BEARBEITEN BUTTON -->
                                        <a
                                            href="<?= e($officeEditUrl) ?>"
                                            data-file-action="office"
                                            class="hidden items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 transition-colors"
                                            title="<?= $canManageThisFile ? 'In Office Light bearbeiten' : 'In Office Light öffnen (Schreibgeschützt)' ?>"
                                            aria-label="<?= $canManageThisFile ? 'In Office Light bearbeiten' : 'In Office Light öffnen' ?>"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!$isEncrypted && strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'epub' && (($appSettings['enable_ebooks'] ?? '1') === '1')): ?>
                                        <!-- E-BOOK LESEN BUTTON (.epub) -->
                                        <a
                                            href="ebooks.php?id=<?= $fId ?>"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 transition-colors"
                                            title="Im E-Book-Reader lesen (.epub)"
                                            aria-label="Im E-Book-Reader lesen"
                                        >
                                            <span class="text-xs leading-none">📖</span>
                                        </a>
                                    <?php endif; ?>

                                    <!-- HERUNTERLADEN-BUTTON -->
                                    <?php if ($isEncrypted): ?>
                                        <button
                                            type="button"
                                            data-file-action="download"
                                            onclick="openVaultModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, true, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors cursor-pointer"
                                            title="Entschlüsseln &amp; Herunterladen"
                                            aria-label="Entschlüsseln &amp; Herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </button>
                                    <?php else: ?>
                                        <a
                                            href="download.php?id=<?= $fId ?>"
                                            data-file-action="download"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-sky-50 dark:bg-sky-950/60 hover:bg-sky-100 dark:hover:bg-sky-900/70 text-sky-700 dark:text-sky-300 transition-colors"
                                            title="Herunterladen"
                                            aria-label="Herunterladen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v11m0 0l-3.5-3.5M12 15l3.5-3.5M5 19h14"/>
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($canManageThisFile || $isEncrypted): ?>
                                        <!-- TRESOR-BUTTON (Einzeldatei-Verschlüsselung AES-256) -->
                                        <button
                                            type="button"
                                            data-file-action="vault"
                                            onclick="openVaultModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, <?= $isEncrypted ? 'true' : 'false' ?>, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                            class="hidden items-center justify-center w-8 h-8 rounded-lg <?= $isEncrypted ? 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300 hover:bg-amber-200/80 border border-amber-300/70 dark:border-amber-800/70' : 'bg-amber-50 dark:bg-amber-950/50 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-700 dark:text-amber-300' ?> transition-colors cursor-pointer"
                                            title="<?= $isEncrypted ? 'Tresor-Schutz verwalten / entschlüsseln' : 'Im Tresor verschlüsseln (AES-256)' ?>"
                                            aria-label="<?= $isEncrypted ? 'Tresor-Schutz verwalten / entschlüsseln' : 'Im Tresor verschlüsseln (AES-256)' ?>"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <!-- VERSIONEN & KOMMENTARE BUTTON -->
                                    <button
                                        type="button"
                                        data-file-action="meta"
                                        onclick="openFileMetaModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, 'comments')"
                                        class="hidden items-center justify-center w-8 h-8 rounded-lg <?= ($versionCount > 0 || $commentCount > 0) ? 'bg-teal-100 dark:bg-teal-950/80 text-teal-800 dark:text-teal-300 border border-teal-300/70 dark:border-teal-800/70' : 'bg-teal-50 dark:bg-teal-950/50 hover:bg-teal-100 dark:hover:bg-teal-900/60 text-teal-700 dark:text-teal-300' ?> transition-colors cursor-pointer"
                                        title="Kommentare &amp; Dateiversionen (🕓 <?= $versionCount ?> / 💬 <?= $commentCount ?>)"
                                        aria-label="Kommentare &amp; Dateiversionen"
                                    >
                                        <span class="text-xs leading-none">💬</span>
                                    </button>

                                    <?php if ($canManageThisFile): ?>
                                        <!-- DATEI UMBENENNEN BUTTON (Anheftbar über ⭐) -->
                                        <button
                                            type="button"
                                            data-file-action="rename"
                                            onclick="openRenameModal('file', <?= $fId ?>, <?= e((string)json_encode($origName)) ?>, <?= e((string)json_encode($folderPath)) ?>)"
                                            class="hidden items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/70 text-indigo-700 dark:text-indigo-300 transition-colors cursor-pointer"
                                            title="Datei umbenennen"
                                            aria-label="Datei umbenennen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <!-- VERSCHIEBEN / KOPIEREN BUTTON -->
                                    <button
                                        type="button"
                                        data-file-action="move"
                                        onclick="openTransferModal('file', <?= $fId ?>, <?= e((string)json_encode($origName)) ?>, <?= e((string)json_encode($folderPath)) ?>, <?= $canManageThisFile ? 'true' : 'false' ?>)"
                                        class="hidden items-center justify-center w-8 h-8 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/70 text-amber-700 dark:text-amber-300 transition-colors cursor-pointer"
                                        title="Datei verschieben oder kopieren"
                                        aria-label="Datei verschieben oder kopieren"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                        </svg>
                                    </button>

                                    <?php if ($canManageThisFile): ?>
                                        <!-- TEILEN-BUTTON -->
                                        <button
                                            type="button"
                                            data-file-action="share"
                                            onclick="openShareModal(<?= $fId ?>, <?= e((string)json_encode($origName)) ?>, '')"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/70 text-emerald-700 dark:text-emerald-300 transition-colors cursor-pointer"
                                            title="Teilen"
                                            aria-label="Teilen"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                                            </svg>
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canDeleteThisFile): ?>
                                        <!-- LÖSCHEN-BUTTON -->
                                        <form
                                            action="delete.php"
                                            method="post"
                                            data-file-action="delete"
                                            class="hidden"
                                            onsubmit="return confirm('Möchten Sie die Datei &quot;<?= e(addslashes((string)$file['original_name'])) ?>&quot; in den Papierkorb verschieben?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="file_id" value="<?= $fId ?>">
                                            <?php if ($currentFolder !== ''): ?>
                                                <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                                            <?php endif; ?>
                                            <?php if ($activeSharedFolder !== null): ?>
                                                <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
                                            <?php elseif ($activeGroupFolder !== null): ?>
                                                <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                                            <?php endif; ?>
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 dark:hover:bg-red-900/70 text-red-700 dark:text-red-300 transition-colors cursor-pointer"
                                                title="Löschen"
                                                aria-label="Löschen"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- ⋯ Alle Datei-Aktionen & Favoriten-Icons anpassen (Mobil & Desktop) -->
                                    <button
                                        type="button"
                                        onclick="openMobileFileMenu(<?= (int)$fileIdx ?>, <?= $canDeleteThisFile ? 'true' : 'false' ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                                        title="Alle Datei-Aktionen &amp; Favoriten-Icons anpassen (⋯)"
                                        aria-label="Datei-Aktionen"
                                    >
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Ganz unten angeheftet: Andersfarbiger virtueller Ordner "👥 Für mich freigegeben" -->
                    <?php if ($showSharedVirtualFolder): ?>
                        <tr class="bg-blue-50/35 dark:bg-blue-950/20 hover:bg-blue-100/50 dark:hover:bg-blue-950/40 transition-all border-t-2 border-blue-200/70 dark:border-blue-800/70">
                            <td class="py-3 pl-2.5 pr-1 sm:pl-6 sm:pr-2 w-8 sm:w-9 align-middle text-center">
                                <span class="text-xs text-blue-500 dark:text-blue-400" title="Systemordner für empfangene Benutzer-Freigaben (unten angeheftet)">👥</span>
                            </td>
                            <td class="py-3 px-1.5 sm:px-4 max-w-[140px] sm:max-w-none overflow-hidden">
                                <div class="flex items-start sm:items-center gap-2 sm:gap-3 min-w-0">
                                    <a href="index.php?shared_with_me=1" class="inline-flex items-center justify-center w-8 h-7 sm:w-10 sm:h-8 rounded-lg bg-blue-100 dark:bg-blue-900/80 text-blue-600 dark:text-blue-300 shrink-0 mt-0.5 sm:mt-0" title="Von anderen Cloud-Benutzern für mich freigegebene Ordner &amp; Dateien öffnen">
                                        <svg class="w-4 h-4 sm:w-5 sm:h-5" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                                        </svg>
                                    </a>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                            <a href="index.php?shared_with_me=1" class="font-bold text-blue-900 dark:text-blue-200 hover:text-blue-600 dark:hover:text-blue-400 truncate max-w-[140px] sm:max-w-xs md:max-w-md block">
                                                Für mich freigegeben (Von Cloud-Benutzern)
                                            </a>
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold <?= $totalSharedWithMeCount > 0 ? 'bg-blue-600 text-white shadow-2xs' : 'bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300' ?>">
                                                👥 <?= $totalSharedWithMeCount ?>
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-blue-700/80 dark:text-blue-300/80 mt-0.5 truncate max-w-[140px] sm:max-w-none">
                                            <?= count($sharedWithMeFolders) ?> <?= count($sharedWithMeFolders) === 1 ? 'Ordner' : 'Ordner' ?> &bull;
                                            <?= count($sharedWithMeFiles) ?> <?= count($sharedWithMeFiles) === 1 ? 'Datei' : 'Dateien' ?>
                                            <span class="sm:hidden"> &bull; <?= e(formatBytes($totalSharedWithMeSize)) ?></span>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 px-4 whitespace-nowrap hidden md:table-cell text-xs text-blue-700 dark:text-blue-400 font-medium">
                                👥 Cloud-Freigaben
                            </td>
                            <td class="py-3 px-4 whitespace-nowrap text-slate-600 dark:text-slate-300 font-medium hidden sm:table-cell">
                                <?= e(formatBytes($totalSharedWithMeSize)) ?>
                            </td>
                            <td class="py-3 px-4 whitespace-nowrap text-xs text-blue-700 dark:text-blue-400 font-medium hidden lg:table-cell">
                                👥 Geteilter Bereich
                            </td>
                            <td class="py-3 pl-1 pr-2.5 sm:px-6 whitespace-nowrap text-right align-middle">
                                <a
                                    href="index.php?shared_with_me=1"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs transition-colors shadow-2xs"
                                >
                                    <span>Öffnen</span>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- FOTO-GALERIE / KACHEL-ANSICHT (#fileGridView) -->
        <div id="fileGridView" class="hidden p-4 sm:p-6 grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3.5">
            <?php if (($currentFolder !== '' && $activeSharedFolder === null) || $sharedWithMeMode || $activeSharedFolder !== null): ?>
                <?php
                if ($sharedWithMeMode) {
                    $upParentGrid = '';
                    $upUrlGrid    = 'index.php';
                    $upLabelGrid  = 'Hauptverzeichnis';
                } elseif ($activeSharedFolder !== null) {
                    $upParentGrid = '';
                    $upUrlGrid    = 'index.php?shared_with_me=1';
                    $upLabelGrid  = '👥 Für mich freigegeben';
                } else {
                    $upParentGrid = str_contains($currentFolder, '/') ? dirname($currentFolder) : '';
                    $upUrlGrid = 'index.php'
                        . ($activeGroupFolder !== null ? '?group_folder=' . (int)$activeGroupFolder['id'] . ($upParentGrid !== '' ? '&amp;folder=' . rawurlencode($upParentGrid) : '') : ($upParentGrid !== '' ? '?folder=' . rawurlencode($upParentGrid) : ''));
                    $upLabelGrid = $upParentGrid !== '' ? $upParentGrid : 'Hauptverzeichnis';
                }
                ?>
                <a
                    href="<?= $upUrlGrid ?>"
                    <?php if (!$sharedWithMeMode && $activeSharedFolder === null): ?>
                        data-drop-folder="<?= e($upParentGrid) ?>"
                        data-drop-folder-label="<?= e($upLabelGrid) ?>"
                    <?php endif; ?>
                    class="group flex flex-col justify-between p-3.5 rounded-2xl bg-slate-50/70 dark:bg-slate-800/40 border border-dashed border-slate-300 dark:border-slate-700 hover:border-brand-400 dark:hover:border-brand-500 transition-all shadow-2xs"
                    title="Übergeordneter Ordner"
                >
                    <div class="flex items-center justify-center h-28 rounded-xl bg-slate-200/60 dark:bg-slate-800 text-slate-600 dark:text-slate-300 mb-2.5 group-hover:scale-[1.02] transition-transform text-xl font-bold">
                        &crarr; ..
                    </div>
                    <div>
                        <p class="text-xs sm:text-sm font-bold text-slate-800 dark:text-slate-200 truncate">
                            Übergeordneter Ordner
                        </p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 truncate">
                            <?= e($upLabelGrid) ?>
                        </p>
                    </div>
                </a>
            <?php endif; ?>

            <?php if ($sharedWithMeMode): ?>
                <?php foreach ($sharedWithMeFolders as $sFolder): ?>
                    <?php
                    $usId    = (int)$sFolder['user_share_id'];
                    $sfPath  = (string)$sFolder['folder_path'];
                    $sfOwner = (string)$sFolder['owner_username'];
                    $sfCount = (int)$sFolder['file_count'];
                    $sfSize  = (int)$sFolder['folder_size'];
                    ?>
                    <a
                        href="index.php?shared_folder=<?= $usId ?>"
                        class="group flex flex-col justify-between p-3.5 rounded-2xl bg-blue-50/40 dark:bg-blue-950/20 border border-blue-200/80 dark:border-blue-800/60 hover:border-blue-400 dark:hover:border-blue-500 transition-all shadow-2xs"
                        title="Freigegebenen Ordner <?= e($sfPath) ?> (von <?= e($sfOwner) ?>) öffnen"
                    >
                        <div class="flex items-center justify-center h-28 rounded-xl bg-blue-100/70 dark:bg-blue-950/60 text-blue-600 dark:text-blue-300 mb-2.5 group-hover:scale-[1.02] transition-transform">
                            <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate" title="<?= e($sfPath) ?>">
                                📁 <?= e($sfPath) ?>
                            </p>
                            <p class="text-[11px] text-blue-700 dark:text-blue-300 mt-0.5 truncate">
                                👤 <?= e($sfOwner) ?> &bull; <?= $sfCount ?> <?= $sfCount === 1 ? 'Datei' : 'Dateien' ?> (<?= e(formatBytes($sfSize)) ?>)
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php foreach ($directSubfolders as $subFolder): ?>
                <?php
                $subPath = (string)$subFolder['folder_path'];
                $subDisplayName = basename($subPath);
                $subFileCount = (int)$subFolder['file_count'];
                $subSize = (int)$subFolder['folder_size'];
                $subUrl = 'index.php?' . ($activeGroupFolder !== null ? 'group_folder=' . (int)$activeGroupFolder['id'] . '&amp;' : '') . 'folder=' . rawurlencode($subPath);
                ?>
                <a
                    href="<?= $subUrl ?>"
                    data-drop-folder="<?= e($subPath) ?>"
                    data-drop-folder-label="<?= e($subDisplayName) ?>"
                    class="group flex flex-col justify-between p-3.5 rounded-2xl bg-amber-50/40 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-800/60 hover:border-amber-400 dark:hover:border-amber-600 transition-all shadow-2xs"
                    title="Ordner <?= e($subPath) ?> öffnen (oder Dateien per Drag & Drop direkt hierher ziehen)"
                >
                    <div class="flex items-center justify-center h-28 rounded-xl bg-amber-100/70 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 mb-2.5 group-hover:scale-[1.02] transition-transform">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white truncate" title="<?= e($subPath) ?>">
                            📁 <?= e($searchQuery !== '' ? $subPath : $subDisplayName) ?>
                        </p>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                            <?= $subFileCount ?> <?= $subFileCount === 1 ? 'Datei' : 'Dateien' ?> &bull; <?= e(formatBytes($subSize)) ?> &bull; 📥
                        </p>
                    </div>
                </a>
            <?php endforeach; ?>

            <?php foreach ($files as $fileIdx => $file): ?>
                <?php
                $fId = (int)$file['id'];
                $origName = (string)$file['original_name'];
                $mimeType = strtolower(trim((string)$file['mime_type']));
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $badge = getFileTypeBadge($origName, (string)$file['mime_type']);
                $isEncrypted = ((int)($file['is_encrypted'] ?? 0) === 1);
                $isFavorite  = ((int)($file['is_favorite'] ?? 0) === 1);
                $isImageThumb = !$isEncrypted && (str_starts_with($mimeType, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'], true));
                $canManageThisFile = $sharedWithMeMode
                    ? ((int)($file['us_can_write'] ?? 0) === 1)
                    : ($activeSharedFolder !== null
                        ? $canWriteSharedFolder
                        : (((int)$file['user_id'] === $userId) || $canWriteActiveGf));
                $canDeleteThisFile = $sharedWithMeMode
                    ? ((int)($file['us_can_delete'] ?? 0) === 1)
                    : ($activeSharedFolder !== null
                        ? $canDeleteSharedFolder
                        : $canManageThisFile);
                ?>
                <div
                    class="group relative flex flex-col justify-between p-2.5 rounded-2xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 hover:border-brand-400 dark:hover:border-brand-500 transition-all shadow-2xs <?= $canManageThisFile ? 'cursor-grab active:cursor-grabbing' : '' ?>"
                    <?php if ($canManageThisFile): ?>
                        draggable="true"
                        data-drag-file-id="<?= $fId ?>"
                        data-drag-file-name="<?= e($origName) ?>"
                    <?php endif; ?>
                >
                    <!-- Top Overlay: Favorit + Aktionen -->
                    <div class="absolute top-4 left-4 right-4 z-10 flex items-center justify-between pointer-events-none">
                        <?php if ($canManageThisFile): ?>
                            <button
                                type="button"
                                onclick="toggleFavorite(<?= $fId ?>, this)"
                                data-fav-btn-id="<?= $fId ?>"
                                data-fav="<?= $isFavorite ? '1' : '0' ?>"
                                class="pointer-events-auto w-7 h-7 rounded-lg bg-white/90 dark:bg-slate-900/90 shadow-xs flex items-center justify-center text-sm cursor-pointer hover:scale-110 transition-transform"
                                title="Favorit umschalten"
                            >
                                <?= $isFavorite ? '⭐' : '☆' ?>
                            </button>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>

                        <button
                            type="button"
                            onclick="openMobileFileMenu(<?= (int)$fileIdx ?>, <?= $canDeleteThisFile ? 'true' : 'false' ?>)"
                            class="pointer-events-auto w-7 h-7 rounded-lg bg-white/90 dark:bg-slate-900/90 shadow-xs flex items-center justify-center text-slate-700 dark:text-slate-200 cursor-pointer hover:bg-brand-50"
                            title="Datei-Aktionen"
                        >
                            ⋯
                        </button>
                    </div>

                    <!-- Thumbnail / Icon -->
                    <button
                        type="button"
                        onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                        class="w-full h-32 rounded-xl overflow-hidden bg-slate-100 dark:bg-slate-900 flex items-center justify-center mb-2.5 cursor-pointer"
                    >
                        <?php if ($isImageThumb): ?>
                            <img
                                src="thumbnail.php?id=<?= $fId ?>"
                                alt="<?= e($origName) ?>"
                                loading="lazy"
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                            >
                        <?php else: ?>
                            <span class="inline-flex items-center justify-center px-3 py-1.5 rounded-xl text-xs font-extrabold <?= e($badge['bg']) ?> <?= e($badge['text']) ?>">
                                <?= $isEncrypted ? '🔒 ' : '' ?><?= e($badge['label']) ?>
                            </span>
                        <?php endif; ?>
                    </button>

                    <div class="min-w-0 px-1">
                        <button
                            type="button"
                            onclick="openPreviewModal(<?= (int)$fileIdx ?>)"
                            class="w-full text-left text-xs font-semibold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400 truncate cursor-pointer"
                            title="<?= e($origName) ?>"
                        >
                            <?= e($origName) ?>
                        </button>
                        <p class="text-[11px] text-slate-400 truncate">
                            <?= e(formatBytes((int)$file['file_size'])) ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($showSharedVirtualFolder): ?>
                <a
                    href="index.php?shared_with_me=1"
                    class="group flex flex-col justify-between p-3.5 rounded-2xl bg-blue-50/50 dark:bg-blue-950/25 border border-blue-200/80 dark:border-blue-800/70 hover:border-blue-400 dark:hover:border-blue-500 transition-all shadow-2xs"
                    title="Für mich freigegebene Ordner &amp; Dateien von anderen Cloud-Benutzern öffnen (unten angeheftet)"
                >
                    <div class="flex items-center justify-center h-28 rounded-xl bg-blue-100/80 dark:bg-blue-900/60 text-blue-600 dark:text-blue-300 mb-2.5 group-hover:scale-[1.02] transition-transform">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs sm:text-sm font-bold text-blue-900 dark:text-blue-200 truncate">
                            👥 Für mich freigegeben
                        </p>
                        <p class="text-[11px] text-blue-700/80 dark:text-blue-300/80 mt-0.5">
                            <?= count($sharedWithMeFolders) ?> Ordner &bull; <?= count($sharedWithMeFiles) ?> <?= count($sharedWithMeFiles) === 1 ? 'Datei' : 'Dateien' ?>
                        </p>
                    </div>
                </a>
            <?php endif; ?>
        </div>

        <?php if (!$largeFilesMode && $totalPages > 1): ?>
            <?php
            $buildPageUrl = static function (int $targetPage): string {
                $qp = $_GET;
                if ($targetPage <= 1) {
                    unset($qp['page']);
                } else {
                    $qp['page'] = (string)$targetPage;
                }
                return 'index.php' . (!empty($qp) ? '?' . http_build_query($qp) : '');
            };
            ?>
            <div class="px-4 py-3.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/30 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Seite <strong><?= $currentPage ?></strong> von <strong><?= $totalPages ?></strong>
                    <span class="hidden sm:inline">(&bull; <?= $totalMatchingFiles ?> Dateien insgesamt, max. <?= $perPage ?> pro Seite)</span>
                </p>
                <div class="flex items-center gap-1.5">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?= e($buildPageUrl($currentPage - 1)) ?>"
                           class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 transition-colors">
                            &larr; Zurück
                        </a>
                    <?php endif; ?>
                    <?php
                    $startP = max(1, $currentPage - 2);
                    $endP   = min($totalPages, $currentPage + 2);
                    for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <a href="<?= e($buildPageUrl($p)) ?>"
                           class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors <?= $p === $currentPage ? 'bg-brand-600 text-white shadow-2xs' : 'bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?= e($buildPageUrl($currentPage + 1)) ?>"
                           class="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-200 transition-colors">
                            Weiter &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- SCHWEBENDE SAMMEL-AKTIONSLEISTE (Batch-Aktionen bei Mehrfachauswahl) -->
<div
    id="batchActionBar"
    class="fixed bottom-4 left-1/2 -translate-x-1/2 z-40 hidden items-center gap-2 sm:gap-3 px-4 py-2.5 rounded-2xl bg-slate-900/95 dark:bg-slate-800/95 text-white shadow-2xl border border-slate-700 backdrop-blur-md max-w-[95vw]"
>
    <span class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-bold text-brand-300 whitespace-nowrap">
        ☑️ <span id="batchSelectedCount">0</span> ausgewählt
    </span>
    <div class="h-4 w-px bg-slate-700"></div>
    <button
        type="button"
        id="btnBatchZip"
        onclick="downloadBatchZip()"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition-colors cursor-pointer whitespace-nowrap"
        title="Ausgewählte Dateien als ZIP herunterladen"
    >
        <span>⬇️ ZIP</span>
    </button>
    <button
        type="button"
        id="btnBatchTransfer"
        onclick="openBatchTransferModal()"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold transition-colors cursor-pointer whitespace-nowrap"
        title="Ausgewählte Ordner &amp; Dateien verschieben oder kopieren"
    >
        <span>⇄ Verschieben / Kopieren</span>
    </button>
    <button
        type="button"
        id="btnBatchDelete"
        onclick="deleteBatchFiles()"
        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-red-600 hover:bg-red-500 text-white text-xs font-semibold transition-colors cursor-pointer whitespace-nowrap"
        title="Ausgewählte Ordner &amp; Dateien in den Papierkorb verschieben"
    >
        <span>🗑️ Löschen</span>
    </button>
    <button
        type="button"
        onclick="clearBatchSelection()"
        class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition-colors cursor-pointer"
        title="Auswahl aufheben"
        aria-label="Auswahl aufheben"
    >
        ✕
    </button>
</div>

<form id="batchDeleteForm" action="delete.php" method="post" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
    <input type="hidden" name="batch_file_ids" id="batchDeleteIdsInput" value="">
    <input type="hidden" name="batch_folders" id="batchDeleteFoldersInput" value="">
    <?php if ($currentFolder !== ''): ?>
        <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
    <?php endif; ?>
    <?php if ($activeSharedFolder !== null): ?>
        <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
    <?php elseif ($activeGroupFolder !== null): ?>
        <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
    <?php endif; ?>
</form>

<!-- TEILEN-MODAL (Popup für öffentliche Links & Teilen mit Cloud-Benutzern für Dateien UND Ordner) -->
<div
    id="shareModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="shareModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-lg rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="min-w-0">
                <h3 id="shareModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                    Element teilen
                </h3>
                <p id="shareModalFilename" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
            </div>
            <button
                type="button"
                onclick="closeShareModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-5 overflow-y-auto space-y-6">
            <input type="hidden" id="shareModalFileId" value="">
            <input type="hidden" id="shareModalFolderPath" value="">

            <!-- 1. Öffentlichen Freigabelink generieren -->
            <div class="space-y-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    1. Öffentlicher Freigabelink (für Freunde)
                </h4>

                <form id="createShareLinkForm" class="space-y-3 bg-slate-50 dark:bg-slate-800/50 p-3.5 rounded-xl border border-slate-200/80 dark:border-slate-700/80">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <div>
                            <label for="shareLinkPassword" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Passwort (optional)
                            </label>
                            <input
                                type="password"
                                id="shareLinkPassword"
                                name="password"
                                placeholder="Kein Passwort"
                                class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                        </div>
                        <div>
                            <label for="shareLinkExpires" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Ablaufdatum
                            </label>
                            <select
                                id="shareLinkExpires"
                                name="expires_days"
                                class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                                <option value="0">Unbegrenzt gültig</option>
                                <option value="1">1 Tag gültig</option>
                                <option value="7">7 Tage gültig</option>
                                <option value="30">30 Tage gültig</option>
                            </select>
                        </div>
                        <div>
                            <label for="shareLinkMaxDownloads" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1" title="Selbstzerstörender Link (Burn after reading)">
                                Max. Downloads 🔥
                            </label>
                            <select
                                id="shareLinkMaxDownloads"
                                name="max_downloads"
                                class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                            >
                                <option value="0">Unbegrenzt</option>
                                <option value="1">🔥 1× (Selbstzerstörend)</option>
                                <option value="3">🔥 3× Downloads</option>
                                <option value="5">5× Downloads</option>
                                <option value="10">10× Downloads</option>
                                <option value="25">25× Downloads</option>
                            </select>
                        </div>
                    </div>

                    <!-- Freigabe-Modus für Ordner-Links (Idee 1: Digitaler Briefkasten / File-Drop) -->
                    <div id="shareLinkModeWrapper" class="hidden">
                        <label for="shareLinkMode" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Ordner-Modus für Besucher dieses Links
                        </label>
                        <select
                            id="shareLinkMode"
                            name="share_mode"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="read">👁️ Nur Download (Besucher können Dateien ansehen &amp; herunterladen)</option>
                            <option value="upload_only">📤 Digitaler Briefkasten / Nur Hochladen (Besucher sehen keine fremden Dateien)</option>
                            <option value="read_write">🔄 Download &amp; Upload (Gemeinsamer Austausch-Ordner)</option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        id="btnGenerateLink"
                        class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        <span>Link &amp; QR-Code generieren</span>
                    </button>
                </form>

                <!-- Frisch generierter Link mit Kopieren-Button & lokalem QR-Code -->
                <div id="generatedLinkBox" class="hidden p-3.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 space-y-3">
                    <div class="flex items-center justify-between text-xs font-semibold text-emerald-800 dark:text-emerald-300">
                        <span>Fertiger Freigabelink &amp; QR-Code:</span>
                        <span id="copyFeedback" class="hidden text-emerald-600 dark:text-emerald-400">✓ Kopiert!</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            id="generatedLinkInput"
                            readonly
                            class="flex-1 px-3 py-2 rounded-lg bg-white dark:bg-slate-900 border border-emerald-300 dark:border-emerald-700 text-xs font-mono text-slate-800 dark:text-slate-100 focus:outline-none"
                        >
                        <button
                            type="button"
                            onclick="copyGeneratedLink()"
                            class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shrink-0 cursor-pointer"
                        >
                            Kopieren
                        </button>
                    </div>
                    <div id="generatedLinkQrContainer" class="hidden pt-1 flex flex-col sm:flex-row items-center gap-3">
                        <div id="generatedLinkQrSvg" class="shrink-0"></div>
                        <div class="text-xs text-emerald-900 dark:text-emerald-200 space-y-1.5 text-center sm:text-left">
                            <p class="font-semibold">📱 Direkt per Smartphone scannen</p>
                            <p class="text-[11px] text-emerald-700 dark:text-emerald-300/80">
                                100 % lokal auf Ihrem Server erzeugt (ohne externe Dienste). Ideal zum schnellen Teilen oder Ausdrucken für den Digitalen Briefkasten.
                            </p>
                            <button
                                type="button"
                                onclick="downloadQrCodeSvg('generatedLinkQrSvg')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white dark:bg-slate-800 border border-emerald-300 dark:border-emerald-700 text-emerald-800 dark:text-emerald-200 text-[11px] font-semibold hover:bg-emerald-100 cursor-pointer"
                            >
                                ⬇️ QR-Code als SVG speichern
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Bestehende Freigabelinks -->
                <div id="existingLinksList" class="space-y-2"></div>
            </div>

            <!-- 2. Mit Cloud-Benutzer teilen (intern) -->
            <div class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                    2. Direkt mit Cloud-Benutzer teilen
                </h4>

                <?php if (empty($otherCloudUsers)): ?>
                    <p class="text-xs text-slate-500 dark:text-slate-400 italic">
                        Es hat noch kein anderer Cloud-Benutzer die Sichtbarkeit für Direktfreigaben in seinen Einstellungen aktiviert.
                    </p>
                <?php else: ?>
                    <form id="shareWithUserForm" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                        <select
                            id="shareTargetUserId"
                            required
                            class="flex-1 px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="">Benutzer auswählen...</option>
                            <?php foreach ($otherCloudUsers as $ou): ?>
                                <option value="<?= (int)$ou['id'] ?>"><?= e((string)$ou['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select
                            id="shareTargetCanWrite"
                            class="px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="0">👁️ Nur Lesen</option>
                            <option value="1">✏️ Lesen &amp; Schreiben</option>
                            <option value="2">🗑️ Lesen, Schreiben &amp; Löschen</option>
                        </select>
                        <button
                            type="submit"
                            class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs sm:text-sm cursor-pointer shrink-0"
                        >
                            Freigeben
                        </button>
                    </form>
                <?php endif; ?>

                <div id="existingUserSharesList" class="space-y-1.5"></div>
            </div>
        </div>
    </div>
</div>

<!-- TRESOR-MODAL (Einzeldatei-Verschlüsselung AES-256 Zero-Knowledge) -->
<div
    id="vaultModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="vaultModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 text-base shrink-0">
                    🔒
                </span>
                <div class="min-w-0">
                    <h3 id="vaultModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                        Tresor-Verschlüsselung
                    </h3>
                    <p id="vaultModalFilename" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeVaultModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Modal Body -->
        <div class="p-5 overflow-y-auto space-y-5">
            <!-- Wichtiger Zero-Knowledge-Warnhinweis -->
            <div class="p-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200/80 dark:border-amber-800/70 text-xs text-amber-900 dark:text-amber-200 leading-relaxed">
                <strong>⚠️ Wichtiger Hinweis (Zero-Knowledge):</strong> Ihr Tresor-Passwort wird <strong>nirgendwo gespeichert</strong>. Wenn Sie dieses Passwort vergessen, kann die Datei <strong>auch vom Administrator nicht wiederhergestellt werden!</strong>
            </div>

            <!-- A) ANSICHT: Datei verschlüsseln -->
            <form id="vaultEncryptForm" action="vault_action.php" method="post" class="space-y-3.5 hidden">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="encrypt_file">
                <input type="hidden" name="file_id" id="vaultEncryptFileId" value="">
                <?php if ($currentFolder !== ''): ?>
                    <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                <?php endif; ?>
                <?php if ($activeGroupFolder !== null): ?>
                    <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                <?php endif; ?>

                <div>
                    <label for="vaultEncPw" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Tresor-Passwort festlegen (mind. 4 Zeichen)
                    </label>
                    <input
                        type="password"
                        id="vaultEncPw"
                        name="vault_password"
                        required
                        minlength="4"
                        autocomplete="new-password"
                        placeholder="Geheimes Tresor-Passwort..."
                        class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    >
                </div>

                <div>
                    <label for="vaultEncPwConfirm" class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Tresor-Passwort wiederholen
                    </label>
                    <input
                        type="password"
                        id="vaultEncPwConfirm"
                        name="vault_password_confirm"
                        required
                        minlength="4"
                        autocomplete="new-password"
                        placeholder="Passwort zur Sicherheit bestätigen..."
                        class="w-full px-3.5 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                >
                    <span>🔒 Jetzt mit AES-256 verschlüsseln</span>
                </button>
            </form>

            <!-- B) ANSICHT: Bereits verschlüsselte Tresor-Datei herunterladen oder dauerhaft entschlüsseln -->
            <div id="vaultProtectedSection" class="space-y-5 hidden">
                <!-- 1. Entschlüsselt herunterladen (Datei bleibt in der Cloud geschützt) -->
                <form id="vaultDownloadForm" action="download.php" method="post" class="space-y-3 bg-slate-50 dark:bg-slate-800/50 p-3.5 rounded-xl border border-slate-200/80 dark:border-slate-700/80">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="id" id="vaultDownloadFileId" value="">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                        1. Entschlüsselt herunterladen
                    </h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Lädt die Datei entschlüsselt auf Ihr Gerät herunter. In der Cloud bleibt der Tresor-Schutz weiter bestehen.
                    </p>
                    <input
                        type="password"
                        id="vaultDlPw"
                        name="vault_password"
                        required
                        placeholder="Tresor-Passwort eingeben..."
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                    <button
                        type="submit"
                        class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                    >
                        <span>📥 Entschlüsseln &amp; Herunterladen</span>
                    </button>
                </form>

                <!-- 2. Tresor-Schutz dauerhaft in der Cloud aufheben -->
                <form id="vaultDecryptForm" action="vault_action.php" method="post" class="space-y-3 pt-4 border-t border-slate-200 dark:border-slate-800">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="decrypt_file">
                    <input type="hidden" name="file_id" id="vaultDecryptFileId" value="">
                    <?php if ($currentFolder !== ''): ?>
                        <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
                    <?php endif; ?>
                    <?php if ($activeGroupFolder !== null): ?>
                        <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
                    <?php endif; ?>

                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                        2. Tresor-Schutz dauerhaft aufheben
                    </h4>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                        Entschlüsselt die Datei dauerhaft auf dem Server, sodass sie wieder als normale Datei vorliegt.
                    </p>
                    <input
                        type="password"
                        id="vaultDecPw"
                        name="vault_password"
                        required
                        placeholder="Tresor-Passwort eingeben..."
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-amber-500"
                    >
                    <button
                        type="submit"
                        class="w-full py-2 px-4 rounded-xl bg-slate-200 dark:bg-slate-800 hover:bg-slate-300 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-semibold text-xs sm:text-sm transition-colors cursor-pointer flex items-center justify-center gap-2"
                    >
                        <span>🔓 Tresor-Schutz dauerhaft entfernen</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- VERSCHIEBEN / KOPIEREN MODAL (Für Dateien und Ordner über das ⇄ Icon) -->
<div
    id="transferModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="transferModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold text-base shrink-0">
                    ⇄
                </span>
                <div class="min-w-0">
                    <h3 id="transferModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                        Verschieben oder Kopieren
                    </h3>
                    <p id="transferModalItemName" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeTransferModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form id="transferModalForm" action="folder_action.php" method="post" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" id="transferModalAction" value="move_file">
            <input type="hidden" name="file_id" id="transferModalFileId" value="">
            <input type="hidden" name="batch_file_ids" id="transferModalBatchIds" value="">
            <input type="hidden" name="batch_folders" id="transferModalBatchFolders" value="">
            <input type="hidden" name="source_folder" id="transferModalSourceFolder" value="">
            <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
            <?php if ($activeGroupFolder !== null): ?>
                <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>

            <div>
                <label for="transferTargetSelect" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Aktion &amp; Zielordner auswählen
                </label>
                <select
                    id="transferTargetSelect"
                    name="target_folder"
                    required
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                >
                    <option value="">⇄ Verschieben / 📋 Kopieren...</option>
                    <optgroup id="optGroupMovePersonal" label="⇄ Verschieben – Meine Dateien">
                        <option value="move|personal:__root__">⇄ 🏠 Mein Hauptverzeichnis</option>
                        <?php foreach ($personalFoldersList as $pfRow): ?>
                            <?php $pfPath = (string)$pfRow['folder_path']; ?>
                            <option value="<?= e('move|personal:' . $pfPath) ?>">⇄ 📁 <?= e($pfPath) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if (!empty($writableGroupFolders)): ?>
                        <optgroup id="optGroupMoveGroup" label="⇄ Verschieben – Gruppenordner">
                            <?php foreach ($writableGroupFolders as $wgf): ?>
                                <?php
                                $wgfId   = (int)$wgf['id'];
                                $wgfName = (string)$wgf['name'];
                                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                                ?>
                                <option value="move|gf:<?= $wgfId ?>:__root__">⇄ 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                                <?php foreach ($wgfSubs as $gfSubRow): ?>
                                    <?php $gfSubPath = (string)$gfSubRow['folder_path']; ?>
                                    <option value="<?= e('move|gf:' . $wgfId . ':' . $gfSubPath) ?>">⇄ 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <optgroup label="📋 Kopieren – Meine Dateien">
                        <option value="copy|personal:__root__">📋 🏠 Mein Hauptverzeichnis</option>
                        <?php foreach ($personalFoldersList as $pfRow): ?>
                            <?php $pfPath = (string)$pfRow['folder_path']; ?>
                            <option value="<?= e('copy|personal:' . $pfPath) ?>">📋 📁 <?= e($pfPath) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php if (!empty($writableGroupFolders)): ?>
                        <optgroup label="📋 Kopieren – Gruppenordner">
                            <?php foreach ($writableGroupFolders as $wgf): ?>
                                <?php
                                $wgfId   = (int)$wgf['id'];
                                $wgfName = (string)$wgf['name'];
                                $wgfSubs = $groupFoldersSubfoldersMap[$wgfId] ?? [];
                                ?>
                                <option value="copy|gf:<?= $wgfId ?>:__root__">📋 👥 <?= e($wgfName) ?> (Hauptverzeichnis)</option>
                                <?php foreach ($wgfSubs as $gfSubRow): ?>
                                    <?php $gfSubPath = (string)$gfSubRow['folder_path']; ?>
                                    <option value="<?= e('copy|gf:' . $wgfId . ':' . $gfSubPath) ?>">📋 👥 <?= e($wgfName) ?> / 📁 <?= e($gfSubPath) ?></option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeTransferModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    Ausführen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- UMBENENNEN MODAL (Für Dateien und Ordner) -->
<div
    id="renameModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="renameModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-base shrink-0">
                    ✏️
                </span>
                <div class="min-w-0">
                    <h3 id="renameModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white truncate">
                        Umbenennen
                    </h3>
                    <p id="renameModalSub" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeRenameModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form id="renameModalForm" action="folder_action.php" method="post" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" id="renameModalAction" value="rename_file">
            <input type="hidden" name="file_id" id="renameModalFileId" value="">
            <input type="hidden" name="old_folder" id="renameModalOldFolder" value="">
            <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
            <?php if ($activeSharedFolder !== null): ?>
                <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
            <?php elseif ($activeGroupFolder !== null): ?>
                <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>

            <div>
                <label for="renameModalInput" id="renameModalLabel" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Neuer Name
                </label>
                <input
                    type="text"
                    id="renameModalInput"
                    name="new_name"
                    required
                    maxlength="240"
                    autocomplete="off"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <p id="renameModalHint" class="text-[11px] text-slate-400 dark:text-slate-500 mt-1.5">
                    Tipp: Die Dateiendung bleibt automatisch erhalten.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeRenameModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    💾 Umbenennen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- OFFICE LIGHT: NEUES DOKUMENT / NEUE TABELLE ANLEGEN MODAL -->
<div
    id="createOfficeModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="createOfficeModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-base shrink-0">
                    📄
                </span>
                <div>
                    <h3 id="createOfficeModalTitle" class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">
                        MiniCloud Office Light
                    </h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Neues Dokument oder neue Tabelle direkt in der Cloud erstellen
                    </p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeCreateOfficeModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form action="office.php" method="post" class="p-5 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="create_document">
            <input type="hidden" name="target_folder" id="officeCreateTargetFolder" value="<?= e($currentFolder) ?>">
            <?php if ($activeSharedFolder !== null): ?>
                <input type="hidden" name="shared_folder_id" value="<?= (int)$activeSharedFolder['id'] ?>">
            <?php elseif ($activeGroupFolder !== null): ?>
                <input type="hidden" name="group_folder_id" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>

            <div>
                <label for="officeCreateType" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Dokumenttyp wählen
                </label>
                <select
                    id="officeCreateType"
                    name="doc_type"
                    onchange="updateOfficeDefaultFilename(this.value)"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                >
                    <option value="docx">📄 Word-Dokument (.docx)</option>
                    <option value="xlsx">📊 Excel-Tabelle (.xlsx)</option>
                    <option value="pptx">📙 PowerPoint-Präsentation (.pptx)</option>
                    <option value="drawio">📐 Visio-Diagramm / Organigramm (.drawio)</option>
                    <option value="csv">📋 CSV-Tabelle (.csv)</option>
                    <option value="txt">📝 Textnotiz (.txt)</option>
                    <option value="md">📑 Markdown-Protokoll (.md)</option>
                </select>
            </div>

            <div>
                <label for="officeCreateName" class="block text-xs sm:text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                    Dateiname
                </label>
                <input
                    type="text"
                    id="officeCreateName"
                    name="doc_name"
                    required
                    value="Neues_Dokument_<?= date('Y-m-d') ?>"
                    placeholder="z.B. Projektplan_2026"
                    class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <p class="text-[11px] text-slate-400 mt-1">
                    Die passende Dateiendung (<code>.docx</code>, <code>.xlsx</code> usw.) wird automatisch ergänzt.
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button
                    type="button"
                    onclick="closeCreateOfficeModal()"
                    class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                >
                    Abbrechen
                </button>
                <button
                    type="submit"
                    class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs sm:text-sm font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    Erstellen &amp; Öffnen &rarr;
                </button>
            </div>
        </form>
    </div>
</div>

<!-- AKTIONSMENÜ & FAVORITEN-ICONS ANPASSEN (Über den ⋯ Button oder Rechtsklick pro Zeile) -->
<div
    id="mobileActionModal"
    class="fixed inset-0 z-50 hidden items-end sm:items-center justify-center p-3 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="mobileActionTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
        <div class="px-4 py-3.5 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
            <div class="min-w-0">
                <h3 id="mobileActionTitle" class="text-sm font-bold text-slate-900 dark:text-white truncate"></h3>
                <p id="mobileActionSubtitle" class="text-[11px] text-slate-500 dark:text-slate-400 truncate"></p>
            </div>
            <button
                type="button"
                onclick="closeMobileActionModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer shrink-0"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="px-4 py-2 bg-slate-50/90 dark:bg-slate-800/60 border-b border-slate-200/70 dark:border-slate-800 flex items-center justify-between gap-2 text-[11px] text-slate-500 dark:text-slate-400">
            <span>💡 Rechts auf <strong>⭐/☆</strong> klicken, um Icons außen anzuheften</span>
            <button
                type="button"
                onclick="resetPinnedFileActions()"
                class="text-[11px] font-semibold text-brand-600 dark:text-brand-400 hover:underline cursor-pointer shrink-0"
                title="Standard-Auswahl (Vorschau, Download, Teilen) wiederherstellen"
            >
                Standard
            </button>
        </div>

        <div id="mobileActionList" class="p-3 space-y-1.5 overflow-y-auto"></div>

        <!-- Verstecktes Lösch-Formular für das mobile Aktionsmenü -->
        <form id="mobileDeleteForm" action="delete.php" method="post" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="file_id" id="mobileDeleteFileId" value="">
            <input type="hidden" name="delete_folder" id="mobileDeleteFolder" value="">
            <?php if ($currentFolder !== ''): ?>
                <input type="hidden" name="return_folder" value="<?= e($currentFolder) ?>">
            <?php endif; ?>
            <?php if ($activeSharedFolder !== null): ?>
                <input type="hidden" name="return_shared_folder" value="<?= (int)$activeSharedFolder['id'] ?>">
            <?php elseif ($activeGroupFolder !== null): ?>
                <input type="hidden" name="return_group_folder" value="<?= (int)$activeGroupFolder['id'] ?>">
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- DATEI-VORSCHAU MODAL MIT DURCHBLÄTTER-FUNKTION -->
<div id="previewModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-xs p-3 sm:p-6">
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-2xl w-full max-w-5xl max-h-[92vh] flex flex-col overflow-hidden">
        <!-- Kopfbereich: Dateiinfos, Zähler & Aktionen -->
        <div class="p-3.5 sm:p-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-3 shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <span id="previewBadge" class="inline-flex items-center justify-center w-10 h-8 rounded-lg text-[10px] font-bold tracking-tight bg-slate-100 text-slate-700 shrink-0">
                    FILE
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="previewFilename" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate max-w-[200px] sm:max-w-md md:max-w-xl"></h3>
                        <span id="previewCounter" class="text-[11px] font-semibold px-2 py-0.5 rounded-full bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300 shrink-0"></span>
                    </div>
                    <p id="previewMeta" class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5"></p>
                </div>
            </div>

            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                <!-- Durchblättern oben (Zurück / Weiter) -->
                <div id="previewNavTop" class="hidden sm:inline-flex items-center gap-1 mr-1">
                    <button
                        type="button"
                        onclick="stepPreview(-1)"
                        title="Vorherige Datei (Pfeiltaste links)"
                        aria-label="Vorherige Datei"
                        class="inline-flex items-center justify-center p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <button
                        type="button"
                        onclick="stepPreview(1)"
                        title="Nächste Datei (Pfeiltaste rechts)"
                        aria-label="Nächste Datei"
                        class="inline-flex items-center justify-center p-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 transition-colors cursor-pointer"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>

                <a
                    id="previewOfficeBtn"
                    href="#"
                    title="In Office Light öffnen / bearbeiten"
                    aria-label="In Office Light öffnen / bearbeiten"
                    class="hidden items-center gap-1.5 px-2.5 sm:px-3 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold transition-colors"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    <span>Bearbeiten</span>
                </a>

                <a
                    id="previewDownloadBtn"
                    href="#"
                    title="Herunterladen"
                    aria-label="Herunterladen"
                    class="inline-flex items-center justify-center p-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                </a>

                <button
                    type="button"
                    onclick="closePreviewModal()"
                    title="Vorschau schließen (Esc)"
                    aria-label="Vorschau schließen"
                    class="inline-flex items-center justify-center p-2 rounded-xl text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Haupt-Vorschaubereich mit seitlichen Durchblätter-Buttons -->
        <div class="relative flex-1 min-h-[320px] sm:min-h-[460px] bg-slate-100/70 dark:bg-slate-950/60 flex items-center justify-center overflow-auto p-4 sm:p-6">
            <button
                type="button"
                id="previewPrevFloating"
                onclick="stepPreview(-1)"
                title="Vorherige Datei (←)"
                aria-label="Vorherige Datei"
                class="hidden absolute left-3 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 text-slate-800 dark:text-white shadow-lg border border-slate-200 dark:border-slate-700 items-center justify-center transition-transform hover:scale-105 cursor-pointer"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <div id="previewStage" class="w-full h-full flex items-center justify-center"></div>

            <button
                type="button"
                id="previewNextFloating"
                onclick="stepPreview(1)"
                title="Nächste Datei (→)"
                aria-label="Nächste Datei"
                class="hidden absolute right-3 top-1/2 -translate-y-1/2 z-10 w-10 h-10 rounded-full bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-700 text-slate-800 dark:text-white shadow-lg border border-slate-200 dark:border-slate-700 items-center justify-center transition-transform hover:scale-105 cursor-pointer"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        <!-- Fußleiste zum komfortablen Durchblättern -->
        <div id="previewFooterNav" class="hidden px-4 py-2.5 border-t border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/90 items-center justify-between gap-3 text-xs shrink-0">
            <button
                type="button"
                onclick="stepPreview(-1)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 font-semibold transition-colors cursor-pointer"
            >
                <span>&larr; Vorherige Datei</span>
            </button>
            <span id="previewFooterHint" class="text-slate-500 dark:text-slate-400 hidden sm:inline">
                Tipp: Nutzen Sie die Pfeiltasten <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono">&larr;</kbd> <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-mono">&rarr;</kbd> zum Durchblättern
            </span>
            <button
                type="button"
                onclick="stepPreview(1)"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700 font-semibold transition-colors cursor-pointer"
            >
                <span>Nächste Datei &rarr;</span>
            </button>
        </div>
    </div>
</div>

<!-- MODAL: DATEIVERSIONEN (🕓 ZEITREISE) & DATEI-KOMMENTARE (💬 NOTIZEN) -->
<div
    id="fileMetaModal"
    class="fixed inset-0 z-50 hidden items-center justify-center p-3 sm:p-4 bg-slate-900/60 backdrop-blur-xs"
    role="dialog"
    aria-modal="true"
    aria-labelledby="fileMetaModalTitle"
>
    <div class="mc-card bg-white dark:bg-slate-900 w-full max-w-2xl max-h-[90vh] rounded-2xl border border-slate-200 dark:border-slate-700 shadow-2xl overflow-hidden flex flex-col">
        <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300 font-bold text-base shrink-0">
                    💬
                </span>
                <div class="min-w-0">
                    <h3 id="fileMetaModalTitle" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                        Kommentare &amp; Dateiversionen
                    </h3>
                    <p id="fileMetaModalSubtitle" class="text-xs text-slate-500 dark:text-slate-400 truncate"></p>
                </div>
            </div>
            <button
                type="button"
                onclick="closeFileMetaModal()"
                class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer shrink-0"
                aria-label="Schließen"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="p-5 overflow-y-auto space-y-6 flex-1">
            <!-- 1. Datei-Kommentare & Notizen -->
            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                        <span>💬</span>
                        <span>Kommentare &amp; Datei-Notizen</span>
                    </h4>
                    <span id="fileMetaCommentCountBadge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-100 dark:bg-teal-950 text-teal-800 dark:text-teal-300">0</span>
                </div>

                <div id="fileMetaCommentsList" class="space-y-2 max-h-48 overflow-y-auto pr-1">
                    <p class="text-xs text-slate-400 italic py-2">Lade Kommentare...</p>
                </div>

                <form id="fileMetaCommentForm" onsubmit="submitFileComment(event)" class="flex items-center gap-2 pt-1">
                    <input
                        type="text"
                        id="fileMetaCommentInput"
                        maxlength="1000"
                        placeholder="Notiz oder Kommentar zu dieser Datei schreiben..."
                        class="flex-1 px-3.5 py-2 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                    <button
                        type="submit"
                        class="px-3.5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-semibold transition-colors cursor-pointer shrink-0"
                    >
                        Senden
                    </button>
                </form>
            </section>

            <!-- 2. Automatische Dateiversionen (Zeitreise) -->
            <section class="pt-4 border-t border-slate-200 dark:border-slate-800 space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 flex items-center gap-1.5">
                            <span>🕓</span>
                            <span>Frühere Dateiversionen (Zeitreise – max. 5 Stände)</span>
                        </h4>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                            Wird eine Datei in Office Light gespeichert oder gleichnamig neu hochgeladen, hebt MiniCloud automatisch die letzten 5 Stände auf.
                        </p>
                    </div>
                    <span id="fileMetaVersionCountBadge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 dark:bg-purple-950 text-purple-800 dark:text-purple-300 shrink-0">0</span>
                </div>

                <div id="fileMetaVersionsList" class="space-y-2 max-h-56 overflow-y-auto pr-1">
                    <p class="text-xs text-slate-400 italic py-2">Lade Dateiversionen...</p>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Client-Side Drag & Drop, Multi-File, Directory Upload, Preview & Share Modal Script -->
<script>
const csrfToken = <?= json_encode(getCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const previewItems = <?= json_encode($previewItemsList ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let currentPreviewIndex = 0;

// --- DATEI-VORSCHAU MODAL LOGIK ---
const previewModal = document.getElementById('previewModal');
const previewStage = document.getElementById('previewStage');
const previewFilename = document.getElementById('previewFilename');
const previewCounter = document.getElementById('previewCounter');
const previewMeta = document.getElementById('previewMeta');
const previewBadge = document.getElementById('previewBadge');
const previewDownloadBtn = document.getElementById('previewDownloadBtn');
const previewNavTop = document.getElementById('previewNavTop');
const previewPrevFloating = document.getElementById('previewPrevFloating');
const previewNextFloating = document.getElementById('previewNextFloating');
const previewFooterNav = document.getElementById('previewFooterNav');

function isImagePreviewable(item) {
    const imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'];
    return item.mime.startsWith('image/') && item.ext !== 'svg' || imgExts.includes(item.ext);
}

function isPdfPreviewable(item) {
    return item.mime === 'application/pdf' || item.ext === 'pdf';
}

function isVideoPreviewable(item) {
    const vidExts = ['mp4', 'webm', 'ogg'];
    return item.mime.startsWith('video/') || vidExts.includes(item.ext);
}

function isAudioPreviewable(item) {
    const audExts = ['mp3', 'wav', 'ogg', 'flac', 'm4a'];
    return item.mime.startsWith('audio/') || audExts.includes(item.ext);
}

function isTextPreviewable(item) {
    const textExts = [
        'txt', 'md', 'csv', 'json', 'xml', 'log', 'sql', 'yml', 'yaml',
        'ini', 'conf', 'php', 'js', 'ts', 'css', 'html', 'htm', 'sh', 'py', 'c', 'cpp', 'h', 'java'
    ];
    return item.mime.startsWith('text/')
        || item.mime === 'application/json'
        || item.mime === 'application/xml'
        || textExts.includes(item.ext);
}

function isZipPreviewable(item) {
    return item.ext === 'zip'
        || item.mime === 'application/zip'
        || item.mime === 'application/x-zip-compressed';
}

function submitZipExtract(fileId, intoSubfolder) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'folder_action.php';
    form.className = 'hidden';

    const addField = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    addField('csrf_token', csrfToken);
    addField('action', 'extract_zip');
    addField('file_id', String(fileId));
    addField('into_subfolder', intoSubfolder ? '1' : '0');
    const curF = <?= json_encode($currentFolder) ?>;
    if (curF !== '') {
        addField('return_folder', curF);
    }

    document.body.appendChild(form);
    form.submit();
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function renderPreviewItem(index) {
    if (!previewItems || previewItems.length === 0) return;
    const total = previewItems.length;
    currentPreviewIndex = ((index % total) + total) % total;
    const item = previewItems[currentPreviewIndex];

    previewFilename.textContent = item.name;
    previewCounter.textContent = `${currentPreviewIndex + 1} / ${total}`;
    previewMeta.textContent = `${item.size} • ${item.date} • 📁 ${item.folder}`;
    previewBadge.textContent = item.badgeLabel;
    previewBadge.className = `inline-flex items-center justify-center w-10 h-8 rounded-lg text-[10px] font-bold tracking-tight shrink-0 ${item.badgeBg} ${item.badgeText}`;
    previewDownloadBtn.href = item.downloadUrl;

    const previewOfficeBtn = document.getElementById('previewOfficeBtn');
    if (previewOfficeBtn) {
        if (item.officeUrl && item.officeUrl !== '') {
            previewOfficeBtn.href = item.officeUrl;
            const isImgItem = isImagePreviewable(item);
            previewOfficeBtn.innerHTML = isImgItem
                ? '<span>🎨</span><span class="hidden sm:inline">Bild bearbeiten</span>'
                : '<span>✏️</span><span class="hidden sm:inline">Bearbeiten</span>';
            previewOfficeBtn.title = isImgItem
                ? 'Im Foto- & Bild-Studio bearbeiten (Zuschneiden, Größe, Filter, DSGVO-Pixel)'
                : 'In Office Light bearbeiten';
            previewOfficeBtn.classList.remove('hidden');
            previewOfficeBtn.classList.add('inline-flex');
        } else {
            previewOfficeBtn.classList.add('hidden');
            previewOfficeBtn.classList.remove('inline-flex');
        }
    }

    const hasMultiple = total > 1;
    previewNavTop.classList.toggle('sm:inline-flex', hasMultiple);
    previewPrevFloating.classList.toggle('hidden', !hasMultiple);
    previewPrevFloating.classList.toggle('inline-flex', hasMultiple);
    previewNextFloating.classList.toggle('hidden', !hasMultiple);
    previewNextFloating.classList.toggle('inline-flex', hasMultiple);
    previewFooterNav.classList.toggle('hidden', !hasMultiple);
    previewFooterNav.classList.toggle('flex', hasMultiple);

    previewStage.innerHTML = '<div class="text-xs text-slate-400 py-12">Lade Vorschau...</div>';

    if (item.isEncrypted) {
        previewStage.innerHTML = `
            <div class="p-8 rounded-2xl bg-white dark:bg-slate-900 border border-amber-200 dark:border-amber-800/80 shadow-xs text-center max-w-md space-y-4">
                <div class="mx-auto w-16 h-14 rounded-2xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 flex items-center justify-center font-bold text-2xl">
                    🔒
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm break-all">${escapeHtml(item.name)}</h4>
                    <p class="text-xs text-amber-700 dark:text-amber-400 font-semibold mt-1">Im Tresor verschlüsselt (AES-256 Zero-Knowledge)</p>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                    Diese Datei ist mit einem persönlichen Tresor-Passwort verschlüsselt. Bitte geben Sie das Passwort ein, um die Datei zu entschlüsseln.
                </p>
                <button
                    type="button"
                    onclick="closePreviewModal(); openVaultModal(${Number(item.id)}, ${escapeHtml(JSON.stringify(item.name))}, true, ${item.canManage ? 'true' : 'false'});"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold shadow-xs transition-colors cursor-pointer"
                >
                    <span>🔓 Tresor-Passwort eingeben</span>
                </button>
            </div>
        `;
    } else if (isImagePreviewable(item)) {
        previewStage.innerHTML = `
            <img
                src="${item.inlineUrl}"
                alt="${escapeHtml(item.name)}"
                class="max-h-[70vh] max-w-full rounded-xl shadow-md object-contain bg-white/50 dark:bg-slate-900/50"
            >
        `;
    } else if (isPdfPreviewable(item)) {
        previewStage.innerHTML = `
            <div class="flex flex-col items-center gap-3 w-full">
                <iframe
                    src="${item.inlineUrl}"
                    title="${escapeHtml(item.name)}"
                    class="w-full h-[64vh] rounded-xl border border-slate-200 dark:border-slate-800 bg-white"
                ></iframe>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    <a href="pdf_tools.php?file_id=${Number(item.id)}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-xs transition-colors">
                        <span>✏️</span>
                        <span>PDF ausfüllen, unterschreiben &amp; schwärzen</span>
                    </a>
                </div>
            </div>
        `;
    } else if (isVideoPreviewable(item)) {
        previewStage.innerHTML = `
            <div class="flex flex-col items-center gap-3 w-full">
                <video controls autoplay class="max-h-[62vh] max-w-full rounded-xl shadow-md bg-black">
                    <source src="${item.inlineUrl}">
                    Ihr Browser unterstützt dieses Videoformat nicht.
                </video>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    <a href="cinema.php?id=${Number(item.id)}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold shadow-xs transition-colors">
                        <span>🍿</span>
                        <span>Im Heimkino (Ambilight) öffnen</span>
                    </a>
                    <a href="video_studio.php?id=${Number(item.id)}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-700 text-white text-xs font-semibold shadow-xs transition-colors">
                        <span>🎬</span>
                        <span>Im Video-Studio schneiden &amp; bearbeiten</span>
                    </a>
                </div>
            </div>
        `;
    } else if (isAudioPreviewable(item)) {
        previewStage.innerHTML = `
            <div class="p-8 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs text-center space-y-4 w-full max-w-md">
                <div class="mx-auto w-14 h-14 rounded-2xl ${item.badgeBg} ${item.badgeText} flex items-center justify-center font-bold text-sm">
                    ${escapeHtml(item.badgeLabel)}
                </div>
                <p class="font-semibold text-slate-900 dark:text-white text-sm truncate">${escapeHtml(item.name)}</p>
                <audio controls autoplay class="w-full">
                    <source src="${item.inlineUrl}">
                </audio>
                <div class="pt-2 border-t border-slate-100 dark:border-slate-800">
                    <a href="music.php?play=${Number(item.id)}" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold shadow-xs transition-colors">
                        <span>🎵</span>
                        <span>Im Musik- &amp; Hörbuch-Studio öffnen</span>
                    </a>
                </div>
            </div>
        `;
    } else if (isZipPreviewable(item)) {
        try {
            const fd = new FormData();
            fd.append('action', 'inspect_zip');
            fd.append('csrf_token', csrfToken);
            fd.append('file_id', String(item.id));
            const res = await fetch('folder_action.php', { method: 'POST', body: fd });
            const zipData = await res.json();
            if (zipData.success) {
                const rowsHtml = (zipData.entries || []).map(eItem => `
                    <tr class="border-b border-slate-100 dark:border-slate-800/70">
                        <td class="py-1.5 px-3 font-mono text-xs ${eItem.is_dir ? 'text-amber-600 dark:text-amber-400 font-semibold' : 'text-slate-700 dark:text-slate-200'} break-all">
                            ${eItem.is_dir ? '📁 ' : '📄 '}${escapeHtml(String(eItem.name || ''))}
                        </td>
                        <td class="py-1.5 px-3 text-right text-[11px] text-slate-400 whitespace-nowrap">
                            ${eItem.is_dir ? 'Ordner' : escapeHtml(String(eItem.size || ''))}
                        </td>
                    </tr>
                `).join('');

                previewStage.innerHTML = `
                    <div class="w-full max-w-2xl bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs overflow-hidden flex flex-col max-h-[70vh]">
                        <div class="p-4 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50/70 dark:bg-slate-800/40">
                            <div>
                                <h4 class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>📦 ZIP-Archiv-Inspektor</span>
                                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300">
                                        ${Number(zipData.file_count || 0)} Dateien • Entpackt: ${escapeHtml(String(zipData.total_size_human || '0 B'))}
                                    </span>
                                </h4>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                    Inhalt direkt im Browser prüfen oder mit 1 Klick auf dem Server entpacken
                                </p>
                            </div>
                            ${item.canManage ? `
                                <div class="flex flex-wrap items-center gap-1.5 shrink-0">
                                    <button
                                        type="button"
                                        onclick="submitZipExtract(${Number(item.id)}, true)"
                                        class="px-3 py-1.5 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                                        title="Erstellt einen eigenen Unterordner mit dem Namen des ZIP-Archivs und entpackt alle Dateien dorthin"
                                    >
                                        📂 In Unterordner entpacken
                                    </button>
                                    <button
                                        type="button"
                                        onclick="submitZipExtract(${Number(item.id)}, false)"
                                        class="px-3 py-1.5 rounded-xl bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-800 dark:text-slate-100 text-xs font-semibold transition-colors cursor-pointer"
                                        title="Entpackt den Inhalt direkt in das aktuelle Verzeichnis"
                                    >
                                        📥 Direkt hierhin
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <table class="w-full text-left border-collapse">
                                <tbody>
                                    ${rowsHtml || '<tr><td class="p-4 text-xs text-slate-400 text-center">Archiv ist leer.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            } else {
                previewStage.innerHTML = `<p class="text-xs text-red-500">${escapeHtml(zipData.message || 'ZIP-Archiv konnte nicht gelesen werden.')}</p>`;
            }
        } catch {
            previewStage.innerHTML = '<p class="text-xs text-red-500">Fehler beim Inspizieren des ZIP-Archivs.</p>';
        }
    } else if (isTextPreviewable(item)) {
        try {
            const res = await fetch(item.textUrl);
            const text = await res.text();
            previewStage.innerHTML = `
                <div class="w-full flex flex-col gap-3">
                    <pre class="w-full h-[60vh] overflow-auto p-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-xs font-mono text-slate-800 dark:text-slate-200 whitespace-pre-wrap break-words leading-relaxed">${escapeHtml(text)}</pre>
                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <a href="assistant.php?file_id=${Number(item.id)}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-xs transition-colors">
                            <span>🤖</span>
                            <span>KI fragen: Datei zusammenfassen &amp; analysieren</span>
                        </a>
                    </div>
                </div>
            `;
        } catch {
            previewStage.innerHTML = '<p class="text-xs text-red-500">Textvorschau konnte nicht geladen werden.</p>';
        }
    } else {
        previewStage.innerHTML = `
            <div class="p-8 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xs text-center max-w-md space-y-4">
                <div class="mx-auto w-16 h-14 rounded-2xl ${item.badgeBg} ${item.badgeText} flex items-center justify-center font-bold text-sm">
                    ${escapeHtml(item.badgeLabel)}
                </div>
                <div>
                    <h4 class="font-bold text-slate-900 dark:text-white text-sm break-all">${escapeHtml(item.name)}</h4>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">${escapeHtml(item.size)} &bull; ${escapeHtml(item.mime)}</p>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    ${item.officeUrl ? 'Dieses Office-Dokument kann direkt online in <strong>MiniCloud Office Light</strong> geöffnet oder vom KI-Assistenten analysiert werden.' : 'Für diesen Dateityp ist keine direkte Browser-Vorschau verfügbar.'}
                </p>
                <div class="flex flex-wrap items-center justify-center gap-2">
                    ${item.officeUrl ? `
                        <a
                            href="${item.officeUrl}"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-xs transition-colors"
                        >
                            <span>✏️ In Office Light öffnen</span>
                        </a>
                        <a
                            href="assistant.php?file_id=${Number(item.id)}"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-xs transition-colors"
                        >
                            <span>🤖 KI fragen</span>
                        </a>
                    ` : ''}
                    <a
                        href="${item.downloadUrl}"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold shadow-xs transition-colors"
                    >
                        <span>Datei herunterladen</span>
                    </a>
                </div>
            </div>
        `;
    }
}

function openPreviewModal(index) {
    if (!previewItems || previewItems.length === 0) return;
    previewModal.classList.remove('hidden');
    previewModal.classList.add('flex');
    renderPreviewItem(index);
}

function closePreviewModal() {
    previewModal.classList.add('hidden');
    previewModal.classList.remove('flex');
    previewStage.innerHTML = '';
}

function stepPreview(delta) {
    if (!previewItems || previewItems.length <= 1) return;
    renderPreviewItem(currentPreviewIndex + delta);
}

document.addEventListener('keydown', (e) => {
    if (previewModal.classList.contains('hidden')) return;
    if (e.key === 'Escape') {
        e.preventDefault();
        closePreviewModal();
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        stepPreview(-1);
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        stepPreview(1);
    }
});

previewModal.addEventListener('click', (e) => {
    if (e.target === previewModal) {
        closePreviewModal();
    }
});

// Touch-Swipe-Unterstützung für Mobilgeräte im Vorschau-Modal
(() => {
    let touchStartX = 0;
    let touchStartY = 0;
    previewModal.addEventListener('touchstart', (e) => {
        if (!e.touches || e.touches.length !== 1) return;
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    }, { passive: true });

    previewModal.addEventListener('touchend', (e) => {
        if (!e.changedTouches || e.changedTouches.length !== 1) return;
        const dx = e.changedTouches[0].clientX - touchStartX;
        const dy = e.changedTouches[0].clientY - touchStartY;
        if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.5) {
            stepPreview(dx < 0 ? 1 : -1);
        }
    }, { passive: true });
})();

// --- TRESOR MODAL LOGIK (AES-256 Einzeldatei-Verschlüsselung) ---
const vaultModal = document.getElementById('vaultModal');
const vaultModalTitle = document.getElementById('vaultModalTitle');
const vaultModalFilename = document.getElementById('vaultModalFilename');
const vaultEncryptForm = document.getElementById('vaultEncryptForm');
const vaultProtectedSection = document.getElementById('vaultProtectedSection');
const vaultDecryptForm = document.getElementById('vaultDecryptForm');

function openVaultModal(fileId, fileName, isEncrypted, canManage = true) {
    vaultModalFilename.textContent = fileName || '';
    document.getElementById('vaultEncryptFileId').value = String(fileId || 0);
    document.getElementById('vaultDownloadFileId').value = String(fileId || 0);
    document.getElementById('vaultDecryptFileId').value = String(fileId || 0);

    document.getElementById('vaultEncPw').value = '';
    document.getElementById('vaultEncPwConfirm').value = '';
    document.getElementById('vaultDlPw').value = '';
    document.getElementById('vaultDecPw').value = '';

    if (isEncrypted) {
        vaultModalTitle.textContent = 'Tresor-geschützte Datei';
        vaultEncryptForm.classList.add('hidden');
        vaultProtectedSection.classList.remove('hidden');
        if (canManage) {
            vaultDecryptForm.classList.remove('hidden');
        } else {
            vaultDecryptForm.classList.add('hidden');
        }
        setTimeout(() => document.getElementById('vaultDlPw').focus(), 50);
    } else {
        vaultModalTitle.textContent = 'Datei im Tresor verschlüsseln';
        vaultProtectedSection.classList.add('hidden');
        vaultEncryptForm.classList.remove('hidden');
        setTimeout(() => document.getElementById('vaultEncPw').focus(), 50);
    }

    vaultModal.classList.remove('hidden');
    vaultModal.classList.add('flex');
}

function closeVaultModal() {
    vaultModal.classList.add('hidden');
    vaultModal.classList.remove('flex');
}

vaultModal.addEventListener('click', (e) => {
    if (e.target === vaultModal) {
        closeVaultModal();
    }
});

// --- ANSICHTSUMSCHALTER (Liste <-> Kacheln) & FAVORITEN & MEHRFACHAUSWAHL ---
function setFileViewMode(mode) {
    const listView = document.getElementById('fileListView');
    const gridView = document.getElementById('fileGridView');
    const btnList = document.getElementById('btnViewList');
    const btnGrid = document.getElementById('btnViewGrid');
    const isGrid = (mode === 'grid');

    try {
        localStorage.setItem('mc_file_view_mode', isGrid ? 'grid' : 'list');
    } catch {}

    if (listView && gridView) {
        if (isGrid) {
            listView.classList.add('hidden');
            gridView.classList.remove('hidden');
            gridView.classList.add('grid');
        } else {
            gridView.classList.add('hidden');
            gridView.classList.remove('grid');
            listView.classList.remove('hidden');
        }
    }

    const activeCls = ['bg-white', 'dark:bg-slate-700', 'text-brand-600', 'dark:text-brand-400', 'shadow-2xs'];
    const inactiveCls = ['text-slate-600', 'dark:text-slate-400'];

    if (btnList && btnGrid) {
        if (isGrid) {
            btnGrid.classList.add(...activeCls);
            btnGrid.classList.remove(...inactiveCls);
            btnList.classList.remove(...activeCls);
            btnList.classList.add(...inactiveCls);
        } else {
            btnList.classList.add(...activeCls);
            btnList.classList.remove(...inactiveCls);
            btnGrid.classList.remove(...activeCls);
            btnGrid.classList.add(...inactiveCls);
        }
    }
}

(() => {
    try {
        const savedView = localStorage.getItem('mc_file_view_mode');
        if (savedView === 'grid') {
            setFileViewMode('grid');
        } else {
            setFileViewMode('list');
        }
    } catch {
        setFileViewMode('list');
    }
})();

async function toggleFavorite(fileId, btnEl) {
    try {
        const fd = new FormData();
        fd.append('action', 'toggle_favorite');
        fd.append('csrf_token', csrfToken);
        fd.append('file_id', String(fileId));
        const res = await fetch('folder_action.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
        });
        const data = await res.json();
        if (!data.success) return;
        const isFav = Boolean(data.is_favorite);
        document.querySelectorAll(`[data-fav-btn-id="${fileId}"]`).forEach(el => {
            el.setAttribute('data-fav', isFav ? '1' : '0');
            el.textContent = isFav ? '⭐' : '☆';
            el.title = isFav ? 'Aus Favoriten entfernen' : 'Als Favorit markieren';
            if (isFav) {
                el.classList.add('text-amber-400');
                el.classList.remove('text-slate-300', 'dark:text-slate-600');
            } else {
                el.classList.remove('text-amber-400');
                el.classList.add('text-slate-300', 'dark:text-slate-600');
            }
        });
        if (previewItems) {
            const pItem = previewItems.find(it => Number(it.id) === Number(fileId));
            if (pItem) pItem.isFavorite = isFav;
        }
    } catch {}
}

function getSelectedFileCheckboxes() {
    return Array.from(document.querySelectorAll('.file-select-checkbox:checked'));
}

function getSelectedFolderCheckboxes() {
    return Array.from(document.querySelectorAll('.folder-select-checkbox:checked'));
}

function updateBatchSelection() {
    const allCbs = Array.from(document.querySelectorAll('.file-select-checkbox, .folder-select-checkbox'));
    const checkedFileCbs = getSelectedFileCheckboxes();
    const checkedFolderCbs = getSelectedFolderCheckboxes();
    const checkedCbs = [...checkedFolderCbs, ...checkedFileCbs];
    const selectAllCb = document.getElementById('selectAllFilesCheckbox');
    const bar = document.getElementById('batchActionBar');
    const countEl = document.getElementById('batchSelectedCount');
    const btnDelete = document.getElementById('btnBatchDelete');
    const btnZip = document.getElementById('btnBatchZip');

    if (selectAllCb && allCbs.length > 0) {
        selectAllCb.checked = (checkedCbs.length === allCbs.length);
        selectAllCb.indeterminate = (checkedCbs.length > 0 && checkedCbs.length < allCbs.length);
    }

    if (!bar || !countEl) return;
    if (checkedCbs.length === 0) {
        bar.classList.add('hidden');
        bar.classList.remove('flex');
        return;
    }

    countEl.textContent = String(checkedCbs.length);
    if (btnZip) {
        btnZip.classList.toggle('hidden', checkedFileCbs.length === 0);
    }
    const anyDeletable = checkedCbs.some(cb => cb.getAttribute('data-can-delete') === '1');
    if (btnDelete) {
        btnDelete.classList.toggle('hidden', !anyDeletable);
    }
    bar.classList.remove('hidden');
    bar.classList.add('flex');
}

function toggleSelectAllFiles(masterCb) {
    const checked = Boolean(masterCb && masterCb.checked);
    document.querySelectorAll('.file-select-checkbox, .folder-select-checkbox').forEach(cb => {
        cb.checked = checked;
    });
    updateBatchSelection();
}

function clearBatchSelection() {
    const selectAllCb = document.getElementById('selectAllFilesCheckbox');
    if (selectAllCb) {
        selectAllCb.checked = false;
        selectAllCb.indeterminate = false;
    }
    document.querySelectorAll('.file-select-checkbox, .folder-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateBatchSelection();
}

function downloadBatchZip() {
    const ids = getSelectedFileCheckboxes().map(cb => cb.value).filter(Boolean);
    if (ids.length === 0) return;
    window.location.href = 'download.php?batch_ids=' + encodeURIComponent(ids.join(','));
}

function openBatchTransferModal() {
    const checkedFileCbs = getSelectedFileCheckboxes();
    const checkedFolderCbs = getSelectedFolderCheckboxes();
    const checkedCbs = [...checkedFolderCbs, ...checkedFileCbs];
    if (checkedCbs.length === 0) return;

    const ids = checkedFileCbs.map(cb => cb.value);
    const folders = checkedFolderCbs.map(cb => cb.value);
    const allCanManage = checkedCbs.every(cb => cb.getAttribute('data-can-manage') === '1');

    if (folders.length > 0 && ids.length > 0) {
        transferModalTitle.textContent = 'Ausgewählte Ordner & Dateien verschieben / kopieren';
        transferModalItemName.textContent = `☑️ ${folders.length} ${folders.length === 1 ? 'Ordner' : 'Ordner'} & ${ids.length} ${ids.length === 1 ? 'Datei' : 'Dateien'}`;
    } else if (folders.length > 0) {
        transferModalTitle.textContent = 'Ausgewählte Ordner verschieben / kopieren';
        transferModalItemName.textContent = `☑️ ${folders.length} ${folders.length === 1 ? 'ausgewählter Ordner' : 'ausgewählte Ordner'}`;
    } else {
        transferModalTitle.textContent = 'Ausgewählte Dateien verschieben / kopieren';
        transferModalItemName.textContent = `☑️ ${ids.length} ${ids.length === 1 ? 'ausgewählte Datei' : 'ausgewählte Dateien'}`;
    }

    transferModalAction.value = 'batch_transfer_files';
    transferModalFileId.value = '';
    document.getElementById('transferModalBatchIds').value = ids.join(',');
    const batchFoldersEl = document.getElementById('transferModalBatchFolders');
    if (batchFoldersEl) batchFoldersEl.value = folders.length > 0 ? JSON.stringify(folders) : '';
    transferModalSourceFolder.value = '';
    transferTargetSelect.setAttribute('name', 'target_folder');
    transferTargetSelect.value = '';

    if (optGroupMovePersonal) {
        optGroupMovePersonal.disabled = !allCanManage;
        optGroupMovePersonal.hidden = !allCanManage;
    }
    if (optGroupMoveGroup) {
        optGroupMoveGroup.disabled = !allCanManage;
        optGroupMoveGroup.hidden = !allCanManage;
    }

    transferModal.classList.remove('hidden');
    transferModal.classList.add('flex');
    setTimeout(() => transferTargetSelect.focus(), 50);
}

function deleteBatchFiles() {
    const checkedFileCbs = getSelectedFileCheckboxes().filter(cb => cb.getAttribute('data-can-delete') === '1');
    const checkedFolderCbs = getSelectedFolderCheckboxes().filter(cb => cb.getAttribute('data-can-delete') === '1');
    if (checkedFileCbs.length === 0 && checkedFolderCbs.length === 0) return;

    const ids = checkedFileCbs.map(cb => cb.value);
    const folders = checkedFolderCbs.map(cb => cb.value);

    let confirmMsg = '';
    if (folders.length > 0 && ids.length > 0) {
        confirmMsg = `Möchten Sie die ${folders.length} ausgewählten Ordner (samt Inhalt) und ${ids.length} Datei(en) wirklich in den Papierkorb verschieben?`;
    } else if (folders.length > 0) {
        confirmMsg = `Möchten Sie die ${folders.length} ausgewählten Ordner (samt enthaltenen Dateien) wirklich in den Papierkorb verschieben?`;
    } else {
        confirmMsg = `Möchten Sie die ${ids.length} ausgewählten Datei(en) wirklich in den Papierkorb verschieben?`;
    }
    if (!confirm(confirmMsg)) {
        return;
    }

    const inputIds = document.getElementById('batchDeleteIdsInput');
    const inputFolders = document.getElementById('batchDeleteFoldersInput');
    const form = document.getElementById('batchDeleteForm');
    if (inputIds && form) {
        inputIds.value = ids.join(',');
        if (inputFolders) {
            inputFolders.value = folders.length > 0 ? JSON.stringify(folders) : '';
        }
        form.submit();
    }
}

// --- VERSCHIEBEN / KOPIEREN MODAL LOGIK ---
const transferModal = document.getElementById('transferModal');
const transferModalTitle = document.getElementById('transferModalTitle');
const transferModalItemName = document.getElementById('transferModalItemName');
const transferModalAction = document.getElementById('transferModalAction');
const transferModalFileId = document.getElementById('transferModalFileId');
const transferModalSourceFolder = document.getElementById('transferModalSourceFolder');
const transferTargetSelect = document.getElementById('transferTargetSelect');
const optGroupMovePersonal = document.getElementById('optGroupMovePersonal');
const optGroupMoveGroup = document.getElementById('optGroupMoveGroup');

function openTransferModal(type, fileId, displayName, folderPath = '', canMove = true) {
    const batchIdsEl = document.getElementById('transferModalBatchIds');
    if (batchIdsEl) batchIdsEl.value = '';
    const batchFoldersEl = document.getElementById('transferModalBatchFolders');
    if (batchFoldersEl) batchFoldersEl.value = '';
    if (type === 'folder') {
        transferModalTitle.textContent = 'Ordner verschieben oder kopieren';
        transferModalItemName.textContent = '📁 ' + displayName;
        transferModalAction.value = 'move_folder';
        transferModalFileId.value = '';
        transferModalSourceFolder.value = folderPath;
        transferTargetSelect.setAttribute('name', 'target_parent');
    } else {
        transferModalTitle.textContent = 'Datei verschieben oder kopieren';
        transferModalItemName.textContent = displayName;
        transferModalAction.value = 'move_file';
        transferModalFileId.value = String(fileId || 0);
        transferModalSourceFolder.value = '';
        transferTargetSelect.setAttribute('name', 'target_folder');
    }

    transferTargetSelect.value = '';
    if (optGroupMovePersonal) {
        optGroupMovePersonal.disabled = !canMove;
        optGroupMovePersonal.hidden = !canMove;
    }
    if (optGroupMoveGroup) {
        optGroupMoveGroup.disabled = !canMove;
        optGroupMoveGroup.hidden = !canMove;
    }

    transferModal.classList.remove('hidden');
    transferModal.classList.add('flex');
    setTimeout(() => transferTargetSelect.focus(), 50);
}

function closeTransferModal() {
    transferModal.classList.add('hidden');
    transferModal.classList.remove('flex');
}

transferModal.addEventListener('click', (e) => {
    if (e.target === transferModal) {
        closeTransferModal();
    }
});

// --- UMBENENNEN MODAL (Für Dateien und Ordner) ---
const renameModal = document.getElementById('renameModal');
const renameModalTitle = document.getElementById('renameModalTitle');
const renameModalSub = document.getElementById('renameModalSub');
const renameModalAction = document.getElementById('renameModalAction');
const renameModalFileId = document.getElementById('renameModalFileId');
const renameModalOldFolder = document.getElementById('renameModalOldFolder');
const renameModalInput = document.getElementById('renameModalInput');
const renameModalLabel = document.getElementById('renameModalLabel');
const renameModalHint = document.getElementById('renameModalHint');

function openRenameModal(type, fileId, currentName, folderPath = '') {
    if (!renameModal || !renameModalInput) return;

    if (type === 'folder') {
        renameModalTitle.textContent = 'Ordner umbenennen';
        renameModalSub.textContent = '📁 ' + (folderPath || currentName);
        renameModalAction.value = 'rename_folder';
        renameModalFileId.value = '';
        renameModalOldFolder.value = folderPath;
        renameModalInput.setAttribute('name', 'new_folder');
        renameModalInput.value = currentName || '';
        if (renameModalLabel) renameModalLabel.textContent = 'Neuer Ordnername';
        if (renameModalHint) renameModalHint.textContent = 'Alle darin enthaltenen Dateien, Unterordner und Freigaben bleiben erhalten.';
    } else {
        renameModalTitle.textContent = 'Datei umbenennen';
        renameModalSub.textContent = '📄 ' + currentName;
        renameModalAction.value = 'rename_file';
        renameModalFileId.value = String(fileId || 0);
        renameModalOldFolder.value = '';
        renameModalInput.setAttribute('name', 'new_name');
        renameModalInput.value = currentName || '';
        if (renameModalLabel) renameModalLabel.textContent = 'Neuer Dateiname';
        if (renameModalHint) renameModalHint.textContent = 'Tipp: Die Dateiendung bleibt automatisch erhalten.';
    }

    renameModal.classList.remove('hidden');
    renameModal.classList.add('flex');

    setTimeout(() => {
        renameModalInput.focus();
        const val = renameModalInput.value;
        const dotIdx = (type === 'file') ? val.lastIndexOf('.') : -1;
        if (dotIdx > 0) {
            renameModalInput.setSelectionRange(0, dotIdx);
        } else {
            renameModalInput.select();
        }
    }, 50);
}

function closeRenameModal() {
    if (!renameModal) return;
    renameModal.classList.add('hidden');
    renameModal.classList.remove('flex');
}

if (renameModal) {
    renameModal.addEventListener('click', (e) => {
        if (e.target === renameModal) {
            closeRenameModal();
        }
    });
}

// --- OFFICE LIGHT MODAL (Neues Dokument / Neue Tabelle anlegen) ---
const createOfficeModal = document.getElementById('createOfficeModal');
const officeCreateNameInput = document.getElementById('officeCreateName');
const officeCreateTargetFolder = document.getElementById('officeCreateTargetFolder');

function openCreateOfficeModal() {
    if (!createOfficeModal) return;
    const uploadFolderInput = document.getElementById('target_folder');
    if (uploadFolderInput && officeCreateTargetFolder) {
        officeCreateTargetFolder.value = uploadFolderInput.value;
    }
    createOfficeModal.classList.remove('hidden');
    createOfficeModal.classList.add('flex');
    if (officeCreateNameInput) {
        setTimeout(() => {
            officeCreateNameInput.focus();
            officeCreateNameInput.select();
        }, 50);
    }
}

function closeCreateOfficeModal() {
    if (!createOfficeModal) return;
    createOfficeModal.classList.add('hidden');
    createOfficeModal.classList.remove('flex');
}

function updateOfficeDefaultFilename(type) {
    if (!officeCreateNameInput) return;
    const today = <?= json_encode(date('Y-m-d')) ?>;
    const defaults = {
        docx:   'Neues_Dokument_' + today,
        xlsx:   'Neue_Tabelle_' + today,
        pptx:   'Neue_Praesentation_' + today,
        drawio: 'Neues_Diagramm_' + today,
        csv:    'Neue_Liste_' + today,
        txt:    'Neue_Notiz_' + today,
        md:     'Neues_Protokoll_' + today
    };
    const cur = officeCreateNameInput.value.trim();
    if (cur === '' || cur.startsWith('Neues_') || cur.startsWith('Neue_')) {
        officeCreateNameInput.value = defaults[type] || ('Neues_Dokument_' + today);
    }
}

if (createOfficeModal) {
    createOfficeModal.addEventListener('click', (e) => {
        if (e.target === createOfficeModal) {
            closeCreateOfficeModal();
        }
    });
}

// --- AKTIONSMENÜ (⋯ Button / Rechtsklick) & ANHEFTBARE FAVORITEN-ICONS PRO ZEILE ---
const mobileActionModal = document.getElementById('mobileActionModal');
const mobileActionTitle = document.getElementById('mobileActionTitle');
const mobileActionSubtitle = document.getElementById('mobileActionSubtitle');
const mobileActionList = document.getElementById('mobileActionList');
const mobileDeleteForm = document.getElementById('mobileDeleteForm');
const mobileDeleteFileId = document.getElementById('mobileDeleteFileId');
const mobileDeleteFolder = document.getElementById('mobileDeleteFolder');

const PINNED_FILE_ACTIONS_STORAGE_KEY = 'mc_pinned_file_actions_u<?= (int)$userId ?>';
const DEFAULT_PINNED_FILE_ACTIONS = ['preview', 'download', 'rename', 'share'];
const ALL_PINNABLE_FILE_ACTIONS = ['preview', 'office', 'download', 'rename', 'vault', 'meta', 'move', 'share', 'delete'];

function getPinnedFileActions() {
    try {
        const raw = localStorage.getItem(PINNED_FILE_ACTIONS_STORAGE_KEY);
        if (!raw) return [...DEFAULT_PINNED_FILE_ACTIONS];
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) {
            return parsed.filter(k => ALL_PINNABLE_FILE_ACTIONS.includes(k));
        }
    } catch {}
    return [...DEFAULT_PINNED_FILE_ACTIONS];
}

function applyPinnedFileActions() {
    const pinned = getPinnedFileActions();
    document.querySelectorAll('[data-file-action]').forEach(el => {
        const key = el.getAttribute('data-file-action');
        const isPinned = pinned.includes(key);
        const isForm = (el.tagName === 'FORM');
        if (isPinned) {
            el.classList.remove('hidden');
            el.classList.add(isForm ? 'inline' : 'inline-flex');
        } else {
            el.classList.add('hidden');
            el.classList.remove('inline', 'inline-flex');
        }
    });

    document.querySelectorAll('[data-action-pin-star]').forEach(btn => {
        const key = btn.getAttribute('data-action-pin-star');
        const isPinned = pinned.includes(key);
        btn.textContent = isPinned ? '⭐' : '☆';
        btn.title = isPinned
            ? 'Aus der Dateizeile ausblenden (nur im ⋯ Menü zeigen)'
            : 'Als Favorit direkt außen neben der Datei anheften';
        if (isPinned) {
            btn.classList.add('text-amber-500', 'bg-amber-50', 'dark:bg-amber-950/60', 'border-amber-200', 'dark:border-amber-800/70');
            btn.classList.remove('text-slate-400', 'bg-white/70', 'dark:bg-slate-800/70', 'border-slate-200/80', 'dark:border-slate-700');
        } else {
            btn.classList.remove('text-amber-500', 'bg-amber-50', 'dark:bg-amber-950/60', 'border-amber-200', 'dark:border-amber-800/70');
            btn.classList.add('text-slate-400', 'bg-white/70', 'dark:bg-slate-800/70', 'border-slate-200/80', 'dark:border-slate-700');
        }
    });
}

function toggleFileActionFavorite(actionKey, ev) {
    if (ev) {
        ev.preventDefault();
        ev.stopPropagation();
    }
    const pinned = getPinnedFileActions();
    const idx = pinned.indexOf(actionKey);
    if (idx !== -1) {
        pinned.splice(idx, 1);
    } else {
        pinned.push(actionKey);
    }
    try {
        localStorage.setItem(PINNED_FILE_ACTIONS_STORAGE_KEY, JSON.stringify(pinned));
    } catch {}
    applyPinnedFileActions();
}

function resetPinnedFileActions() {
    try {
        localStorage.setItem(PINNED_FILE_ACTIONS_STORAGE_KEY, JSON.stringify(DEFAULT_PINNED_FILE_ACTIONS));
    } catch {}
    applyPinnedFileActions();
}

applyPinnedFileActions();

function closeMobileActionModal() {
    if (!mobileActionModal) return;
    mobileActionModal.classList.add('hidden');
    mobileActionModal.classList.remove('flex');
}

if (mobileActionModal) {
    mobileActionModal.addEventListener('click', (e) => {
        if (e.target === mobileActionModal) {
            closeMobileActionModal();
        }
    });
}

function appendMenuActionRow(label, cls, onClickFn, pinKey = null) {
    const row = document.createElement('div');
    row.className = 'flex items-center gap-1.5';

    const b = document.createElement('button');
    b.type = 'button';
    b.className = `flex-1 min-w-0 flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-semibold transition-colors cursor-pointer text-left ${cls}`;
    b.textContent = label;
    b.addEventListener('click', () => {
        closeMobileActionModal();
        onClickFn();
    });
    row.appendChild(b);

    if (pinKey && ALL_PINNABLE_FILE_ACTIONS.includes(pinKey)) {
        const isPinned = getPinnedFileActions().includes(pinKey);
        const starBtn = document.createElement('button');
        starBtn.type = 'button';
        starBtn.setAttribute('data-action-pin-star', pinKey);
        starBtn.className = `w-9 h-9 rounded-xl border flex items-center justify-center text-sm transition-all hover:scale-110 cursor-pointer shrink-0 ${
            isPinned
                ? 'text-amber-500 bg-amber-50 dark:bg-amber-950/60 border-amber-200 dark:border-amber-800/70'
                : 'text-slate-400 bg-white/70 dark:bg-slate-800/70 border-slate-200/80 dark:border-slate-700 hover:text-amber-500'
        }`;
        starBtn.textContent = isPinned ? '⭐' : '☆';
        starBtn.title = isPinned
            ? 'Aus der Dateizeile ausblenden (nur im ⋯ Menü zeigen)'
            : 'Als Favorit direkt außen neben der Datei anheften';
        starBtn.addEventListener('click', (ev) => toggleFileActionFavorite(pinKey, ev));
        row.appendChild(starBtn);
    }

    mobileActionList.appendChild(row);
}

function openMobileFileMenu(fileIdx, canDelete) {
    if (!previewItems || !previewItems[fileIdx] || !mobileActionModal) return;
    const item = previewItems[fileIdx];
    mobileActionTitle.textContent = item.name;
    mobileActionSubtitle.textContent = `${item.size} • ${item.date}`;
    mobileActionList.innerHTML = '';

    // 1. Vorschau
    appendMenuActionRow('👁️ Vorschau öffnen', 'bg-violet-50 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300 hover:bg-violet-100', () => {
        openPreviewModal(fileIdx);
    }, 'preview');

    // 2. Office Light (falls unterstützt & unverschlüsselt)
    if (item.officeUrl && item.officeUrl !== '') {
        appendMenuActionRow(item.canManage ? '✏️ In Office Light bearbeiten' : '📄 In Office Light öffnen', 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100', () => {
            window.location.href = item.officeUrl;
        }, 'office');
    }

    // 2b. Im Übersetzungs-Studio übersetzen (für PDF, Word, Text, Markdown, Excel, PowerPoint, Untertitel)
    <?php if (($appSettings['enable_translator'] ?? '1') === '1'): ?>
    if (!item.isEncrypted && ['pdf', 'docx', 'odt', 'txt', 'md', 'csv', 'xlsx', 'pptx', 'html', 'htm', 'srt', 'vtt'].includes(String(item.ext || '').toLowerCase())) {
        appendMenuActionRow('🌐 Dokument übersetzen (18 Sprachen)', 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 hover:bg-sky-100', () => {
            window.location.href = 'translate.php?file_id=' + Number(item.id);
        }, null);
    }
    <?php endif; ?>

    // 3. Herunterladen
    appendMenuActionRow(item.isEncrypted ? '🔓 Entschlüsseln & Herunterladen' : '⬇️ Datei herunterladen', 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 hover:bg-sky-100', () => {
        if (item.isEncrypted) {
            openVaultModal(item.id, item.name, true, item.canManage);
        } else {
            window.location.href = item.downloadUrl;
        }
    }, 'download');

    // 4. Kommentare & Dateiversionen (Zeitreise)
    const vCount = Number(item.versionCount || 0);
    const cCount = Number(item.commentCount || 0);
    appendMenuActionRow(`💬 Kommentare & Versionen (🕓 ${vCount} / 💬 ${cCount})`, 'bg-teal-50 dark:bg-teal-950/60 text-teal-700 dark:text-teal-300 hover:bg-teal-100', () => {
        openFileMetaModal(item.id, item.name, 'comments');
    }, 'meta');

    // 5. Teilen
    if (item.canManage) {
        appendMenuActionRow('🔗 Teilen (Link / Benutzer)', 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100', () => {
            openShareModal(item.id, item.name, '');
        }, 'share');
    }

    // 6. Tresor
    if (item.canManage || item.isEncrypted) {
        appendMenuActionRow(item.isEncrypted ? '🔒 Tresor-Schutz verwalten' : '🔒 Im Tresor verschlüsseln (AES-256)', 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 hover:bg-amber-100', () => {
            openVaultModal(item.id, item.name, item.isEncrypted, item.canManage);
        }, 'vault');
    }

    // 7a. Datei umbenennen
    if (item.canManage) {
        appendMenuActionRow('✏️ Datei umbenennen', 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100', () => {
            openRenameModal('file', item.id, item.name, item.rawFolder || '');
        }, 'rename');
    }

    // 7b. Verschieben / Kopieren
    appendMenuActionRow('⇄ Verschieben oder kopieren', 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200', () => {
        openTransferModal('file', item.id, item.name, item.rawFolder || '', item.canManage);
    }, 'move');

    // 8. Datei selbst als Favorit markieren
    if (item.canManage) {
        appendMenuActionRow(item.isFavorite ? '⭐ Datei aus Favoriten entfernen' : '☆ Datei als Favorit markieren', 'bg-amber-50/70 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 hover:bg-amber-100', () => {
            toggleFavorite(item.id);
        }, null);
    }

    // 9. Löschen
    if (canDelete) {
        appendMenuActionRow('🗑️ In den Papierkorb verschieben', 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 hover:bg-red-100', () => {
            if (confirm(`Möchten Sie die Datei "${item.name}" in den Papierkorb verschieben?`)) {
                mobileDeleteFolder.value = '';
                mobileDeleteFolder.disabled = true;
                mobileDeleteFileId.disabled = false;
                mobileDeleteFileId.value = String(item.id);
                mobileDeleteForm.submit();
            }
        }, 'delete');
    }

    mobileActionModal.classList.remove('hidden');
    mobileActionModal.classList.add('flex');
}

function openMobileFolderMenu(displayName, subPath, fileCount, canZip, canShare, canDelete) {
    if (!mobileActionModal) return;
    mobileActionTitle.textContent = '📁 ' + displayName;
    mobileActionSubtitle.textContent = `${fileCount} ${fileCount === 1 ? 'Datei' : 'Dateien'}`;
    mobileActionList.innerHTML = '';

    if (canZip) {
        appendMenuActionRow('⬇️ Ordner als ZIP herunterladen', 'bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 hover:bg-sky-100', () => {
            window.location.href = 'download.php?folder=' + encodeURIComponent(subPath);
        }, 'download');
    }

    if (canShare) {
        appendMenuActionRow('🔗 Ordner teilen', 'bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100', () => {
            openShareModal(0, subPath, subPath);
        }, 'share');
    }

    if (canDelete || <?= ($canUploadHere && $activeSharedFolder === null) ? 'true' : 'false' ?>) {
        appendMenuActionRow('✏️ Ordner umbenennen', 'bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100', () => {
            openRenameModal('folder', 0, displayName, subPath);
        }, 'rename');
    }

    appendMenuActionRow('⇄ Ordner verschieben oder kopieren', 'bg-amber-50 dark:bg-amber-950/60 text-amber-800 dark:text-amber-300 hover:bg-amber-100', () => {
        openTransferModal('folder', 0, displayName, subPath, canDelete);
    }, 'move');

    if (canDelete) {
        appendMenuActionRow('🗑️ Ordner in den Papierkorb verschieben', 'bg-red-50 dark:bg-red-950/60 text-red-700 dark:text-red-300 hover:bg-red-100', () => {
            if (confirm(`Möchten Sie den Ordner "${subPath}" in den Papierkorb verschieben?`)) {
                mobileDeleteFileId.value = '';
                mobileDeleteFileId.disabled = true;
                mobileDeleteFolder.disabled = false;
                mobileDeleteFolder.value = subPath;
                mobileDeleteForm.submit();
            }
        }, 'delete');
    }

    mobileActionModal.classList.remove('hidden');
    mobileActionModal.classList.add('flex');
}

// --- SHARE MODAL LOGIK (für Dateien UND Ordner) ---
const shareModal = document.getElementById('shareModal');
const shareModalTitle = document.getElementById('shareModalTitle');
const shareModalFileId = document.getElementById('shareModalFileId');
const shareModalFolderPath = document.getElementById('shareModalFolderPath');
const shareModalFilename = document.getElementById('shareModalFilename');
const generatedLinkBox = document.getElementById('generatedLinkBox');
const generatedLinkInput = document.getElementById('generatedLinkInput');
const existingLinksList = document.getElementById('existingLinksList');
const existingUserSharesList = document.getElementById('existingUserSharesList');

function openShareModal(fileId, itemName, folderPath = '') {
    shareModalFileId.value = String(fileId || 0);
    shareModalFolderPath.value = folderPath || '';
    shareModalTitle.textContent = folderPath ? 'Ordner teilen' : 'Datei teilen';
    shareModalFilename.textContent = folderPath ? ('📁 ' + itemName) : itemName;
    document.getElementById('shareLinkPassword').value = '';
    document.getElementById('shareLinkExpires').value = '0';
    const maxDlSelect = document.getElementById('shareLinkMaxDownloads');
    if (maxDlSelect) maxDlSelect.value = '0';
    const modeWrapper = document.getElementById('shareLinkModeWrapper');
    const modeSelect = document.getElementById('shareLinkMode');
    if (modeWrapper && modeSelect) {
        modeSelect.value = 'read';
        if (folderPath) {
            modeWrapper.classList.remove('hidden');
        } else {
            modeWrapper.classList.add('hidden');
        }
    }
    generatedLinkBox.classList.add('hidden');
    shareModal.classList.remove('hidden');
    shareModal.classList.add('flex');
    loadShareDetails(fileId, folderPath);
}

function closeShareModal() {
    shareModal.classList.add('hidden');
    shareModal.classList.remove('flex');
}

function downloadQrCodeSvg(containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    const svg = el.querySelector('svg');
    if (!svg) return;
    const blob = new Blob([svg.outerHTML], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'minicloud-freigabe-qr.svg';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function toggleExistingLinkQr(shareId) {
    const box = document.getElementById('linkQrBox_' + shareId);
    if (box) {
        box.classList.toggle('hidden');
        box.classList.toggle('flex');
    }
}

function copyGeneratedLink(customUrl) {
    const urlToCopy = customUrl || generatedLinkInput.value;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(urlToCopy);
    } else {
        generatedLinkInput.select();
        document.execCommand('copy');
    }
    const fb = document.getElementById('copyFeedback');
    fb.classList.remove('hidden');
    setTimeout(() => fb.classList.add('hidden'), 2000);
}

async function loadShareDetails(fileId, folderPath = '') {
    existingLinksList.innerHTML = '<p class="text-xs text-slate-400">Lade Freigaben...</p>';
    existingUserSharesList.innerHTML = '';

    try {
        const queryParam = folderPath
            ? `folder_path=${encodeURIComponent(folderPath)}`
            : `file_id=${encodeURIComponent(fileId)}`;
        const res = await fetch(`share_action.php?action=list&${queryParam}`);
        const data = await res.json();
        if (!data.success) {
            existingLinksList.innerHTML = '';
            return;
        }

        // Bestehende Links rendern
        if (data.links.length === 0) {
            existingLinksList.innerHTML = '<p class="text-xs text-slate-400">Noch keine aktiven Freigabelinks vorhanden.</p>';
        } else {
            existingLinksList.innerHTML = data.links.map(l => {
                let modeBadge = '';
                if (folderPath) {
                    if (l.share_mode === 'upload_only') {
                        modeBadge = ' &bull; <span class="text-amber-600 dark:text-amber-400 font-semibold">📤 Digitaler Briefkasten (Nur Upload)</span>';
                    } else if (l.share_mode === 'read_write') {
                        modeBadge = ' &bull; <span class="text-emerald-600 dark:text-emerald-400 font-semibold">🔄 Download &amp; Upload</span>';
                    } else {
                        modeBadge = ' &bull; <span>👁️ Nur Download</span>';
                    }
                }
                let burnBadge = '';
                if (l.max_downloads !== null && Number(l.max_downloads) > 0) {
                    burnBadge = ` &bull; <span class="text-rose-600 dark:text-rose-400 font-semibold">🔥 ${Number(l.download_count || 0)}/${Number(l.max_downloads)} Downloads</span>`;
                }
                const sid = Number(l.id) || 0;
                return `
                <div class="p-2.5 rounded-xl bg-slate-100/80 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-1.5">
                                <input type="text" readonly value="${escapeHtml(String(l.url || ''))}" class="w-full bg-transparent font-mono text-slate-700 dark:text-slate-200 truncate focus:outline-none">
                            </div>
                            <div class="text-[11px] text-slate-400 mt-0.5">
                                ${l.has_password ? '🔒 Passwortgeschützt' : '🌐 Öffentlich'}
                                ${l.expires_at ? ' &bull; Gültig bis ' + escapeHtml(String(l.expires_at)) : ' &bull; Unbegrenzt'}
                                ${burnBadge}
                                ${modeBadge}
                            </div>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            ${l.qr_svg ? `
                                <button type="button" onclick="toggleExistingLinkQr(${sid})" class="px-2 py-1 rounded bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-brand-50 cursor-pointer" title="QR-Code anzeigen">
                                    📱 QR
                                </button>
                            ` : ''}
                            <button type="button" data-copy-url="${escapeHtml(String(l.url || ''))}" onclick="copyGeneratedLink(this.getAttribute('data-copy-url'))" class="px-2 py-1 rounded bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 hover:bg-brand-50 cursor-pointer">
                                Kopieren
                            </button>
                            <button type="button" onclick="deleteShareLink(${sid})" class="px-2 py-1 rounded bg-red-50 dark:bg-red-950/60 text-red-600 dark:text-red-300 hover:bg-red-100 cursor-pointer">
                                Löschen
                            </button>
                        </div>
                    </div>
                    ${l.qr_svg ? `
                        <div id="linkQrBox_${sid}" class="hidden items-center gap-3 pt-2 border-t border-slate-200 dark:border-slate-700">
                            <div id="linkQrSvg_${sid}" class="shrink-0">${l.qr_svg}</div>
                            <div class="space-y-1">
                                <p class="font-semibold text-slate-800 dark:text-slate-200">📱 QR-Code für diesen Link</p>
                                <button type="button" onclick="downloadQrCodeSvg('linkQrSvg_${sid}')" class="px-2.5 py-1 rounded bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 text-[11px] font-semibold cursor-pointer">
                                    ⬇️ Als SVG speichern
                                </button>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
            }).join('');
        }

        // Bestehende Benutzer-Freigaben rendern
        if (data.user_shares.length === 0) {
            existingUserSharesList.innerHTML = '<p class="text-xs text-slate-400">Noch mit keinem anderen Cloud-Benutzer geteilt.</p>';
        } else {
            existingUserSharesList.innerHTML = data.user_shares.map(u => {
                const permLabel = u.can_delete
                    ? '🗑️ Lesen, Schreiben & Löschen'
                    : (u.can_write ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen');
                return `
                <div class="flex items-center justify-between gap-2 p-2 rounded-xl bg-blue-50/70 dark:bg-blue-950/40 border border-blue-200/70 dark:border-blue-800/60 text-xs">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="font-medium text-blue-900 dark:text-blue-200 truncate">👤 <strong>${escapeHtml(String(u.username || ''))}</strong></span>
                        <span class="px-1.5 py-0.5 rounded bg-white/80 dark:bg-slate-800 text-[11px] font-semibold">
                            ${permLabel}
                        </span>
                    </div>
                    <button type="button" onclick="revokeUserShare(${Number(u.id) || 0})" class="text-red-600 dark:text-red-400 hover:underline font-medium cursor-pointer shrink-0">
                        Entfernen
                    </button>
                </div>
                `;
            }).join('');
        }
    } catch {
        existingLinksList.innerHTML = '';
    }
}

document.getElementById('createShareLinkForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const fileId = shareModalFileId.value;
    const folderPath = shareModalFolderPath.value;
    const fd = new FormData();
    fd.append('action', 'create_link');
    fd.append('csrf_token', csrfToken);
    fd.append('file_id', fileId);
    fd.append('folder_path', folderPath);
    fd.append('password', document.getElementById('shareLinkPassword').value);
    fd.append('expires_days', document.getElementById('shareLinkExpires').value);
    const maxDlSelect = document.getElementById('shareLinkMaxDownloads');
    if (maxDlSelect) {
        fd.append('max_downloads', maxDlSelect.value);
    }
    const modeSelect = document.getElementById('shareLinkMode');
    if (modeSelect && folderPath) {
        fd.append('share_mode', modeSelect.value);
    }

    const res = await fetch('share_action.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        generatedLinkInput.value = data.share_url;
        generatedLinkBox.classList.remove('hidden');
        const qrContainer = document.getElementById('generatedLinkQrContainer');
        const qrSvgEl = document.getElementById('generatedLinkQrSvg');
        if (qrContainer && qrSvgEl && data.qr_svg) {
            qrSvgEl.innerHTML = data.qr_svg;
            qrContainer.classList.remove('hidden');
        }
        document.getElementById('shareLinkPassword').value = '';
        loadShareDetails(fileId, folderPath);
    } else {
        alert(data.message || 'Fehler beim Erstellen des Freigabelinks.');
    }
});

async function deleteShareLink(shareId) {
    const fd = new FormData();
    fd.append('action', 'delete_link');
    fd.append('csrf_token', csrfToken);
    fd.append('share_id', String(shareId));
    await fetch('share_action.php', { method: 'POST', body: fd });
    loadShareDetails(shareModalFileId.value, shareModalFolderPath.value);
}

const shareWithUserForm = document.getElementById('shareWithUserForm');
if (shareWithUserForm) {
    shareWithUserForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fileId = shareModalFileId.value;
        const folderPath = shareModalFolderPath.value;
        const targetUserId = document.getElementById('shareTargetUserId').value;
        const canWriteEl = document.getElementById('shareTargetCanWrite');
        const canWrite = canWriteEl ? canWriteEl.value : '0';
        if (!targetUserId) return;

        const fd = new FormData();
        fd.append('action', 'share_user');
        fd.append('csrf_token', csrfToken);
        fd.append('file_id', fileId);
        fd.append('folder_path', folderPath);
        fd.append('target_user_id', targetUserId);
        fd.append('can_write', canWrite);

        const res = await fetch('share_action.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            document.getElementById('shareTargetUserId').value = '';
            loadShareDetails(fileId, folderPath);
        } else {
            alert(data.message || 'Fehler beim Teilen.');
        }
    });
}

async function revokeUserShare(userShareId) {
    const fd = new FormData();
    fd.append('action', 'unshare_user');
    fd.append('csrf_token', csrfToken);
    fd.append('user_share_id', String(userShareId));
    await fetch('share_action.php', { method: 'POST', body: fd });
    loadShareDetails(shareModalFileId.value, shareModalFolderPath.value);
}

// --- UPLOAD & DIREKTE ORDNER-DRAG-&-DROP LOGIK (Externer Auto-Upload + Internes Datei-Verschieben) ---
(() => {
    const mainFilesSection = document.getElementById('mainFilesSection');
    if (!mainFilesSection) return;

    const fileInput = document.getElementById('fileInput');
    const folderInput = document.getElementById('folderInput');
    const btnSelectFiles = document.getElementById('btnSelectFiles');
    const btnSelectFolder = document.getElementById('btnSelectFolder');
    const uploadForm = document.getElementById('uploadForm');
    const progressWrapper = document.getElementById('uploadProgressWrapper');
    const progressBar = document.getElementById('uploadProgressBar');
    const progressPercent = document.getElementById('uploadProgressPercent');
    const progressText = document.getElementById('uploadProgressText');
    const globalDropHint = document.getElementById('globalDropHint');
    const globalDropHintText = document.getElementById('globalDropHintText');

    const remainingQuota = <?= (int)$storageStats['free'] ?>;
    const defaultTargetFolder = <?= json_encode($currentFolder) ?>;
    const defaultTargetLabel = defaultTargetFolder !== '' ? `„${defaultTargetFolder}“` : 'das aktuelle Verzeichnis';
    const activeGroupFolderId = <?= $activeGroupFolder !== null ? (int)$activeGroupFolder['id'] : 'null' ?>;
    let isUploading = false;
    let draggedInternalFileIds = [];
    let draggedInternalLabel = '';

    function formatSize(bytes) {
        if (bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 2).replace('.', ',') + ' ' + units[i];
    }

    function showDropHint(text) {
        if (!globalDropHint || !globalDropHintText) return;
        globalDropHintText.textContent = text;
        globalDropHint.classList.remove('hidden');
        globalDropHint.classList.add('flex');
    }

    function hideDropHint() {
        if (!globalDropHint) return;
        globalDropHint.classList.add('hidden');
        globalDropHint.classList.remove('flex');
    }

    function clearFolderDropHighlights() {
        document.querySelectorAll('[data-drop-folder]').forEach((el) => {
            el.classList.remove('ring-2', 'ring-brand-500', 'bg-brand-50/90', 'dark:bg-brand-950/60');
        });
        mainFilesSection.classList.remove('ring-2', 'ring-brand-500');
    }

    function startImmediateUpload(itemsToUpload, overrideTargetFolder = null, overrideLabel = null) {
        if (!uploadForm || isUploading || itemsToUpload.length === 0) return;

        const totalBytes = itemsToUpload.reduce((acc, item) => acc + (item.file.size || 0), 0);
        if (totalBytes > remainingQuota) {
            alert(`Fehler: ${itemsToUpload.length} Datei(en) (${formatSize(totalBytes)}) überschreiten Ihren freien Speicher (${formatSize(remainingQuota)})!`);
            return;
        }

        const effectiveFolder = overrideTargetFolder !== null
            ? overrideTargetFolder
            : (document.getElementById('target_folder')?.value ?? defaultTargetFolder);
        const targetDisplay = overrideLabel || (effectiveFolder !== '' ? `Ordner „${effectiveFolder}“` : 'Hauptverzeichnis');

        isUploading = true;
        hideDropHint();

        if (progressWrapper) {
            progressWrapper.classList.remove('hidden');
            progressBar.style.width = '0%';
            progressPercent.textContent = '0%';
            progressText.textContent = `Lade ${itemsToUpload.length} Datei(en) in ${targetDisplay} hoch...`;
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('target_folder', effectiveFolder);
        const sfInput = uploadForm.querySelector('input[name="shared_folder_id"]');
        if (sfInput) {
            formData.append('shared_folder_id', sfInput.value);
        }
        const gfInput = uploadForm.querySelector('input[name="group_folder_id"]');
        if (gfInput) {
            formData.append('group_folder_id', gfInput.value);
        }

        for (const item of itemsToUpload) {
            formData.append('files[]', item.file, item.file.name);
            formData.append('relative_paths[]', item.relativePath);
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload.php', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.addEventListener('progress', (evt) => {
            if (evt.lengthComputable && progressBar && progressPercent && progressText) {
                const pct = Math.round((evt.loaded / evt.total) * 100);
                progressBar.style.width = pct + '%';
                progressPercent.textContent = pct + '%';
                progressText.textContent = `Hochladen in ${targetDisplay} (${formatSize(evt.loaded)} von ${formatSize(evt.total)})...`;
            }
        });

        xhr.addEventListener('load', () => {
            try {
                const resp = JSON.parse(xhr.responseText);
                window.location.href = resp.redirect || 'index.php';
            } catch {
                window.location.href = 'index.php';
            }
        });

        xhr.addEventListener('error', () => {
            isUploading = false;
            alert('Netzwerkfehler beim Hochladen.');
            if (progressWrapper) progressWrapper.classList.add('hidden');
        });

        xhr.send(formData);
    }

    function handleFileListAndUpload(fileList, overrideTargetFolder = null, overrideLabel = null) {
        const items = [];
        for (const file of fileList) {
            const relPath = file.webkitRelativePath || file.name;
            items.push({ file, relativePath: relPath });
        }
        startImmediateUpload(items, overrideTargetFolder, overrideLabel);
    }

    if (btnSelectFiles && fileInput) {
        btnSelectFiles.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) handleFileListAndUpload(fileInput.files);
        });
    }

    if (btnSelectFolder && folderInput) {
        btnSelectFolder.addEventListener('click', () => folderInput.click());
        folderInput.addEventListener('change', () => {
            if (folderInput.files.length > 0) handleFileListAndUpload(folderInput.files);
        });
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', (e) => e.preventDefault());
    }

    async function readEntryRecursively(entry, pathPrefix = '') {
        if (entry.isFile) {
            return new Promise((resolve) => {
                entry.file((file) => {
                    resolve([{ file, relativePath: pathPrefix + file.name }]);
                }, () => resolve([]));
            });
        }
        if (entry.isDirectory) {
            const dirReader = entry.createReader();
            const entries = [];
            const readBatch = () => new Promise((resolve) => {
                dirReader.readEntries((batch) => {
                    if (batch.length > 0) {
                        entries.push(...batch);
                        readBatch().then(resolve);
                    } else {
                        resolve();
                    }
                }, () => resolve());
            });
            await readBatch();
            const results = [];
            for (const childEntry of entries) {
                const subItems = await readEntryRecursively(childEntry, pathPrefix + entry.name + '/');
                results.push(...subItems);
            }
            return results;
        }
        return [];
    }

    async function extractDroppedItems(dataTransfer) {
        const items = dataTransfer?.items;
        if (items && items.length > 0 && typeof items[0].webkitGetAsEntry === 'function') {
            const entries = [];
            for (let i = 0; i < items.length; i++) {
                const entry = items[i].webkitGetAsEntry();
                if (entry) entries.push(entry);
            }
            if (entries.length > 0) {
                const extractedItems = [];
                for (const entry of entries) {
                    const extracted = await readEntryRecursively(entry, '');
                    extractedItems.push(...extracted);
                }
                return extractedItems;
            }
        }
        if (dataTransfer?.files?.length > 0) {
            const fallback = [];
            for (const file of dataTransfer.files) {
                fallback.push({ file, relativePath: file.webkitRelativePath || file.name });
            }
            return fallback;
        }
        return [];
    }

    // 1. Internes Ziehen von Cloud-Dateien ([data-drag-file-id]) in Ordner
    document.querySelectorAll('[data-drag-file-id]').forEach((el) => {
        el.addEventListener('dragstart', (e) => {
            const fileId = el.getAttribute('data-drag-file-id');
            const fileName = el.getAttribute('data-drag-file-name') || 'Datei';
            if (!fileId) return;

            // Falls die gezogene Datei Teil einer Mehrfachauswahl ist, alle markierten Dateien gemeinsam verschieben
            const checkedIds = typeof getSelectedBatchCheckboxes === 'function'
                ? getSelectedBatchCheckboxes()
                    .filter(cb => cb.getAttribute('data-can-manage') === '1')
                    .map(cb => cb.value)
                : [];

            if (checkedIds.length > 1 && checkedIds.includes(fileId)) {
                draggedInternalFileIds = checkedIds;
                draggedInternalLabel = `${checkedIds.length} ausgewählte Dateien`;
            } else {
                draggedInternalFileIds = [fileId];
                draggedInternalLabel = `„${fileName}“`;
            }

            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('application/x-minicloud-files', draggedInternalFileIds.join(','));
            el.classList.add('opacity-50');
        });

        el.addEventListener('dragend', () => {
            el.classList.remove('opacity-50');
            draggedInternalFileIds = [];
            draggedInternalLabel = '';
            clearFolderDropHighlights();
            hideDropHint();
        });
    });

    function submitInternalMoveToFolder(fileIds, targetFolderPath) {
        if (!fileIds || fileIds.length === 0) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'folder_action.php';
        form.className = 'hidden';

        const addField = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        addField('csrf_token', csrfToken);
        addField('action', 'batch_transfer_files');
        addField('batch_file_ids', fileIds.join(','));
        addField('target_folder', 'move|' + (targetFolderPath !== '' ? targetFolderPath : '__root__'));
        if (defaultTargetFolder !== '') {
            addField('return_folder', defaultTargetFolder);
        }
        if (activeGroupFolderId !== null) {
            addField('group_folder_id', String(activeGroupFolderId));
        }

        document.body.appendChild(form);
        form.submit();
    }

    // 2. Direkte Drop-Zonen auf allen Ordner-Zeilen & Ordner-Kacheln ([data-drop-folder])
    document.querySelectorAll('[data-drop-folder]').forEach((folderEl) => {
        const folderPath = folderEl.getAttribute('data-drop-folder') ?? '';
        const folderLabel = folderEl.getAttribute('data-drop-folder-label') || (folderPath !== '' ? folderPath : 'Hauptverzeichnis');

        folderEl.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearFolderDropHighlights();
            folderEl.classList.add('ring-2', 'ring-brand-500', 'bg-brand-50/90', 'dark:bg-brand-950/60');

            if (draggedInternalFileIds.length > 0) {
                e.dataTransfer.dropEffect = 'move';
                showDropHint(`📂 Loslassen, um ${draggedInternalLabel} direkt in „${folderLabel}“ zu verschieben`);
            } else if (uploadForm) {
                e.dataTransfer.dropEffect = 'copy';
                showDropHint(`📥 Loslassen, um Dateien direkt in den Ordner „${folderLabel}“ hochzuladen`);
            }
        });

        folderEl.addEventListener('dragleave', (e) => {
            if (!folderEl.contains(e.relatedTarget)) {
                folderEl.classList.remove('ring-2', 'ring-brand-500', 'bg-brand-50/90', 'dark:bg-brand-950/60');
            }
        });

        folderEl.addEventListener('drop', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            clearFolderDropHighlights();
            hideDropHint();

            // Fall A: Internes Verschieben von Cloud-Dateien in diesen Ordner
            if (draggedInternalFileIds.length > 0) {
                const idsToMove = [...draggedInternalFileIds];
                draggedInternalFileIds = [];
                submitInternalMoveToFolder(idsToMove, folderPath);
                return;
            }

            // Fall B: Externer Datei-/Ordner-Upload direkt in diesen Ordner
            if (!uploadForm) return;
            const extractedItems = await extractDroppedItems(e.dataTransfer);
            if (extractedItems.length > 0) {
                startImmediateUpload(extractedItems, folderPath, `Ordner „${folderLabel}“`);
            }
        });
    });

    // 3. Fallback Drop-Zone auf dem gesamten Dateibereich (#mainFilesSection) für das aktuelle Verzeichnis
    mainFilesSection.addEventListener('dragover', (e) => {
        if (draggedInternalFileIds.length > 0 || !uploadForm) return;
        e.preventDefault();
        if (!e.target.closest('[data-drop-folder]')) {
            clearFolderDropHighlights();
            mainFilesSection.classList.add('ring-2', 'ring-brand-500');
            showDropHint(`📥 Dateien oder Ordner hier ablegen, um sie direkt in ${defaultTargetLabel} hochzuladen`);
        }
    });

    mainFilesSection.addEventListener('dragleave', (e) => {
        if (!mainFilesSection.contains(e.relatedTarget)) {
            clearFolderDropHighlights();
            hideDropHint();
        }
    });

    mainFilesSection.addEventListener('drop', async (e) => {
        if (draggedInternalFileIds.length > 0 || !uploadForm) return;
        if (e.target.closest('[data-drop-folder]')) return;
        e.preventDefault();
        clearFolderDropHighlights();
        hideDropHint();

        const extractedItems = await extractDroppedItems(e.dataTransfer);
        if (extractedItems.length > 0) {
            startImmediateUpload(extractedItems, null, null);
        }
    });

    // 4. Zwischenablage-Upload per Strg+V (Screenshots & kopierte Bilder/Dateien sofort hochladen)
    window.addEventListener('paste', (e) => {
        if (!uploadForm || isUploading) return;
        const activeTag = document.activeElement?.tagName?.toLowerCase() || '';
        const isTyping = activeTag === 'input' || activeTag === 'textarea' || document.activeElement?.isContentEditable;

        const clipboardItems = e.clipboardData?.items;
        if (!clipboardItems || clipboardItems.length === 0) return;

        const pastedUploadItems = [];
        const now = new Date();
        const pad2 = (n) => String(n).padStart(2, '0');
        const ts = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}_${pad2(now.getHours())}-${pad2(now.getMinutes())}-${pad2(now.getSeconds())}`;

        for (let i = 0; i < clipboardItems.length; i++) {
            const item = clipboardItems[i];
            if (item.kind === 'file') {
                const rawFile = item.getAsFile();
                if (!rawFile) continue;
                let finalFile = rawFile;
                // Wenn ein Screenshot aus der Zwischenablage eingefügt wird (Standardname "image.png"), Zeitstempel-Namen vergeben
                if (!rawFile.name || /^image\.(png|jpe?g|webp|gif)$/i.test(rawFile.name)) {
                    const ext = (rawFile.type.split('/')[1] || 'png').replace(/[^a-z0-9]/gi, '');
                    const customName = `Screenshot_${ts}.${ext}`;
                    try {
                        finalFile = new File([rawFile], customName, { type: rawFile.type });
                    } catch {
                        finalFile = rawFile;
                    }
                }
                pastedUploadItems.push({ file: finalFile, relativePath: finalFile.name });
            }
        }

        if (pastedUploadItems.length > 0 && (!isTyping || pastedUploadItems.some(it => it.file.type.startsWith('image/')))) {
            e.preventDefault();
            startImmediateUpload(pastedUploadItems, null, null);
        }
    });
})();

// --- DATEIVERSIONEN (🕓) & DATEI-KOMMENTARE (💬) MODAL LOGIK ---
let currentMetaFileId = 0;
let currentMetaFileName = '';

function openFileMetaModal(fileId, fileName, focusSection = 'comments') {
    currentMetaFileId = Number(fileId) || 0;
    currentMetaFileName = String(fileName || '');
    const modal = document.getElementById('fileMetaModal');
    const sub = document.getElementById('fileMetaModalSubtitle');
    if (!modal || currentMetaFileId <= 0) return;

    if (sub) sub.textContent = currentMetaFileName;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');

    loadFileMetaData(currentMetaFileId, focusSection);
}

function closeFileMetaModal() {
    const modal = document.getElementById('fileMetaModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}

async function loadFileMetaData(fileId, focusSection = '') {
    const comList = document.getElementById('fileMetaCommentsList');
    const verList = document.getElementById('fileMetaVersionsList');
    const comBadge = document.getElementById('fileMetaCommentCountBadge');
    const verBadge = document.getElementById('fileMetaVersionCountBadge');
    const comInp = document.getElementById('fileMetaCommentInput');

    try {
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('action', 'file_meta');
        fd.append('file_id', String(fileId));

        const res = await fetch('folder_action.php', { method: 'POST', body: fd });
        const d = await res.json();
        if (!d || !d.success) {
            if (comList) comList.innerHTML = `<p class="text-xs text-red-600 dark:text-red-400">${escapeHtml(d?.message || 'Fehler beim Laden.')}</p>`;
            return;
        }

        const comments = Array.isArray(d.comments) ? d.comments : [];
        const versions = Array.isArray(d.versions) ? d.versions : [];

        if (comBadge) comBadge.textContent = String(comments.length);
        if (verBadge) verBadge.textContent = String(versions.length);

        if (comList) {
            if (comments.length === 0) {
                comList.innerHTML = '<p class="text-xs text-slate-400 italic py-2">Noch keine Kommentare oder Notizen zu dieser Datei vorhanden.</p>';
            } else {
                comList.innerHTML = comments.map((c) => `
                    <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 flex items-start justify-between gap-2 text-xs">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="font-bold text-brand-600 dark:text-brand-400">${escapeHtml(c.username)}</span>
                                <span class="text-[10px] text-slate-400">${escapeHtml(c.date_fmt)}</span>
                            </div>
                            <p class="text-slate-800 dark:text-slate-200 whitespace-pre-line break-words">${escapeHtml(c.text)}</p>
                        </div>
                        ${c.can_delete ? `<button type="button" onclick="deleteFileComment(${Number(c.id)})" class="p-1 text-slate-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer shrink-0" title="Kommentar löschen">🗑️</button>` : ''}
                    </div>
                `).join('');
                comList.scrollTop = comList.scrollHeight;
            }
        }

        if (verList) {
            if (versions.length === 0) {
                verList.innerHTML = '<p class="text-xs text-slate-400 italic py-2">Noch keine früheren Dateiversionen gespeichert. Sobald die Datei in Office Light bearbeitet oder erneut unter gleichem Namen hochgeladen wird, erscheint der vorherige Stand automatisch hier.</p>';
            } else {
                verList.innerHTML = versions.map((v) => `
                    <div class="p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200/80 dark:border-slate-700/80 flex flex-wrap items-center justify-between gap-2 text-xs">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="px-1.5 py-0.5 rounded bg-purple-100 dark:bg-purple-950 text-purple-800 dark:text-purple-300 font-mono font-bold text-[10px]">#${Number(v.id)}</span>
                                <span class="font-semibold text-slate-900 dark:text-white">${escapeHtml(v.date_fmt)}</span>
                                <span class="text-slate-400">(${escapeHtml(v.size_human)})</span>
                            </div>
                            ${v.note ? `<p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">${escapeHtml(v.note)}</p>` : ''}
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <a href="download.php?version_id=${Number(v.id)}" class="px-2.5 py-1 rounded-lg bg-white dark:bg-slate-700 hover:bg-slate-100 dark:hover:bg-slate-600 border border-slate-200 dark:border-slate-600 text-slate-700 dark:text-slate-200 font-semibold transition-colors" title="Diesen Stand herunterladen">⬇️ Download</a>
                            ${d.can_write ? `<button type="button" onclick="restoreFileVersion(${Number(v.id)})" class="px-2.5 py-1 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-semibold transition-colors cursor-pointer" title="Auf diesen Stand zurücksetzen">⏪ Wiederherstellen</button>` : ''}
                            ${d.is_owner ? `<button type="button" onclick="deleteFileVersion(${Number(v.id)})" class="p-1 text-slate-400 hover:text-red-600 dark:hover:text-red-400 cursor-pointer" title="Version löschen">🗑️</button>` : ''}
                        </div>
                    </div>
                `).join('');
            }
        }

        if (focusSection === 'comments' && comInp) {
            setTimeout(() => comInp.focus(), 80);
        }
    } catch {
        if (comList) comList.innerHTML = '<p class="text-xs text-red-600">Verbindungsfehler beim Laden.</p>';
    }
}

async function submitFileComment(e) {
    e.preventDefault();
    const inp = document.getElementById('fileMetaCommentInput');
    if (!inp || currentMetaFileId <= 0) return;
    const txt = inp.value.trim();
    if (!txt) return;

    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'add_comment');
    fd.append('file_id', String(currentMetaFileId));
    fd.append('comment_text', txt);

    const res = await fetch('folder_action.php', { method: 'POST', body: fd });
    const d = await res.json();
    if (d && d.success) {
        inp.value = '';
        await loadFileMetaData(currentMetaFileId, 'comments');
    } else {
        alert(d?.message || 'Kommentar konnte nicht gespeichert werden.');
    }
}

async function deleteFileComment(commentId) {
    if (!confirm('Diesen Kommentar wirklich löschen?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'delete_comment');
    fd.append('file_id', String(currentMetaFileId));
    fd.append('comment_id', String(commentId));

    await fetch('folder_action.php', { method: 'POST', body: fd });
    await loadFileMetaData(currentMetaFileId);
}

async function restoreFileVersion(versionId) {
    if (!confirm(`Möchten Sie die Datei „${currentMetaFileName}“ auf die ausgewählte Version #${versionId} zurücksetzen?\n\nDer aktuelle Stand geht dabei nicht verloren, sondern wird automatisch als neue Version gesichert.`)) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'restore_version');
    fd.append('file_id', String(currentMetaFileId));
    fd.append('version_id', String(versionId));

    const res = await fetch('folder_action.php', { method: 'POST', body: fd });
    const d = await res.json();
    if (d && d.success) {
        window.location.reload();
    } else {
        alert(d?.message || 'Wiederherstellung fehlgeschlagen.');
    }
}

async function deleteFileVersion(versionId) {
    if (!confirm('Diese alte Dateiversion endgültig löschen?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('action', 'delete_version');
    fd.append('file_id', String(currentMetaFileId));
    fd.append('version_id', String(versionId));

    await fetch('folder_action.php', { method: 'POST', body: fd });
    await loadFileMetaData(currentMetaFileId);
}

// Auto-Open Vorschau / Auto-Highlight Datei-Zeile (z. B. aus Strg+K Spotlight-Suche)
(() => {
    try {
        const params = new URLSearchParams(window.location.search);
        const previewId = parseInt(params.get('preview_id') || '0', 10);
        const highlightId = parseInt(params.get('highlight_id') || '0', 10) || previewId;

        if (highlightId > 0) {
            const rowEl = document.querySelector(`[data-file-row-id="${highlightId}"]`);
            if (rowEl) {
                rowEl.classList.add('ring-2', 'ring-brand-500', 'bg-brand-50/70', 'dark:bg-brand-950/40');
                setTimeout(() => {
                    rowEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 80);
            }
        }

        if (previewId > 0 && Array.isArray(previewItems)) {
            const idx = previewItems.findIndex(it => Number(it.id) === previewId);
            if (idx !== -1) {
                setTimeout(() => {
                    openPreviewModal(idx);
                }, 60);
            }
        }
    } catch (e) {}
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
