<?php
/************************************************************
 * IWP Update Scheduler - Cron Runner
 * Checks for due schedules and triggers updates via IWP.
 *
 * Usage:
 *   php cron-runner.php                  (check all due schedules)
 *   php cron-runner.php --schedule=3     (run specific schedule)
 *   HTTP: cron-runner.php?schedule_id=3
 ************************************************************/

$startTime = microtime(true);

// Determine schedule ID from CLI or HTTP
$scheduleId = null;
if (php_sapi_name() === 'cli') {
    foreach ($argv as $arg) {
        if (strpos($arg, '--schedule=') === 0) {
            $scheduleId = (int)substr($arg, 11);
        }
    }
} else {
    $scheduleId = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : null;
}

// Load IWP config
require_once(dirname(__FILE__) . '/../config.php');

$db = new mysqli(
    $config['SQL_HOST'],
    $config['SQL_USERNAME'],
    $config['SQL_PASSWORD'],
    $config['SQL_DATABASE'],
    (int)$config['SQL_PORT']
);

if ($db->connect_error) {
    logMsg("DB connection failed: " . $db->connect_error);
    exit(1);
}

$db->set_charset('utf8mb4');
$prefix = $config['SQL_TABLE_NAME_PREFIX'];

// Bootstrap IWP environment for update execution
define('USER_SESSION_NOT_REQUIRED', true);
define('SCHEDULER_CRON_MODE', true);

// We need IWP's core functions to trigger updates
$iwpRoot = dirname(__FILE__) . '/..';

// Get due schedules
$now = new DateTime('now', new DateTimeZone('Europe/Amsterdam'));
$nowStr = $now->format('Y-m-d H:i:s');

if ($scheduleId) {
    $result = $db->query("SELECT * FROM {$prefix}scheduled_updates WHERE id = $scheduleId AND is_active = 1");
} else {
    $result = $db->query("SELECT * FROM {$prefix}scheduled_updates WHERE is_active = 1 AND next_run <= '$nowStr'");
}

if ($result->num_rows === 0) {
    logMsg("No schedules due at $nowStr");
    output(['status' => 'idle', 'message' => 'No schedules due']);
    exit(0);
}

$totalQueued = 0;

// First, track all currently available updates (record first-seen timestamps)
trackAllUpdates($db, $prefix);

while ($schedule = $result->fetch_assoc()) {
    logMsg("Processing schedule #{$schedule['id']}: {$schedule['name']}");

    // Get exceptions for this schedule
    $exceptions = getExceptions($db, $prefix, (int)$schedule['id']);

    // Min age in hours (skip updates released less than X hours ago)
    $minAgeHours = (int)($schedule['min_update_age_hours'] ?? 0);

    // Determine which sites to update
    $siteIds = $schedule['site_ids'] ? explode(',', $schedule['site_ids']) : null;
    $siteWhere = $siteIds ? "AND s.siteID IN (" . implode(',', array_map('intval', $siteIds)) . ")" : "";

    // Fetch sites with available updates
    $sites = $db->query("
        SELECT s.siteID, s.name, s.URL, ss.stats
        FROM {$prefix}sites s
        LEFT JOIN {$prefix}site_stats ss ON s.siteID = ss.siteID
        WHERE 1=1 $siteWhere
        ORDER BY s.name
    ");

    while ($site = $sites->fetch_assoc()) {
        $stats = @unserialize(@base64_decode($site['stats']));
        if (empty($stats)) continue;

        $siteId = (int)$site['siteID'];
        $updateParams = [];

        // Plugins
        if ($schedule['update_plugins'] && !empty($stats['upgradable_plugins'])) {
            foreach ($stats['upgradable_plugins'] as $plugin) {
                $plugin = (array)$plugin;
                $slug = $plugin['slug'] ?? '';
                $file = $plugin['file'] ?? '';

                // Check exceptions
                if (isExcepted($exceptions, 'plugin', $slug, $siteId)) {
                    logMsg("  SKIP plugin '$slug' on site {$site['name']} (exception)");
                    logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'plugin', $slug,
                        $plugin['name'] ?? $slug, $plugin['old_version'] ?? '', $plugin['new_version'] ?? '', 'skipped', 'Excluded by exception rule');
                    continue;
                }

                // Check min age
                if ($minAgeHours > 0 && isTooNew($db, $prefix, $siteId, 'plugin', $slug, $plugin['new_version'] ?? '', $minAgeHours)) {
                    logMsg("  SKIP plugin '$slug' on site {$site['name']} (update <{$minAgeHours}h old)");
                    logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'plugin', $slug,
                        $plugin['name'] ?? $slug, $plugin['old_version'] ?? '', $plugin['new_version'] ?? '', 'skipped', "Update <{$minAgeHours}h old, will be included next time");
                    continue;
                }

                $updateParams['plugins'][] = $file;
                logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'plugin', $slug,
                    $plugin['name'] ?? $slug, $plugin['old_version'] ?? '', $plugin['new_version'] ?? '', 'queued');
            }
        }

        // Themes
        if ($schedule['update_themes'] && !empty($stats['upgradable_themes'])) {
            foreach ($stats['upgradable_themes'] as $theme) {
                $theme = (array)$theme;
                $slug = $theme['theme_tmp'] ?? $theme['slug'] ?? '';

                if (isExcepted($exceptions, 'theme', $slug, $siteId)) {
                    logMsg("  SKIP theme '$slug' on site {$site['name']} (exception)");
                    logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'theme', $slug,
                        $theme['name'] ?? $slug, $theme['old_version'] ?? '', $theme['new_version'] ?? '', 'skipped', 'Excluded by exception rule');
                    continue;
                }

                // Check min age
                if ($minAgeHours > 0 && isTooNew($db, $prefix, $siteId, 'theme', $slug, $theme['new_version'] ?? '', $minAgeHours)) {
                    logMsg("  SKIP theme '$slug' on site {$site['name']} (update <{$minAgeHours}h old)");
                    logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'theme', $slug,
                        $theme['name'] ?? $slug, $theme['old_version'] ?? '', $theme['new_version'] ?? '', 'skipped', "Update <{$minAgeHours}h old, will be included next time");
                    continue;
                }

                $updateParams['themes'][] = $slug;
                logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'theme', $slug,
                    $theme['name'] ?? $slug, $theme['old_version'] ?? '', $theme['new_version'] ?? '', 'queued');
            }
        }

        // Core
        if ($schedule['update_core'] && !empty($stats['core_updates'])) {
            $core = (array)$stats['core_updates'];
            $updateParams['core'] = $core['current'] ?? '';
            logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'core', 'wordpress',
                'WordPress Core', $core['current'] ?? '', $core['new_version'] ?? $core['version'] ?? '', 'queued');
        }

        // Translations
        if ($schedule['update_translations'] && !empty($stats['upgradable_translations'])) {
            $updateParams['translations'] = true;
            logToDb($db, $prefix, $schedule['id'], $siteId, $site['name'], 'translation', 'translations',
                'Translations', '', '', 'queued');
        }

        // Trigger update via IWP's internal AJAX mechanism
        if (!empty($updateParams)) {
            $queued = triggerIWPUpdate($db, $prefix, $siteId, $updateParams, $iwpRoot);
            $totalQueued += $queued;
            logMsg("  Queued $queued update tasks for site {$site['name']}");
        }
    }

    // Update schedule: last_run and next_run
    $nextRun = calculateNextRun($schedule['schedule_time'], $schedule['days_of_week']);
    $db->query("UPDATE {$prefix}scheduled_updates SET last_run = '$nowStr', next_run = '$nextRun' WHERE id = {$schedule['id']}");
    logMsg("Schedule #{$schedule['id']} done. Next run: $nextRun");
}

$elapsed = round(microtime(true) - $startTime, 2);
logMsg("Completed. Queued $totalQueued updates in {$elapsed}s");
output(['status' => 'done', 'queued' => $totalQueued, 'elapsed' => $elapsed]);

$db->close();

// ============================================================
// Functions
// ============================================================

function triggerIWPUpdate($db, $prefix, $siteId, $updateParams, $iwpRoot) {
    $siteData = $db->query("SELECT * FROM {$prefix}sites WHERE siteID = $siteId")->fetch_assoc();
    if (!$siteData) return 0;

    $stats = $db->query("SELECT stats FROM {$prefix}site_stats WHERE siteID = $siteId")->fetch_assoc();
    $siteStats = @unserialize(@base64_decode($stats['stats'] ?? ''));
    if (!$siteStats) return 0;

    $count = 0;
    $actionID = md5(uniqid('scheduler_', true));
    $timeout = DEFAULT_MAX_CLIENT_REQUEST_TIMEOUT;

    $user = $db->query("SELECT userID FROM {$prefix}users WHERE accessLevel = 'admin' LIMIT 1")->fetch_assoc();
    $userID = $user['userID'] ?? 1;

    // Determine site URL (same logic as IWP)
    $URL = $siteData['URL'];
    if ($siteData['connectURL'] !== 'siteURL' && $siteData['connectURL'] !== 'default') {
        $URL = $siteData['adminURL'];
    }

    // Build per-item request list
    $requestItems = [];

    if (!empty($updateParams['plugins']) && !empty($siteStats['upgradable_plugins'])) {
        foreach ($siteStats['upgradable_plugins'] as $plugin) {
            $plugin = (array)$plugin;
            if (in_array($plugin['file'], $updateParams['plugins'])) {
                unset($plugin['sections']);
                $requestItems[] = [
                    'requestParams' => ['upgrade_plugins' => [$plugin]],
                    'additionalData' => ['uniqueName' => $plugin['file'], 'detailedAction' => 'plugin'],
                    'extraTimeout' => 20
                ];
            }
        }
    }

    if (!empty($updateParams['themes']) && !empty($siteStats['upgradable_themes'])) {
        foreach ($siteStats['upgradable_themes'] as $theme) {
            $theme = (array)$theme;
            $slug = $theme['theme_tmp'] ?? $theme['slug'] ?? '';
            if (in_array($slug, $updateParams['themes'])) {
                $requestItems[] = [
                    'requestParams' => ['upgrade_themes' => [$theme]],
                    'additionalData' => ['uniqueName' => $slug, 'detailedAction' => 'theme'],
                    'extraTimeout' => 20
                ];
            }
        }
    }

    if (!empty($updateParams['core']) && !empty($siteStats['core_updates'])) {
        $requestItems[] = [
            'requestParams' => ['wp_upgrade' => $siteStats['core_updates']],
            'additionalData' => ['uniqueName' => 'core', 'detailedAction' => 'core'],
            'extraTimeout' => 120
        ];
    }

    if (!empty($updateParams['translations'])) {
        $requestItems[] = [
            'requestParams' => ['upgrade_translations' => true],
            'additionalData' => ['uniqueName' => 'translations', 'detailedAction' => 'translations'],
            'extraTimeout' => 60
        ];
    }

    if (empty($requestItems)) return 0;

    // Build combined bulkActionParams
    $bulkParams = [];
    foreach ($requestItems as $item) {
        foreach ($item['requestParams'] as $key => $val) {
            if ($key === 'upgrade_plugins' || $key === 'upgrade_themes') {
                $bulkParams[$key] = array_merge($bulkParams[$key] ?? [], $val);
            } else {
                $bulkParams[$key] = $val;
            }
        }
    }

    // callOpt
    $callOpt = [];
    if (!empty($siteData['callOpt'])) {
        $callOpt = @unserialize($siteData['callOpt']) ?: [];
    }
    if ($siteData['connectURL'] === 'adminURL') {
        $callOpt['connectURL'] = 'adminURL';
    }
    if (!empty($siteData['httpAuth'])) {
        $callOpt['httpAuth'] = @unserialize($siteData['httpAuth']);
    }
    $callOptSer = !empty($callOpt) ? serialize($callOpt) : '';

    $isOpenSSL = $siteData['isOpenSSLActive'];
    $privateKey = $siteData['privateKey'];       // base64-encoded in DB
    $randomSignature = $siteData['randomSignature'];

    $historyIDs = [];

    foreach ($requestItems as $idx => $item) {
        $reqParams = $item['requestParams'];
        $reqParams['bulkActionParams'] = $bulkParams;
        $reqParams['username'] = $siteData['adminUsername'];

        $totalTimeout = $timeout + $item['extraTimeout'];

        // Step 1: Insert history record (need historyID for signing)
        $status = ($idx === 0) ? 'pending' : 'scheduled';
        $microtime = microtime(true);

        $sqlHistory = "INSERT INTO {$prefix}history
            (siteID, actionID, userID, type, action, events, URL, timeout, status, isPluginResponse, microtimeAdded, showUser, callOpt)
            VALUES ($siteId, '$actionID', $userID, 'PTC', 'update', " . count($requestItems) . ",
            '" . $db->real_escape_string(trim($URL)) . "', $totalTimeout, '$status', 1, $microtime, 'Y',
            '" . $db->real_escape_string($callOptSer) . "')";

        $db->query($sqlHistory);
        $historyID = $db->insert_id;

        if (!$historyID) {
            logMsg("  ERROR inserting history: " . $db->error);
            continue;
        }

        // Step 2: Add runCondition for chaining (items after the first wait for previous)
        if ($idx > 0 && !empty($historyIDs)) {
            $prevHID = end($historyIDs);
            $runCondition = serialize([
                'satisfyType' => 'OR',
                'query' => [
                    'table' => 'history_additional_data',
                    'select' => 'historyID',
                    'where' => "historyID = $prevHID AND status IN('success', 'error', 'netError')",
                    'lastHistoryID' => $prevHID
                ]
            ]);
            $db->query("UPDATE {$prefix}history SET runCondition = '" . $db->real_escape_string($runCondition) . "',
                timeScheduled = " . time() . " WHERE historyID = $historyID");
        }

        // Step 3: Sign using requestAction + historyID (matches IWP's appFunctions.php signing logic)
        $signString = 'do_upgrade' . $historyID;

        $signature = '';
        if (function_exists('openssl_verify') && $isOpenSSL) {
            openssl_sign($signString, $sigBin, base64_decode($privateKey));
            $signature = base64_encode($sigBin);
        } elseif (!$isOpenSSL) {
            $signature = base64_encode(md5($signString . $randomSignature));
        }

        $signatureNew = '';
        if (function_exists('openssl_verify') && $isOpenSSL && defined('OPENSSL_ALGO_SHA256')) {
            openssl_sign($signString, $sigBin256, base64_decode($privateKey), OPENSSL_ALGO_SHA256);
            $signatureNew = base64_encode($sigBin256);
        } elseif (!$isOpenSSL) {
            $signatureNew = base64_encode(md5($signString . $randomSignature));
        }

        // Step 4: Encrypt secure data if present
        if (!empty($siteData['ftpDetails'])) {
            $reqParams['secure']['account_info'] = $siteData['ftpDetails'];
        }
        if (isset($reqParams['secure']) && $isOpenSSL && !empty($privateKey)) {
            $secureData = serialize($reqParams['secure']);
            $secureDataArray = str_split($secureData, 96);
            $secureDataEncrypt = [];
            foreach ($secureDataArray as $part) {
                openssl_private_encrypt($part, $encPart, base64_decode($privateKey));
                $secureDataEncrypt[] = $encPart;
            }
            $reqParams['secure'] = base64_encode(serialize($secureDataEncrypt));
        }

        // Step 5: Build full request data (matches IWP's internal format)
        $requestData = [
            'iwp_action' => 'do_upgrade',
            'params' => $reqParams,
            'id' => $historyID,
            'signature' => $signature,
            'signature_new' => $signatureNew,
            'iwp_admin_version' => APP_VERSION,
            'is_save_activity_log' => 1,
            'activities_log_datetime' => date('Y-m-d H:i:s', time())
        ];

        $requestSerialized = base64_encode(serialize($requestData));

        // Step 6: Insert additional data and raw request
        $db->query("INSERT INTO {$prefix}history_additional_data (historyID, uniqueName, detailedAction)
            VALUES ($historyID, '" . $db->real_escape_string($item['additionalData']['uniqueName']) . "',
                    '" . $db->real_escape_string($item['additionalData']['detailedAction']) . "')");

        $db->query("INSERT INTO {$prefix}history_raw_details (historyID, request, panelRequest)
            VALUES ($historyID, '" . $db->real_escape_string($requestSerialized) . "', '')");

        $historyIDs[] = $historyID;
        $count++;
    }

    // Step 7: Trigger execute.php for each history entry (first one starts, rest chain via runCondition)
    if (!empty($historyIDs)) {
        triggerExecute($historyIDs[0], $actionID);
    }

    return $count;
}

function triggerExecute($historyID, $actionID) {
    // Call IWP's execute.php with historyID + actionID (as IWP does internally)
    $executeUrl = 'https://' . APP_DOMAIN_PATH . 'execute.php';
    $ch = curl_init($executeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'historyID' => $historyID,
        'actionID' => $actionID
    ]));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMsg("  execute.php: HTTP $httpCode, response: " . substr($response, 0, 100));
}

function getExceptions($db, $prefix, $scheduleId) {
    $result = $db->query("SELECT * FROM {$prefix}update_exceptions
        WHERE schedule_id = $scheduleId OR schedule_id IS NULL");
    $exceptions = [];
    while ($row = $result->fetch_assoc()) {
        $exceptions[] = $row;
    }
    return $exceptions;
}

function isExcepted($exceptions, $type, $slug, $siteId) {
    foreach ($exceptions as $exc) {
        if ($exc['type'] === $type && $exc['slug'] === $slug) {
            // Global exception (no specific site) or site-specific match
            if ($exc['site_id'] === null || (int)$exc['site_id'] === $siteId) {
                return true;
            }
        }
    }
    return false;
}

function isTooNew($db, $prefix, $siteId, $type, $slug, $newVersion, $minAgeHours) {
    if (!$newVersion || $minAgeHours <= 0) return false;

    $stmt = $db->prepare("SELECT first_seen FROM {$prefix}update_first_seen
        WHERE site_id = ? AND type = ? AND slug = ? AND new_version = ?");
    $stmt->bind_param('isss', $siteId, $type, $slug, $newVersion);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        // Not tracked yet - treat as brand new, skip it
        return true;
    }

    $firstSeen = new DateTime($row['first_seen'], new DateTimeZone('Europe/Amsterdam'));
    $now = new DateTime('now', new DateTimeZone('Europe/Amsterdam'));
    $ageHours = ($now->getTimestamp() - $firstSeen->getTimestamp()) / 3600;

    return $ageHours < $minAgeHours;
}

function trackAllUpdates($db, $prefix) {
    // Record first-seen timestamps for all currently available updates
    $result = $db->query("SELECT siteID, stats FROM {$prefix}site_stats WHERE stats IS NOT NULL");
    $stmt = $db->prepare("INSERT IGNORE INTO {$prefix}update_first_seen (site_id, type, slug, new_version) VALUES (?, ?, ?, ?)");

    while ($row = $result->fetch_assoc()) {
        $siteId = (int)$row['siteID'];
        $stats = @unserialize(@base64_decode($row['stats']));
        if (!$stats) continue;

        if (!empty($stats['upgradable_plugins'])) {
            foreach ($stats['upgradable_plugins'] as $p) {
                $p = (array)$p;
                $type = 'plugin';
                $slug = $p['slug'] ?? '';
                $ver = $p['new_version'] ?? '';
                if ($slug && $ver) {
                    $stmt->bind_param('isss', $siteId, $type, $slug, $ver);
                    $stmt->execute();
                }
            }
        }

        if (!empty($stats['upgradable_themes'])) {
            foreach ($stats['upgradable_themes'] as $t) {
                $t = (array)$t;
                $type = 'theme';
                $slug = $t['theme_tmp'] ?? $t['slug'] ?? '';
                $ver = $t['new_version'] ?? '';
                if ($slug && $ver) {
                    $stmt->bind_param('isss', $siteId, $type, $slug, $ver);
                    $stmt->execute();
                }
            }
        }
    }
}

function logToDb($db, $prefix, $scheduleId, $siteId, $siteName, $type, $slug, $name, $oldVer, $newVer, $status, $message = null) {
    $stmt = $db->prepare("INSERT INTO {$prefix}scheduled_update_log
        (schedule_id, site_id, site_name, type, item_slug, item_name, old_version, new_version, status, message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iissssssss', $scheduleId, $siteId, $siteName, $type, $slug, $name, $oldVer, $newVer, $status, $message);
    $stmt->execute();
}

function calculateNextRun($time, $daysOfWeek) {
    $days = array_map('intval', explode(',', $daysOfWeek));
    $now = new DateTime('now', new DateTimeZone('Europe/Amsterdam'));
    $candidate = clone $now;
    $timeParts = array_map('intval', explode(':', $time));
    $candidate->setTime($timeParts[0], $timeParts[1], $timeParts[2] ?? 0);

    for ($i = 0; $i < 8; $i++) {
        $dayOfWeek = (int)$candidate->format('w');
        if (in_array($dayOfWeek, $days) && $candidate > $now) {
            return $candidate->format('Y-m-d H:i:s');
        }
        $candidate->modify('+1 day');
        $candidate->setTime($timeParts[0], $timeParts[1], $timeParts[2] ?? 0);
    }
    return $candidate->format('Y-m-d H:i:s');
}

function logMsg($msg) {
    $logFile = dirname(__FILE__) . '/cron.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

function output($data) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($data);
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
}
