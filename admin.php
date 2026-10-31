<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Strikter Schutz: Nur eingeloggte Administratoren (oder Teil-Administratoren) dürfen auf admin.php zugreifen
requireAdmin();

$pdo = getDB();
ensureUserSecurityColumns($pdo);
$currentAdminId = (int)$_SESSION['user_id'];
$availableAdminPerms = getAvailableAdminPermissions();

/**
 * Ermittelt kompakte System- und Server-Kennzahlen (Speicherplatz, RAM, CPU, Netzwerk, Uptime).
 *
 * @return array<string, mixed>
 */
function getServerDashboardMetrics(PDO $pdo): array
{
    $nowMicro = microtime(true);

    // 1. Festplatten-Speicherplatz & MiniCloud-Belegung
    $checkDir  = is_dir(UPLOAD_DIR) ? UPLOAD_DIR : __DIR__;
    $diskTotal = (int)(@disk_total_space($checkDir) ?: 0);
    $diskFree  = (int)(@disk_free_space($checkDir) ?: 0);
    $diskUsed  = max(0, $diskTotal - $diskFree);
    $diskPct   = $diskTotal > 0 ? min(100.0, round(($diskUsed / $diskTotal) * 100, 1)) : 0.0;

    $cloudRow   = $pdo->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS sz FROM files')->fetch();
    $cloudFiles = (int)($cloudRow['cnt'] ?? 0);
    $cloudBytes = (int)($cloudRow['sz'] ?? 0);

    // 2. Arbeitsspeicher (RAM) aus /proc/meminfo
    $ramTotal = 0;
    $ramAvail = 0;
    if (is_readable('/proc/meminfo')) {
        $memInfo = (string)@file_get_contents('/proc/meminfo');
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $memInfo, $mTotal)) {
            $ramTotal = (int)$mTotal[1] * 1024;
        }
        if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $memInfo, $mAvail)) {
            $ramAvail = (int)$mAvail[1] * 1024;
        } elseif (preg_match('/^MemFree:\s+(\d+)\s+kB/m', $memInfo, $mFree)) {
            $ramAvail = (int)$mFree[1] * 1024;
        }
    }
    $ramUsed = max(0, $ramTotal - $ramAvail);
    $ramPct  = $ramTotal > 0 ? min(100.0, round(($ramUsed / $ramTotal) * 100, 1)) : 0.0;

    // 3. CPU-Auslastung & Load Average
    $cpuCores = 1;
    $cpuModel = 'Server CPU';
    if (is_readable('/proc/cpuinfo')) {
        $cpuInfo = (string)@file_get_contents('/proc/cpuinfo');
        $coresCount = preg_match_all('/^processor\s*:/m', $cpuInfo);
        if ($coresCount > 0) {
            $cpuCores = $coresCount;
        }
        if (preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $mModel)) {
            $cpuModel = trim($mModel[1]);
        }
    }

    $loadAvg = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0.0, 0.0, 0.0]) : [0.0, 0.0, 0.0];
    $load1   = round((float)($loadAvg[0] ?? 0.0), 2);
    $load5   = round((float)($loadAvg[1] ?? 0.0), 2);
    $load15  = round((float)($loadAvg[2] ?? 0.0), 2);

    $readCpuStat = static function (): ?array {
        if (!is_readable('/proc/stat')) {
            return null;
        }
        $firstLine = strtok((string)@file_get_contents('/proc/stat'), "\n");
        if (!is_string($firstLine) || !preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $firstLine, $m)) {
            return null;
        }
        $user   = (int)$m[1];
        $nice   = (int)$m[2];
        $system = (int)$m[3];
        $idle   = (int)$m[4];
        $iowait = (int)$m[5];
        $irq    = (int)$m[6];
        $soft   = (int)$m[7];
        $idleAll = $idle + $iowait;
        $total   = $user + $nice + $system + $idleAll + $irq + $soft;
        return ['total' => $total, 'idle' => $idleAll];
    };

    $cpuPct = min(100.0, round(($load1 / max(1, $cpuCores)) * 100, 1));
    $currCpu = $readCpuStat();
    if ($currCpu !== null) {
        $prevCpu = $_SESSION['admin_cpu_prev'] ?? null;
        if (is_array($prevCpu) && isset($prevCpu['total'], $prevCpu['idle']) && $currCpu['total'] > $prevCpu['total']) {
            $diffTotal = $currCpu['total'] - (int)$prevCpu['total'];
            $diffIdle  = $currCpu['idle'] - (int)$prevCpu['idle'];
            $cpuPct    = min(100.0, max(0.0, round((1.0 - ($diffIdle / max(1, $diffTotal))) * 100, 1)));
        }
        $_SESSION['admin_cpu_prev'] = $currCpu;
    }

    // 4. Netzwerk-Traffic & Durchsatz aus /proc/net/dev
    $rxBytes   = 0;
    $txBytes   = 0;
    $ifaceName = 'eth0';
    if (is_readable('/proc/net/dev')) {
        $lines = explode("\n", (string)@file_get_contents('/proc/net/dev'));
        $foundIface = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$ifRaw, $rest] = explode(':', $line, 2);
            $if = trim($ifRaw);
            if ($if === 'lo' || str_starts_with($if, 'docker') || str_starts_with($if, 'veth') || str_starts_with($if, 'br-')) {
                continue;
            }
            $cols = preg_split('/\s+/', trim($rest)) ?: [];
            if (isset($cols[0], $cols[8])) {
                $rxBytes += (int)$cols[0];
                $txBytes += (int)$cols[8];
                $foundIface[] = $if;
            }
        }
        if (!empty($foundIface)) {
            $ifaceName = implode(', ', array_slice($foundIface, 0, 2));
        }
    }

    $rxRateBps = 0;
    $txRateBps = 0;
    $prevNet   = $_SESSION['admin_net_prev'] ?? null;
    if (is_array($prevNet) && isset($prevNet['ts'], $prevNet['rx'], $prevNet['tx'])) {
        $dt = $nowMicro - (float)$prevNet['ts'];
        if ($dt >= 0.3 && $rxBytes >= (int)$prevNet['rx'] && $txBytes >= (int)$prevNet['tx']) {
            $rxRateBps = (int)round(($rxBytes - (int)$prevNet['rx']) / $dt);
            $txRateBps = (int)round(($txBytes - (int)$prevNet['tx']) / $dt);
        }
    }
    $_SESSION['admin_net_prev'] = [
        'ts' => $nowMicro,
        'rx' => $rxBytes,
        'tx' => $txBytes,
    ];

    // 5. Server-Uptime & System-Metadaten
    $uptimeFormatted = '–';
    if (is_readable('/proc/uptime')) {
        $upRaw  = explode(' ', trim((string)@file_get_contents('/proc/uptime')));
        $upSecs = (int)floor((float)($upRaw[0] ?? 0));
        $days   = intdiv($upSecs, 86400);
        $hours  = intdiv($upSecs % 86400, 3600);
        $mins   = intdiv($upSecs % 3600, 60);
        $uptimeFormatted = $days > 0
            ? sprintf('%dd %dh %dm', $days, $hours, $mins)
            : sprintf('%dh %dm', $hours, $mins);
    }

    $dbVersion = (string)($pdo->query('SELECT VERSION()')->fetchColumn() ?: 'MySQL/MariaDB');

    // 6. MariaDB / MySQL Monitoring-Kennzahlen (Status, Variablen & Tabellen-Speicher)
    $dbStatus = [];
    $dbVars   = [];
    try {
        $stmtSt = $pdo->query(
            "SHOW GLOBAL STATUS WHERE Variable_name IN (
                'Uptime', 'Questions', 'Queries', 'Slow_queries',
                'Threads_connected', 'Threads_running', 'Max_used_connections',
                'Aborted_connects', 'Aborted_clients',
                'Bytes_received', 'Bytes_sent',
                'Com_select', 'Com_insert', 'Com_update', 'Com_delete',
                'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
                'Innodb_buffer_pool_bytes_data', 'Innodb_buffer_pool_pages_total',
                'Innodb_buffer_pool_pages_data', 'Innodb_buffer_pool_pages_free',
                'Innodb_page_size', 'Open_tables', 'Opened_tables'
            )"
        );
        if ($stmtSt) {
            foreach ($stmtSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)($r['Variable_name'] ?? '');
                if ($k !== '') {
                    $dbStatus[$k] = (string)($r['Value'] ?? '0');
                }
            }
        }
    } catch (Throwable) {
        // Fallback auf eingeschränkten Shared-Hosts
    }

    try {
        $stmtVar = $pdo->query(
            "SHOW GLOBAL VARIABLES WHERE Variable_name IN (
                'max_connections', 'innodb_buffer_pool_size', 'long_query_time',
                'character_set_server', 'collation_server', 'version_comment'
            )"
        );
        if ($stmtVar) {
            foreach ($stmtVar->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string)($r['Variable_name'] ?? '');
                if ($k !== '') {
                    $dbVars[$k] = (string)($r['Value'] ?? '');
                }
            }
        }
    } catch (Throwable) {
        // Ignorieren
    }

    // DB-Uptime formatieren
    $dbUptimeSecs = max(1, (int)($dbStatus['Uptime'] ?? 0));
    $dbDays       = intdiv($dbUptimeSecs, 86400);
    $dbHours      = intdiv($dbUptimeSecs % 86400, 3600);
    $dbMins       = intdiv($dbUptimeSecs % 3600, 60);
    $dbUptimeFmt  = $dbDays > 0
        ? sprintf('%dd %dh %dm', $dbDays, $dbHours, $dbMins)
        : sprintf('%dh %dm', $dbHours, $dbMins);

    // Fragen / Queries & QPS (Live-Intervall + Durchschnitt seit Start)
    $dbQuestions = (int)($dbStatus['Queries'] ?? ($dbStatus['Questions'] ?? 0));
    $dbAvgQps    = round($dbQuestions / $dbUptimeSecs, 2);
    $dbLiveQps   = $dbAvgQps;
    $prevDb      = $_SESSION['admin_db_prev'] ?? null;
    if (is_array($prevDb) && isset($prevDb['ts'], $prevDb['q'])) {
        $dtDb = $nowMicro - (float)$prevDb['ts'];
        if ($dtDb >= 0.3 && $dbQuestions >= (int)$prevDb['q']) {
            $dbLiveQps = round(($dbQuestions - (int)$prevDb['q']) / $dtDb, 2);
        }
    }
    $_SESSION['admin_db_prev'] = [
        'ts' => $nowMicro,
        'q'  => $dbQuestions,
    ];

    $dbSlowQueries = (int)($dbStatus['Slow_queries'] ?? 0);
    $comSelect     = (int)($dbStatus['Com_select'] ?? 0);
    $comWrite      = (int)($dbStatus['Com_insert'] ?? 0) + (int)($dbStatus['Com_update'] ?? 0) + (int)($dbStatus['Com_delete'] ?? 0);
    $comTotalRw    = max(1, $comSelect + $comWrite);
    $dbReadPct     = round(($comSelect / $comTotalRw) * 100, 1);
    $dbWritePct    = round(($comWrite / $comTotalRw) * 100, 1);

    // Verbindungen & Thread-Pool
    $dbThreadsConn  = (int)($dbStatus['Threads_connected'] ?? 1);
    $dbThreadsRun   = (int)($dbStatus['Threads_running'] ?? 1);
    $dbMaxUsedConn  = (int)($dbStatus['Max_used_connections'] ?? $dbThreadsConn);
    $dbMaxConnLimit = max(1, (int)($dbVars['max_connections'] ?? 151));
    $dbConnPct      = min(100.0, round(($dbThreadsConn / $dbMaxConnLimit) * 100, 1));
    $dbPeakConnPct  = min(100.0, round(($dbMaxUsedConn / $dbMaxConnLimit) * 100, 1));
    $dbAbortedConn  = (int)($dbStatus['Aborted_connects'] ?? 0);

    // InnoDB Buffer Pool & Cache-Hit-Rate
    $innoReadReq   = (int)($dbStatus['Innodb_buffer_pool_read_requests'] ?? 0);
    $innoDiskReads = (int)($dbStatus['Innodb_buffer_pool_reads'] ?? 0);
    $innoHitRate   = $innoReadReq > 0
        ? max(0.0, min(100.0, round((1.0 - ($innoDiskReads / $innoReadReq)) * 100, 2)))
        : 100.0;

    $innoPageSize  = max(1024, (int)($dbStatus['Innodb_page_size'] ?? 16384));
    $innoPoolTotal = (int)($dbVars['innodb_buffer_pool_size'] ?? 0);
    if ($innoPoolTotal <= 0 && isset($dbStatus['Innodb_buffer_pool_pages_total'])) {
        $innoPoolTotal = (int)$dbStatus['Innodb_buffer_pool_pages_total'] * $innoPageSize;
    }
    $innoPoolData = (int)($dbStatus['Innodb_buffer_pool_bytes_data'] ?? 0);
    if ($innoPoolData <= 0 && isset($dbStatus['Innodb_buffer_pool_pages_data'])) {
        $innoPoolData = (int)$dbStatus['Innodb_buffer_pool_pages_data'] * $innoPageSize;
    }
    $innoPoolPct = $innoPoolTotal > 0 ? min(100.0, round(($innoPoolData / $innoPoolTotal) * 100, 1)) : 0.0;

    // DB-Netzwerk-Traffic
    $dbBytesRx = (int)($dbStatus['Bytes_received'] ?? 0);
    $dbBytesTx = (int)($dbStatus['Bytes_sent'] ?? 0);

    // Datenbank-Tabellen & Speicherbelegung (SHOW TABLE STATUS)
    $dbDataBytes  = 0;
    $dbIndexBytes = 0;
    $dbFreeBytes  = 0;
    $dbTotalRows  = 0;
    $dbTableCount = 0;
    $dbTablesList = [];

    $tableLabels = [
        'file_search_index'        => '🔍 Volltext-Suchindex',
        'files'                    => '📄 Dateien',
        'file_versions'            => '🕓 Dateiversionen',
        'folders'                  => '📁 Ordner',
        'users'                    => '👥 Benutzerkonten',
        'audit_logs'               => '📜 Audit-Protokoll',
        'messages'                 => '💬 Chat-Nachrichten',
        'calendar_events'          => '📅 Kalender-Termine',
        'contacts'                 => '📇 Kontakte',
        'shares'                   => '🔗 Öffentliche Links',
        'user_shares'              => '🤝 Interne Freigaben',
        'file_comments'            => '💭 Datei-Kommentare',
        'office_collab_deltas'     => '⚡ Live-Co-Authoring Deltas',
        'office_collab_presence'   => '🟢 Live-Co-Authoring Präsenz',
        'groups'                   => '🏢 Benutzergruppen',
        'group_members'            => '👤 Gruppenmitglieder',
        'group_folders'            => '🗂️ Gruppenordner',
        'group_folder_permissions' => '🔑 Gruppenordner-Rechte',
        'settings'                 => '⚙️ Systemeinstellungen',
        'rate_limits'              => '🛡️ Rate-Limiting',
    ];

    try {
        $stmtTbl = $pdo->query('SHOW TABLE STATUS');
        if ($stmtTbl) {
            foreach ($stmtTbl->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tName = (string)($t['Name'] ?? '');
                if ($tName === '') {
                    continue;
                }
                $dLen  = (int)($t['Data_length'] ?? 0);
                $iLen  = (int)($t['Index_length'] ?? 0);
                $fLen  = (int)($t['Data_free'] ?? 0);
                $tRows = (int)($t['Rows'] ?? 0);
                $tot   = $dLen + $iLen;

                $dbDataBytes  += $dLen;
                $dbIndexBytes += $iLen;
                $dbFreeBytes  += $fLen;
                $dbTotalRows  += $tRows;
                $dbTableCount++;

                $dbTablesList[] = [
                    'name'       => $tName,
                    'label'      => $tableLabels[$tName] ?? $tName,
                    'engine'     => (string)($t['Engine'] ?? 'InnoDB'),
                    'rows'       => $tRows,
                    'data_fmt'   => formatBytes($dLen),
                    'index_fmt'  => formatBytes($iLen),
                    'free_fmt'   => formatBytes($fLen),
                    'total_bytes'=> $tot,
                    'total_fmt'  => formatBytes($tot),
                ];
            }
        }
    } catch (Throwable) {
        // Ignorieren
    }

    usort($dbTablesList, static fn(array $a, array $b): int => $b['total_bytes'] <=> $a['total_bytes']);
    $dbTotalBytes = $dbDataBytes + $dbIndexBytes;
    $dbIndexPct   = $dbTotalBytes > 0 ? round(($dbIndexBytes / $dbTotalBytes) * 100, 1) : 0.0;

    return [
        'disk_total_fmt'      => formatBytes($diskTotal),
        'disk_used_fmt'       => formatBytes($diskUsed),
        'disk_free_fmt'       => formatBytes($diskFree),
        'disk_pct'            => $diskPct,
        'cloud_bytes_fmt'     => formatBytes($cloudBytes),
        'cloud_files'         => $cloudFiles,
        'ram_total_fmt'       => formatBytes($ramTotal),
        'ram_used_fmt'        => formatBytes($ramUsed),
        'ram_free_fmt'        => formatBytes($ramAvail),
        'ram_pct'             => $ramPct,
        'cpu_pct'             => $cpuPct,
        'cpu_cores'           => $cpuCores,
        'cpu_model'           => $cpuModel,
        'load_1'              => number_format($load1, 2, ',', '.'),
        'load_5'              => numberformat_de($load5),
        'load_15'             => numberformat_de($load15),
        'net_iface'           => $ifaceName,
        'net_rx_total'        => formatBytes($rxBytes),
        'net_tx_total'        => formatBytes($txBytes),
        'net_rx_rate'         => formatBytes($rxRateBps) . '/s',
        'net_tx_rate'         => formatBytes($txRateBps) . '/s',
        'hostname'            => (string)(gethostname() ?: 'localhost'),
        'uptime'              => $uptimeFormatted,
        'php_version'         => PHP_VERSION,
        'db_version'          => $dbVersion,
        'db_uptime'           => $dbUptimeFmt,
        'db_total_fmt'        => formatBytes($dbTotalBytes),
        'db_data_fmt'         => formatBytes($dbDataBytes),
        'db_index_fmt'        => formatBytes($dbIndexBytes),
        'db_free_fmt'         => formatBytes($dbFreeBytes),
        'db_index_pct'        => $dbIndexPct,
        'db_tables_count'     => $dbTableCount,
        'db_total_rows'       => number_format($dbTotalRows, 0, ',', '.'),
        'db_questions_fmt'    => number_format($dbQuestions, 0, ',', '.'),
        'db_qps_live'         => numberformat_de($dbLiveQps),
        'db_qps_avg'          => numberformat_de($dbAvgQps),
        'db_slow_queries'     => $dbSlowQueries,
        'db_read_pct'         => number_format($dbReadPct, 1, ',', '.'),
        'db_write_pct'        => number_format($dbWritePct, 1, ',', '.'),
        'db_threads_conn'     => $dbThreadsConn,
        'db_threads_run'      => $dbThreadsRun,
        'db_max_used_conn'    => $dbMaxUsedConn,
        'db_max_conn_limit'   => $dbMaxConnLimit,
        'db_conn_pct'         => $dbConnPct,
        'db_peak_conn_pct'    => $dbPeakConnPct,
        'db_aborted_conn'     => $dbAbortedConn,
        'db_inno_hit_rate'    => number_format($innoHitRate, 2, ',', '.'),
        'db_inno_pool_used'   => formatBytes($innoPoolData),
        'db_inno_pool_total'  => formatBytes($innoPoolTotal),
        'db_inno_pool_pct'    => $innoPoolPct,
        'db_bytes_rx_fmt'     => formatBytes($dbBytesRx),
        'db_bytes_tx_fmt'     => formatBytes($dbBytesTx),
        'db_tables'           => $dbTablesList,
        'timestamp'           => date('H:i:s'),
    ];
}

function numberformat_de(float $val): string
{
    return number_format($val, 2, ',', '.');
}

/**
 * Lädt eine HTTPS-URL (JSON oder ZIP) über cURL oder Stream-Context herunter.
 */
function fetchUrlBytes(string $url, ?string &$errorOut = null): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'MiniCloud-Updater/1.5 (+https://github.com/uWiiiDev/MiniCloud)',
                CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
            ]);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if (is_string($res) && $code >= 200 && $code < 300) {
                return $res;
            }
            $errorOut = $err !== '' ? $err : sprintf('HTTP-Statuscode %d von GitHub erhalten.', $code);
            return null;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 1,
            'header'          => "User-Agent: MiniCloud-Updater/1.5\r\nAccept: application/vnd.github+json\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) {
        $errorOut = 'Verbindung zu GitHub fehlgeschlagen.';
        return null;
    }
    return $res;
}

/**
 * Ermittelt den aktuell auf dem Server installierten GitHub-Commit (aus settings oder lokalem .git).
 *
 * @return array{sha: string, short: string, date: string}
 */
function getLocalInstalledCommitInfo(PDO $pdo): array
{
    $settings = getAppSettings($pdo);
    $sha  = trim((string)($settings['installed_commit_sha'] ?? ''));
    $date = trim((string)($settings['installed_commit_date'] ?? ''));

    $gitHeadFile = __DIR__ . '/.git/refs/heads/main';
    if ($sha === '' && is_readable($gitHeadFile)) {
        $rawHead = trim((string)@file_get_contents($gitHeadFile));
        if (preg_match('/^[0-9a-f]{40}$/i', $rawHead)) {
            $sha = strtolower($rawHead);
        }
    }

    return [
        'sha'   => $sha,
        'short' => $sha !== '' ? substr($sha, 0, 7) : '',
        'date'  => $date,
    ];
}

/**
 * Prüft über die GitHub-API die neuesten Commits des main-Branches (inkl. Historie für Rollback/Versionswechsel).
 *
 * @return array<string, mixed>
 */
function checkGitHubUpdates(PDO $pdo, string $repo = 'uWiiiDev/MiniCloud'): array
{
    if (
        !preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $repo)
        || strcasecmp($repo, 'uwebenzelrath/MiniCloud') === 0
        || strcasecmp($repo, 'uWiiiMiniCloud/MiniCloud') === 0
    ) {
        $repo = 'uWiiiDev/MiniCloud';
    }

    $err = null;
    $apiUrl = sprintf('https://api.github.com/repos/%s/commits?sha=main&per_page=15', $repo);
    $rawJson = fetchUrlBytes($apiUrl, $err);
    if ($rawJson === null) {
        return [
            'ok'    => false,
            'error' => $err ?? 'GitHub-API konnte nicht erreicht werden.',
        ];
    }

    $data = json_decode($rawJson, true);
    if (!is_array($data) || empty($data[0]['sha'])) {
        return [
            'ok'    => false,
            'error' => 'Ungültige Antwort der GitHub-API.',
        ];
    }

    $local = getLocalInstalledCommitInfo($pdo);
    $commits = [];
    foreach ($data as $item) {
        if (!is_array($item) || empty($item['sha'])) {
            continue;
        }
        $cSha = strtolower((string)$item['sha']);
        $cMsg = trim((string)($item['commit']['message'] ?? ''));
        $firstLine = strtok($cMsg, "\r\n") ?: $cMsg;
        $rawDate = (string)($item['commit']['committer']['date'] ?? ($item['commit']['author']['date'] ?? ''));
        $ts = $rawDate !== '' ? (strtotime($rawDate) ?: time()) : time();
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Berlin'));

        $commits[] = [
            'sha'       => $cSha,
            'short'     => substr($cSha, 0, 7),
            'message'   => $firstLine,
            'date_fmt'  => $dt->format('d.m.Y H:i') . ' Uhr',
            'author'    => (string)($item['commit']['author']['name'] ?? 'GitHub'),
            'html_url'  => (string)($item['html_url'] ?? ('https://github.com/' . $repo . '/commit/' . $cSha)),
        ];
    }

    $latest = $commits[0];
    $isUpToDate = ($local['sha'] !== '' && $local['sha'] === $latest['sha']);

    return [
        'ok'                 => true,
        'repo'               => $repo,
        'installed_sha'      => $local['sha'],
        'installed_short'    => $local['short'],
        'installed_date'     => $local['date'],
        'latest_sha'         => $latest['sha'],
        'latest_short'       => $latest['short'],
        'latest_message'     => $latest['message'],
        'latest_date'        => $latest['date_fmt'],
        'is_up_to_date'      => $isUpToDate,
        'commits'            => $commits,
    ];
}

/**
 * Liefert das geschützte Verzeichnis für lokale Programm-Sicherungspunkte (Snapshots) und legt es bei Bedarf an.
 */
function getSnapshotBackupDir(): string
{
    $dir = rtrim(UPLOAD_DIR, '/\\') . '/.system_backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $htFile = $dir . '/.htaccess';
    if (!is_file($htFile)) {
        @file_put_contents($htFile, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/**
 * Erstellt einen lokalen ZIP-Sicherungspunkt aller aktuellen Programmdateien (ohne uploads/ und ohne .git/),
 * damit ein Update jederzeit mit einem Klick lokal rückgängig gemacht werden kann.
 *
 * @return array{ok: bool, filename?: string, message: string}
 */
function createLocalAppSnapshot(PDO $pdo, string $reason = 'Vor System-Update'): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'Die PHP-Erweiterung ZipArchive ist nicht aktiviert.'];
    }

    $backupDir = getSnapshotBackupDir();
    if (!is_dir($backupDir) || !is_writable($backupDir)) {
        return ['ok' => false, 'message' => 'Das Sicherungsverzeichnis uploads/.system_backups ist nicht beschreibbar.'];
    }

    $localInfo = getLocalInstalledCommitInfo($pdo);
    $verInfo   = getAppVersionInfo();
    $shortSha  = $localInfo['short'] !== '' ? $localInfo['short'] : ($verInfo['hash'] ?? 'local');
    $cleanShort = preg_replace('/[^a-z0-9]/i', '', $shortSha) ?: 'local';

    $filename = sprintf('snapshot_%s_%s.zip', date('Ymd_His'), strtolower(substr($cleanShort, 0, 8)));
    $zipPath  = $backupDir . '/' . $filename;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'message' => 'Lokales Sicherungsarchiv konnte nicht angelegt werden.'];
    }

    $rootDir = realpath(__DIR__) ?: __DIR__;
    $filesAdded = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        $fullPath = $fileInfo->getPathname();
        $relPath  = ltrim(str_replace('\\', '/', substr($fullPath, strlen($rootDir))), '/');

        if (
            $relPath === '' ||
            str_starts_with($relPath, 'uploads/') ||
            str_starts_with($relPath, '.git/') ||
            str_starts_with($relPath, '.github/') ||
            str_starts_with($relPath, '.idea/') ||
            str_starts_with($relPath, '.vscode/') ||
            $relPath === 'config/database.php' ||
            $relPath === 'config/db_credentials.php' ||
            $relPath === 'config/database.local.php'
        ) {
            continue;
        }

        if ($zip->addFile($fullPath, $relPath)) {
            $filesAdded++;
        }
    }

    $meta = [
        'filename'    => $filename,
        'sha'         => $localInfo['sha'],
        'short'       => $shortSha,
        'commit_date' => $localInfo['date'],
        'version'     => $verInfo['version'] ?? 'v1.x',
        'reason'      => $reason,
        'created_at'  => date('d.m.Y H:i:s') . ' Uhr',
        'timestamp'   => time(),
        'files_count' => $filesAdded,
    ];
    $zip->addFromString('_snapshot_meta.json', (string)json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();
    @chmod($zipPath, 0640);

    // Automatisch maximal die 8 neuesten lokalen Sicherungspunkte behalten
    $allZips = glob($backupDir . '/snapshot_*.zip') ?: [];
    if (count($allZips) > 8) {
        usort($allZips, static fn(string $a, string $b): int => ((int)@filemtime($b)) <=> ((int)@filemtime($a)));
        foreach (array_slice($allZips, 8) as $oldZip) {
            @unlink($oldZip);
        }
    }

    return [
        'ok'       => true,
        'filename' => $filename,
        'message'  => sprintf('Lokaler Sicherungspunkt (#%s, %d Dateien) wurde erfolgreich erstellt.', $shortSha, $filesAdded),
    ];
}

/**
 * Liest alle vorhandenen lokalen Programm-Sicherungspunkte aus `uploads/.system_backups/` aus.
 *
 * @return array<int, array<string, mixed>>
 */
function getLocalAppSnapshots(): array
{
    $backupDir = getSnapshotBackupDir();
    $files = glob($backupDir . '/snapshot_*.zip') ?: [];
    usort($files, static fn(string $a, string $b): int => ((int)@filemtime($b)) <=> ((int)@filemtime($a)));

    $snapshots = [];
    foreach ($files as $filePath) {
        $fname = basename($filePath);
        $size  = (int)@filesize($filePath);
        $mtime = (int)@filemtime($filePath);

        $meta = [
            'filename'    => $fname,
            'sha'         => '',
            'short'       => 'lokal',
            'commit_date' => '',
            'version'     => '',
            'reason'      => 'Sicherungspunkt',
            'created_at'  => date('d.m.Y H:i:s', $mtime ?: time()) . ' Uhr',
            'size_fmt'    => formatBytes($size),
            'files_count' => 0,
        ];

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($filePath) === true) {
                $rawMeta = $zip->getFromName('_snapshot_meta.json');
                if (is_string($rawMeta)) {
                    $decoded = json_decode($rawMeta, true);
                    if (is_array($decoded)) {
                        $meta = array_merge($meta, $decoded);
                        $meta['filename'] = $fname;
                        $meta['size_fmt'] = formatBytes($size);
                    }
                }
                $zip->close();
            }
        }

        $snapshots[] = $meta;
    }

    return $snapshots;
}

/**
 * Stellt einen lokalen Sicherungspunkt (Snapshot) wieder her.
 *
 * @return array{ok: bool, message: string}
 */
function restoreLocalAppSnapshot(PDO $pdo, string $snapshotFilename): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'message' => 'Die PHP-Erweiterung ZipArchive ist nicht aktiviert.'];
    }

    $cleanName = basename($snapshotFilename);
    if (!preg_match('/^snapshot_[0-9]{8}_[0-9]{6}_[a-z0-9]+\.zip$/i', $cleanName)) {
        return ['ok' => false, 'message' => 'Ungültiger Dateiname des Sicherungspunkts.'];
    }

    $zipPath = getSnapshotBackupDir() . '/' . $cleanName;
    if (!is_file($zipPath) || !is_readable($zipPath)) {
        return ['ok' => false, 'message' => 'Der gewählte Sicherungspunkt wurde nicht gefunden.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['ok' => false, 'message' => 'Das Sicherungsarchiv konnte nicht geöffnet werden.'];
    }

    $meta = [];
    $rawMeta = $zip->getFromName('_snapshot_meta.json');
    if (is_string($rawMeta)) {
        $decoded = json_decode($rawMeta, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $restoredCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $relPath = (string)$zip->getNameIndex($i);
        if (
            $relPath === '' ||
            $relPath === '_snapshot_meta.json' ||
            str_ends_with($relPath, '/') ||
            str_contains($relPath, '..') ||
            str_starts_with($relPath, 'uploads/') ||
            str_starts_with($relPath, '.git/') ||
            str_starts_with($relPath, '.github/') ||
            $relPath === 'config/database.php' ||
            $relPath === 'config/db_credentials.php' ||
            $relPath === 'config/database.local.php'
        ) {
            continue;
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            continue;
        }

        $destPath = __DIR__ . '/' . $relPath;
        $destDir  = dirname($destPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        if (@file_put_contents($destPath, $content) !== false) {
            $restoredCount++;
        }
    }
    $zip->close();

    if ($restoredCount === 0) {
        return ['ok' => false, 'message' => 'Keine Dateien konnten aus dem Sicherungspunkt wiederhergestellt werden.'];
    }

    try {
        ensureSchemaUpdated($pdo);
        $snapSha  = trim((string)($meta['sha'] ?? ''));
        $snapDate = trim((string)($meta['commit_date'] ?? ($meta['created_at'] ?? '')));
        if ($snapSha !== '') {
            saveAppSetting($pdo, 'installed_commit_sha', $snapSha);
        }
        if ($snapDate !== '') {
            saveAppSetting($pdo, 'installed_commit_date', $snapDate);
        }
    } catch (Throwable) {
        // Ignorieren
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    $shortLabel = (string)($meta['short'] ?? 'lokal');
    return [
        'ok'      => true,
        'message' => sprintf(
            'Wiederherstellung erfolgreich! %d Programmdateien wurden auf den Sicherungspunkt #%s (%s) zurückgesetzt.',
            $restoredCount,
            $shortLabel,
            (string)($meta['created_at'] ?? '')
        ),
    ];
}

/**
 * Lädt das neueste Release-/Branch-Archiv oder einen gezielt ausgewählten Commit (Rollback / Zeitreise)
 * von GitHub herunter, legt zuvor automatisch einen lokalen Sicherungspunkt an und aktualisiert alle Programmdateien,
 * während `uploads/` und die Datenbank-Zugangsdaten in `config/database.php` geschützt bleiben.
 *
 * @return array{ok: bool, message: string}
 */
function performGitHubUpdate(
    PDO $pdo,
    string $repo = 'uWiiiDev/MiniCloud',
    string $targetSha = '',
    string $targetDate = '',
    string $targetMessage = ''
): array {
    if (!class_exists('ZipArchive')) {
        return [
            'ok'      => false,
            'message' => 'Die PHP-Erweiterung ZipArchive ist auf diesem Server nicht aktiviert.',
        ];
    }

    if (
        !preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $repo)
        || strcasecmp($repo, 'uwebenzelrath/MiniCloud') === 0
        || strcasecmp($repo, 'uWiiiMiniCloud/MiniCloud') === 0
    ) {
        $repo = 'uWiiiDev/MiniCloud';
    }

    $cleanTargetSha = strtolower(trim($targetSha));
    if ($cleanTargetSha !== '' && !preg_match('/^[0-9a-f]{7,40}$/', $cleanTargetSha)) {
        return [
            'ok'      => false,
            'message' => 'Ungültige Commit-ID (SHA) für die Versionsauswahl.',
        ];
    }

    $check = checkGitHubUpdates($pdo, $repo);
    $installSha  = $cleanTargetSha !== '' ? $cleanTargetSha : (string)($check['latest_sha'] ?? '');
    $installDate = trim($targetDate) !== '' ? trim($targetDate) : (string)($check['latest_date'] ?? date('d.m.Y H:i') . ' Uhr');

    if ($cleanTargetSha !== '' && !empty($check['commits']) && is_array($check['commits'])) {
        foreach ($check['commits'] as $cItem) {
            if (isset($cItem['sha']) && str_starts_with((string)$cItem['sha'], $cleanTargetSha)) {
                $installSha  = (string)$cItem['sha'];
                $installDate = (string)($cItem['date_fmt'] ?? $installDate);
                break;
            }
        }
    }

    $zipUrl = $cleanTargetSha !== ''
        ? sprintf('https://github.com/%s/archive/%s.zip', $repo, $installSha)
        : sprintf('https://github.com/%s/archive/refs/heads/main.zip', $repo);

    $dlErr = null;
    $zipBytes = fetchUrlBytes($zipUrl, $dlErr);
    if ($zipBytes === null || strlen($zipBytes) < 1000) {
        return [
            'ok'      => false,
            'message' => 'Download des Versions-Pakets von GitHub fehlgeschlagen: ' . ($dlErr ?? 'Leeres Archiv.'),
        ];
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'mc_upd_');
    if ($tmpZip === false || file_put_contents($tmpZip, $zipBytes) === false) {
        return [
            'ok'      => false,
            'message' => 'Temporäre Update-Datei konnte nicht gespeichert werden.',
        ];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        return [
            'ok'      => false,
            'message' => 'Das heruntergeladene ZIP-Archiv konnte nicht geöffnet werden.',
        ];
    }

    // Automatisch vor dem Überschreiben einen lokalen Sicherungspunkt des aktuellen Stands erstellen
    $shortTarget = $installSha !== '' ? substr($installSha, 0, 7) : 'main';
    $snapReason  = $cleanTargetSha !== ''
        ? sprintf('Automatisch vor Wechsel auf #%s', $shortTarget)
        : sprintf('Automatisch vor Update auf #%s', $shortTarget);
    createLocalAppSnapshot($pdo, $snapReason);

    // Bestehende Datenbank-Zugangsdaten in config/db_credentials.php sichern, falls noch nicht ausgelagert
    $credFilePath = __DIR__ . '/config/db_credentials.php';
    if (!is_file($credFilePath) && defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
        $credPhp = "<?php\ndeclare(strict_types=1);\n\n"
            . "// Automatisch gesicherte Datenbank-Zugangsdaten\n"
            . "define('DB_HOST', " . var_export((string)DB_HOST, true) . ");\n"
            . "define('DB_PORT', " . var_export((string)(defined('DB_PORT') ? DB_PORT : '3306'), true) . ");\n"
            . "define('DB_NAME', " . var_export((string)DB_NAME, true) . ");\n"
            . "define('DB_USER', " . var_export((string)DB_USER, true) . ");\n"
            . "define('DB_PASS', " . var_export((string)DB_PASS, true) . ");\n";
        if (@file_put_contents($credFilePath, $credPhp) !== false) {
            @chmod($credFilePath, 0640);
        }
    }

    $existingDbFile = __DIR__ . '/config/database.php';
    $existingDbContent = is_readable($existingDbFile) ? (string)@file_get_contents($existingDbFile) : '';
    $preservedDefines = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $constName) {
        if (preg_match("/define\(\s*'" . $constName . "'\s*,[^\r\n]+/", $existingDbContent, $mDef)) {
            $preservedDefines[$constName] = $mDef[0];
        }
    }

    $updatedFilesCount = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = (string)$zip->getNameIndex($i);
        if ($entryName === '' || str_ends_with($entryName, '/')) {
            continue;
        }

        // Top-Level-Ordner im GitHub-ZIP (z.B. "MiniCloud-main/" oder "MiniCloud-<sha>/") entfernen
        $slashPos = strpos($entryName, '/');
        if ($slashPos === false) {
            continue;
        }
        $relPath = substr($entryName, $slashPos + 1);
        if ($relPath === '' || str_contains($relPath, '..')) {
            continue;
        }

        // Benutzerdateien (uploads/), lokale Zugangsdaten und Git-Metadaten niemals überschreiben
        if (
            str_starts_with($relPath, 'uploads/') ||
            str_starts_with($relPath, '.git/') ||
            str_starts_with($relPath, '.github/') ||
            $relPath === 'config/db_credentials.php' ||
            $relPath === 'config/database.local.php'
        ) {
            continue;
        }

        $fileContent = $zip->getFromIndex($i);
        if ($fileContent === false) {
            continue;
        }

        // In config/database.php die bestehenden Datenbank-Konstanten (insb. DB_PASS) erhalten
        if ($relPath === 'config/database.php' && !empty($preservedDefines)) {
            foreach ($preservedDefines as $constName => $defLine) {
                $fileContent = (string)preg_replace(
                    "/define\(\s*'" . $constName . "'\s*,[^\r\n]+/",
                    addcslashes($defLine, '\\$'),
                    $fileContent,
                    1
                );
            }
        }

        $destPath = __DIR__ . '/' . $relPath;
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        if (@file_put_contents($destPath, $fileContent) !== false) {
            $updatedFilesCount++;
        }
    }

    $zip->close();
    @unlink($tmpZip);

    if ($updatedFilesCount === 0) {
        return [
            'ok'      => false,
            'message' => 'Keine Dateien konnten im Zielverzeichnis aktualisiert werden (bitte Schreibrechte prüfen).',
        ];
    }

    // Datenbank-Schema prüfen/migrieren und Commit-Stand vermerken
    try {
        ensureSchemaUpdated($pdo);
        if ($installSha !== '') {
            saveAppSetting($pdo, 'installed_commit_sha', $installSha);
            saveAppSetting($pdo, 'installed_commit_date', $installDate);
        }
    } catch (Throwable) {
        // Ignorieren
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    $shortSha = $installSha !== '' ? substr($installSha, 0, 7) : 'aktuell';
    $actionLabel = $cleanTargetSha !== '' ? 'Versionswechsel / Rollback' : 'System-Update';
    return [
        'ok'      => true,
        'message' => sprintf(
            '%s erfolgreich abgeschlossen! %d Dateien wurden auf Stand #%s (%s) gebracht. Ein lokaler Sicherungspunkt des vorherigen Stands wurde automatisch angelegt.',
            $actionLabel,
            $updatedFilesCount,
            $shortSha,
            $targetMessage !== '' ? mb_substr($targetMessage, 0, 60) : $installDate
        ),
    ];
}

/**
 * Erzeugt einen vollständigen, wiederherstellbaren SQL-Dump (Struktur + Daten) aller MiniCloud-Tabellen.
 */
function generateDatabaseSqlDump(PDO $pdo): string
{
    $lines = [];
    $lines[] = '-- ============================================================================';
    $lines[] = '-- MiniCloud Datenbank-Backup (.sql)';
    $lines[] = '-- Erstellt am: ' . date('d.m.Y H:i:s') . ' Uhr';
    $lines[] = '-- Datenbank:   ' . DB_NAME;
    $lines[] = '-- ============================================================================';
    $lines[] = '';
    $lines[] = 'SET NAMES utf8mb4;';
    $lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $lines[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
    $lines[] = '';

    $tablesStmt = $pdo->query('SHOW TABLES');
    $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];

    foreach ($tables as $table) {
        $tableName = (string)$table;
        if ($tableName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            continue;
        }

        $lines[] = '-- ----------------------------------------------------------------------------';
        $lines[] = '-- Tabelle `' . $tableName . '`';
        $lines[] = '-- ----------------------------------------------------------------------------';
        $lines[] = 'DROP TABLE IF EXISTS `' . $tableName . '`;';

        $createStmt = $pdo->query('SHOW CREATE TABLE `' . $tableName . '`');
        $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_NUM) : false;
        if (is_array($createRow) && isset($createRow[1])) {
            $lines[] = $createRow[1] . ';';
            $lines[] = '';
        }

        $rowsStmt = $pdo->query('SELECT * FROM `' . $tableName . '`');
        $rows = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!empty($rows)) {
            $cols = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $cols) . '`';

            foreach (array_chunk($rows, 100) as $chunk) {
                $valueGroups = [];
                foreach ($chunk as $row) {
                    $escapedVals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $escapedVals[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $escapedVals[] = (string)$val;
                        } else {
                            $escapedVals[] = $pdo->quote((string)$val);
                        }
                    }
                    $valueGroups[] = '(' . implode(', ', $escapedVals) . ')';
                }
                $lines[] = 'INSERT INTO `' . $tableName . '` (' . $colList . ') VALUES';
                $lines[] = implode(",\n", $valueGroups) . ';';
            }
            $lines[] = '';
        }
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $lines[] = '';

    return implode("\n", $lines);
}

if (isset($_GET['ajax_server_stats']) && $_GET['ajax_server_stats'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hasAdminPermission('system')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für System-Kennzahlen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(getServerDashboardMetrics($pdo), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ajax_check_update']) && $_GET['ajax_check_update'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hasAdminPermission('system')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung für System-Updates.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $settings = getAppSettings($pdo);
    $repo = trim((string)($settings['github_repo'] ?? 'uWiiiDev/MiniCloud')) ?: 'uWiiiDev/MiniCloud';
    echo json_encode(checkGitHubUpdates($pdo, $repo), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: admin.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // Berechtigungsprüfung für delegierte Teil-Administratoren je nach Aktion
    $actionPermMap = [
        'create_user'                     => 'users',
        'delete_user'                     => 'users',
        'update_user'                     => 'users',
        'toggle_lock_user'                => 'users',
        'update_registration_settings'    => 'users',
        'create_group'                    => 'users',
        'delete_group'                    => 'users',
        'add_group_member'                => 'users',
        'remove_group_member'             => 'users',
        'create_group_folder'             => 'users',
        'delete_group_folder'             => 'users',
        'grant_group_folder_permission'   => 'users',
        'revoke_group_folder_permission'  => 'users',
        'update_modules_settings'         => 'modules',
        'reset_module_stats'              => 'modules',
        'reindex_fulltext_search'         => 'modules',
        'save_browser_ocr_index'          => 'modules',
        'update_branding_only'            => 'branding',
        'update_mail_settings'            => 'mail',
        'send_test_email'                 => 'mail',
        'download_db_backup'              => 'system',
        'optimize_db_tables'              => 'system',
        'clear_audit_log'                 => 'system',
        'run_github_update'               => 'system',
        'create_local_snapshot'           => 'system',
        'restore_local_snapshot'          => 'system',
        'delete_local_snapshot'           => 'system',
    ];
    if (isset($actionPermMap[$action]) && !hasAdminPermission($actionPermMap[$action])) {
        setFlash('error', 'Keine Berechtigung für diesen Administrations-Bereich.');
        header('Location: admin.php');
        exit;
    }

    // --- 0. 1-Klick Datenbank-Backup (.sql) herunterladen ---
    if ($action === 'download_db_backup') {
        logAuditEvent($pdo, 'db_backup', 'Vollständiges SQL-Datenbank-Backup heruntergeladen');
        $sqlDump = generateDatabaseSqlDump($pdo);
        $filename = 'minicloud_db_backup_' . date('Y-m-d_His') . '.sql';

        if (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sqlDump));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $sqlDump;
        exit;
    }

    // --- 0f-2. MariaDB-Tabellen optimieren & defragmentieren (OPTIMIZE TABLE) ---
    if ($action === 'optimize_db_tables') {
        $optimizedCount = 0;
        try {
            $stmtTables = $pdo->query('SHOW TABLES');
            $tables = $stmtTables ? $stmtTables->fetchAll(PDO::FETCH_COLUMN) : [];
            foreach ($tables as $tbl) {
                $tName = (string)$tbl;
                if ($tName !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $tName)) {
                    $stmtOpt = $pdo->query('OPTIMIZE TABLE `' . $tName . '`');
                    if ($stmtOpt) {
                        $stmtOpt->fetchAll();
                        $optimizedCount++;
                    }
                }
            }
            logAuditEvent($pdo, 'db_optimize', sprintf('MariaDB-Tabellen optimiert & defragmentiert (%d Tabellen)', $optimizedCount));
            setFlash('success', sprintf('Alle %d MariaDB-Tabellen (inkl. Volltext-Suchindex) wurden erfolgreich optimiert und defragmentiert.', $optimizedCount));
        } catch (Throwable $e) {
            setFlash('error', 'Tabellen-Optimierung konnte nicht vollständig ausgeführt werden: ' . $e->getMessage());
        }
        header('Location: admin.php#tab-system');
        exit;
    }

    // --- 0g. Sicherheits- & Aktivitätsprotokoll (Audit-Log) leeren ---
    if ($action === 'clear_audit_log') {
        $pdo->exec('DELETE FROM audit_logs');
        logAuditEvent($pdo, 'audit_clear', 'Sicherheits- & Aktivitätsprotokoll durch Administrator geleert');
        setFlash('success', 'Das Sicherheits- und Aktivitätsprotokoll wurde geleert.');
        header('Location: admin.php#tab-system');
        exit;
    }

    // --- 1. Neuen Benutzer anlegen ---
    if ($action === 'create_user') {
        $username         = trim((string)($_POST['username'] ?? ''));
        $email            = trim((string)($_POST['email'] ?? ''));
        $password         = (string)($_POST['password'] ?? '');
        $quotaMb          = max(1, (int)($_POST['quota_mb'] ?? 100));
        $makeAdmin        = isset($_POST['is_admin']) && $_POST['is_admin'] === '1' ? 1 : 0;
        $rawCreatePerms   = isset($_POST['admin_permissions']) && is_array($_POST['admin_permissions']) ? $_POST['admin_permissions'] : [];
        $createPermsStr   = implode(',', parseAdminPermissionsString(implode(',', array_map('strval', $rawCreatePerms))));
        if (isset($_POST['is_admin']) && $_POST['is_admin'] === 'partial') {
            $makeAdmin = 0;
        } elseif ($makeAdmin === 1) {
            $createPermsStr = '';
        }
        $twoFactor        = isset($_POST['two_factor_enabled']) ? max(0, min(2, (int)$_POST['two_factor_enabled'])) : 0;
        $allowUserSharing = isset($_POST['allow_user_sharing']) && $_POST['allow_user_sharing'] === '1' ? 1 : 0;

        if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
            setFlash('error', 'Benutzername muss 3–32 Zeichen lang sein (Buchstaben, Zahlen, . _ -).');
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
        } elseif ($twoFactor === 1 && $email === '') {
            setFlash('error', 'Für die 2FA per E-Mail muss eine gültige E-Mail-Adresse hinterlegt werden.');
        } elseif (strlen($password) < 8) {
            setFlash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
        } else {
            $stmtCheck = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
            $stmtCheck->execute([':u' => $username]);
            if ($stmtCheck->fetch()) {
                setFlash('error', sprintf('Der Benutzername "%s" existiert bereits.', $username));
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmtIns = $pdo->prepare(
                    'INSERT INTO users (username, email, password_hash, two_factor_enabled, is_admin, admin_permissions, theme, allow_user_sharing, storage_quota)
                     VALUES (:u, :em, :h, :tfa, :adm, :perms, :theme, :aus, :q)'
                );
                $stmtIns->execute([
                    ':u'     => $username,
                    ':em'    => $email !== '' ? $email : null,
                    ':h'     => $hash,
                    ':tfa'   => $twoFactor,
                    ':adm'   => isFullAdmin() ? $makeAdmin : 0,
                    ':perms' => (isFullAdmin() && $makeAdmin === 0) ? $createPermsStr : '',
                    ':theme' => 'light',
                    ':aus'   => $allowUserSharing,
                    ':q'     => $quotaMb * 1024 * 1024,
                ]);
                logAuditEvent($pdo, 'user_create', sprintf('Neues Benutzerkonto „%s“ (%d MB%s) angelegt', $username, $quotaMb, $makeAdmin ? ', Admin' : ''));
                setFlash('success', sprintf('Benutzer "%s" (%d MB) wurde erfolgreich angelegt.', $username, $quotaMb));
            }
        }
        header('Location: admin.php#tab-users');
        exit;
    }

    // --- 2. Benutzer löschen ---
    if ($action === 'delete_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId === $currentAdminId) {
            setFlash('error', 'Sie können Ihr eigenes Administratorkonto nicht löschen.');
        } elseif ($targetUserId > 0) {
            $stmtTargetUser = $pdo->prepare('SELECT username, is_admin, admin_permissions FROM users WHERE id = :id LIMIT 1');
            $stmtTargetUser->execute([':id' => $targetUserId]);
            $targetRow = $stmtTargetUser->fetch();

            if ($targetRow) {
                $targetIsPrivileged = ((int)$targetRow['is_admin'] === 1) || (trim((string)($targetRow['admin_permissions'] ?? '')) !== '');
                if ($targetIsPrivileged && !isFullAdmin()) {
                    setFlash('error', 'Nur Voll-Administratoren können Konten von Administratoren oder Teil-Administratoren löschen.');
                    header('Location: admin.php#tab-users');
                    exit;
                }

                $deletedUsername = (string)$targetRow['username'];

                // Physische Dateien (und Versionsstände) des Benutzers vor dem Löschen entfernen
                $stmtFiles = $pdo->prepare('SELECT id, filename, stored_name FROM files WHERE user_id = :uid');
                $stmtFiles->execute([':uid' => $targetUserId]);
                foreach ($stmtFiles->fetchAll() as $f) {
                    deletePhysicalFileVersions($pdo, (int)$f['id']);
                    $sn = !empty($f['stored_name']) ? (string)$f['stored_name'] : (string)($f['filename'] ?? '');
                    if ($sn !== '') {
                        $p = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($sn);
                        if (is_file($p)) {
                            @unlink($p);
                        }
                    }
                }
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetUserId]);
                logAuditEvent($pdo, 'user_delete', sprintf('Benutzerkonto „%s“ inklusive Dateien gelöscht', $deletedUsername));
                setFlash('success', 'Benutzerkonto und zugehörige Dateien wurden gelöscht.');
            }
        }
        header('Location: admin.php#tab-users');
        exit;
    }

    // --- 2b. Benutzerkonto sperren / entsperren ---
    if ($action === 'toggle_lock_user') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $lockReason   = mb_substr(trim((string)($_POST['lock_reason'] ?? '')), 0, 250);

        if ($targetUserId === $currentAdminId) {
            setFlash('error', 'Sie können Ihr eigenes Konto nicht sperren.');
        } elseif ($targetUserId > 0) {
            $stmtTarget = $pdo->prepare('SELECT username, is_admin, admin_permissions, is_locked FROM users WHERE id = :id LIMIT 1');
            $stmtTarget->execute([':id' => $targetUserId]);
            $targetRow = $stmtTarget->fetch();

            if ($targetRow) {
                $targetIsPrivileged = ((int)$targetRow['is_admin'] === 1) || (trim((string)($targetRow['admin_permissions'] ?? '')) !== '');
                if ($targetIsPrivileged && !isFullAdmin()) {
                    setFlash('error', 'Nur Voll-Administratoren können andere Administratoren oder Teil-Administratoren sperren.');
                } else {
                    $newLockState = ((int)$targetRow['is_locked'] === 1) ? 0 : 1;
                    $pdo->prepare('UPDATE users SET is_locked = :l, lock_reason = :r WHERE id = :id')
                        ->execute([
                            ':l'  => $newLockState,
                            ':r'  => $newLockState === 1 ? $lockReason : '',
                            ':id' => $targetUserId,
                        ]);

                    logAuditEvent(
                        $pdo,
                        $newLockState === 1 ? 'user_lock' : 'user_unlock',
                        sprintf(
                            'Benutzerkonto „%s“ %s%s',
                            (string)$targetRow['username'],
                            $newLockState === 1 ? 'gesperrt' : 'entsperrt',
                            ($newLockState === 1 && $lockReason !== '') ? (' (Grund: ' . $lockReason . ')') : ''
                        )
                    );
                    setFlash(
                        'success',
                        sprintf(
                            'Benutzer „%s“ wurde %s.',
                            (string)$targetRow['username'],
                            $newLockState === 1 ? 'gesperrt (aktive Sitzungen werden sofort beendet)' : 'wieder entsperrt'
                        )
                    );
                }
            }
        }
        header('Location: admin.php#tab-users');
        exit;
    }

    // --- 3. Benutzer Passwort / Quota / E-Mail / 2FA / Teilen-Sichtbarkeit / Teil-Admin-Rechte aktualisieren ---
    if ($action === 'update_user') {
        $targetUserId     = (int)($_POST['user_id'] ?? 0);
        $email            = trim((string)($_POST['email'] ?? ''));
        $quotaMb          = max(1, (int)($_POST['quota_mb'] ?? 100));
        $newPassword      = (string)($_POST['new_password'] ?? '');
        $makeAdmin        = isset($_POST['is_admin']) && $_POST['is_admin'] === '1' ? 1 : 0;
        $twoFactor        = isset($_POST['two_factor_enabled']) ? max(0, min(2, (int)$_POST['two_factor_enabled'])) : 0;
        $allowUserSharing = isset($_POST['allow_user_sharing']) && $_POST['allow_user_sharing'] === '1' ? 1 : 0;
        $rawPerms = [];
        if (isset($_POST['admin_permissions']) && is_array($_POST['admin_permissions'])) {
            $rawPerms = $_POST['admin_permissions'];
        } elseif (isset($_POST['admin_perms']) && is_array($_POST['admin_perms'])) {
            $rawPerms = $_POST['admin_perms'];
        }
        $cleanPermsStr = implode(',', parseAdminPermissionsString(implode(',', array_map('strval', $rawPerms))));
        // Falls Teil-Admin-Rechte ausgewählt wurden, ist das Konto ein Teil-Admin (is_admin = 0)
        if ($cleanPermsStr !== '' && $targetUserId !== $currentAdminId) {
            $makeAdmin = 0;
        }

        $stmtExisting = $pdo->prepare('SELECT is_admin, admin_permissions FROM users WHERE id = :id LIMIT 1');
        $stmtExisting->execute([':id' => $targetUserId]);
        $existingUser = $stmtExisting->fetch();

        if (!isFullAdmin() && $existingUser) {
            // Teil-Admins dürfen keinen Voll-Admin oder Teil-Admin bearbeiten oder Admin-Rollen/Teil-Rechte verändern
            $existingIsPrivileged = ((int)$existingUser['is_admin'] === 1) || (trim((string)($existingUser['admin_permissions'] ?? '')) !== '');
            if ($existingIsPrivileged && $targetUserId !== $currentAdminId) {
                setFlash('error', 'Nur Voll-Administratoren dürfen Konten von Administratoren oder Teil-Administratoren bearbeiten.');
                header('Location: admin.php#tab-users');
                exit;
            }
            $makeAdmin     = (int)$existingUser['is_admin'];
            $cleanPermsStr = (string)($existingUser['admin_permissions'] ?? '');
        }

        if ($targetUserId === $currentAdminId) {
            $makeAdmin = isFullAdmin() ? 1 : (int)($existingUser['is_admin'] ?? 0);
        }

        if ($targetUserId > 0 && $existingUser) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                setFlash('error', 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
                header('Location: admin.php#tab-users');
                exit;
            }
            if ($twoFactor === 1 && $email === '') {
                setFlash('error', 'Um die 2FA per E-Mail zu aktivieren, muss eine gültige E-Mail-Adresse angegeben werden.');
                header('Location: admin.php#tab-users');
                exit;
            }

            if ($newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    setFlash('error', 'Neues Passwort muss mindestens 8 Zeichen lang sein.');
                    header('Location: admin.php#tab-users');
                    exit;
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmtUpd = $pdo->prepare(
                    'UPDATE users
                     SET email = :em,
                         two_factor_enabled = :tfa,
                         allow_user_sharing = :aus,
                         password_hash = :h,
                         storage_quota = :q,
                         is_admin = :adm,
                         admin_permissions = :perms
                     WHERE id = :id'
                );
                $stmtUpd->execute([
                    ':em'    => $email !== '' ? $email : null,
                    ':tfa'   => $twoFactor,
                    ':aus'   => $allowUserSharing,
                    ':h'     => $hash,
                    ':q'     => $quotaMb * 1024 * 1024,
                    ':adm'   => $makeAdmin,
                    ':perms' => $makeAdmin === 1 ? '' : $cleanPermsStr,
                    ':id'    => $targetUserId,
                ]);
            } else {
                $stmtUpd = $pdo->prepare(
                    'UPDATE users
                     SET email = :em,
                         two_factor_enabled = :tfa,
                         allow_user_sharing = :aus,
                         storage_quota = :q,
                         is_admin = :adm,
                         admin_permissions = :perms
                     WHERE id = :id'
                );
                $stmtUpd->execute([
                    ':em'    => $email !== '' ? $email : null,
                    ':tfa'   => $twoFactor,
                    ':aus'   => $allowUserSharing,
                    ':q'     => $quotaMb * 1024 * 1024,
                    ':adm'   => $makeAdmin,
                    ':perms' => $makeAdmin === 1 ? '' : $cleanPermsStr,
                    ':id'    => $targetUserId,
                ]);
            }
            setFlash('success', 'Benutzer-Einstellungen und Berechtigungen wurden aktualisiert.');
        }
        header('Location: admin.php#tab-users');
        exit;
    }

    // --- 4. Neue Gruppe anlegen ---
    if ($action === 'create_group') {
        $groupName = trim((string)($_POST['group_name'] ?? ''));
        $groupDesc = trim((string)($_POST['group_description'] ?? ''));

        if ($groupName === '') {
            setFlash('error', 'Bitte geben Sie einen Gruppennamen ein.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO `groups` (name, description) VALUES (:n, :d)');
                $stmt->execute([':n' => mb_substr($groupName, 0, 100), ':d' => mb_substr($groupDesc, 0, 255)]);
                setFlash('success', sprintf('Gruppe "%s" wurde angelegt.', $groupName));
            } catch (PDOException) {
                setFlash('error', 'Eine Gruppe mit diesem Namen existiert bereits.');
            }
        }
        header('Location: admin.php#groups');
        exit;
    }

    // --- 5. Gruppe löschen ---
    if ($action === 'delete_group') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId > 0) {
            $pdo->prepare('DELETE FROM `groups` WHERE id = :id')->execute([':id' => $groupId]);
            setFlash('success', 'Gruppe wurde gelöscht.');
        }
        header('Location: admin.php#groups');
        exit;
    }

    // --- 6. Benutzer zu Gruppe hinzufügen / entfernen ---
    if ($action === 'add_group_member') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $memberId = (int)($_POST['user_id'] ?? 0);
        if ($groupId > 0 && $memberId > 0) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO group_members (group_id, user_id) VALUES (:gid, :uid)');
            $stmt->execute([':gid' => $groupId, ':uid' => $memberId]);
            setFlash('success', 'Benutzer wurde zur Gruppe hinzugefügt.');
        }
        header('Location: admin.php#groups');
        exit;
    }

    if ($action === 'remove_group_member') {
        $gmId = (int)($_POST['gm_id'] ?? 0);
        if ($gmId > 0) {
            $pdo->prepare('DELETE FROM group_members WHERE id = :id')->execute([':id' => $gmId]);
            setFlash('success', 'Benutzer wurde aus der Gruppe entfernt.');
        }
        header('Location: admin.php#groups');
        exit;
    }

    // --- 7. Gruppenordner anlegen ---
    if ($action === 'create_group_folder') {
        $folderName = trim((string)($_POST['folder_name'] ?? ''));
        $folderDesc = trim((string)($_POST['folder_description'] ?? ''));

        if ($folderName === '') {
            setFlash('error', 'Bitte geben Sie einen Namen für den Gruppenordner ein.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO group_folders (name, description, created_by) VALUES (:n, :d, :c)');
                $stmt->execute([
                    ':n' => mb_substr($folderName, 0, 120),
                    ':d' => mb_substr($folderDesc, 0, 255),
                    ':c' => $currentAdminId,
                ]);
                setFlash('success', sprintf('Gruppenordner "%s" wurde erfolgreich erstellt.', $folderName));
            } catch (PDOException) {
                setFlash('error', 'Ein Gruppenordner mit diesem Namen existiert bereits.');
            }
        }
        header('Location: admin.php#group-folders');
        exit;
    }

    // --- 8. Gruppenordner löschen ---
    if ($action === 'delete_group_folder') {
        $gfId = (int)($_POST['group_folder_id'] ?? 0);
        if ($gfId > 0) {
            $stmtFiles = $pdo->prepare('SELECT filename FROM files WHERE group_folder_id = :gfid');
            $stmtFiles->execute([':gfid' => $gfId]);
            foreach ($stmtFiles->fetchAll() as $f) {
                $p = UPLOAD_DIR . basename((string)$f['filename']);
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            $pdo->prepare('DELETE FROM group_folders WHERE id = :id')->execute([':id' => $gfId]);
            setFlash('success', 'Gruppenordner und darin enthaltene Dateien wurden gelöscht.');
        }
        header('Location: admin.php#group-folders');
        exit;
    }

    // --- 9. Berechtigung für Gruppenordner vergeben (Benutzer oder Gruppe) ---
    if ($action === 'grant_group_folder_permission') {
        $gfId       = (int)($_POST['group_folder_id'] ?? 0);
        $targetType = (string)($_POST['target_type'] ?? 'user'); // 'user' oder 'group'
        $targetId   = (int)($_POST['target_id'] ?? 0);
        $canWrite   = isset($_POST['can_write']) && $_POST['can_write'] === '1' ? 1 : 0;

        if ($gfId > 0 && $targetId > 0) {
            if ($targetType === 'group') {
                $pdo->prepare('DELETE FROM group_folder_permissions WHERE group_folder_id = :gf AND group_id = :gid')
                    ->execute([':gf' => $gfId, ':gid' => $targetId]);
                $stmt = $pdo->prepare(
                    'INSERT INTO group_folder_permissions (group_folder_id, group_id, can_write) VALUES (:gf, :gid, :cw)'
                );
                $stmt->execute([':gf' => $gfId, ':gid' => $targetId, ':cw' => $canWrite]);
                setFlash('success', 'Gruppenberechtigung für den Gruppenordner wurde gespeichert.');
            } else {
                $pdo->prepare('DELETE FROM group_folder_permissions WHERE group_folder_id = :gf AND user_id = :uid')
                    ->execute([':gf' => $gfId, ':uid' => $targetId]);
                $stmt = $pdo->prepare(
                    'INSERT INTO group_folder_permissions (group_folder_id, user_id, can_write) VALUES (:gf, :uid, :cw)'
                );
                $stmt->execute([':gf' => $gfId, ':uid' => $targetId, ':cw' => $canWrite]);
                setFlash('success', 'Benutzerberechtigung für den Gruppenordner wurde gespeichert.');
            }
        }
        header('Location: admin.php#group-folders');
        exit;
    }

    // --- 10. Berechtigung für Gruppenordner entziehen ---
    if ($action === 'revoke_group_folder_permission') {
        $permId = (int)($_POST['permission_id'] ?? 0);
        if ($permId > 0) {
            $pdo->prepare('DELETE FROM group_folder_permissions WHERE id = :id')->execute([':id' => $permId]);
            setFlash('success', 'Berechtigung wurde entzogen.');
        }
        header('Location: admin.php#group-folders');
        exit;
    }

    // --- 11a. Öffentliche Selbstregistrierung aktualisieren ---
    if ($action === 'update_registration_settings') {
        $allowRegistration = isset($_POST['allow_registration']) && $_POST['allow_registration'] === '1' ? '1' : '0';
        $registrationCode  = mb_substr(trim((string)($_POST['registration_code'] ?? '')), 0, 100);
        $regQuotaMb        = max(1, min(102400, (int)($_POST['registration_default_quota_mb'] ?? 100)));

        saveAppSetting($pdo, 'allow_registration', $allowRegistration);
        saveAppSetting($pdo, 'registration_code', $registrationCode);
        saveAppSetting($pdo, 'registration_default_quota_mb', (string)$regQuotaMb);

        setFlash('success', 'Einstellungen für die öffentliche Selbstregistrierung wurden gespeichert.');
        header('Location: admin.php#tab-users');
        exit;
    }

    // --- 11b. Module & Funktionen aktualisieren ---
    if ($action === 'update_modules_settings') {
        $enableCalendar  = isset($_POST['enable_calendar']) && $_POST['enable_calendar'] === '1' ? '1' : '0';
        $enableChat      = isset($_POST['enable_chat']) && $_POST['enable_chat'] === '1' ? '1' : '0';
        $enableTasks     = isset($_POST['enable_tasks']) && $_POST['enable_tasks'] === '1' ? '1' : '0';
        $enableNotes     = isset($_POST['enable_notes']) && $_POST['enable_notes'] === '1' ? '1' : '0';
        $enableContacts  = isset($_POST['enable_contacts']) && $_POST['enable_contacts'] === '1' ? '1' : '0';
        $enableWebdav    = isset($_POST['enable_webdav']) && $_POST['enable_webdav'] === '1' ? '1' : '0';
        $enableDashboard = isset($_POST['enable_dashboard']) && $_POST['enable_dashboard'] === '1' ? '1' : '0';
        $enablePolls     = isset($_POST['enable_polls']) && $_POST['enable_polls'] === '1' ? '1' : '0';
        $enableMenti     = isset($_POST['enable_menti']) && $_POST['enable_menti'] === '1' ? '1' : '0';
        $enablePasswords = isset($_POST['enable_passwords']) && $_POST['enable_passwords'] === '1' ? '1' : '0';
        $enablePdfTools  = isset($_POST['enable_pdf_tools']) && $_POST['enable_pdf_tools'] === '1' ? '1' : '0';
        $enableProjects  = isset($_POST['enable_projects']) && $_POST['enable_projects'] === '1' ? '1' : '0';
        $enableOffice    = isset($_POST['enable_office']) && $_POST['enable_office'] === '1' ? '1' : '0';
        $enableAssistant = isset($_POST['enable_assistant']) && $_POST['enable_assistant'] === '1' ? '1' : '0';
        $enableEbooks    = isset($_POST['enable_ebooks']) && $_POST['enable_ebooks'] === '1' ? '1' : '0';
        $enableGames     = isset($_POST['enable_games']) && $_POST['enable_games'] === '1' ? '1' : '0';
        $enableMindmap   = isset($_POST['enable_mindmap']) && $_POST['enable_mindmap'] === '1' ? '1' : '0';
        $enableBudget    = isset($_POST['enable_budget']) && $_POST['enable_budget'] === '1' ? '1' : '0';
        $enableSplit     = isset($_POST['enable_split']) && $_POST['enable_split'] === '1' ? '1' : '0';
        $enableClub      = isset($_POST['enable_club']) && $_POST['enable_club'] === '1' ? '1' : '0';
        $enableGallery    = isset($_POST['enable_gallery']) && $_POST['enable_gallery'] === '1' ? '1' : '0';
        $enableForms      = isset($_POST['enable_forms']) && $_POST['enable_forms'] === '1' ? '1' : '0';
        $enableMusic      = isset($_POST['enable_music']) && $_POST['enable_music'] === '1' ? '1' : '0';
        $enableCalculator  = isset($_POST['enable_calculator']) && $_POST['enable_calculator'] === '1' ? '1' : '0';
        $enableToolbox     = isset($_POST['enable_toolbox']) && $_POST['enable_toolbox'] === '1' ? '1' : '0';
        $enableVideoStudio = isset($_POST['enable_video_studio']) && $_POST['enable_video_studio'] === '1' ? '1' : '0';
        $enableSurflog     = isset($_POST['enable_surflog']) && $_POST['enable_surflog'] === '1' ? '1' : '0';
        $enableCinema      = isset($_POST['enable_cinema']) && $_POST['enable_cinema'] === '1' ? '1' : '0';
        $enableTranslator  = isset($_POST['enable_translator']) && $_POST['enable_translator'] === '1' ? '1' : '0';
        $deeplApiKey       = mb_substr(trim((string)($_POST['translator_deepl_api_key'] ?? '')), 0, 160);
        $libreUrlRaw       = trim((string)($_POST['translator_libretranslate_url'] ?? ''));
        $libreUrl          = '';
        if ($libreUrlRaw !== '' && preg_match('/^https?:\/\/[^\s"\'<>]+$/i', $libreUrlRaw)) {
            $ltParts = @parse_url($libreUrlRaw);
            $ltHost  = strtolower(trim((string)($ltParts['host'] ?? ''), '[]'));
            $ltPort  = isset($ltParts['port']) ? (int)$ltParts['port'] : (strtolower((string)($ltParts['scheme'] ?? 'http')) === 'https' ? 443 : 80);
            $blockedPorts = [21, 22, 23, 25, 53, 110, 143, 445, 3306, 5432, 6379, 9200, 11211, 11434, 27017];
            if ($ltHost !== '' && !in_array($ltHost, ['169.254.169.254', 'metadata.google.internal', '100.100.100.200', 'fd00:ec2::254'], true) && !in_array($ltPort, $blockedPorts, true)) {
                $libreUrl = rtrim(mb_substr($libreUrlRaw, 0, 200), '/');
            }
        }
        $transDefaultTgt   = strtolower(trim((string)($_POST['translator_default_target'] ?? 'en')));
        if (!in_array($transDefaultTgt, ['de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'pt', 'cs', 'tr', 'uk', 'da', 'sv', 'el', 'ru', 'ar', 'zh', 'ja'], true)) {
            $transDefaultTgt = 'en';
        }
        $enableFulltext    = isset($_POST['enable_fulltext_search']) && $_POST['enable_fulltext_search'] === '1' ? '1' : '0';
        $trashRetention    = isset($_POST['trash_retention_days']) ? max(0, min(3650, (int)$_POST['trash_retention_days'])) : 30;
        $useOllama         = isset($_POST['assistant_use_ollama']) && $_POST['assistant_use_ollama'] === '1' ? '1' : '0';
        $ollamaModelRaw   = trim((string)($_POST['assistant_ollama_model'] ?? 'gemma2:2b'));
        $ollamaModel      = preg_replace('/[^a-zA-Z0-9._:-]/', '', $ollamaModelRaw) ?: 'gemma2:2b';

        saveAppSetting($pdo, 'enable_calendar', $enableCalendar);
        saveAppSetting($pdo, 'enable_chat', $enableChat);
        saveAppSetting($pdo, 'enable_tasks', $enableTasks);
        saveAppSetting($pdo, 'enable_notes', $enableNotes);
        saveAppSetting($pdo, 'enable_contacts', $enableContacts);
        saveAppSetting($pdo, 'enable_webdav', $enableWebdav);
        saveAppSetting($pdo, 'enable_dashboard', $enableDashboard);
        saveAppSetting($pdo, 'enable_polls', $enablePolls);
        saveAppSetting($pdo, 'enable_menti', $enableMenti);
        saveAppSetting($pdo, 'enable_passwords', $enablePasswords);
        saveAppSetting($pdo, 'enable_pdf_tools', $enablePdfTools);
        saveAppSetting($pdo, 'enable_projects', $enableProjects);
        saveAppSetting($pdo, 'enable_office', $enableOffice);
        saveAppSetting($pdo, 'enable_assistant', $enableAssistant);
        saveAppSetting($pdo, 'assistant_use_ollama', $useOllama);
        saveAppSetting($pdo, 'assistant_ollama_model', mb_substr($ollamaModel, 0, 64));
        saveAppSetting($pdo, 'enable_ebooks', $enableEbooks);
        saveAppSetting($pdo, 'enable_games', $enableGames);
        saveAppSetting($pdo, 'enable_mindmap', $enableMindmap);
        saveAppSetting($pdo, 'enable_budget', $enableBudget);
        saveAppSetting($pdo, 'enable_split', $enableSplit);
        saveAppSetting($pdo, 'enable_club', $enableClub);
        saveAppSetting($pdo, 'enable_gallery', $enableGallery);
        saveAppSetting($pdo, 'enable_forms', $enableForms);
        saveAppSetting($pdo, 'enable_music', $enableMusic);
        saveAppSetting($pdo, 'enable_calculator', $enableCalculator);
        saveAppSetting($pdo, 'enable_toolbox', $enableToolbox);
        saveAppSetting($pdo, 'enable_video_studio', $enableVideoStudio);
        saveAppSetting($pdo, 'enable_surflog', $enableSurflog);
        saveAppSetting($pdo, 'enable_cinema', $enableCinema);
        saveAppSetting($pdo, 'enable_translator', $enableTranslator);
        saveAppSetting($pdo, 'translator_deepl_api_key', $deeplApiKey);
        saveAppSetting($pdo, 'translator_libretranslate_url', $libreUrl);
        saveAppSetting($pdo, 'translator_default_target', $transDefaultTgt);
        saveAppSetting($pdo, 'enable_fulltext_search', $enableFulltext);
        saveAppSetting($pdo, 'trash_retention_days', (string)$trashRetention);
        if ($trashRetention > 0) {
            purgeExpiredTrashFiles($pdo, true);
        }

        setFlash('success', 'Alle Modul-Einstellungen wurden erfolgreich gespeichert.');
        header('Location: admin.php#tab-modules');
        exit;
    }

    // --- 11b-2. Modul-Nutzungsstatistik zurücksetzen ---
    if ($action === 'reset_module_stats') {
        resetModuleUsageStatistics($pdo);
        logAuditEvent($pdo, 'admin_Action', 'Modul-Nutzungsstatistik zurückgesetzt');
        setFlash('success', 'Die Modul-Nutzungsstatistik wurde auf 0 zurückgesetzt.');
        header('Location: admin.php#tab-modules');
        exit;
    }

    // --- 11b-3. Volltext-Suchindex für fehlende (oder alle) Cloud-Dokumente aufbauen / aktualisieren ---
    if ($action === 'reindex_fulltext_search') {
        @set_time_limit(300);
        $onlyMissing = !isset($_POST['full_rebuild']) || $_POST['full_rebuild'] !== '1';
        $res = reindexAllCloudFiles($pdo, 10000, $onlyMissing);
        logAuditEvent(
            $pdo,
            'admin_action',
            sprintf(
                'Volltext-Suchindex aktualisiert (%s: %d Dokumente neu indexiert, %d im Index)',
                $onlyMissing ? 'Nur fehlende Dokumente inkl. OCR' : 'Komplett-Neuaufbau',
                $res['indexed'],
                $res['total_index_rows']
            )
        );
        setFlash(
            'success',
            sprintf(
                '🔎✅ Volltext-Suchindex aktualisiert (%s): %d Dokument(e) erfolgreich neu indexiert (%d Dokumente insgesamt im Suchindex).',
                $onlyMissing ? 'nur fehlende Dateien inkl. Scan-OCR' : 'Komplett-Neuaufbau',
                $res['indexed'],
                $res['total_index_rows']
            )
        );
        header('Location: admin.php#tab-modules');
        exit;
    }

    // --- 11b-4. Browser-WebAssembly-OCR-Ergebnis für ein Scan-PDF speichern (STRATO-Fallback) ---
    if ($action === 'save_browser_ocr_index') {
        header('Content-Type: application/json; charset=UTF-8');
        $fileId  = (int)($_POST['file_id'] ?? 0);
        $ocrText = trim((string)($_POST['ocr_text'] ?? ''));
        $ok      = ($fileId > 0 && $ocrText !== '') ? saveManualFileSearchIndexText($pdo, $fileId, $ocrText) : false;
        echo json_encode(['ok' => $ok]);
        exit;
    }

    // --- 11c. Darstellung, Logo & Impressum (Branding) aktualisieren ---
    if ($action === 'update_branding_only') {
        $newTitle    = mb_substr(trim((string)($_POST['app_title'] ?? '')), 0, 80);
        $newSubtitle = mb_substr(trim((string)($_POST['app_subtitle'] ?? '')), 0, 80);
        $imprintText = mb_substr(trim((string)($_POST['imprint_text'] ?? '')), 0, 25000);
        $removeLogo  = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';

        if ($newTitle === '') {
            $newTitle = APP_NAME;
        }

        saveAppSetting($pdo, 'app_title', $newTitle);
        saveAppSetting($pdo, 'app_subtitle', $newSubtitle);
        saveAppSetting($pdo, 'imprint_text', $imprintText);

        if ($removeLogo) {
            saveAppSetting($pdo, 'logo_data_uri', '');
        }

        // Optionaler Logo-Upload (PNG, JPG, WEBP, GIF, SVG bis max. 2 MB)
        if (isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) && (int)($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $err = (int)$_FILES['logo_file']['error'];
            $tmp = (string)($_FILES['logo_file']['tmp_name'] ?? '');
            $sz  = (int)($_FILES['logo_file']['size'] ?? 0);

            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                setFlash('error', 'Fehler beim Hochladen der Logo-Datei.');
                header('Location: admin.php#tab-branding');
                exit;
            }
            if ($sz <= 0 || $sz > 2 * 1024 * 1024) {
                setFlash('error', 'Das Logo darf maximal 2 MB groß sein.');
                header('Location: admin.php#tab-branding');
                exit;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)($finfo->file($tmp) ?: '');
            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml'];
            if (!in_array($mime, $allowedMimes, true)) {
                setFlash('error', 'Ungültiges Bildformat. Erlaubt sind PNG, JPG, WEBP, GIF und SVG.');
                header('Location: admin.php#tab-branding');
                exit;
            }

            $rawBytes = file_get_contents($tmp);
            if ($rawBytes !== false) {
                if ($mime === 'image/svg+xml' && preg_match('/<\s*script|onload|onerror|javascript:|<\s*foreignObject/i', $rawBytes)) {
                    setFlash('error', 'Die SVG-Datei enthält potenziell unsicheren aktiven Code und wurde abgelehnt.');
                    header('Location: admin.php#tab-branding');
                    exit;
                }
                $dataUri = 'data:' . $mime . ';base64,' . base64_encode($rawBytes);
                saveAppSetting($pdo, 'logo_data_uri', $dataUri);
            }
        }

        setFlash('success', 'Anwendungstitel, Logo und Impressum wurden erfolgreich gespeichert.');
        header('Location: admin.php#tab-branding');
        exit;
    }

    // --- 11d. E-Mail- & SMTP-Konfiguration aktualisieren ---
    if ($action === 'update_mail_settings') {
        $mailFrom       = mb_substr(trim((string)($_POST['mail_from'] ?? '')), 0, 190);
        $mailTransport  = trim((string)($_POST['mail_transport'] ?? 'mail'));
        $smtpHost       = mb_substr(trim((string)($_POST['smtp_host'] ?? '')), 0, 190);
        $smtpPort       = (int)($_POST['smtp_port'] ?? 465);
        $smtpEncryption = trim((string)($_POST['smtp_encryption'] ?? 'ssl'));
        $smtpUser       = mb_substr(trim((string)($_POST['smtp_user'] ?? '')), 0, 190);
        $smtpPassInput  = (string)($_POST['smtp_pass'] ?? '');
        $clearSmtpPass  = isset($_POST['clear_smtp_pass']) && $_POST['clear_smtp_pass'] === '1';

        if (!in_array($mailTransport, ['mail', 'smtp'], true)) {
            $mailTransport = 'mail';
        }
        if (!in_array($smtpEncryption, ['ssl', 'tls', 'none'], true)) {
            $smtpEncryption = 'ssl';
        }
        if ($smtpPort <= 0 || $smtpPort > 65535) {
            $smtpPort = $smtpEncryption === 'tls' ? 587 : 465;
        }

        if ($mailFrom !== '' && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Die angegebene Absender-E-Mail-Adresse ist ungültig.');
            header('Location: admin.php#tab-mail');
            exit;
        }

        if ($mailTransport === 'smtp' && $smtpHost === '') {
            setFlash('error', 'Bitte geben Sie für den SMTP-Versand einen gültigen SMTP-Server (Host) an.');
            header('Location: admin.php#tab-mail');
            exit;
        }

        saveAppSetting($pdo, 'mail_from', $mailFrom);
        saveAppSetting($pdo, 'mail_transport', $mailTransport);
        saveAppSetting($pdo, 'smtp_host', $smtpHost);
        saveAppSetting($pdo, 'smtp_port', (string)$smtpPort);
        saveAppSetting($pdo, 'smtp_encryption', $smtpEncryption);
        saveAppSetting($pdo, 'smtp_user', $smtpUser);

        if ($clearSmtpPass) {
            saveAppSetting($pdo, 'smtp_pass', '');
        } elseif ($smtpPassInput !== '') {
            saveAppSetting($pdo, 'smtp_pass', $smtpPassInput);
        }

        setFlash('success', 'E-Mail- und SMTP-Konfiguration wurde erfolgreich gespeichert.');
        header('Location: admin.php#tab-mail');
        exit;
    }

    // --- 11e. Kombinierter Fallback (update_branding) ---
    if ($action === 'update_branding') {
        $newTitle       = mb_substr(trim((string)($_POST['app_title'] ?? '')), 0, 80);
        $newSubtitle    = mb_substr(trim((string)($_POST['app_subtitle'] ?? '')), 0, 80);
        $imprintText    = mb_substr(trim((string)($_POST['imprint_text'] ?? '')), 0, 25000);
        $mailFrom       = mb_substr(trim((string)($_POST['mail_from'] ?? '')), 0, 190);
        $mailTransport  = trim((string)($_POST['mail_transport'] ?? 'mail'));
        $smtpHost       = mb_substr(trim((string)($_POST['smtp_host'] ?? '')), 0, 190);
        $smtpPort       = (int)($_POST['smtp_port'] ?? 465);
        $smtpEncryption = trim((string)($_POST['smtp_encryption'] ?? 'ssl'));
        $smtpUser       = mb_substr(trim((string)($_POST['smtp_user'] ?? '')), 0, 190);
        $smtpPassInput  = (string)($_POST['smtp_pass'] ?? '');
        $clearSmtpPass  = isset($_POST['clear_smtp_pass']) && $_POST['clear_smtp_pass'] === '1';
        $removeLogo     = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';

        if ($newTitle === '') {
            $newTitle = APP_NAME;
        }
        if (!in_array($mailTransport, ['mail', 'smtp'], true)) {
            $mailTransport = 'mail';
        }
        if (!in_array($smtpEncryption, ['ssl', 'tls', 'none'], true)) {
            $smtpEncryption = 'ssl';
        }
        if ($smtpPort <= 0 || $smtpPort > 65535) {
            $smtpPort = $smtpEncryption === 'tls' ? 587 : 465;
        }

        if ($mailFrom !== '' && filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Die angegebene Absender-E-Mail-Adresse für 2FA-Codes ist ungültig.');
            header('Location: admin.php#tab-mail');
            exit;
        }

        if ($mailTransport === 'smtp' && $smtpHost === '') {
            setFlash('error', 'Bitte geben Sie für den SMTP-Versand einen gültigen SMTP-Server (Host) an.');
            header('Location: admin.php#tab-mail');
            exit;
        }

        saveAppSetting($pdo, 'app_title', $newTitle);
        saveAppSetting($pdo, 'app_subtitle', $newSubtitle);
        saveAppSetting($pdo, 'imprint_text', $imprintText);
        saveAppSetting($pdo, 'mail_from', $mailFrom);
        saveAppSetting($pdo, 'mail_transport', $mailTransport);
        saveAppSetting($pdo, 'smtp_host', $smtpHost);
        saveAppSetting($pdo, 'smtp_port', (string)$smtpPort);
        saveAppSetting($pdo, 'smtp_encryption', $smtpEncryption);
        saveAppSetting($pdo, 'smtp_user', $smtpUser);

        $allowRegistration = isset($_POST['allow_registration']) && $_POST['allow_registration'] === '1' ? '1' : '0';
        $registrationCode  = mb_substr(trim((string)($_POST['registration_code'] ?? '')), 0, 100);
        $regQuotaMb        = max(1, min(102400, (int)($_POST['registration_default_quota_mb'] ?? 100)));
        $enableCalendar    = isset($_POST['enable_calendar']) && $_POST['enable_calendar'] === '1' ? '1' : '0';
        $enableChat        = isset($_POST['enable_chat']) && $_POST['enable_chat'] === '1' ? '1' : '0';
        saveAppSetting($pdo, 'allow_registration', $allowRegistration);
        saveAppSetting($pdo, 'registration_code', $registrationCode);
        saveAppSetting($pdo, 'registration_default_quota_mb', (string)$regQuotaMb);
        saveAppSetting($pdo, 'enable_calendar', $enableCalendar);
        saveAppSetting($pdo, 'enable_chat', $enableChat);

        if ($clearSmtpPass) {
            saveAppSetting($pdo, 'smtp_pass', '');
        } elseif ($smtpPassInput !== '') {
            saveAppSetting($pdo, 'smtp_pass', $smtpPassInput);
        }

        if ($removeLogo) {
            saveAppSetting($pdo, 'logo_data_uri', '');
        }

        setFlash('success', 'Einstellungen wurden erfolgreich gespeichert.');
        header('Location: admin.php#tab-branding');
        exit;
    }

    // -------------------------------------------------------------------------
    // 0b. TEST-E-MAIL SENDEN (PHP mail() / Postfix oder SMTP prüfen)
    // -------------------------------------------------------------------------
    if ($action === 'send_test_email') {
        $testRecipient = trim((string)($_POST['test_email'] ?? ''));
        if (filter_var($testRecipient, FILTER_VALIDATE_EMAIL) === false) {
            setFlash('error', 'Bitte geben Sie eine gültige Empfänger-E-Mail-Adresse für die Test-E-Mail ein.');
            header('Location: admin.php#tab-mail');
            exit;
        }

        $settings = getAppSettings($pdo);
        $cleanTitle = preg_replace('/[\r\n"]/', '', $settings['app_title'] ?: APP_NAME) ?: APP_NAME;
        $transportLabel = $settings['mail_transport'] === 'smtp'
            ? sprintf('Externer SMTP-Server (%s:%s)', $settings['smtp_host'], $settings['smtp_port'])
            : 'Standard PHP mail() / Lokaler Mailserver (Postfix)';

        $subject = sprintf('[%s] Test-E-Mail des E-Mail-Versands', $cleanTitle);
        $body = implode("\r\n", [
            'Hallo,',
            '',
            sprintf('dies ist eine automatische Test-E-Mail Ihrer %s-Installation.', $cleanTitle),
            '',
            'Aktive Versandart: ' . $transportLabel,
            'Zeitpunkt:         ' . date('d.m.Y H:i:s') . ' Uhr',
            'Server-URL:        ' . getBaseUrl(),
            '',
            'Der E-Mail-Versand für Passwort-Resets und die Zwei-Faktor-Authentifizierung (2FA) funktioniert einwandfrei!',
        ]);

        $mailErr = null;
        if (sendSystemEmail($pdo, $testRecipient, $subject, $body, $mailErr)) {
            setFlash('success', sprintf('Test-E-Mail wurde erfolgreich über „%s“ an %s versendet.', $transportLabel, $testRecipient));
        } else {
            setFlash('error', sprintf('Fehler beim Senden der Test-E-Mail: %s', $mailErr ?: 'Unbekannter Versandfehler.'));
        }
        header('Location: admin.php#tab-mail');
        exit;
    }

    // -------------------------------------------------------------------------
    // 0c. SYSTEM-UPDATE ODER GEZIELTEN VERSIONSWECHSEL VON GITHUB DURCHFÜHREN
    // -------------------------------------------------------------------------
    if ($action === 'run_github_update') {
        $settings = getAppSettings($pdo);
        $repoInput = trim((string)($_POST['github_repo'] ?? ($settings['github_repo'] ?? 'uWiiiDev/MiniCloud')));
        if (
            !preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $repoInput)
            || strcasecmp($repoInput, 'uwebenzelrath/MiniCloud') === 0
            || strcasecmp($repoInput, 'uWiiiMiniCloud/MiniCloud') === 0
        ) {
            $repoInput = 'uWiiiDev/MiniCloud';
        }
        saveAppSetting($pdo, 'github_repo', $repoInput);

        $targetSha = trim((string)($_POST['target_sha'] ?? ''));
        $targetDate = trim((string)($_POST['target_date'] ?? ''));
        $targetMessage = trim((string)($_POST['target_message'] ?? ''));

        $result = performGitHubUpdate($pdo, $repoInput, $targetSha, $targetDate, $targetMessage);
        if ($result['ok']) {
            logAuditEvent($pdo, 'system_update', $result['message']);
        }
        setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        header('Location: admin.php#tab-system');
        exit;
    }

    // -------------------------------------------------------------------------
    // 0d. MANUELLEN LOKALEN SICHERUNGSPUNKT (SNAPSHOT) ERSTELLEN
    // -------------------------------------------------------------------------
    if ($action === 'create_local_snapshot') {
        $snapRes = createLocalAppSnapshot($pdo, 'Manueller Sicherungspunkt');
        setFlash($snapRes['ok'] ? 'success' : 'error', $snapRes['message']);
        header('Location: admin.php#tab-system');
        exit;
    }

    // -------------------------------------------------------------------------
    // 0e. LOKALEN SICHERUNGSPUNKT (SNAPSHOT) WIEDERHERSTELLEN (ROLLBACK)
    // -------------------------------------------------------------------------
    if ($action === 'restore_local_snapshot') {
        $snapFile = trim((string)($_POST['snapshot_filename'] ?? ''));
        $res = restoreLocalAppSnapshot($pdo, $snapFile);
        if ($res['ok']) {
            logAuditEvent($pdo, 'snapshot_restore', $res['message']);
        }
        setFlash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: admin.php#tab-system');
        exit;
    }

    // -------------------------------------------------------------------------
    // 0f. LOKALEN SICHERUNGSPUNKT LÖSCHEN
    // -------------------------------------------------------------------------
    if ($action === 'delete_local_snapshot') {
        $snapFile = basename(trim((string)($_POST['snapshot_filename'] ?? '')));
        if (preg_match('/^snapshot_[0-9]{8}_[0-9]{6}_[a-z0-9]+\.zip$/i', $snapFile)) {
            $path = getSnapshotBackupDir() . '/' . $snapFile;
            if (is_file($path) && @unlink($path)) {
                setFlash('success', 'Der lokale Sicherungspunkt wurde gelöscht.');
            } else {
                setFlash('error', 'Der Sicherungspunkt konnte nicht gelöscht werden.');
            }
        } else {
            setFlash('error', 'Ungültiger Sicherungspunkt.');
        }
        header('Location: admin.php#tab-system');
        exit;
    }
}

$currentSettings = getAppSettings($pdo);

// Daten für die Admin-Übersicht laden
$allUsers = $pdo->query(
    'SELECT u.id, u.username, u.email, u.two_factor_enabled, u.totp_secret, u.allow_user_sharing,
            u.is_admin, u.is_locked, u.lock_reason, u.admin_permissions, u.theme, u.storage_quota, u.created_at,
            COALESCE(SUM(f.file_size), 0) AS used_bytes, COUNT(f.id) AS file_count
     FROM users u
     LEFT JOIN files f ON f.user_id = u.id
     GROUP BY u.id
     ORDER BY u.id ASC'
)->fetchAll();

$allGroups = $pdo->query('SELECT * FROM `groups` ORDER BY name ASC')->fetchAll();

$groupMembersRaw = $pdo->query(
    'SELECT gm.id AS gm_id, gm.group_id, gm.user_id, u.username
     FROM group_members gm
     INNER JOIN users u ON u.id = gm.user_id
     ORDER BY u.username ASC'
)->fetchAll();
$membersByGroup = [];
foreach ($groupMembersRaw as $gm) {
    $membersByGroup[(int)$gm['group_id']][] = $gm;
}

$allGroupFolders = $pdo->query(
    'SELECT gf.*,
            (SELECT COUNT(*) FROM files f WHERE f.group_folder_id = gf.id) AS file_count,
            (SELECT COALESCE(SUM(f.file_size), 0) FROM files f WHERE f.group_folder_id = gf.id) AS folder_size
     FROM group_folders gf
     ORDER BY gf.name ASC'
)->fetchAll();

$permsRaw = $pdo->query(
    'SELECT gfp.*, u.username, g.name AS group_name
     FROM group_folder_permissions gfp
     LEFT JOIN users u ON u.id = gfp.user_id
     LEFT JOIN `groups` g ON g.id = gfp.group_id
     ORDER BY gfp.id ASC'
)->fetchAll();
$permsByFolder = [];
foreach ($permsRaw as $p) {
    $permsByFolder[(int)$p['group_folder_id']][] = $p;
}

$serverStats = getServerDashboardMetrics($pdo);
$auditLogs   = $pdo->query('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 120')->fetchAll();

$pageTitle = 'Administration';
require_once __DIR__ . '/includes/header.php';
?>

<?php
$localCommitInfo = getLocalInstalledCommitInfo($pdo);
$versionMeta     = getAppVersionInfo();
$configuredRepo  = trim((string)($currentSettings['github_repo'] ?? 'uWiiiDev/MiniCloud')) ?: 'uWiiiDev/MiniCloud';
$localSnapshots  = getLocalAppSnapshots();
?>

<div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <div class="flex items-center gap-3.5">
        <div class="w-11 h-11 rounded-2xl bg-brand-50 dark:bg-brand-950/70 border border-brand-200/70 dark:border-brand-800/70 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl shrink-0">
            🛡️
        </div>
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white">System-Administration</h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Benutzer &amp; Registrierung, Cloud-Module, Branding &amp; Impressum, E-Mail-Versand sowie Updates &amp; Server-Status.
            </p>
        </div>
    </div>
    <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
        &larr; Zurück zum Dashboard
    </a>
</div>

<?php if (!isFullAdmin()): ?>
    <div class="mb-4 p-3.5 rounded-2xl bg-indigo-50/90 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800/80 flex flex-wrap items-center justify-between gap-2 text-xs text-indigo-900 dark:text-indigo-200">
        <div class="flex items-center gap-2">
            <span class="text-base">🛡️</span>
            <span>
                <strong>Teil-Administrator-Zugang:</strong> Ihnen wurden vom Haupt-Administrator folgende Verwaltungsbereiche übertragen:
            </span>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <?php foreach ($availableAdminPerms as $pKey => $pMeta): ?>
                <?php if (hasAdminPermission($pKey)): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-bold bg-indigo-600 text-white text-[11px]">
                        <?= $pMeta['icon'] ?> <?= e($pMeta['label']) ?>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- THEMATISCHE BEREICHS-NAVIGATION (TABS) -->
<nav aria-label="Administrations-Bereiche" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-2 shadow-xs mb-6 flex flex-wrap items-center gap-1.5">
    <?php if (hasAdminPermission('users')): ?>
    <button
        type="button"
        data-admin-tab-btn="tab-users"
        onclick="switchAdminTab('tab-users')"
        class="admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>👥</span>
        <span>Benutzer &amp; Registrierung</span>
        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-200"><?= count($allUsers) ?></span>
    </button>
    <?php endif; ?>

    <?php if (hasAdminPermission('modules')): ?>
    <button
        type="button"
        data-admin-tab-btn="tab-modules"
        onclick="switchAdminTab('tab-modules')"
        class="admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>🧩</span>
        <span>Module &amp; Funktionen</span>
    </button>
    <?php endif; ?>

    <?php if (hasAdminPermission('branding')): ?>
    <button
        type="button"
        data-admin-tab-btn="tab-branding"
        onclick="switchAdminTab('tab-branding')"
        class="admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>🎨</span>
        <span>Darstellung &amp; Impressum</span>
    </button>
    <?php endif; ?>

    <?php if (hasAdminPermission('mail')): ?>
    <button
        type="button"
        data-admin-tab-btn="tab-mail"
        onclick="switchAdminTab('tab-mail')"
        class="admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>✉️</span>
        <span>E-Mail &amp; SMTP</span>
    </button>
    <?php endif; ?>

    <?php if (hasAdminPermission('system')): ?>
    <button
        type="button"
        data-admin-tab-btn="tab-system"
        onclick="switchAdminTab('tab-system')"
        class="admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer"
    >
        <span>🖥️</span>
        <span>System, Update &amp; Status</span>
    </button>
    <?php endif; ?>

    <button
        type="button"
        data-admin-tab-btn="all"
        onclick="switchAdminTab('all')"
        class="admin-tab-btn sm:ml-auto inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all cursor-pointer"
        title="Alle freigeschalteten Bereiche untereinander einblenden"
    >
        <span>📋</span>
        <span>Alle Bereiche</span>
    </button>
</nav>

<!-- ======================================================================= -->
<!-- TAB 1: BENUTZER & REGISTRIERUNG                                         -->
<!-- ======================================================================= -->
<?php if (hasAdminPermission('users')): ?>
<div id="tab-users" data-admin-tab-panel="tab-users" class="space-y-6 mb-10">
    <!-- 1a. Öffentliche Selbstregistrierung -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    👤
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Öffentliche Selbstregistrierung</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Legen Sie fest, ob Besucher sich selbst auf der Anmeldeseite ein Benutzerkonto erstellen dürfen (optional geschützt per Einladungs-Code).
                    </p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
            </span>
        </div>

        <form action="admin.php" method="post" class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-end">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_registration_settings">

            <div class="lg:col-span-5">
                <label class="flex items-start gap-2.5 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                    <input
                        type="checkbox"
                        name="allow_registration"
                        value="1"
                        <?= ($currentSettings['allow_registration'] ?? '0') === '1' ? 'checked' : '' ?>
                        class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    >
                    <span>
                        <strong>Selbstregistrierung auf der Anmeldeseite erlauben</strong><br>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400">Neue Konten sind aus Datenschutzgründen in der Teilen-Auswahl standardmäßig unsichtbar.</span>
                    </span>
                </label>
            </div>

            <div class="lg:col-span-3">
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Einladungs-Code (optional)
                </label>
                <input
                    type="text"
                    name="registration_code"
                    maxlength="100"
                    value="<?= e($currentSettings['registration_code'] ?? '') ?>"
                    placeholder="Leer = ohne Code"
                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="lg:col-span-2">
                <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                    Start-Quota (MB)
                </label>
                <input
                    type="number"
                    name="registration_default_quota_mb"
                    min="1"
                    max="102400"
                    value="<?= e($currentSettings['registration_default_quota_mb'] ?? '100') ?>"
                    class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
            </div>

            <div class="lg:col-span-2">
                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Speichern
                </button>
            </div>
        </form>
    </section>

    <!-- 1b. Benutzer anlegen & Benutzerliste -->
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Formular: Neuen Benutzer anlegen -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
            <h2 class="text-base font-bold text-slate-900 dark:text-white mb-1">Neuen Benutzer anlegen</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                Erstellen Sie manuell ein neues Benutzerkonto inkl. E-Mail-Adresse (für Passwort-Reset &amp; 2FA) und Speicher-Quota.
            </p>

            <form action="admin.php" method="post" class="space-y-3.5">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_user">

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Benutzername</label>
                    <input
                        type="text"
                        name="username"
                        required
                        minlength="3"
                        maxlength="32"
                        placeholder="z.B. anna_schmidt"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">E-Mail-Adresse (für Passwort-Reset &amp; 2FA)</label>
                    <input
                        type="email"
                        name="email"
                        maxlength="190"
                        placeholder="z.B. anna@beispiel.de"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Initial-Passwort (BCRYPT)</label>
                    <input
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        placeholder="Mindestens 8 Zeichen"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Speicherplatz (MB)</label>
                        <input
                            type="number"
                            name="quota_mb"
                            value="100"
                            min="1"
                            max="102400"
                            required
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Rolle</label>
                        <select
                            name="is_admin"
                            onchange="const box = document.getElementById('createUserPartialPermsBox'); if (box) box.classList.toggle('hidden', this.value !== 'partial');"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="0">Standardbenutzer</option>
                            <?php if (isFullAdmin()): ?>
                                <option value="partial">🛡️ Teil-Administrator (Bereiche wählen)</option>
                                <option value="1">Voll-Administrator (Alle Rechte)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <?php if (isFullAdmin()): ?>
                    <div id="createUserPartialPermsBox" class="hidden p-3 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200 dark:border-indigo-800 space-y-1.5">
                        <p class="text-xs font-bold text-indigo-900 dark:text-indigo-200 pb-1 border-b border-indigo-200/70 dark:border-indigo-800">
                            🛡️ Teil-Admin-Bereiche auswählen:
                        </p>
                        <?php foreach ($availableAdminPerms as $pKey => $pMeta): ?>
                            <label class="flex items-start gap-2 p-1 rounded-lg hover:bg-indigo-100/60 dark:hover:bg-indigo-900/40 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="admin_permissions[]"
                                    value="<?= e($pKey) ?>"
                                    class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <span class="leading-tight">
                                    <span class="font-semibold"><?= $pMeta['icon'] ?> <?= e($pMeta['label']) ?></span>
                                    <span class="block text-[10px] text-slate-500 dark:text-slate-400"><?= e($pMeta['desc']) ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <label class="flex items-center gap-2.5 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                    <input type="checkbox" name="two_factor_enabled" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>🔒 <strong>2FA per E-Mail aktivieren</strong> (Sendet 6-stelligen Code beim Login)</span>
                </label>

                <label class="flex items-center gap-2.5 p-2.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                    <input type="checkbox" name="allow_user_sharing" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>👁️ <strong>In Teilen-Auswahl sichtbar</strong> (Standardmäßig aus Datenschutzgründen deaktiviert)</span>
                </label>

                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Benutzer erstellen
                </button>
            </form>
        </div>

        <!-- Liste aller Benutzer -->
        <div class="lg:col-span-2 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-xs">
            <div class="p-4 sm:p-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-bold text-slate-900 dark:text-white">Registrierte Cloud-Benutzer</h2>
                    <span id="adminUserCountBadge" class="px-2 py-0.5 rounded-full text-xs font-bold bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300">
                        <?= count($allUsers) ?>
                    </span>
                </div>
                <div class="relative w-full sm:w-72">
                    <span class="absolute inset-y-0 left-3 flex items-center text-slate-400 pointer-events-none text-xs">🔍</span>
                    <input
                        type="search"
                        id="adminUserSearchInput"
                        placeholder="Benutzer, E-Mail, #ID oder Rolle suchen…"
                        autocomplete="off"
                        class="w-full pl-8 pr-7 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>
            </div>

            <div class="overflow-x-auto pb-52 -mb-52">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">
                            <th class="py-3 px-4">Benutzer &amp; E-Mail</th>
                            <th class="py-3 px-4">Belegt / Quota</th>
                            <th class="py-3 px-4">Anpassen (E-Mail, 2FA, Quota, Passwort)</th>
                            <th class="py-3 px-4 text-right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody id="adminUsersTableBody" class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php foreach ($allUsers as $u): ?>
                            <?php
                            $uId = (int)$u['id'];
                            $uQuotaMb = (int)round(((int)$u['storage_quota']) / (1024 * 1024));
                            $uEmail = trim((string)($u['email'] ?? ''));
                            $u2faMode = (int)($u['two_factor_enabled'] ?? 0);
                            $uHasTotp = trim((string)($u['totp_secret'] ?? '')) !== '';
                            $uShareVis = (int)($u['allow_user_sharing'] ?? 0) === 1;
                            $uLocked = (int)($u['is_locked'] ?? 0) === 1;
                            $uLockReason = trim((string)($u['lock_reason'] ?? ''));
                            $uAdminPerms = parseAdminPermissionsString((string)($u['admin_permissions'] ?? ''));
                            $uSearchText = mb_strtolower(
                                (string)$u['username'] . ' ' .
                                $uEmail . ' #' . $uId . ' ' .
                                (((int)$u['is_admin'] === 1) ? 'admin administrator' : (!empty($uAdminPerms) ? 'teil-admin teiladmin ' . implode(' ', $uAdminPerms) : 'standard')) . ' ' .
                                ($uLocked ? 'gesperrt sperre locked ' . $uLockReason : 'aktiv') . ' ' .
                                ($uShareVis ? 'teilen sichtbar' : 'verborgen') . ' ' .
                                ($u2faMode > 0 ? '2fa totp' : ''),
                                'UTF-8'
                            );
                            ?>
                            <tr data-admin-user-row="1" data-user-search="<?= e($uSearchText) ?>" class="<?= $uLocked ? 'bg-red-50/40 dark:bg-red-950/20' : 'hover:bg-slate-50/60 dark:hover:bg-slate-800/40' ?>">
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-slate-900 dark:text-white flex flex-wrap items-center gap-1.5">
                                        <span class="<?= $uLocked ? 'line-through text-red-700 dark:text-red-300' : '' ?>"><?= e((string)$u['username']) ?></span>
                                        <?php if ($uLocked): ?>
                                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-red-600 text-white" title="<?= e($uLockReason !== '' ? 'Gesperrt: ' . $uLockReason : 'Konto vom Administrator gesperrt') ?>">🔒 Gesperrt</span>
                                        <?php endif; ?>
                                        <?php if ((int)$u['is_admin'] === 1): ?>
                                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-brand-600 text-white" title="Voll-Administrator mit allen Rechten">Admin</span>
                                        <?php elseif (!empty($uAdminPerms)): ?>
                                            <?php
                                            $permNames = [];
                                            foreach ($uAdminPerms as $pk) {
                                                if (isset($availableAdminPerms[$pk])) {
                                                    $permNames[] = $availableAdminPerms[$pk]['label'];
                                                }
                                            }
                                            ?>
                                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-indigo-600 text-white" title="<?= e('Übertragene Admin-Rechte: ' . implode(', ', $permNames)) ?>">
                                                🛡️ Teil-Admin (<?= count($uAdminPerms) ?>)
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($u2faMode === 2 && $uHasTotp): ?>
                                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-600 text-white" title="2FA per Authenticator-App (Google Authenticator / TOTP) aktiviert">📱 TOTP</span>
                                        <?php elseif ($u2faMode === 1 && $uEmail !== ''): ?>
                                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-emerald-600 text-white" title="2FA per E-Mail aktiviert">🔒 2FA</span>
                                        <?php endif; ?>
                                        <?php if ($uShareVis): ?>
                                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-blue-600 text-white" title="Sichtbar in der Teilen-Auswahl anderer Benutzer">👁️ Teilen</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($uLocked && $uLockReason !== ''): ?>
                                        <div class="text-[11px] font-medium text-red-600 dark:text-red-400 truncate max-w-[220px]" title="<?= e($uLockReason) ?>">
                                            ⛔ Grund: <?= e($uLockReason) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-xs text-slate-500 dark:text-slate-400 truncate max-w-[200px]" title="<?= e($uEmail !== '' ? $uEmail : 'Keine E-Mail-Adresse hinterlegt') ?>">
                                        <?= $uEmail !== '' ? '✉️ ' . e($uEmail) : '<span class="italic text-slate-400">Keine E-Mail</span>' ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400">ID #<?= $uId ?> &bull; Theme: <?= e((string)$u['theme']) ?></div>
                                </td>
                                <td class="py-3 px-4 text-xs text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                    <div><strong><?= e(formatBytes((int)$u['used_bytes'])) ?></strong> / <?= e(formatBytes((int)$u['storage_quota'], 0)) ?></div>
                                    <div class="text-slate-400"><?= (int)$u['file_count'] ?> Dateien</div>
                                </td>
                                <td class="py-3 px-4">
                                    <form action="admin.php" method="post" class="flex flex-wrap items-center gap-2">
                                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="update_user">
                                        <input type="hidden" name="user_id" value="<?= $uId ?>">
                                        <input
                                            type="email"
                                            name="email"
                                            value="<?= e($uEmail) ?>"
                                            placeholder="E-Mail (Reset & 2FA)"
                                            title="E-Mail-Adresse des Benutzers"
                                            class="w-full sm:w-44 px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                                        >
                                        <input
                                            type="number"
                                            name="quota_mb"
                                            value="<?= $uQuotaMb ?>"
                                            min="1"
                                            title="Quota in MB"
                                            class="w-24 sm:w-20 px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                                        >
                                        <input
                                            type="password"
                                            name="new_password"
                                            placeholder="Neues Passwort (optional)"
                                            class="w-full sm:w-40 px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                                        >
                                        <select
                                            name="two_factor_enabled"
                                            title="2FA-Methode des Benutzers"
                                            class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs"
                                        >
                                            <option value="0" <?= $u2faMode === 0 ? 'selected' : '' ?>>2FA: Aus</option>
                                            <option value="1" <?= $u2faMode === 1 ? 'selected' : '' ?>>2FA: E-Mail</option>
                                            <?php if ($uHasTotp || $u2faMode === 2): ?>
                                                <option value="2" <?= $u2faMode === 2 ? 'selected' : '' ?>>2FA: App (TOTP)</option>
                                            <?php endif; ?>
                                        </select>
                                        <label class="inline-flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300 cursor-pointer" title="In Teilen-Auswahl für andere Benutzer sichtbar">
                                            <input type="checkbox" name="allow_user_sharing" value="1" <?= $uShareVis ? 'checked' : '' ?>>
                                            <span>Teilen</span>
                                        </label>
                                        <?php if (isFullAdmin()): ?>
                                            <label class="inline-flex items-center gap-1 text-xs text-slate-600 dark:text-slate-300 cursor-pointer" title="Voll-Administrator (alle Rechte)">
                                                <input
                                                    type="checkbox"
                                                    name="is_admin"
                                                    value="1"
                                                    <?= (int)$u['is_admin'] === 1 ? 'checked' : '' ?>
                                                    <?= $uId === $currentAdminId ? 'disabled' : '' ?>
                                                    onchange="if (this.checked) { this.form.querySelectorAll('.partial-perm-cb').forEach(cb => cb.checked = false); const badge = this.form.querySelector('.partial-perm-label'); if (badge) badge.textContent = '🛡️ Teil-Rechte'; }"
                                                >
                                                <span>Voll-Admin</span>
                                            </label>
                                            <?php if ($uId !== $currentAdminId): ?>
                                                <details class="relative inline-block">
                                                    <summary class="list-none inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-xs font-semibold cursor-pointer select-none <?= !empty($uAdminPerms) ? 'bg-indigo-600 border-indigo-600 text-white shadow-2xs' : 'bg-indigo-50/80 dark:bg-indigo-950/60 border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/60' ?>" title="Einzelne Admin-Bereiche an diesen Benutzer übertragen (Teil-Administration)">
                                                        <span class="partial-perm-label">🛡️ Teil-Rechte<?= !empty($uAdminPerms) ? ' (' . count($uAdminPerms) . ')' : '' ?></span>
                                                    </summary>
                                                    <div class="absolute right-0 sm:left-0 mt-1.5 w-68 p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-2xl z-40 space-y-1.5">
                                                        <p class="text-[11px] font-bold text-slate-800 dark:text-slate-200 pb-1 border-b border-slate-200 dark:border-slate-800">
                                                            🛡️ Teil-Administration übertragen:
                                                        </p>
                                                        <?php foreach ($availableAdminPerms as $pKey => $pMeta): ?>
                                                            <label class="flex items-start gap-2 p-1 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/70 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                                                                <input
                                                                    type="checkbox"
                                                                    name="admin_permissions[]"
                                                                    value="<?= e($pKey) ?>"
                                                                    <?= in_array($pKey, $uAdminPerms, true) ? 'checked' : '' ?>
                                                                    onchange="const form = this.form; const checkedCount = form.querySelectorAll('.partial-perm-cb:checked').length; if (checkedCount > 0) { const fullAdm = form.querySelector('input[name=is_admin]'); if (fullAdm && !fullAdm.disabled) fullAdm.checked = false; } const badge = form.querySelector('.partial-perm-label'); if (badge) badge.textContent = checkedCount > 0 ? ('🛡️ Teil-Rechte (' + checkedCount + ')') : '🛡️ Teil-Rechte';"
                                                                    class="partial-perm-cb mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                                >
                                                                <span class="leading-tight">
                                                                    <span class="font-semibold"><?= $pMeta['icon'] ?> <?= e($pMeta['label']) ?></span>
                                                                    <span class="block text-[10px] text-slate-400"><?= e($pMeta['desc']) ?></span>
                                                                </span>
                                                            </label>
                                                        <?php endforeach; ?>
                                                        <div class="pt-1.5 border-t border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
                                                            <span class="text-[10px] text-slate-400">Direkt übernehmen:</span>
                                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-bold cursor-pointer">
                                                                ✓ Teil-Rechte speichern
                                                            </button>
                                                        </div>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-200 dark:bg-slate-700 hover:bg-brand-600 hover:text-white text-xs font-medium transition-colors cursor-pointer">
                                            Speichern
                                        </button>
                                    </form>
                                </td>
                                <td class="py-3 px-4 text-right whitespace-nowrap">
                                    <?php if ($uId !== $currentAdminId && (isFullAdmin() || (int)$u['is_admin'] === 0)): ?>
                                        <div class="inline-flex items-center justify-end gap-1.5">
                                            <!-- Benutzer sperren / entsperren -->
                                            <form
                                                action="admin.php"
                                                method="post"
                                                class="inline"
                                                onsubmit="return handleLockUserSubmit(this, <?= $uLocked ? 'true' : 'false' ?>, <?= e((string)json_encode((string)$u['username'])) ?>);"
                                            >
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="toggle_lock_user">
                                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                <input type="hidden" name="lock_state" value="<?= $uLocked ? '0' : '1' ?>">
                                                <input type="hidden" name="lock_reason" value="">
                                                <?php if ($uLocked): ?>
                                                    <button
                                                        type="submit"
                                                        title="Benutzerkonto wieder entsperren"
                                                        class="px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/70 text-xs font-medium cursor-pointer"
                                                    >
                                                        🔓 Entsperren
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        type="submit"
                                                        title="Benutzerkonto sperren (sofortiger Logout & Login-Sperre)"
                                                        class="px-2.5 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/70 text-xs font-medium cursor-pointer"
                                                    >
                                                        🔒 Sperren
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Benutzer löschen -->
                                            <form action="admin.php" method="post" class="inline" onsubmit="return confirm('Benutzer &quot;<?= e(addslashes((string)$u['username'])) ?>&quot; samt Dateien wirklich löschen?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $uId ?>">
                                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-50 dark:bg-red-950/60 hover:bg-red-100 text-red-600 dark:text-red-300 text-xs font-medium cursor-pointer">
                                                    Löschen
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400"><?= $uId === $currentAdminId ? 'Aktuell' : 'Geschützt' ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr id="adminUserNoMatchRow" class="hidden">
                            <td colspan="4" class="py-6 px-4 text-center text-xs text-slate-500 dark:text-slate-400 italic">
                                Kein registrierter Benutzer entspricht Ihrer Suche.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 2: MODULE & FUNKTIONEN (inkl. Modul-Nutzungsstatistik)              -->
<!-- ======================================================================= -->
<?php if (hasAdminPermission('modules')): ?>
<?php
$moduleUsageStats = getModuleUsageStatistics($pdo, $currentSettings);
$modTotals        = $moduleUsageStats['totals'];
$modTop           = $moduleUsageStats['top_module'];
$modLatest        = $moduleUsageStats['latest_module'];
$modList          = $moduleUsageStats['modules'];
$enabledModCount  = 0;
foreach ($modList as $mItem) {
    if ($mItem['enabled']) {
        $enabledModCount++;
    }
}
?>
<div id="tab-modules" data-admin-tab-panel="tab-modules" class="hidden space-y-6 mb-10">
    <!-- 1. MODUL-NUTZUNGSSTATISTIK & BELIEBTHEIT -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 text-lg">
                    📊
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Modul-Nutzungsstatistik &amp; Beliebtheit</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        DSGVO-konforme, anonymisierte Auswertung, wie oft welche Cloud-Module aufgerufen werden – inklusive aktuellem Datenbestand je Modul.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!-- Zeitraum-Umschalter -->
                <div class="inline-flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200 dark:border-slate-700" role="group">
                    <button type="button" onclick="switchModuleStatRange('today')" data-mod-range-btn="today"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-colors cursor-pointer text-slate-600 dark:text-slate-300">
                        Heute (<?= (int)$modTotals['today'] ?>)
                    </button>
                    <button type="button" onclick="switchModuleStatRange('7d')" data-mod-range-btn="7d"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-colors cursor-pointer text-slate-600 dark:text-slate-300">
                        7 Tage (<?= (int)$modTotals['d7'] ?>)
                    </button>
                    <button type="button" onclick="switchModuleStatRange('30d')" data-mod-range-btn="30d"
                            class="px-2.5 py-1 rounded-lg text-xs font-semibold transition-colors cursor-pointer text-slate-600 dark:text-slate-300">
                        30 Tage (<?= (int)$modTotals['d30'] ?>)
                    </button>
                    <button type="button" onclick="switchModuleStatRange('total')" data-mod-range-btn="total"
                            class="px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer bg-brand-600 text-white shadow-xs">
                        Gesamt (<?= (int)$modTotals['total'] ?>)
                    </button>
                </div>

                <?php if ($modTotals['total'] > 0): ?>
                    <form action="admin.php" method="post" onsubmit="return confirm('Möchten Sie die gesammelte Modul-Nutzungsstatistik wirklich auf 0 zurücksetzen?');">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="reset_module_stats">
                        <button type="submit"
                                class="px-3 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/50 hover:bg-rose-100 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/70 text-xs font-semibold transition-colors cursor-pointer"
                                title="Alle Aufrufzähler auf 0 zurücksetzen">
                            🗑️ Zurücksetzen
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4 KPI-Kacheln -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 mb-5">
            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">🏆 Beliebtestes Modul</p>
                <?php if ($modTop !== null): ?>
                    <p class="text-sm font-bold text-slate-900 dark:text-white mt-1 truncate">
                        <?= e($modTop['icon'] . ' ' . $modTop['label']) ?>
                    </p>
                    <p class="text-[11px] text-brand-600 dark:text-brand-400 font-semibold mt-0.5">
                        <?= number_format((int)$modTop['hits'], 0, ',', '.') ?> Aufrufe gesamt
                    </p>
                <?php else: ?>
                    <p class="text-sm font-bold text-slate-500 dark:text-slate-400 mt-1">Noch keine Daten</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Zählung läuft ab sofort</p>
                <?php endif; ?>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">📈 Modul-Aufrufe</p>
                <p class="text-lg font-extrabold text-slate-900 dark:text-white mt-0.5" id="modStatKpiHits">
                    <?= number_format((int)$modTotals['total'], 0, ',', '.') ?>
                </p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400" id="modStatKpiSub">
                    Heute: <?= (int)$modTotals['today'] ?> · 7 Tage: <?= (int)$modTotals['d7'] ?> · 30 Tage: <?= (int)$modTotals['d30'] ?>
                </p>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">🕒 Zuletzt genutzt</p>
                <?php if ($modLatest !== null): ?>
                    <p class="text-sm font-bold text-slate-900 dark:text-white mt-1 truncate">
                        <?= e($modLatest['icon'] . ' ' . $modLatest['label']) ?>
                    </p>
                    <p class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold mt-0.5">
                        <?= e(formatDateTime((string)$modLatest['last_used_at'])) ?> Uhr
                    </p>
                <?php else: ?>
                    <p class="text-sm font-bold text-slate-500 dark:text-slate-400 mt-1">–</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">Noch kein Aufruf protokolliert</p>
                <?php endif; ?>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200/80 dark:border-slate-700/80">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">🧩 Aktive Module</p>
                <p class="text-lg font-extrabold text-slate-900 dark:text-white mt-0.5">
                    <?= $enabledModCount ?> / <?= count($modList) ?>
                </p>
                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                    1 Kernmodul + <?= max(0, $enabledModCount - 1) ?> Zusatzmodule aktiv
                </p>
            </div>
        </div>

        <!-- Balkendiagramm & Rangliste aller Module -->
        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border-b border-slate-200 dark:border-slate-700">
                        <th class="py-2.5 px-3 font-bold w-10">#</th>
                        <th class="py-2.5 px-3 font-bold">Modul</th>
                        <th class="py-2.5 px-3 font-bold w-1/3">Nutzungsanteil &amp; Verteilung</th>
                        <th class="py-2.5 px-3 font-bold text-right">Aufrufe</th>
                        <th class="py-2.5 px-3 font-bold">Gespeicherter Bestand</th>
                        <th class="py-2.5 px-3 font-bold text-right">Zuletzt verwendet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/70 dark:divide-slate-800" id="moduleStatsTableBody">
                    <?php $rankIdx = 1; ?>
                    <?php foreach ($modList as $mKey => $mStat): ?>
                        <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition-colors"
                            data-mod-stat-row="<?= e($mKey) ?>"
                            data-hits-today="<?= (int)$mStat['hits_today'] ?>"
                            data-hits-7d="<?= (int)$mStat['hits_7d'] ?>"
                            data-hits-30d="<?= (int)$mStat['hits_30d'] ?>"
                            data-hits-total="<?= (int)$mStat['hits_total'] ?>"
                            data-pct-today="<?= e((string)$mStat['pct_today']) ?>"
                            data-pct-7d="<?= e((string)$mStat['pct_7d']) ?>"
                            data-pct-30d="<?= e((string)$mStat['pct_30d']) ?>"
                            data-pct-total="<?= e((string)$mStat['pct_total']) ?>">
                            <td class="py-2.5 px-3 font-bold text-slate-400 mod-rank-cell"><?= $rankIdx++ ?>.</td>
                            <td class="py-2.5 px-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-base"><?= e($mStat['icon']) ?></span>
                                    <div>
                                        <a href="<?= e($mStat['url']) ?>" class="font-bold text-slate-900 dark:text-white hover:text-brand-600 dark:hover:text-brand-400">
                                            <?= e($mStat['label']) ?>
                                        </a>
                                        <span class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase <?= $mStat['enabled'] ? 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-700 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-400' ?>">
                                            <?= $mStat['key'] === 'files' ? 'Kern' : ($mStat['enabled'] ? 'Aktiv' : 'Aus') ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 px-3">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex-1 h-2.5 rounded-full bg-slate-200 dark:bg-slate-800 overflow-hidden">
                                        <div class="h-full <?= e($mStat['color']) ?> transition-all duration-300 mod-pct-bar"
                                             style="width: <?= max($mStat['hits_total'] > 0 ? 3 : 0, (float)$mStat['pct_total']) ?>%;"></div>
                                    </div>
                                    <span class="w-12 text-right font-semibold text-slate-700 dark:text-slate-300 mod-pct-label">
                                        <?= number_format((float)$mStat['pct_total'], 1, ',', '.') ?> %
                                    </span>
                                </div>
                            </td>
                            <td class="py-2.5 px-3 text-right font-bold text-slate-900 dark:text-white mod-hits-label">
                                <?= number_format((int)$mStat['hits_total'], 0, ',', '.') ?>
                            </td>
                            <td class="py-2.5 px-3 text-slate-600 dark:text-slate-300">
                                <span class="px-2 py-0.5 rounded-lg bg-slate-100 dark:bg-slate-800 font-medium text-[11px]">
                                    <?= e($mStat['inventory']) ?>
                                </span>
                            </td>
                            <td class="py-2.5 px-3 text-right text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                <?= $mStat['last_used_at'] !== null ? e(formatDateTime($mStat['last_used_at']) . ' Uhr') : '<span class="italic text-slate-400">Noch nie</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script>
    function switchModuleStatRange(rangeKey) {
        const buttons = document.querySelectorAll('[data-mod-range-btn]');
        buttons.forEach((btn) => {
            const isActive = btn.getAttribute('data-mod-range-btn') === rangeKey;
            btn.className = isActive
                ? 'px-2.5 py-1 rounded-lg text-xs font-bold transition-colors cursor-pointer bg-brand-600 text-white shadow-xs'
                : 'px-2.5 py-1 rounded-lg text-xs font-semibold transition-colors cursor-pointer text-slate-600 dark:text-slate-300';
        });

        const tbody = document.getElementById('moduleStatsTableBody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('[data-mod-stat-row]'));

        rows.sort((a, b) => {
            const ha = parseInt(a.getAttribute('data-hits-' + rangeKey) || '0', 10);
            const hb = parseInt(b.getAttribute('data-hits-' + rangeKey) || '0', 10);
            if (hb !== ha) return hb - ha;
            const ta = parseInt(a.getAttribute('data-hits-total') || '0', 10);
            const tb = parseInt(b.getAttribute('data-hits-total') || '0', 10);
            return tb - ta;
        });

        let sumHits = 0;
        rows.forEach((row, idx) => {
            const hits = parseInt(row.getAttribute('data-hits-' + rangeKey) || '0', 10);
            const pct = parseFloat(row.getAttribute('data-pct-' + rangeKey) || '0');
            sumHits += hits;

            const rankCell = row.querySelector('.mod-rank-cell');
            const bar = row.querySelector('.mod-pct-bar');
            const pctLbl = row.querySelector('.mod-pct-label');
            const hitsLbl = row.querySelector('.mod-hits-label');

            if (rankCell) rankCell.textContent = (idx + 1) + '.';
            if (bar) bar.style.width = (hits > 0 ? Math.max(3, pct) : 0) + '%';
            if (pctLbl) pctLbl.textContent = pct.toFixed(1).replace('.', ',') + ' %';
            if (hitsLbl) hitsLbl.textContent = hits.toLocaleString('de-DE');

            tbody.appendChild(row);
        });

        const kpiHits = document.getElementById('modStatKpiHits');
        if (kpiHits) {
            kpiHits.textContent = sumHits.toLocaleString('de-DE');
        }
    }
    </script>

    <!-- 2. CLOUD-MODULE AKTIVIEREN / DEAKTIVIEREN -->
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    🧩
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Cloud-Module &amp; Zusatzfunktionen (13 Module)</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Aktivieren oder deaktivieren Sie einzelne Funktionsmodule der Cloud global für alle Benutzer (inkl. Kopfzeilen-Menü und Cockpit).
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    onclick="document.querySelectorAll('#modulesAdminForm input[type=checkbox]').forEach(cb => cb.checked = true)"
                    class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold transition-colors cursor-pointer"
                >
                    ☑️ Alle aktivieren
                </button>
                <button
                    type="button"
                    onclick="document.querySelectorAll('#modulesAdminForm input[type=checkbox]').forEach(cb => cb.checked = false)"
                    class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-300 text-xs font-semibold transition-colors cursor-pointer"
                >
                    ☐ Alle abwählen
                </button>
            </div>
        </div>

        <form id="modulesAdminForm" action="admin.php" method="post" class="space-y-5">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_modules_settings">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Modul 1: Kalender -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📅 Kalender-Modul
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_calendar'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_calendar'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>

                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_calendar"
                                value="1"
                                <?= ($currentSettings['enable_calendar'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Integrierten Termin-Kalender aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Ermöglicht persönliche Termine, Kalender-Freigaben zwischen Benutzern (unter Beachtung der Datenschutz-Freigabe), Cloud-Datei-Anhänge am Termin sowie `.ics`-Export.
                                </span>
                            </span>
                        </label>
                    </div>

                    <?php if (($currentSettings['enable_calendar'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="calendar.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📅 Kalender öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 2: Chat & Rundnachrichten -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                💬 Chat- &amp; Rundnachrichten-Modul
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_chat'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_chat'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>

                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_chat"
                                value="1"
                                <?= ($currentSettings['enable_chat'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Integrierten Messenger / Chat aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Direktnachrichten zwischen Benutzern (nur bei aktivierter Teilen-Sichtbarkeit), Desktop-/Mobil-Benachrichtigungen sowie offizielle Admin-Rundnachrichten an alle Benutzer.
                                </span>
                            </span>
                        </label>
                    </div>

                    <?php if (($currentSettings['enable_chat'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="messages.php?channel=broadcast" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📢 Rundnachricht an alle Benutzer senden &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 3: Aufgaben & Kanban-Board -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📋 Aufgaben &amp; Kanban-Board
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_tasks'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_tasks'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_tasks"
                                value="1"
                                <?= ($currentSettings['enable_tasks'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Aufgaben- &amp; Kanban-Board aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    3-Spalten-Board (Offen, In Arbeit, Erledigt) mit Drag &amp; Drop, Prioritäten, Fälligkeiten, Checklisten, Cloud-Dateianhängen und Teilen im Team.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_tasks'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="tasks.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📋 Aufgaben-Board öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 4: Notizwand & Pinboard -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📌 Notizwand &amp; Pinboard
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_notes'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_notes'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_notes"
                                value="1"
                                <?= ($currentSettings['enable_notes'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Digitale Haftnotizen / Pinboard aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Farbige Haftnotizen mit Anpinnen, #Tags, Live-Suche, Teilen mit Benutzern/Gruppen und 1-Klick-Export als Markdown-Datei in die Cloud.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_notes'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="notes.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📌 Notizwand öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 5: Adressbuch & Kontakte -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📇 Adressbuch &amp; Kontakte
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_contacts'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_contacts'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_contacts"
                                value="1"
                                <?= ($currentSettings['enable_contacts'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Adressbuch &amp; Kontakte aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Persönliche und geteilte Kontakte inkl. vCard (<code>.vcf</code>) Import/Export und automatischer Anzeige von Geburtstagen im Kalender.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_contacts'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="contacts.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📇 Adressbuch öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 6: WebDAV-Schnittstelle -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📡 WebDAV-Netzlaufwerk
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_webdav'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_webdav'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_webdav"
                                value="1"
                                <?= ($currentSettings['enable_webdav'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>WebDAV-Server-Endpunkt (<code>webdav.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Erlaubt Benutzern das Einbinden ihrer Cloud als natives Netzlaufwerk unter Windows Explorer, macOS Finder, Linux und Smartphones.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_webdav'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="settings.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📡 Verbindungs-URL in Einstellungen anzeigen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 7: Persönliches Tages-Cockpit (Mein Tag) -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🏠 Tages-Cockpit („Mein Tag“)
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_dashboard'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_dashboard'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_dashboard"
                                value="1"
                                <?= ($currentSettings['enable_dashboard'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Tages-Cockpit (<code>dashboard.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Zentrale Übersichtsseite mit anstehenden Terminen, Geburtstagen, fälligen Aufgaben, aktiven Abstimmungen, angepinnten Notizen und neuesten Dateien.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_dashboard'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="dashboard.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🏠 Tages-Cockpit öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 8: Terminfinder & Umfragen -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📊 Terminfinder &amp; Umfragen
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_polls'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_polls'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_polls"
                                value="1"
                                <?= ($currentSettings['enable_polls'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Terminfinder &amp; Umfragen (<code>polls.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Doodle-Alternative für Termin- und Entscheidungs-Abstimmungen (intern für Gruppen sowie per öffentlichem Link für Gäste) inkl. 1-Klick-Kalenderübernahme.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_polls'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="polls.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📊 Umfragen öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 9: Passwort-Manager & Schlüsselbund -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🔐 Passwort-Manager &amp; Schlüsselbund
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_passwords'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_passwords'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_passwords"
                                value="1"
                                <?= ($currentSettings['enable_passwords'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Passwort-Manager (<code>passwords.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    AES-256-GCM verschlüsselter Passwort-Tresor für Webseiten-Logins, WLAN, Server, Lizenzen und PINs inkl. Passwort-Generator und Gruppen-Sharing.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_passwords'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="passwords.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🔐 Schlüsselbund öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 10: Live-Mentimeter & Interaktive Präsentationen -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🎤 Live-Mentimeter &amp; Publikums-Voting
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_menti'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_menti'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_menti"
                                value="1"
                                <?= ($currentSettings['enable_menti'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Live-Mentimeter (<code>menti.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Interaktive Präsentationen mit Wortwolken, Live-Balken, Skalen, Q&amp;A (Upvoting) und Live-Quiz per lokalem QR-Code &amp; 6-stelligem PIN.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_menti'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="menti.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🎤 Live-Mentimeter öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 11: PDF-Werkzeugkasten & Dokumenten-Scanner -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📄 PDF-Werkzeugkasten &amp; Scanner
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_pdf_tools'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_pdf_tools'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_pdf_tools"
                                value="1"
                                <?= ($currentSettings['enable_pdf_tools'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>PDF &amp; Dokumenten-Scanner (<code>pdf_tools.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Kamera-/Foto-zu-PDF-Scanner mit S/W-Dokumentenfilter sowie PDFs zusammenfügen (Merge), Seiten extrahieren (Split) und komprimieren.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_pdf_tools'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="pdf_tools.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📄 PDF &amp; Scan öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 12: Projektmanagement -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🚀 Projektmanagement
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_projects'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_projects'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_projects"
                                value="1"
                                <?= ($currentSettings['enable_projects'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Projektmanagement (<code>projects.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Projekt-Zentrale mit Meilensteinen, visuellem Gantt-Zeitstrahl, Live-Zeiterfassung, Budgetkontrolle, verknüpften Kanban-Boards &amp; Projektstatus-Bericht.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_projects'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="projects.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🚀 Projekte öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 13: Office Light -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📝 Office Light (Word, Excel &amp; Markdown)
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_office'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_office'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_office"
                                value="1"
                                <?= ($currentSettings['enable_office'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Office Light Zentrale &amp; Editor (<code>office.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Eigene Office-Startzentrale mit Dokumenten-Vorlagen, Übersicht aller Word- (<code>.docx</code>), Excel- (<code>.xlsx</code>/<code>.csv</code>), Markdown- und Textdateien sowie Live-Team-Co-Editing im Browser.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_office'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="office.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📝 Office Light öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 14: Lokaler KI- & Wissens-Assistent (Stufe 1 + optionales lokales Sprachmodell Stufe 2) -->
                <?php $ollamaStatus = probeLocalOllamaStatus((string)($currentSettings['assistant_ollama_model'] ?? 'gemma2:2b')); ?>
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🤖 Lokaler KI- &amp; Wissens-Assistent
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_assistant'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_assistant'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_assistant"
                                value="1"
                                <?= ($currentSettings['enable_assistant'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Cloud-Assistent &amp; Schnell-Chat (<code>assistant.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    <strong>Stufe 1 (immer aktiv, auch auf STRATO):</strong> Kontext-Gedächtnis, Volltext-Handbuchsuche, natürliche Termin-/Aufgaben-/Notiz-Befehle &amp; Live-Datenbank-Abfragen für alle Module (Speicher, Termine, Aufgaben, Notizen, Kontakte/Geburtstage, Haushaltsbuch, Verein, Hörbücher &amp; E-Books).
                                </span>
                            </span>
                        </label>

                        <!-- Stufe 2: Optionales lokales KI-Sprachmodell (Ollama) -->
                        <div class="mt-2 p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-slate-700/80 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <label class="flex items-center gap-2 text-[11px] font-bold text-slate-800 dark:text-slate-200 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        name="assistant_use_ollama"
                                        value="1"
                                        <?= ($currentSettings['assistant_use_ollama'] ?? '1') === '1' ? 'checked' : '' ?>
                                        class="rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                    >
                                    <span>🧠 Stufe 2: Lokales Sprachmodell (Ollama) nutzen, falls verfügbar</span>
                                </label>
                                <?php if ($ollamaStatus['available']): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        🟢 Ollama aktiv (<?= e($ollamaStatus['active_model']) ?>)
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400" title="Auf Shared-Webhosting (z. B. STRATO) wird automatisch und verzögerungsfrei Stufe 1 verwendet.">
                                        ⚪ Nur Stufe 1 (kein lokales Ollama)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 shrink-0">Modell (`127.0.0.1:11434`):</span>
                                <input
                                    type="text"
                                    name="assistant_ollama_model"
                                    list="ollamaModelsDatalist"
                                    value="<?= e((string)($currentSettings['assistant_ollama_model'] ?? 'gemma2:2b')) ?>"
                                    placeholder="gemma2:2b"
                                    class="w-full px-2 py-1 rounded-md bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[11px] font-mono text-slate-800 dark:text-slate-200"
                                >
                                <?php if (!empty($ollamaStatus['models'])): ?>
                                    <datalist id="ollamaModelsDatalist">
                                        <?php foreach ($ollamaStatus['models'] as $oMdl): ?>
                                            <option value="<?= e($oMdl) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if (($currentSettings['enable_assistant'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="assistant.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🤖 Cloud-Assistent öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 15: E-Book-Reader & Bibliothek (.epub) -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📚 E-Book-Reader &amp; Bibliothek (<code>.epub</code>)
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_ebooks'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_ebooks'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_ebooks"
                                value="1"
                                <?= ($currentSettings['enable_ebooks'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>E-Book-Reader &amp; Bücherregal (<code>ebooks.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Digitales Bücherregal für alle <code>.epub</code>-Dateien mit automatischer Cover- &amp; Metadaten-Extraktion, Kapitel-Inhaltsverzeichnis, Volltextsuche, Lesezeichen, Sepia-/Nachtmodus und geräteübergreifend synchronisiertem Lesefortschritt.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_ebooks'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="ebooks.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📚 E-Book-Bibliothek öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 16: Browserspiele & Pausenraum -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🎮 Browserspiele &amp; Pausenraum
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_games'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_games'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_games"
                                value="1"
                                <?= ($currentSettings['enable_games'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Spiele-Zentrale &amp; Cloud-Highscores (<code>games.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    20 werbefreie Browserspiele inkl. Vollbildmodus (<em>Gummigeruch Kart-Cup, Cloud-Traffic-Racer, Cloud-Darts Meister-Arena, Teich-Hüpfer, Geheimagent Zero, Cloud-Wuseler, Schatten-Shinobi, Dach-Sprinter, Galaxis-Abwehr, Fass-Kletterer, Bomben-Flieger, Pillen-Schnapper, Helden von MiniCloud RPG, Sudoku, Minesweeper, Memory, Block-Puzzle, Snake, Brick Breaker</em> sowie <em>Vier Gewinnt &amp; Tic-Tac-Toe</em>) inkl. Highscore-Ranglisten.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_games'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="games.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🎮 Spiele-Zentrale öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 17: Mind-Map-Editor -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🧠 Mind-Map-Editor &amp; Ideensammler
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_mindmap'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_mindmap'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_mindmap"
                                value="1"
                                <?= ($currentSettings['enable_mindmap'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Mind-Map-Editor (<code>mindmap.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Interaktive Zeichenfläche für visuelle Mind-Maps mit Tastatur-Steuerung (<code>Tab</code>/<code>Enter</code>), Auto-Layout, Team-Freigabe, PNG-/SVG-/JSON-Export in die Cloud und 1-Klick-Aufgabenerstellung.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_mindmap'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="mindmap.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🧠 Mind-Map-Editor öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 18: Haushaltsplaner & Finanz-Übersicht -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                💰 Haushaltsplaner &amp; Finanz-Übersicht
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_budget'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_budget'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_budget"
                                value="1"
                                <?= ($currentSettings['enable_budget'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Haushaltsplaner &amp; Finanzbuch (<code>budget.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Persönliche und geteilte Haushaltsbücher mit Einnahmen &amp; Ausgaben, Fixkosten-/Abo-Verwaltung, Kategorie-Budgets, Sparzielen, WG-/Familien-Kostenausgleich, Beleg-Verknüpfung &amp; CSV-Export.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_budget'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="budget.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                💰 Haushaltsplaner öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 18b: Kostenaufteilung & Reisekasse -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🧾 Kostenaufteilung &amp; Reisekasse
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_split'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_split'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_split"
                                value="1"
                                <?= ($currentSettings['enable_split'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Kostenaufteilung &amp; Reisekasse (<code>split.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Gruppen-Kostenabrechnung für Reisen, Ausflüge &amp; Projekte mit registrierten Cloud-Nutzern und beliebig vielen Freitext-Gästen, flexibler Profiteur-Auswahl (gleichmäßig, nach Anteilen oder exakt) und automatischer Berechnung „Wer schuldet wem wie viel?“.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_split'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="split.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🧾 Kostenaufteilung &amp; Reisekasse öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 19: Vereinsverwaltung & Vorstands-Portal -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🏛️ Vereinsverwaltung &amp; Vorstands-Portal
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_club'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_club'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_club"
                                value="1"
                                <?= ($currentSettings['enable_club'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Vereinsverwaltung &amp; Vorstands-Portal (<code>club.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Mitgliederdatei, Beitragstarife, 1-Klick-Jahresbeitragsgenerator, Kassenwart-Sync in <code>budget.php</code>, Ehrenamts-Arbeitsstunden, Inventar-/Schlüssel-Verleih, Cloud-Protokolle und feingranulare Aufgabenverteilung auf verschiedene Vorstandsmitglieder (<em>1. Vorsitzender, Schriftführer, Kassenwart, Gerätewart, Kassenprüfer</em>).
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_club'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="club.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🏛️ Vereinsverwaltung öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 20: Foto-Alben & Medien-Galerie -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🖼️ Foto-Alben &amp; Medien-Galerie
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_gallery'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_gallery'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_gallery"
                                value="1"
                                <?= ($currentSettings['enable_gallery'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Foto-Alben &amp; Medien-Galerie (<code>gallery.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Chronologische Fotowand aller Cloud-Bilder, virtuelle Foto-Alben ohne doppelten Speicherverbrauch, Vollbild-Diashow mit Tastatursteuerung, lokaler EXIF-Metadaten-Inspektor und öffentliche Gäste-Sammelalben per QR-Code.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_gallery'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="gallery.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🖼️ Foto-Galerie öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 21: Online-Formulare & Veranstaltungs-Anmeldung -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                📝 Online-Formulare &amp; Anmeldung
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_forms'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_forms'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_forms"
                                value="1"
                                <?= ($currentSettings['enable_forms'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Online-Formulare &amp; Anmeldung (<code>forms.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    DSGVO-konformer Formular-Baukasten mit 4 Vorlagen (<em>Event-Anmeldung, Helfer-/Schichtliste, Online-Mitgliedsantrag, Feedback-Bogen</em>), Kapazitätslimit, Gäste-QR-Code, CSV-Cloud-Ablage und 1-Klick-Übernahme in <code>club.php</code>.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_forms'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="forms.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                📝 Online-Formulare öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 22: Musik-, Podcast- & Hörbuch-Studio -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🎵 Musik- &amp; Hörbuch-Studio
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_music'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_music'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_music"
                                value="1"
                                <?= ($currentSettings['enable_music'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Musik-, Podcast- &amp; Hörbuch-Studio (<code>music.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    MP3/Audio-Mediathek mit lokaler ID3-Tag- &amp; Cover-Erkennung, HTTP-Range-Streaming, Frequenz-Visualizer, 4 Sound-Presets (Equalizer), Hörbuch-Lesezeichen, Sleep-Timer, Smartphone-Sperrbildschirm-Steuerung &amp; teilbaren Playlists.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_music'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="music.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🎵 Musik- &amp; Hörbuch-Studio öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 23: Taschenrechner & Rechen-Studio -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🧮 Taschenrechner &amp; Rechen-Studio
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_calculator'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_calculator'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_calculator"
                                value="1"
                                <?= ($currentSettings['enable_calculator'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Taschenrechner &amp; Rechen-Studio (<code>calculator.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Standard- &amp; Wissenschaftsrechner (inkl. Tastatur-/Numpad-Steuerung), interaktiver Rechenstreifen mit Bon-Summe (&Sigma;) &amp; Cloud-Export (<code>.txt</code>/<code>.csv</code> oder Haftnotiz), MwSt.-/Prozent-/Rabatt-Rechner, Einheiten-Umrechner und Zins-/Kredit-Rechner.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_calculator'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="calculator.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🧮 Taschenrechner öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 24: Alltags-Werkzeuge & Toolbox -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🧰 Alltags-Werkzeuge &amp; Toolbox
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_toolbox'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_toolbox'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_toolbox"
                                value="1"
                                <?= ($currentSettings['enable_toolbox'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Alltags-Werkzeuge &amp; Toolbox (<code>toolbox.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    6-in-1 Schweizer Taschenmesser: Gäste-WLAN-Karten &amp; GiroCode-Überweisungs-QR-Generator, interaktives Glücksrad &amp; Team-/Turnier-Auslosung, Stoppuhr &amp; Weltzeituhr, Text-Diff-Checker, Farb-/WCAG-Kontrast-Studio und Passphrasen-/UUID-Werkbank.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_toolbox'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="toolbox.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🧰 Toolbox öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 25: Video-Studio & Schnittplatz -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🎬 Video-Studio &amp; Schnittplatz
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_video_studio'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_video_studio'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_video_studio"
                                value="1"
                                <?= ($currentSettings['enable_video_studio'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Video-Studio &amp; Schnittplatz (<code>video_studio.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Browser-Videoschnittplatz (100 % STRATO-tauglich): Filmstreifen-Timeline mit Trimmen (In/Out), 90°/180°-Drehung, 16:9-/9:16-Formatwechsel mit Kino-Unschärferand, Text-Bauchbinden, 8 Farbfilter, Hintergrundmusik-Mischer, Standbild-Export &amp; Webcam-/Screencast-Aufnahme.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_video_studio'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="video_studio.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🎬 Video-Studio öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 26: Surftagebuch & Quiver-Log -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🏄‍♂️ Surftagebuch &amp; Quiver-Log
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_surflog'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_surflog'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_surflog"
                                value="1"
                                <?= ($currentSettings['enable_surflog'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Surftagebuch &amp; Quiver-Log (<code>surflog.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Wassersport-Logbuch für Windsurfen, Kiten, Wingfoilen, Wellenreiten &amp; SUP mit Knoten↔Beaufort-Rechner, 16er-Windrose, Quiver-Materialverwaltung (inkl. Kosten/Session), GPX-Speed-Heatmap, Cloud-Foto-/Video-Verknüpfung &amp; CSV-Export.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_surflog'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="surflog.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🏄‍♂️ Surftagebuch öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 27: Heimkino & Film-Mediathek -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🍿 Heimkino &amp; Film-Mediathek
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_cinema'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_cinema'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_cinema"
                                value="1"
                                <?= ($currentSettings['enable_cinema'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Heimkino &amp; Film-Mediathek (<code>cinema.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Privates Streaming-Kino für alle Cloud-Videos mit Echtzeit-Ambilight, „Saallicht dimmen“, „Weiterschauen“-Automatik, Kino-Sound-Booster, Untertiteln (<code>.srt</code>/<code>.vtt</code>), Szenen-Sprungmarken &amp; Gäste-Kino-Ticket.
                                </span>
                            </span>
                        </label>
                    </div>
                    <?php if (($currentSettings['enable_cinema'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="cinema.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🍿 Heimkino öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 27b: Übersetzungs-Studio & Dokument-Übersetzer -->
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🌐 Übersetzungs-Studio &amp; Dokument-Übersetzer
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= ($currentSettings['enable_translator'] ?? '1') === '1' ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= ($currentSettings['enable_translator'] ?? '1') === '1' ? 'Aktiviert' : 'Deaktiviert' ?>
                            </span>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_translator"
                                value="1"
                                <?= ($currentSettings['enable_translator'] ?? '1') === '1' ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Neuronales Übersetzungs-Studio (<code>translate.php</code>) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Satz- und kontextbasierte Übersetzung (18 Sprachen) für Freitexte und Cloud-Dokumente (PDF inkl. OCR, Word <code>.docx</code>, Text/Markdown) mit 1-Klick-Export in die Cloud. Läuft sofort ohne API-Key; optional mit eigenem DeepL-Key.
                                </span>
                            </span>
                        </label>

                        <div class="mt-2 p-2.5 rounded-lg bg-white/80 dark:bg-slate-900/70 border border-slate-200/80 dark:border-slate-700/80 space-y-2">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-200 mb-0.5">
                                        🔑 Optionaler DeepL API-Key (Free <code>:fx</code> oder Pro):
                                    </label>
                                    <input
                                        type="password"
                                        name="translator_deepl_api_key"
                                        value="<?= e((string)($currentSettings['translator_deepl_api_key'] ?? '')) ?>"
                                        autocomplete="off"
                                        placeholder="Leer lassen für kostenlose Standard-NMT-Engine"
                                        class="w-full px-2.5 py-1 rounded-md bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[11px] font-mono text-slate-800 dark:text-slate-200"
                                    >
                                </div>
                                <div>
                                    <label class="block text-[11px] font-bold text-slate-700 dark:text-slate-200 mb-0.5">
                                        🎯 Standard-Zielsprache:
                                    </label>
                                    <?php $curTransTgt = (string)($currentSettings['translator_default_target'] ?? 'en'); ?>
                                    <select
                                        name="translator_default_target"
                                        class="w-full px-2 py-1 rounded-md bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[11px] font-semibold text-slate-800 dark:text-slate-200"
                                    >
                                        <?php foreach (['en' => 'Englisch (EN)', 'de' => 'Deutsch (DE)', 'fr' => 'Französisch (FR)', 'es' => 'Spanisch (ES)', 'it' => 'Italienisch (IT)', 'nl' => 'Niederländisch (NL)', 'pl' => 'Polnisch (PL)', 'tr' => 'Türkisch (TR)', 'uk' => 'Ukrainisch (UK)'] as $tCode => $tName): ?>
                                            <option value="<?= e($tCode) ?>" <?= $curTransTgt === $tCode ? 'selected' : '' ?>><?= e($tName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Intranet / Offline-Übersetzung (Ollama + optional LibreTranslate) -->
                            <div class="pt-2 border-t border-slate-200/70 dark:border-slate-700/70 space-y-1.5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-[11px] font-bold text-slate-700 dark:text-slate-200">
                                        🔒 Intranet- &amp; Offline-Fähigkeit (100 % lokal ohne Internet):
                                    </span>
                                    <?php if ($ollamaStatus['available']): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300" title="<?= e(implode(', ', $ollamaStatus['models'] ?? [])) ?>">
                                            🟢 Lokales Ollama bereit (<?= !empty($ollamaStatus['models']) ? e(implode(', ', $ollamaStatus['models'])) : e($ollamaStatus['active_model']) ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400">
                                            ⚪ Kein lokales Ollama erkannt
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-medium text-slate-500 dark:text-slate-400 mb-0.5">
                                        Optionale lokale LibreTranslate-URL im Intranet (z. B. <code>http://127.0.0.1:5000</code>):
                                    </label>
                                    <input
                                        type="text"
                                        name="translator_libretranslate_url"
                                        value="<?= e((string)($currentSettings['translator_libretranslate_url'] ?? '')) ?>"
                                        placeholder="http://127.0.0.1:5000 (optional – sonst nutzt der Intranet-Modus automatisch das lokale Ollama-Modell)"
                                        class="w-full px-2.5 py-1 rounded-md bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-[11px] font-mono text-slate-800 dark:text-slate-200"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php if (($currentSettings['enable_translator'] ?? '1') === '1'): ?>
                        <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                            <a href="translate.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                                🌐 Übersetzungs-Studio öffnen &rarr;
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modul 28: Automatische Papierkorb-Bereinigung (Aufbewahrungsfrist) -->
                <?php $curTrashDays = isset($currentSettings['trash_retention_days']) ? (int)$currentSettings['trash_retention_days'] : 30; ?>
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3">
                    <div class="space-y-2.5">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white">
                                🗑️ Automatische Papierkorb-Bereinigung
                            </p>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $curTrashDays > 0 ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                <?= $curTrashDays > 0 ? $curTrashDays . ' Tage' : 'Nie (Manuell)' ?>
                            </span>
                        </div>
                        <div class="space-y-1.5">
                            <label for="trash_retention_days" class="block text-xs font-semibold text-slate-700 dark:text-slate-200">
                                Aufbewahrungsfrist für gelöschte Dateien (<code>deleted_at</code>):
                            </label>
                            <select
                                id="trash_retention_days"
                                name="trash_retention_days"
                                class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 text-xs font-medium text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500 cursor-pointer"
                            >
                                <?php foreach ([7 => '7 Tage', 14 => '14 Tage', 30 => '30 Tage (Standard)', 60 => '60 Tage', 90 => '90 Tage', 180 => '180 Tage', 365 => '365 Tage (1 Jahr)', 0 => 'Nie automatisch löschen (nur manuell)'] as $tVal => $tLabel): ?>
                                    <option value="<?= $tVal ?>" <?= $curTrashDays === $tVal ? 'selected' : '' ?>><?= e($tLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                Dateien im Papierkorb inkl. Dateiversionen und Vorschaubilder (<code>.thumbs</code>) werden nach Ablauf dieser Frist automatisch endgültig vom Server gelöscht.
                            </p>
                        </div>
                    </div>
                    <div class="pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                        <a href="trash.php" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline">
                            🗑️ Papierkorb öffnen &rarr;
                        </a>
                    </div>
                </div>

                <!-- Modul 29: Dokumenten-Volltextsuche (MariaDB FULLTEXT Index – Zero-RAM Alternative zu Elasticsearch) -->
                <?php
                $ftEnabled = ($currentSettings['enable_fulltext_search'] ?? '1') === '1';
                $ftStats   = getFulltextSearchStats($pdo);
                ?>
                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between space-y-3 sm:col-span-2">
                    <div class="space-y-2.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>🔎 Dokumenten-Volltextsuche (Inhaltssuche in PDF, Word, Excel, PowerPoint, Visio &amp; Text)</span>
                            </p>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-indigo-100 dark:bg-indigo-950 text-indigo-800 dark:text-indigo-300">
                                    📊 <?= (int)$ftStats['indexed_files'] ?> / <?= (int)$ftStats['eligible_files'] ?> Dokumente indexiert (<?= number_format((int)$ftStats['total_chars'], 0, ',', '.') ?> Zeichen)
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $ftStats['pdftotext_available'] ? 'bg-teal-100 dark:bg-teal-950 text-teal-800 dark:text-teal-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300' ?>">
                                    <?= $ftStats['pdftotext_available'] ? '⚡ PDF-Turbo (pdftotext aktiv)' : '📄 PDF Pure-PHP Modus' ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= !empty($ftStats['ocr_available']) ? 'bg-violet-100 dark:bg-violet-950 text-violet-800 dark:text-violet-300' : (!empty($ftStats['local_wasm_ocr']) ? 'bg-blue-100 dark:bg-blue-950 text-blue-800 dark:text-blue-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300') ?>">
                                    <?= !empty($ftStats['ocr_available']) ? '👁️ Scan-OCR aktiv (Tesseract)' : (!empty($ftStats['local_wasm_ocr']) ? '🌐 Browser-WASM-OCR bereit (STRATO)' : 'Kein OCR') ?>
                                </span>
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase <?= $ftEnabled ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300' : 'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' ?>">
                                    <?= $ftEnabled ? 'Aktiviert' : 'Deaktiviert' ?>
                                </span>
                            </div>
                        </div>
                        <label class="flex items-start gap-2.5 text-xs text-slate-700 dark:text-slate-200 cursor-pointer">
                            <input
                                type="checkbox"
                                name="enable_fulltext_search"
                                value="1"
                                <?= $ftEnabled ? 'checked' : '' ?>
                                class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                            >
                            <span>
                                <strong>Dokumenten-Volltextsuche (<code>file_search_index</code> via MariaDB/MySQL FULLTEXT + automatisches Scan-OCR) aktivieren</strong><br>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Durchsucht bei der globalen Schnellsuche (<code>Strg + K</code>) sowie im Dateimanager nicht nur Dateinamen, sondern in unter 5 Millisekunden auch den <strong>Textinhalt</strong> von PDF- (inkl. eingescannten Bild-PDFs per automatischer OCR-Texterkennung), Word- (<code>.docx</code>), Excel- (<code>.xlsx</code>/<code>.csv</code>), PowerPoint- (<code>.pptx</code>), Visio-/Draw.io- (<code>.drawio</code>/<code>.vsdx</code>) und Text-/Markdown-Dateien – inklusive Treffer-Textausschnitt (E-Books sind ausgeschlossen). <strong>Zero-Knowledge-Garantie:</strong> Tresor-verschlüsselte Dateien werden niemals indexiert.
                                </span>
                            </span>
                        </label>
                    </div>
                    <div class="pt-2.5 border-t border-slate-200/80 dark:border-slate-700/80 flex flex-wrap items-center justify-between gap-2">
                        <span id="browserOcrStatusText" class="text-[11px] text-slate-500 dark:text-slate-400">
                            Neue Uploads und Office-Light-Speicherungen werden automatisch im Hintergrund indexiert (inkl. OCR bei Scan-PDFs).
                        </span>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="submit"
                                form="formReindexFulltext"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-950/80 hover:bg-indigo-100 dark:hover:bg-indigo-900 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-semibold transition-colors cursor-pointer"
                                title="Indexiert ausschließlich noch fehlende Dokumente (inkl. Scan-PDFs per OCR), ohne bereits indexierte Dateien erneut zu verarbeiten"
                            >
                                🔄 Fehlende Dokumente nachindexieren<?= count($ftStats['unindexed_pdfs'] ?? []) > 0 ? ' (' . count($ftStats['unindexed_pdfs']) . ' Scan-PDFs)' : '' ?>
                            </button>
                            <?php if (empty($ftStats['ocr_available']) && !empty($ftStats['local_wasm_ocr']) && !empty($ftStats['unindexed_pdfs'])): ?>
                                <button
                                    type="button"
                                    id="btnBrowserWasmOcr"
                                    onclick="runBrowserWasmOcrForUnindexedPdfs()"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                                    title="Für Shared-Webhosting ohne Server-Tesseract (z. B. STRATO): Führt die OCR-Texterkennung für fehlende Scan-PDFs direkt im Browser über WebAssembly aus"
                                >
                                    🌐 Scan-PDFs im Browser per OCR indexieren (<?= count($ftStats['unindexed_pdfs']) ?>)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                >
                    Modul-Einstellungen speichern
                </button>
            </div>
        </form>
        <form id="formReindexFulltext" action="admin.php" method="post" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="reindex_fulltext_search">
            <input type="hidden" name="only_missing" value="1">
        </form>
        <?php if (!empty($ftStats['unindexed_pdfs'])): ?>
            <script>
            window.mcUnindexedScanPdfs = <?= json_encode($ftStats['unindexed_pdfs'], JSON_UNESCAPED_UNICODE) ?>;
            async function runBrowserWasmOcrForUnindexedPdfs() {
                const list = window.mcUnindexedScanPdfs || [];
                if (!list.length) return;
                const btn = document.getElementById('btnBrowserWasmOcr');
                const statusEl = document.getElementById('browserOcrStatusText');
                if (btn) btn.disabled = true;

                try {
                    if (!window.Tesseract) {
                        if (statusEl) statusEl.textContent = '⏳ Lade lokale WebAssembly-OCR-Engine (assets/ocr/tesseract.min.js)…';
                        await new Promise((resolve, reject) => {
                            const s = document.createElement('script');
                            s.src = 'assets/ocr/tesseract.min.js';
                            s.onload = resolve;
                            s.onerror = reject;
                            document.head.appendChild(s);
                        });
                    }
                    const pdfjsLib = await import('./assets/pdfjs/pdf.min.mjs');
                    pdfjsLib.GlobalWorkerOptions.workerSrc = './assets/pdfjs/pdf.worker.min.mjs';

                    const ocrBaseUrl = new URL('assets/ocr', window.location.href).href;
                    const worker = await window.Tesseract.createWorker('deu', 1, {
                        workerPath: ocrBaseUrl + '/worker.min.js',
                        corePath: ocrBaseUrl,
                        langPath: ocrBaseUrl,
                        workerBlobURL: false
                    });

                    let doneCount = 0;
                    for (let i = 0; i < list.length; i++) {
                        const item = list[i];
                        if (statusEl) {
                            statusEl.textContent = `🔍 Browser-OCR (${i + 1}/${list.length}): „${item.name}“…`;
                        }
                        try {
                            const pdfDoc = await pdfjsLib.getDocument('download.php?id=' + item.id + '&inline=1').promise;
                            const maxP = Math.min(pdfDoc.numPages, 6);
                            const textParts = [];
                            for (let p = 1; p <= maxP; p++) {
                                const page = await pdfDoc.getPage(p);
                                const vp = page.getViewport({ scale: 1.75 });
                                const canvas = document.createElement('canvas');
                                canvas.width = vp.width;
                                canvas.height = vp.height;
                                await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                                const res = await worker.recognize(canvas);
                                if (res && res.data && res.data.text) {
                                    textParts.push(res.data.text.trim());
                                }
                            }
                            const fullText = textParts.join('\n').trim();
                            if (fullText.length > 0) {
                                const fd = new FormData();
                                fd.append('csrf_token', <?= json_encode(getCsrfToken()) ?>);
                                fd.append('action', 'save_browser_ocr_index');
                                fd.append('file_id', String(item.id));
                                fd.append('ocr_text', fullText);
                                const saveRes = await fetch('admin.php', { method: 'POST', body: fd });
                                const saveJson = await saveRes.json();
                                if (saveJson && saveJson.ok) doneCount++;
                            }
                        } catch (e) {}
                    }
                    await worker.terminate();
                    if (statusEl) {
                        statusEl.textContent = `✅ Browser-OCR abgeschlossen: ${doneCount} Scan-PDF(s) wurden erfolgreich im Volltext-Index gespeichert!`;
                    }
                    setTimeout(() => window.location.reload(), 1200);
                } catch (err) {
                    if (statusEl) statusEl.textContent = '⚠️ Browser-OCR Fehler: ' + (err.message || err);
                    if (btn) btn.disabled = false;
                }
            }
            </script>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 3: DARSTELLUNG, LOGO & IMPRESSUM (BRANDING)                         -->
<!-- ======================================================================= -->
<?php if (hasAdminPermission('branding')): ?>
<div id="tab-branding" data-admin-tab-panel="tab-branding" class="hidden space-y-6 mb-10">
    <section id="branding" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    🎨
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Anwendungstitel, Logo &amp; Impressum</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Passen Sie den Namen der Cloud, das Kopfzeilen-Logo und das öffentlich abrufbare Impressum (<code class="font-mono">impressum.php</code>) an.
                    </p>
                </div>
            </div>
            <a
                href="impressum.php"
                target="_blank"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold transition-colors"
            >
                <span>Impressum-Seite öffnen &nearr;</span>
            </a>
        </div>

        <form action="admin.php" method="post" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="update_branding_only">

            <!-- Linke Spalte: Titel, Untertitel & Logo -->
            <div class="lg:col-span-5 space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Titel / Name der Anwendung
                    </label>
                    <input
                        type="text"
                        name="app_title"
                        required
                        maxlength="80"
                        value="<?= e($currentSettings['app_title']) ?>"
                        placeholder="z.B. MiniCloud oder Familien-Cloud"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Zusatztitel / Badge in der Kopfzeile (optional)
                    </label>
                    <input
                        type="text"
                        name="app_subtitle"
                        maxlength="80"
                        value="<?= e($currentSettings['app_subtitle']) ?>"
                        placeholder="z.B. Private Cloud (leer lassen zum Ausblenden)"
                        class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    >
                </div>

                <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3">
                    <div class="flex items-center gap-3">
                        <?php if ($currentSettings['logo_data_uri'] !== ''): ?>
                            <img
                                src="<?= e($currentSettings['logo_data_uri']) ?>"
                                alt="Aktuelles Logo"
                                class="w-12 h-12 rounded-xl object-contain bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 p-1 shrink-0"
                            >
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-brand-600 to-indigo-700 flex items-center justify-center text-white shrink-0">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">Eigenes Logo hochladen</p>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400">PNG, JPG, WEBP, GIF oder SVG (max. 2 MB)</p>
                        </div>
                    </div>

                    <input
                        type="file"
                        name="logo_file"
                        accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
                        class="block w-full text-xs text-slate-600 dark:text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-brand-50 dark:file:bg-brand-950 file:text-brand-700 dark:file:text-brand-300 hover:file:bg-brand-100 cursor-pointer"
                    >

                    <?php if ($currentSettings['logo_data_uri'] !== ''): ?>
                        <label class="inline-flex items-center gap-2 text-xs text-red-600 dark:text-red-400 cursor-pointer">
                            <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300">
                            <span>Eigenes Logo entfernen (Standard-Wolken-Icon verwenden)</span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rechte Spalte: Impressum -->
            <div class="lg:col-span-7 flex flex-col justify-between space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Impressum-Text (wird öffentlich unter <code class="text-brand-600 dark:text-brand-400">impressum.php</code> angezeigt)
                    </label>
                    <textarea
                        name="imprint_text"
                        rows="9"
                        placeholder="Angaben gemäß § 5 DDG:&#10;Max Mustermann&#10;Musterstraße 1&#10;12345 Musterstadt&#10;&#10;Kontakt:&#10;E-Mail: info@beispiel.de"
                        class="w-full px-3.5 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                    ><?= e($currentSettings['imprint_text']) ?></textarea>
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                    >
                        Branding &amp; Impressum speichern
                    </button>
                </div>
            </div>
        </form>
    </section>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 4: E-MAIL & SMTP (Passwort-Reset & 2FA)                             -->
<!-- ======================================================================= -->
<?php if (hasAdminPermission('mail')): ?>
<div id="tab-mail" data-admin-tab-panel="tab-mail" class="hidden space-y-6 mb-10">
    <section class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    ✉️
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">E-Mail- &amp; SMTP-Konfiguration</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Wird für das Zurücksetzen vergessener Passwörter (<code class="font-mono">forgot_password.php</code>) sowie für 6-stellige 2FA-Sicherheitscodes beim Login verwendet.
                    </p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300">
                Aktiv: <?= $currentSettings['mail_transport'] === 'smtp' ? 'Externer SMTP-Server' : 'PHP mail() / Postfix' ?>
            </span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Linke Spalte: Versandart & SMTP-Einstellungen -->
            <form action="admin.php" method="post" class="lg:col-span-7 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                <input type="hidden" name="action" value="update_mail_settings">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Versandart
                        </label>
                        <select
                            name="mail_transport"
                            id="mailTransportSelect"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                            <option value="mail" <?= $currentSettings['mail_transport'] === 'mail' ? 'selected' : '' ?>>
                                Standard PHP mail() / Lokaler Server (Postfix)
                            </option>
                            <option value="smtp" <?= $currentSettings['mail_transport'] === 'smtp' ? 'selected' : '' ?>>
                                Externer SMTP-Server (z.B. STRATO / IONOS)
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Absender-E-Mail-Adresse (From)
                        </label>
                        <input
                            type="email"
                            name="mail_from"
                            maxlength="190"
                            value="<?= e($currentSettings['mail_from']) ?>"
                            placeholder="Standard: noreply@<?= e(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'runready.cloud')) ?: 'runready.cloud') ?>"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                    </div>
                </div>

                <!-- Optionale SMTP-Zugangsdaten -->
                <div
                    id="smtpSettingsBox"
                    class="<?= $currentSettings['mail_transport'] === 'smtp' ? '' : 'hidden' ?> p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3.5"
                >
                    <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">
                        🔐 SMTP-Verbindungsdaten
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-[11px] font-medium text-slate-700 dark:text-slate-300 mb-1">
                                SMTP-Server (Host)
                            </label>
                            <input
                                type="text"
                                name="smtp_host"
                                maxlength="190"
                                value="<?= e($currentSettings['smtp_host']) ?>"
                                placeholder="z.B. smtp.strato.de"
                                class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                            >
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Verschlüsselung
                            </label>
                            <select
                                name="smtp_encryption"
                                id="smtpEncryptionSelect"
                                class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                            >
                                <option value="ssl" <?= $currentSettings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL / TLS (465)</option>
                                <option value="tls" <?= $currentSettings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                                <option value="none" <?= $currentSettings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>Keine (25)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-700 dark:text-slate-300 mb-1">
                                Port
                            </label>
                            <input
                                type="number"
                                name="smtp_port"
                                id="smtpPortInput"
                                min="1"
                                max="65535"
                                value="<?= e($currentSettings['smtp_port']) ?>"
                                class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                            >
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-medium text-slate-700 dark:text-slate-300 mb-1">
                                SMTP-Benutzername
                            </label>
                            <input
                                type="text"
                                name="smtp_user"
                                maxlength="190"
                                autocomplete="off"
                                value="<?= e($currentSettings['smtp_user']) ?>"
                                placeholder="z.B. noreply@ihredomain.de"
                                class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                            >
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-700 dark:text-slate-300 mb-1">
                                SMTP-Passwort <?= $currentSettings['smtp_pass'] !== '' ? '<span class="text-emerald-600 dark:text-emerald-400">(hinterlegt)</span>' : '' ?>
                            </label>
                            <input
                                type="password"
                                name="smtp_pass"
                                autocomplete="new-password"
                                placeholder="<?= $currentSettings['smtp_pass'] !== '' ? '•••••••• (leer lassen zum Behalten)' : 'Passwort des Postfachs' ?>"
                                class="w-full px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-900 dark:text-white"
                            >
                        </div>
                    </div>

                    <?php if ($currentSettings['smtp_pass'] !== ''): ?>
                        <label class="inline-flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400 cursor-pointer">
                            <input type="checkbox" name="clear_smtp_pass" value="1" class="rounded border-slate-300">
                            <span>Gespeichertes SMTP-Passwort löschen</span>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="flex justify-end pt-1">
                    <button
                        type="submit"
                        class="px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-sm shadow-xs transition-colors cursor-pointer"
                    >
                        E-Mail-Einstellungen speichern
                    </button>
                </div>
            </form>

            <!-- Rechte Spalte: Live-Funktions-Test (Test-E-Mail senden) -->
            <div class="lg:col-span-5 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-3.5">
                <div>
                    <p class="text-sm font-bold text-slate-900 dark:text-white">
                        🧪 E-Mail-Versand live testen
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        Sendet eine Test-E-Mail über die aktuell gespeicherte Versandart (<strong><?= $currentSettings['mail_transport'] === 'smtp' ? 'Externer SMTP-Server' : 'Standard PHP mail() / Postfix' ?></strong>).
                    </p>
                </div>

                <form action="admin.php" method="post" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="send_test_email">
                    <div>
                        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">
                            Empfänger-E-Mail für Test
                        </label>
                        <input
                            type="email"
                            name="test_email"
                            required
                            placeholder="z.B. ihre-adresse@beispiel.de"
                            class="w-full px-3 py-2 rounded-xl bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs sm:text-sm text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-brand-500"
                        >
                    </div>
                    <button
                        type="submit"
                        class="w-full py-2.5 px-4 rounded-xl bg-slate-200/80 dark:bg-slate-700 hover:bg-brand-600 hover:text-white text-slate-800 dark:text-slate-100 text-xs sm:text-sm font-semibold transition-colors cursor-pointer"
                    >
                        ✉️ Test-E-Mail jetzt senden
                    </button>
                </form>

                <p class="text-[11px] text-slate-400 pt-2 border-t border-slate-200/80 dark:border-slate-700/80">
                    💡 <strong>Hinweis für STRATO / IONOS:</strong> Die Absender-Adresse (<code class="font-mono">From</code>) muss als echtes E-Mail-Postfach Ihrer Domain angelegt sein.
                </p>
            </div>
        </div>
    </section>
</div>
<?php endif; ?>

<!-- ======================================================================= -->
<!-- TAB 5: SYSTEM, UPDATE & SERVER-STATUS                                   -->
<!-- ======================================================================= -->
<?php if (hasAdminPermission('system')): ?>
<div id="tab-system" data-admin-tab-panel="tab-system" class="hidden space-y-6 mb-10">
    <!-- 5a. SYSTEM-UPDATE, VERSIONSWAHL & ROLLBACK ÜBER GITHUB / LOKALE SNAPSHOTS -->
    <section id="system-update" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400 text-lg">
                    🔄
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">System-Update, Versionsauswahl &amp; Rollback</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Installieren Sie neue Updates per 1-Klick, wechseln Sie zu jedem beliebigen früheren GitHub-Stand oder stellen Sie lokale Sicherungspunkte wieder her.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <form action="admin.php" method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="create_local_snapshot">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 dark:hover:bg-amber-900/60 text-amber-800 dark:text-amber-300 border border-amber-200/80 dark:border-amber-800/70 text-xs font-semibold transition-colors cursor-pointer"
                        title="Aktuellen Programmstand jetzt lokal als Sicherungspunkt speichern"
                    >
                        <span>📸</span>
                        <span>Sicherungspunkt anlegen</span>
                    </button>
                </form>

                <button
                    type="button"
                    id="btnCheckGithubUpdate"
                    onclick="checkGithubUpdateNow()"
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-semibold transition-colors cursor-pointer"
                >
                    <span>🔍</span>
                    <span>Auf Updates prüfen</span>
                </button>
            </div>
        </div>

        <!-- Verstecktes Formular für die gezielte Installation eines beliebigen GitHub-Commits (Rollback / Zeitreise) -->
        <form id="installSpecificCommitForm" action="admin.php" method="post" class="hidden">
            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
            <input type="hidden" name="action" value="run_github_update">
            <input type="hidden" name="github_repo" id="specificCommitRepo" value="<?= e($configuredRepo) ?>">
            <input type="hidden" name="target_sha" id="specificCommitSha" value="">
            <input type="hidden" name="target_date" id="specificCommitDate" value="">
            <input type="hidden" name="target_message" id="specificCommitMsg" value="">
        </form>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
            <!-- Linke Spalte: Installierter Stand, Update-Aktion & Lokale Sicherungspunkte -->
            <div class="lg:col-span-5 space-y-4">
                <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 space-y-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Installierte Version:</span>
                        <span class="px-2.5 py-0.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-xs font-mono font-bold text-slate-800 dark:text-slate-200">
                            <?= e($versionMeta['version']) ?>
                        </span>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Installierter Commit:</span>
                        <span id="updInstalledCommit" class="text-xs font-mono text-slate-700 dark:text-slate-300">
                            <?= $localCommitInfo['short'] !== '' ? '#' . e($localCommitInfo['short']) : 'Build #' . e($versionMeta['hash']) ?>
                            <?= $localCommitInfo['date'] !== '' ? ' (' . e($localCommitInfo['date']) . ')' : '' ?>
                        </span>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">Update-Status:</span>
                        <span id="updStatusBadge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-200/80 dark:bg-slate-700 text-slate-700 dark:text-slate-300">
                            Bereit zur Prüfung
                        </span>
                    </div>

                    <form
                        action="admin.php"
                        method="post"
                        class="pt-2 border-t border-slate-200 dark:border-slate-700 space-y-3"
                        onsubmit="return confirm('Möchten Sie das System jetzt auf den neuesten GitHub-Stand aktualisieren? Vor dem Update wird automatisch ein lokaler Sicherungspunkt angelegt, sodass Sie jederzeit zurückwechseln können.');"
                    >
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="run_github_update">

                        <div>
                            <label class="block text-[11px] font-medium text-slate-600 dark:text-slate-400 mb-1">
                                GitHub-Repository (Branch: <code class="font-mono">main</code>)
                            </label>
                            <input
                                type="text"
                                name="github_repo"
                                id="mainGithubRepoInput"
                                value="<?= e($configuredRepo) ?>"
                                placeholder="uWiiiDev/MiniCloud"
                                class="w-full px-3 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs font-mono text-slate-900 dark:text-white"
                            >
                        </div>

                        <button
                            type="submit"
                            id="btnRunGithubUpdate"
                            class="w-full py-2.5 px-4 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                        >
                            <span>⬇️</span>
                            <span>Neuestes Update von GitHub installieren</span>
                        </button>
                    </form>
                </div>

                <!-- Lokale Sicherungspunkte (1-Klick-Rollback ohne GitHub) -->
                <div class="p-4 rounded-xl bg-amber-50/50 dark:bg-amber-950/20 border border-amber-200/80 dark:border-amber-800/60 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h3 class="text-xs font-bold uppercase tracking-wider text-amber-900 dark:text-amber-300 flex items-center gap-1.5">
                                <span>🛡️</span>
                                <span>Lokale Sicherungspunkte (Rollback)</span>
                            </h3>
                            <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                                Vor jedem Update wird der bisherige Programmstand automatisch hier gesichert.
                            </p>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 dark:bg-amber-900/70 text-amber-800 dark:text-amber-200 shrink-0">
                            <?= count($localSnapshots) ?> / 8
                        </span>
                    </div>

                    <?php if (empty($localSnapshots)): ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400 italic py-2">
                            Noch kein lokaler Sicherungspunkt vorhanden. Beim nächsten Update (oder per Klick auf „📸 Sicherungspunkt anlegen“) wird automatisch ein Backup erstellt.
                        </p>
                    <?php else: ?>
                        <?php $latestSnap = $localSnapshots[0]; ?>
                        <!-- Prominenter 1-Klick-Button für das jüngste Backup -->
                        <form
                            action="admin.php"
                            method="post"
                            onsubmit="return confirm('Möchten Sie das letzte Update rückgängig machen und den lokalen Sicherungspunkt #<?= e((string)$latestSnap['short']) ?> vom <?= e((string)$latestSnap['created_at']) ?> wiederherstellen?');"
                        >
                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                            <input type="hidden" name="action" value="restore_local_snapshot">
                            <input type="hidden" name="snapshot_filename" value="<?= e((string)$latestSnap['filename']) ?>">
                            <button
                                type="submit"
                                class="w-full py-2 px-3 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-semibold text-xs shadow-2xs transition-colors cursor-pointer flex items-center justify-center gap-1.5"
                            >
                                <span>⏪</span>
                                <span>Letztes Update rückgängig machen (Stand #<?= e((string)$latestSnap['short']) ?>)</span>
                            </button>
                        </form>

                        <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1 pt-1">
                            <?php foreach ($localSnapshots as $snap): ?>
                                <div class="p-2 rounded-lg bg-white dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700/80 flex items-center justify-between gap-2 text-xs">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5">
                                            <span class="px-1.5 py-0.2 rounded bg-slate-100 dark:bg-slate-700 font-mono font-bold text-[10px] text-slate-700 dark:text-slate-200">
                                                #<?= e((string)$snap['short']) ?>
                                            </span>
                                            <span class="font-semibold text-slate-800 dark:text-slate-200 truncate">
                                                <?= e((string)$snap['reason']) ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] text-slate-400 mt-0.5">
                                            <?= e((string)$snap['created_at']) ?> &bull; <?= e((string)$snap['size_fmt']) ?>
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <form
                                            action="admin.php"
                                            method="post"
                                            class="inline"
                                            onsubmit="return confirm('Diesen Sicherungspunkt (#<?= e((string)$snap['short']) ?> vom <?= e((string)$snap['created_at']) ?>) jetzt wiederherstellen?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="restore_local_snapshot">
                                            <input type="hidden" name="snapshot_filename" value="<?= e((string)$snap['filename']) ?>">
                                            <button
                                                type="submit"
                                                class="px-2 py-1 rounded-lg bg-amber-50 dark:bg-amber-950/70 hover:bg-amber-600 hover:text-white text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-[11px] font-semibold transition-colors cursor-pointer"
                                                title="Auf diesen Sicherungspunkt zurücksetzen"
                                            >
                                                ⏪ Wiederherstellen
                                            </button>
                                        </form>
                                        <form
                                            action="admin.php"
                                            method="post"
                                            class="inline"
                                            onsubmit="return confirm('Diesen lokalen Sicherungspunkt löschen?');"
                                        >
                                            <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_local_snapshot">
                                            <input type="hidden" name="snapshot_filename" value="<?= e((string)$snap['filename']) ?>">
                                            <button
                                                type="submit"
                                                class="p-1 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 transition-colors cursor-pointer"
                                                title="Sicherungspunkt löschen"
                                            >
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 1-Klick Datenbank-Backup (.sql Export) -->
                <div class="pt-3 border-t border-slate-200 dark:border-slate-700/80 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            💾 1-Klick Datenbank-Backup (<code class="font-mono">.sql</code>)
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed">
                        Exportiert sämtliche 15 MiniCloud-Datenbanktabellen (Benutzer, Dateiverzeichnis, Ordner, Freigaben, Kontakte, Kalender, Chat, Audit-Log, Einstellungen) als vollständige SQL-Sicherungsdatei direkt auf Ihr Gerät &ndash; ganz ohne phpMyAdmin.
                    </p>
                    <form action="admin.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="download_db_backup">
                        <button
                            type="submit"
                            class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs sm:text-sm shadow-xs transition-colors cursor-pointer flex items-center justify-center gap-2"
                        >
                            <span>💾 Vollständiges Datenbank-Backup (.sql) herunterladen</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Rechte Box: Versionsverlauf auf GitHub (mit direkter 1-Klick-Installation jedes beliebigen Stands) -->
            <div class="lg:col-span-7 p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Versionsverlauf auf GitHub (<code class="font-mono">main</code>) &ndash; Zeitreise &amp; Rollback
                        </h3>
                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                            Sie können zu jedem beliebigen früheren oder neueren Stand aus der Liste wechseln.
                        </p>
                    </div>
                    <a
                        href="https://github.com/<?= e($configuredRepo) ?>/commits/main"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline shrink-0"
                    >
                        Auf GitHub öffnen &nearr;
                    </a>
                </div>

                <div id="githubCommitsList" class="space-y-2 text-xs text-slate-600 dark:text-slate-300 max-h-[460px] overflow-y-auto pr-1">
                    <p class="text-slate-400 italic py-4 text-center">
                        Klicken Sie oben rechts auf „🔍 Auf Updates prüfen“, um die Versionshistorie von GitHub abzurufen.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- 5b. SERVER-STATUS & SYSTEM-DASHBOARD (Kompakt & Live-Aktualisierend) -->
    <section id="server-status" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 mb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                    </svg>
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Server-Status &amp; Systemressourcen</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        Host: <strong class="text-slate-700 dark:text-slate-200"><?= e($serverStats['hostname']) ?></strong>
                        &bull; Uptime: <strong id="srvUptime" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['uptime']) ?></strong>
                        &bull; PHP <?= e($serverStats['php_version']) ?>
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 font-medium">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>Live (<span id="srvTimestamp"><?= e($serverStats['timestamp']) ?></span>)</span>
                </span>
                <button
                    type="button"
                    onclick="refreshServerDashboard()"
                    class="px-2.5 py-1 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-semibold transition-colors cursor-pointer"
                    title="Jetzt aktualisieren"
                >
                    ↻
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Karte 1: Festplatten-Speicherplatz -->
            <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                        <span>💾 Speicherplatz (Server)</span>
                        <span id="srvDiskPctLabel" class="font-bold text-slate-900 dark:text-white"><?= e(number_format((float)$serverStats['disk_pct'], 1, ',', '.')) ?>%</span>
                    </div>
                    <p id="srvDiskMain" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                        <?= e($serverStats['disk_used_fmt']) ?> <span class="text-xs font-normal text-slate-500 dark:text-slate-400">von <?= e($serverStats['disk_total_fmt']) ?></span>
                    </p>
                    <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden my-2.5">
                        <div
                            id="srvDiskBar"
                            class="h-full <?= $serverStats['disk_pct'] >= 90 ? 'bg-red-600' : ($serverStats['disk_pct'] >= 75 ? 'bg-amber-500' : 'bg-brand-600') ?> rounded-full transition-all duration-300"
                            style="width: <?= e((string)$serverStats['disk_pct']) ?>%"
                        ></div>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between">
                    <span>Frei: <strong id="srvDiskFree" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['disk_free_fmt']) ?></strong></span>
                    <span>Cloud: <strong id="srvCloudUsed" class="text-brand-600 dark:text-brand-400"><?= e($serverStats['cloud_bytes_fmt']) ?></strong> (<?= (int)$serverStats['cloud_files'] ?>)</span>
                </p>
            </div>

            <!-- Karte 2: Arbeitsspeicher (RAM) -->
            <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                        <span>🧠 Arbeitsspeicher (RAM)</span>
                        <span id="srvRamPctLabel" class="font-bold text-slate-900 dark:text-white"><?= e(number_format((float)$serverStats['ram_pct'], 1, ',', '.')) ?>%</span>
                    </div>
                    <p id="srvRamMain" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                        <?= e($serverStats['ram_used_fmt']) ?> <span class="text-xs font-normal text-slate-500 dark:text-slate-400">von <?= e($serverStats['ram_total_fmt']) ?></span>
                    </p>
                    <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden my-2.5">
                        <div
                            id="srvRamBar"
                            class="h-full <?= $serverStats['ram_pct'] >= 90 ? 'bg-red-600' : ($serverStats['ram_pct'] >= 75 ? 'bg-amber-500' : 'bg-indigo-600') ?> rounded-full transition-all duration-300"
                            style="width: <?= e((string)$serverStats['ram_pct']) ?>%"
                        ></div>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between">
                    <span>Verfügbar: <strong id="srvRamFree" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['ram_free_fmt']) ?></strong></span>
                    <span>DB: <?= e( strtok($serverStats['db_version'], '-') ?: 'MariaDB' ) ?></span>
                </p>
            </div>

            <!-- Karte 3: CPU & Systemlast -->
            <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                        <span>⚡ CPU-Auslastung (<?= (int)$serverStats['cpu_cores'] ?> <?= (int)$serverStats['cpu_cores'] === 1 ? 'Kern' : 'Kerne' ?>)</span>
                        <span id="srvCpuPctLabel" class="font-bold text-slate-900 dark:text-white"><?= e(number_format((float)$serverStats['cpu_pct'], 1, ',', '.')) ?>%</span>
                    </div>
                    <p id="srvCpuLoad" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                        Load: <?= e($serverStats['load_1']) ?> <span class="text-xs font-normal text-slate-500 dark:text-slate-400">/ <?= e($serverStats['load_5']) ?> / <?= e($serverStats['load_15']) ?></span>
                    </p>
                    <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden my-2.5">
                        <div
                            id="srvCpuBar"
                            class="h-full <?= $serverStats['cpu_pct'] >= 85 ? 'bg-red-600' : ($serverStats['cpu_pct'] >= 65 ? 'bg-amber-500' : 'bg-emerald-600') ?> rounded-full transition-all duration-300"
                            style="width: <?= e((string)$serverStats['cpu_pct']) ?>%"
                        ></div>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 truncate" title="<?= e($serverStats['cpu_model']) ?>">
                    <?= e($serverStats['cpu_model']) ?>
                </p>
            </div>

            <!-- Karte 4: Netzwerkauslastung -->
            <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                        <span>🌐 Netzwerk (<?= e($serverStats['net_iface']) ?>)</span>
                        <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-semibold">Aktiv</span>
                    </div>
                    <div class="grid grid-cols-2 gap-2 my-1.5">
                        <div class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200/70 dark:border-slate-700">
                            <span class="block text-[10px] text-slate-400 uppercase">Empfangen (↓)</span>
                            <span id="srvNetRxRate" class="text-xs sm:text-sm font-bold text-emerald-600 dark:text-emerald-400"><?= e($serverStats['net_rx_rate']) ?></span>
                        </div>
                        <div class="px-2.5 py-1.5 rounded-lg bg-white dark:bg-slate-800 border border-slate-200/70 dark:border-slate-700">
                            <span class="block text-[10px] text-slate-400 uppercase">Gesendet (↑)</span>
                            <span id="srvNetTxRate" class="text-xs sm:text-sm font-bold text-brand-600 dark:text-brand-400"><?= e($serverStats['net_tx_rate']) ?></span>
                        </div>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between pt-1">
                    <span>Gesamt ↓ <strong id="srvNetRxTotal" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['net_rx_total']) ?></strong></span>
                    <span>Gesamt ↑ <strong id="srvNetTxTotal" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['net_tx_total']) ?></strong></span>
                </p>
            </div>
        </div>

        <!-- 5b-2. MARIADB / MYSQL DATENBANK-MONITORING (Live-Kennzahlen & Tabellen-Analyse) -->
        <div class="mt-5 pt-5 border-t border-slate-200 dark:border-slate-800">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3.5">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 text-sm">
                        🗄️
                    </span>
                    <div>
                        <h3 class="text-xs sm:text-sm font-bold text-slate-900 dark:text-white flex flex-wrap items-center gap-2">
                            <span>MariaDB Datenbank-Monitor</span>
                            <span class="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 border border-slate-200/80 dark:border-slate-700 font-mono text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                                <?= e($serverStats['db_version']) ?>
                            </span>
                        </h3>
                        <p class="text-[11px] text-slate-500 dark:text-slate-400">
                            DB-Uptime: <strong id="srvDbUptime" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['db_uptime']) ?></strong>
                            &bull; DB-Traffic: ↓ <strong id="srvDbBytesRx" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['db_bytes_rx_fmt']) ?></strong>
                            / ↑ <strong id="srvDbBytesTx" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['db_bytes_tx_fmt']) ?></strong>
                        </p>
                    </div>
                </div>

                <form
                    action="admin.php"
                    method="post"
                    class="inline"
                    onsubmit="return confirm('Möchten Sie jetzt alle MariaDB-Tabellen (inkl. Volltext-Suchindex) optimieren und defragmentieren (OPTIMIZE TABLE)?');"
                >
                    <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="optimize_db_tables">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-600 hover:text-white text-indigo-700 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800/70 text-xs font-semibold transition-colors cursor-pointer"
                        title="Führt OPTIMIZE TABLE auf allen MiniCloud-Tabellen aus, gibt Überhang-Speicher frei und aktualisiert die Index-Statistiken"
                    >
                        <span>⚡</span>
                        <span>Tabellen optimieren (<code class="font-mono text-[11px]">OPTIMIZE</code>)</span>
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- DB-Karte 1: Datenbank-Speicher & Index-Aufschlüsselung -->
                <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                            <span>🗄️ DB-Größe &amp; Index</span>
                            <span id="srvDbTablesBadge" class="font-bold text-slate-900 dark:text-white"><?= (int)$serverStats['db_tables_count'] ?> Tab.</span>
                        </div>
                        <p id="srvDbTotalMain" class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                            <?= e($serverStats['db_total_fmt']) ?> <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(<span id="srvDbRowsTotal"><?= e($serverStats['db_total_rows']) ?></span> Zeilen)</span>
                        </p>
                        <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden my-2.5" title="Verhältnis: Index-Speicher zu Nutzdaten">
                            <div
                                id="srvDbIndexBar"
                                class="h-full bg-indigo-600 rounded-full transition-all duration-300"
                                style="width: <?= e((string)$serverStats['db_index_pct']) ?>%"
                            ></div>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between">
                        <span>Daten: <strong id="srvDbDataFmt" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['db_data_fmt']) ?></strong></span>
                        <span>Index: <strong id="srvDbIndexFmt" class="text-indigo-600 dark:text-indigo-400"><?= e($serverStats['db_index_fmt']) ?></strong></span>
                    </p>
                </div>

                <!-- DB-Karte 2: InnoDB Buffer Pool & RAM-Cache-Hit-Rate -->
                <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                            <span>🚀 InnoDB RAM-Cache</span>
                            <span id="srvDbHitRate" class="font-bold text-emerald-600 dark:text-emerald-400" title="InnoDB Buffer-Pool Cache-Hit-Rate (Lesezugriffe direkt aus dem RAM)"><?= e($serverStats['db_inno_hit_rate']) ?>% Hit</span>
                        </div>
                        <p class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                            <span id="srvDbPoolUsed"><?= e($serverStats['db_inno_pool_used']) ?></span>
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">von <span id="srvDbPoolTotal"><?= e($serverStats['db_inno_pool_total']) ?></span></span>
                        </p>
                        <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden my-2.5">
                            <div
                                id="srvDbPoolBar"
                                class="h-full <?= $serverStats['db_inno_pool_pct'] >= 92 ? 'bg-amber-500' : 'bg-emerald-600' ?> rounded-full transition-all duration-300"
                                style="width: <?= e((string)$serverStats['db_inno_pool_pct']) ?>%"
                            ></div>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between">
                        <span>Pool-Belegung: <strong id="srvDbPoolPct" class="text-slate-700 dark:text-slate-200"><?= e(number_format((float)$serverStats['db_inno_pool_pct'], 1, ',', '.')) ?>%</strong></span>
                        <span>Überhang: <strong id="srvDbFreeFmt" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['db_free_fmt']) ?></strong></span>
                    </p>
                </div>

                <!-- DB-Karte 3: Abfrage-Durchsatz (QPS) & Lese/Schreib-Verhältnis -->
                <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                            <span>⚡ SQL-Durchsatz (QPS)</span>
                            <span class="text-[11px] font-semibold text-brand-600 dark:text-brand-400">Ø <span id="srvDbQpsAvg"><?= e($serverStats['db_qps_avg']) ?></span>/s</span>
                        </div>
                        <p class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                            <span id="srvDbQpsLive"><?= e($serverStats['db_qps_live']) ?></span> Queries/s
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(<span id="srvDbQuestions"><?= e($serverStats['db_questions_fmt']) ?></span> ges.)</span>
                        </p>
                        <div class="w-full h-2 bg-amber-500/80 rounded-full overflow-hidden my-2.5" title="Verhältnis: Lesezugriffe (SELECT) vs. Schreibzugriffe (INSERT/UPDATE/DELETE)">
                            <div
                                id="srvDbReadBar"
                                class="h-full bg-brand-600 rounded-l-full transition-all duration-300"
                                style="width: <?= e(str_replace(',', '.', (string)$serverStats['db_read_pct'])) ?>%"
                            ></div>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between">
                        <span>Lesen/Schr.: <strong id="srvDbRwRatio" class="text-slate-700 dark:text-slate-200"><?= e($serverStats['db_read_pct']) ?>% / <?= e($serverStats['db_write_pct']) ?>%</strong></span>
                        <span>Slow: <strong id="srvDbSlow" class="<?= (int)$serverStats['db_slow_queries'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' ?>"><?= (int)$serverStats['db_slow_queries'] ?></strong></span>
                    </p>
                </div>

                <!-- DB-Karte 4: Verbindungen & Thread-Pool -->
                <div class="p-4 rounded-xl bg-slate-50/70 dark:bg-slate-800/40 border border-slate-200/80 dark:border-slate-700/80 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1">
                            <span>🔌 DB-Verbindungen</span>
                            <span id="srvDbConnPctLabel" class="font-bold text-slate-900 dark:text-white">Peak: <?= e(number_format((float)$serverStats['db_peak_conn_pct'], 1, ',', '.')) ?>%</span>
                        </div>
                        <p class="text-sm sm:text-base font-bold text-slate-900 dark:text-white truncate">
                            <span id="srvDbThreadsConn"><?= (int)$serverStats['db_threads_conn'] ?></span> aktiv
                            <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(<span id="srvDbThreadsRun"><?= (int)$serverStats['db_threads_run'] ?></span> laufend / max. <?= (int)$serverStats['db_max_conn_limit'] ?>)</span>
                        </p>
                        <div class="w-full h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden my-2.5" title="Spitzen-Verbindungsauslastung (Max_used_connections im Verhältnis zu max_connections)">
                            <div
                                id="srvDbPeakConnBar"
                                class="h-full <?= $serverStats['db_peak_conn_pct'] >= 80 ? 'bg-red-600' : ($serverStats['db_peak_conn_pct'] >= 50 ? 'bg-amber-500' : 'bg-emerald-600') ?> rounded-full transition-all duration-300"
                                style="width: <?= e((string)$serverStats['db_peak_conn_pct']) ?>%"
                            ></div>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400 flex items-center justify-between">
                        <span>Spitzenwert: <strong id="srvDbMaxUsedConn" class="text-slate-700 dark:text-slate-200"><?= (int)$serverStats['db_max_used_conn'] ?></strong> / <?= (int)$serverStats['db_max_conn_limit'] ?></span>
                        <span>Abgebr.: <strong id="srvDbAbortedConn" class="text-slate-700 dark:text-slate-200"><?= (int)$serverStats['db_aborted_conn'] ?></strong></span>
                    </p>
                </div>
            </div>

            <!-- Aufklappbare Detail-Tabelle aller MariaDB-Tabellen nach Speicherverbrauch -->
            <?php if (!empty($serverStats['db_tables'])): ?>
                <details class="mt-3.5 group rounded-xl bg-slate-50/60 dark:bg-slate-800/30 border border-slate-200/80 dark:border-slate-700/80 overflow-hidden">
                    <summary class="px-4 py-2.5 text-xs font-semibold text-slate-700 dark:text-slate-200 cursor-pointer select-none flex items-center justify-between hover:bg-slate-100/70 dark:hover:bg-slate-800/60 transition-colors">
                        <span class="flex items-center gap-2">
                            <span>📋</span>
                            <span>Tabellen-Aufschlüsselung nach Speicherverbrauch anzeigen (<?= count($serverStats['db_tables']) ?> Tabellen, inkl. Volltext-Suchindex)</span>
                        </span>
                        <span class="text-slate-400 group-open:rotate-180 transition-transform">▼</span>
                    </summary>
                    <div class="border-t border-slate-200/80 dark:border-slate-700/80 overflow-x-auto max-h-80 overflow-y-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead class="bg-slate-100/90 dark:bg-slate-800/90 text-slate-600 dark:text-slate-300 sticky top-0">
                                <tr>
                                    <th class="py-2 px-3 font-semibold">Tabelle / Modul</th>
                                    <th class="py-2 px-3 font-semibold">SQL-Name</th>
                                    <th class="py-2 px-3 font-semibold text-right">Einträge (ca.)</th>
                                    <th class="py-2 px-3 font-semibold text-right">Nutzdaten</th>
                                    <th class="py-2 px-3 font-semibold text-right">Index-Größe</th>
                                    <th class="py-2 px-3 font-semibold text-right">Überhang</th>
                                    <th class="py-2 px-3 font-semibold text-right">Gesamt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200/60 dark:divide-slate-800">
                                <?php foreach ($serverStats['db_tables'] as $tbl): ?>
                                    <tr class="hover:bg-white/80 dark:hover:bg-slate-800/60">
                                        <td class="py-1.5 px-3 font-semibold text-slate-800 dark:text-slate-200 whitespace-nowrap">
                                            <?= e((string)$tbl['label']) ?>
                                        </td>
                                        <td class="py-1.5 px-3 font-mono text-[11px] text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                            <?= e((string)$tbl['name']) ?>
                                        </td>
                                        <td class="py-1.5 px-3 font-mono text-right text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                            <?= e(number_format((int)$tbl['rows'], 0, ',', '.')) ?>
                                        </td>
                                        <td class="py-1.5 px-3 font-mono text-right text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                            <?= e((string)$tbl['data_fmt']) ?>
                                        </td>
                                        <td class="py-1.5 px-3 font-mono text-right text-indigo-600 dark:text-indigo-400 whitespace-nowrap">
                                            <?= e((string)$tbl['index_fmt']) ?>
                                        </td>
                                        <td class="py-1.5 px-3 font-mono text-right text-slate-400 whitespace-nowrap">
                                            <?= e((string)$tbl['free_fmt']) ?>
                                        </td>
                                        <td class="py-1.5 px-3 font-mono font-bold text-right text-slate-900 dark:text-white whitespace-nowrap">
                                            <?= e((string)$tbl['total_fmt']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <!-- 5c. SICHERHEITS- & AKTIVITÄTSPROTOKOLL (AUDIT-LOG) -->
    <section id="audit-log" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 text-lg">
                    📜
                </span>
                <div>
                    <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Sicherheits- &amp; Aktivitätsprotokoll (Audit-Log)</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        DSGVO-konformes Protokoll sicherheitsrelevanter Ereignisse (Anmeldungen, Freigaben, selbstzerstörende Links, ZIP-Entpackungen, Backups) mit anonymisierten IP-Adressen.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    id="auditLogFilterInput"
                    oninput="filterAuditLogRows(this.value)"
                    placeholder="🔍 Protokoll filtern..."
                    class="px-3 py-1.5 rounded-xl bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-700 text-xs text-slate-800 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                >
                <?php if (!empty($auditLogs)): ?>
                    <form
                        action="admin.php"
                        method="post"
                        onsubmit="return confirm('Möchten Sie das gesamte Sicherheits- und Aktivitätsprotokoll wirklich leeren?');"
                    >
                        <input type="hidden" name="csrf_token" value="<?= e(getCsrfToken()) ?>">
                        <input type="hidden" name="action" value="clear_audit_log">
                        <button
                            type="submit"
                            class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-red-50 dark:hover:bg-red-950/60 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 border border-slate-200 dark:border-slate-700 text-xs font-semibold transition-colors cursor-pointer"
                        >
                            🗑️ Protokoll leeren
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($auditLogs)): ?>
            <p class="text-xs text-slate-400 italic py-6 text-center">
                Noch keine Einträge im Sicherheits- &amp; Aktivitätsprotokoll vorhanden.
            </p>
        <?php else: ?>
            <?php
            $eventBadgeMeta = [
                'login_success'      => ['label' => '🔑 Login OK',        'cls' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300'],
                'login_failed'       => ['label' => '⚠️ Login-Fehler',    'cls' => 'bg-red-100 dark:bg-red-950/80 text-red-800 dark:text-red-300'],
                'password_reset'     => ['label' => '🔐 Passwort-Reset',  'cls' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300'],
                'password_reset_req' => ['label' => '✉️ Reset-Anfrage',   'cls' => 'bg-amber-50 dark:bg-amber-950/50 text-amber-700 dark:text-amber-300'],
                'share_create'       => ['label' => '🔗 Link erstellt',   'cls' => 'bg-brand-100 dark:bg-brand-950/80 text-brand-800 dark:text-brand-300'],
                'share_delete'       => ['label' => '🗑️ Link gelöscht',   'cls' => 'bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300'],
                'share_burn'         => ['label' => '🔥 Selbstzerstört',  'cls' => 'bg-orange-100 dark:bg-orange-950/80 text-orange-800 dark:text-orange-300'],
                'file_drop'          => ['label' => '📥 Gast-Upload',     'cls' => 'bg-indigo-100 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-300'],
                'zip_extract'        => ['label' => '📦 ZIP entpackt',    'cls' => 'bg-purple-100 dark:bg-purple-950/80 text-purple-800 dark:text-purple-300'],
                'db_backup'          => ['label' => '💾 SQL-Backup',      'cls' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300'],
                'db_optimize'        => ['label' => '⚡ DB optimiert',    'cls' => 'bg-indigo-100 dark:bg-indigo-950/80 text-indigo-800 dark:text-indigo-300'],
                'user_create'        => ['label' => '👤 Konto angelegt',  'cls' => 'bg-brand-100 dark:bg-brand-950/80 text-brand-800 dark:text-brand-300'],
                'user_delete'        => ['label' => '🚫 Konto gelöscht',  'cls' => 'bg-red-100 dark:bg-red-950/80 text-red-800 dark:text-red-300'],
                'user_lock'          => ['label' => '🔒 Konto gesperrt',  'cls' => 'bg-red-100 dark:bg-red-950/80 text-red-800 dark:text-red-300'],
                'user_unlock'        => ['label' => '🔓 Konto entsperrt', 'cls' => 'bg-emerald-100 dark:bg-emerald-950/80 text-emerald-800 dark:text-emerald-300'],
                'system_update'      => ['label' => '⬆️ System-Update',   'cls' => 'bg-brand-100 dark:bg-brand-950/80 text-brand-800 dark:text-brand-300'],
                'snapshot_restore'   => ['label' => '⏪ Rollback',        'cls' => 'bg-amber-100 dark:bg-amber-950/80 text-amber-800 dark:text-amber-300'],
                'audit_clear'        => ['label' => '🧹 Log geleert',     'cls' => 'bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300'],
            ];
            ?>
            <div class="overflow-x-auto max-h-96 overflow-y-auto rounded-xl border border-slate-200/80 dark:border-slate-800">
                <table class="w-full text-left border-collapse text-xs">
                    <thead class="bg-slate-100/90 dark:bg-slate-800/90 text-slate-600 dark:text-slate-300 sticky top-0 z-10">
                        <tr>
                            <th class="py-2.5 px-3 font-semibold">Zeitpunkt</th>
                            <th class="py-2.5 px-3 font-semibold">Ereignis</th>
                            <th class="py-2.5 px-3 font-semibold">Benutzer</th>
                            <th class="py-2.5 px-3 font-semibold">Details</th>
                            <th class="py-2.5 px-3 font-semibold text-right">IP (anonym.)</th>
                        </tr>
                    </thead>
                    <tbody id="auditLogTableBody" class="divide-y divide-slate-200/70 dark:divide-slate-800">
                        <?php foreach ($auditLogs as $log): ?>
                            <?php
                            $evType = (string)$log['event_type'];
                            $meta   = $eventBadgeMeta[$evType] ?? ['label' => 'ℹ️ ' . $evType, 'cls' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300'];
                            $logTs  = strtotime((string)$log['created_at']);
                            $fmtTs  = $logTs ? date('d.m.Y H:i:s', $logTs) : (string)$log['created_at'];
                            $uLabel = trim((string)($log['username'] ?? '')) !== '' ? (string)$log['username'] : 'Gast / System';
                            ?>
                            <tr data-audit-row class="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="py-2 px-3 font-mono text-[11px] text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                    <?= e($fmtTs) ?>
                                </td>
                                <td class="py-2 px-3 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold <?= $meta['cls'] ?>">
                                        <?= e($meta['label']) ?>
                                    </span>
                                </td>
                                <td class="py-2 px-3 font-semibold text-slate-800 dark:text-slate-200 whitespace-nowrap">
                                    <?= e($uLabel) ?>
                                </td>
                                <td class="py-2 px-3 text-slate-700 dark:text-slate-300">
                                    <?= e((string)$log['description']) ?>
                                </td>
                                <td class="py-2 px-3 font-mono text-[11px] text-slate-400 text-right whitespace-nowrap">
                                    <?= e((string)($log['ip_address'] ?? '–')) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>

<script>
function handleLockUserSubmit(form, isCurrentlyLocked, username) {
    if (isCurrentlyLocked) {
        return confirm(`Möchten Sie das Benutzerkonto „${username}“ wieder entsperren?`);
    }
    const reason = prompt(
        `Benutzerkonto „${username}“ jetzt sperren?\n\nDer Benutzer wird sofort abgemeldet und kann sich nicht mehr einloggen.\nOptionaler Sperrgrund (wird beim Anmeldeversuch angezeigt):`,
        ''
    );
    if (reason === null) {
        return false;
    }
    const reasonInput = form.querySelector('input[name="lock_reason"]');
    if (reasonInput) {
        reasonInput.value = reason.trim();
    }
    return true;
}

function switchAdminTab(tabId, updateHash = true) {
    const panels = document.querySelectorAll('[data-admin-tab-panel]');
    const buttons = document.querySelectorAll('[data-admin-tab-btn]');

    // Legacy-Hash-Namen automatisch auf die neuen Tab-Namen abbilden
    const hashMap = {
        'branding': 'tab-branding',
        'system-update': 'tab-system',
        'server-status': 'tab-system',
        'audit-log': 'tab-system',
        'users': 'tab-users'
    };
    const resolvedTab = hashMap[tabId] || tabId;

    panels.forEach((panel) => {
        const pId = panel.getAttribute('data-admin-tab-panel');
        if (resolvedTab === 'all' || pId === resolvedTab) {
            panel.classList.remove('hidden');
        } else {
            panel.classList.add('hidden');
        }
    });

    buttons.forEach((btn) => {
        const bId = btn.getAttribute('data-admin-tab-btn');
        if (bId === resolvedTab) {
            btn.className = 'admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer bg-brand-600 text-white shadow-xs' + (bId === 'all' ? ' sm:ml-auto' : '');
        } else {
            btn.className = 'admin-tab-btn inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all cursor-pointer text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' + (bId === 'all' ? ' sm:ml-auto' : '');
        }
    });

    if (updateHash && history.replaceState) {
        history.replaceState(null, '', '#' + resolvedTab);
    }
}

(() => {
    const initialHash = (window.location.hash || '').replace(/^#/, '');
    const availablePanels = Array.from(document.querySelectorAll('[data-admin-tab-panel]')).map(el => el.getAttribute('data-admin-tab-panel'));
    const defaultTab = availablePanels[0] || 'all';
    const validTabs = ['tab-users', 'tab-modules', 'tab-branding', 'tab-mail', 'tab-system', 'all', 'branding', 'system-update', 'server-status', 'users'];
    const requestedTab = validTabs.includes(initialHash) ? initialHash : defaultTab;
    const hashMap = { 'branding': 'tab-branding', 'system-update': 'tab-system', 'server-status': 'tab-system', 'audit-log': 'tab-system', 'users': 'tab-users' };
    const resolvedReq = hashMap[requestedTab] || requestedTab;
    const finalInitialTab = (resolvedReq === 'all' || availablePanels.includes(resolvedReq)) ? requestedTab : defaultTab;
    switchAdminTab(finalInitialTab, false);

    const transportSel = document.getElementById('mailTransportSelect');
    const smtpBox = document.getElementById('smtpSettingsBox');
    const encSel = document.getElementById('smtpEncryptionSelect');
    const portInput = document.getElementById('smtpPortInput');

    if (transportSel && smtpBox) {
        transportSel.addEventListener('change', () => {
            if (transportSel.value === 'smtp') {
                smtpBox.classList.remove('hidden');
            } else {
                smtpBox.classList.add('hidden');
            }
        });
    }

    if (encSel && portInput) {
        encSel.addEventListener('change', () => {
            if (encSel.value === 'ssl' && (portInput.value === '587' || portInput.value === '25')) {
                portInput.value = '465';
            } else if (encSel.value === 'tls' && (portInput.value === '465' || portInput.value === '25')) {
                portInput.value = '587';
            } else if (encSel.value === 'none' && (portInput.value === '465' || portInput.value === '587')) {
                portInput.value = '25';
            }
        });
    }
})();

async function refreshServerDashboard() {
    try {
        const res = await fetch('admin.php?ajax_server_stats=1', {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const d = await res.json();

        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val;
        };

        setTxt('srvUptime', d.uptime);
        setTxt('srvTimestamp', d.timestamp);

        setTxt('srvDiskPctLabel', String(d.disk_pct).replace('.', ',') + '%');
        const diskBar = document.getElementById('srvDiskBar');
        if (diskBar) diskBar.style.width = d.disk_pct + '%';
        setTxt('srvDiskFree', d.disk_free_fmt);
        setTxt('srvCloudUsed', d.cloud_bytes_fmt);

        setTxt('srvRamPctLabel', String(d.ram_pct).replace('.', ',') + '%');
        const ramBar = document.getElementById('srvRamBar');
        if (ramBar) ramBar.style.width = d.ram_pct + '%';
        setTxt('srvRamFree', d.ram_free_fmt);

        setTxt('srvCpuPctLabel', String(d.cpu_pct).replace('.', ',') + '%');
        const cpuBar = document.getElementById('srvCpuBar');
        if (cpuBar) cpuBar.style.width = d.cpu_pct + '%';
        const cpuLoad = document.getElementById('srvCpuLoad');
        if (cpuLoad) {
            cpuLoad.innerHTML = `Load: ${d.load_1} <span class="text-xs font-normal text-slate-500 dark:text-slate-400">/ ${d.load_5} / ${d.load_15}</span>`;
        }

        setTxt('srvNetRxRate', d.net_rx_rate);
        setTxt('srvNetTxRate', d.net_tx_rate);
        setTxt('srvNetRxTotal', d.net_rx_total);
        setTxt('srvNetTxTotal', d.net_tx_total);

        if (d.db_uptime !== undefined) {
            setTxt('srvDbUptime', d.db_uptime);
            setTxt('srvDbBytesRx', d.db_bytes_rx_fmt);
            setTxt('srvDbBytesTx', d.db_bytes_tx_fmt);
            setTxt('srvDbDataFmt', d.db_data_fmt);
            setTxt('srvDbIndexFmt', d.db_index_fmt);
            setTxt('srvDbFreeFmt', d.db_free_fmt);
            const dbIndexBar = document.getElementById('srvDbIndexBar');
            if (dbIndexBar) dbIndexBar.style.width = d.db_index_pct + '%';

            setTxt('srvDbHitRate', d.db_inno_hit_rate + '% Hit');
            setTxt('srvDbPoolUsed', d.db_inno_pool_used);
            setTxt('srvDbPoolTotal', d.db_inno_pool_total);
            setTxt('srvDbPoolPct', String(d.db_inno_pool_pct).replace('.', ',') + '%');
            const dbPoolBar = document.getElementById('srvDbPoolBar');
            if (dbPoolBar) dbPoolBar.style.width = d.db_inno_pool_pct + '%';

            setTxt('srvDbQpsLive', d.db_qps_live);
            setTxt('srvDbQpsAvg', d.db_qps_avg);
            setTxt('srvDbQuestions', d.db_questions_fmt);
            setTxt('srvDbRwRatio', d.db_read_pct + '% / ' + d.db_write_pct + '%');
            setTxt('srvDbSlow', d.db_slow_queries);
            const dbReadBar = document.getElementById('srvDbReadBar');
            if (dbReadBar) dbReadBar.style.width = String(d.db_read_pct).replace(',', '.') + '%';

            setTxt('srvDbConnPctLabel', 'Peak: ' + String(d.db_peak_conn_pct).replace('.', ',') + '%');
            setTxt('srvDbThreadsConn', d.db_threads_conn);
            setTxt('srvDbThreadsRun', d.db_threads_run);
            setTxt('srvDbMaxUsedConn', d.db_max_used_conn);
            setTxt('srvDbAbortedConn', d.db_aborted_conn);
            const dbPeakBar = document.getElementById('srvDbPeakConnBar');
            if (dbPeakBar) dbPeakBar.style.width = d.db_peak_conn_pct + '%';
        }
    } catch {
        // Ignorieren bei kurzem Verbindungsabbruch
    }
}

// Ersten Messpunkt nach 1,5s nachladen (für sofortige Live-CPU-% und Netzwerk-Durchsatzrate), danach alle 5s
setTimeout(refreshServerDashboard, 1500);
setInterval(refreshServerDashboard, 5000);

function installGitHubCommit(sha, shortSha, dateFmt, message) {
    const form = document.getElementById('installSpecificCommitForm');
    if (!form) return;
    const repoInput = document.getElementById('mainGithubRepoInput');
    const repoField = document.getElementById('specificCommitRepo');
    const shaField = document.getElementById('specificCommitSha');
    const dateField = document.getElementById('specificCommitDate');
    const msgField = document.getElementById('specificCommitMsg');

    const promptText = `Möchten Sie Ihr System jetzt auf den GitHub-Stand #${shortSha} (${dateFmt})\n„${message}“\nbringen?\n\nVorab wird automatisch ein lokaler Sicherungspunkt Ihres aktuellen Stands angelegt. Benutzerdateien (uploads/) und Datenbank-Zugangsdaten bleiben unangetastet.`;
    if (!confirm(promptText)) return;

    if (repoField && repoInput && repoInput.value.trim() !== '') {
        repoField.value = repoInput.value.trim();
    }
    if (shaField) shaField.value = sha;
    if (dateField) dateField.value = dateFmt;
    if (msgField) msgField.value = message;
    form.submit();
}

async function checkGithubUpdateNow() {
    const badge = document.getElementById('updStatusBadge');
    const list = document.getElementById('githubCommitsList');
    const btn = document.getElementById('btnCheckGithubUpdate');
    if (!badge || !list) return;

    badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200';
    badge.textContent = 'Prüfe GitHub...';
    if (btn) btn.disabled = true;

    try {
        const res = await fetch('admin.php?ajax_check_update=1', {
            headers: { 'Accept': 'application/json' }
        });
        const d = await res.json();
        const esc = (s) => {
            const div = document.createElement('div');
            div.textContent = s ?? '';
            return div.innerHTML;
        };
        const escJsAttr = (s) => String(s ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;').replace(/\r?\n/g, ' ');

        if (!d || !d.ok) {
            badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300';
            badge.textContent = 'Prüfung fehlgeschlagen';
            list.innerHTML = `<p class="text-red-600 dark:text-red-400 py-3">${esc(d && d.error ? d.error : 'GitHub-API nicht erreichbar.')}</p>`;
            return;
        }

        if (d.is_up_to_date) {
            badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300';
            badge.textContent = '✅ Aktuell (#' + d.latest_short + ')';
        } else {
            badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300';
            badge.textContent = '🆕 Abweichender Stand / Update (#' + d.latest_short + ')';
        }

        if (Array.isArray(d.commits) && d.commits.length > 0) {
            list.innerHTML = d.commits.map((c, idx) => {
                const isInstalled = d.installed_sha && c.sha === d.installed_sha;
                const actionBtn = isInstalled
                    ? '<span class="px-2 py-1 rounded-lg text-[10px] font-bold bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">✓ Installiert</span>'
                    : `<button type="button" onclick="installGitHubCommit('${escJsAttr(c.sha)}', '${escJsAttr(c.short)}', '${escJsAttr(c.date_fmt)}', '${escJsAttr(c.message)}')" class="px-2.5 py-1 rounded-lg ${idx === 0 ? 'bg-brand-600 hover:bg-brand-700 text-white' : 'bg-slate-100 dark:bg-slate-700 hover:bg-amber-600 hover:text-white text-slate-700 dark:text-slate-200'} text-[11px] font-semibold transition-colors cursor-pointer" title="Genau diesen Programmstand von GitHub installieren">${idx === 0 ? '⬆️ Aktualisieren' : '⏮️ Diesen Stand installieren'}</button>`;

                return `
                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-800 border ${isInstalled ? 'border-emerald-400 dark:border-emerald-700 ring-1 ring-emerald-400/30' : (idx === 0 ? 'border-brand-300 dark:border-brand-700' : 'border-slate-200/80 dark:border-slate-700/80')} flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-slate-900 dark:text-white truncate" title="${esc(c.message)}">${esc(c.message)}</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">${esc(c.date_fmt)} &bull; ${esc(c.author)}</p>
                        </div>
                        <div class="flex items-center justify-end gap-1.5 shrink-0">
                            <a href="${esc(c.html_url)}" target="_blank" rel="noopener noreferrer" class="px-2 py-1 rounded-lg bg-slate-100 dark:bg-slate-700/70 text-[11px] font-mono text-brand-600 dark:text-brand-400 hover:underline" title="Änderungen auf GitHub ansehen">#${esc(c.short)}</a>
                            ${actionBtn}
                        </div>
                    </div>
                `;
            }).join('');
        }
    } catch {
        badge.className = 'px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300';
        badge.textContent = 'Verbindungsfehler';
    } finally {
        if (btn) btn.disabled = false;
    }
}

function filterAuditLogRows(query) {
    const q = String(query || '').trim().toLowerCase();
    const rows = document.querySelectorAll('#auditLogTableBody [data-audit-row]');
    rows.forEach((row) => {
        const txt = (row.textContent || '').toLowerCase();
        row.style.display = (q === '' || txt.includes(q)) ? '' : 'none';
    });
}

// Beim Öffnen der Admin-Seite einmal automatisch im Hintergrund den GitHub-Update-Status prüfen
setTimeout(checkGithubUpdateNow, 800);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
