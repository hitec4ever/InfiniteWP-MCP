<?php
// Helper: trigger updates for a site via IWP's update mechanism
// Usage: php run-update.php <siteID> <type> [--slugs=slug1,slug2] [--exclude=slug1,slug2]
// type: plugins, themes, core, translations, all

require_once(dirname(__FILE__) . '/../../config.php');

$siteId = (int)($argv[1] ?? 0);
$type = $argv[2] ?? 'all';

if (!$siteId) { echo "Error: No site ID\n"; exit(1); }

// Parse optional args
$slugs = [];
$exclude = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--slugs=') === 0) $slugs = explode(',', substr($arg, 8));
    if (strpos($arg, '--exclude=') === 0) $exclude = explode(',', substr($arg, 10));
}

$db = new mysqli($config['SQL_HOST'], $config['SQL_USERNAME'], $config['SQL_PASSWORD'], $config['SQL_DATABASE'], (int)$config['SQL_PORT']);
$db->set_charset('utf8mb4');
$prefix = $config['SQL_TABLE_NAME_PREFIX'];

// Get site
$siteData = $db->query("SELECT * FROM {$prefix}sites WHERE siteID = $siteId")->fetch_assoc();
if (!$siteData) { echo "Error: Site $siteId not found\n"; exit(1); }

$statsRow = $db->query("SELECT stats FROM {$prefix}site_stats WHERE siteID = $siteId")->fetch_assoc();
$stats = @unserialize(@base64_decode($statsRow['stats'] ?? ''));
if (!$stats) { echo "Error: No stats for site\n"; exit(1); }

// Also load exceptions
$exceptions = [];
$excResult = $db->query("SELECT * FROM {$prefix}update_exceptions");
while ($row = $excResult->fetch_assoc()) {
    $exceptions[] = $row;
}

function isExcluded($exceptions, $excludeList, $excType, $slug, $siteId) {
    if (in_array($slug, $excludeList)) return true;
    foreach ($exceptions as $exc) {
        if ($exc['type'] === $excType && $exc['slug'] === $slug) {
            if ($exc['site_id'] === null || (int)$exc['site_id'] === $siteId) return true;
        }
    }
    return false;
}

// Build update items
$requestItems = [];
$updated = [];
$skipped = [];

$user = $db->query("SELECT userID FROM {$prefix}users WHERE accessLevel='admin' LIMIT 1")->fetch_assoc();
$userID = $user['userID'] ?? 1;

// URL
$URL = $siteData['URL'];
if ($siteData['connectURL'] !== 'siteURL' && $siteData['connectURL'] !== 'default') {
    $URL = $siteData['adminURL'];
}

// Plugins
if (($type === 'plugins' || $type === 'all') && !empty($stats['upgradable_plugins'])) {
    foreach ($stats['upgradable_plugins'] as $p) {
        $p = (array)$p;
        $pSlug = $p['slug'] ?? '';
        $pFile = $p['file'] ?? '';

        if (!empty($slugs) && !in_array($pSlug, $slugs) && !in_array($pFile, $slugs)) continue;
        if (isExcluded($exceptions, $exclude, 'plugin', $pSlug, $siteId)) {
            $skipped[] = "Plugin {$p['name']} ({$pSlug}): excluded";
            continue;
        }

        unset($p['sections']);
        $requestItems[] = [
            'requestParams' => ['upgrade_plugins' => [$p]],
            'additionalData' => ['uniqueName' => $pFile, 'detailedAction' => 'plugin'],
            'extraTimeout' => 20,
            'label' => "Plugin {$p['name']} {$p['old_version']} -> {$p['new_version']}"
        ];
    }
}

// Themes
if (($type === 'themes' || $type === 'all') && !empty($stats['upgradable_themes'])) {
    foreach ($stats['upgradable_themes'] as $t) {
        $t = (array)$t;
        $tSlug = $t['theme_tmp'] ?? $t['slug'] ?? '';

        if (!empty($slugs) && !in_array($tSlug, $slugs)) continue;
        if (isExcluded($exceptions, $exclude, 'theme', $tSlug, $siteId)) {
            $skipped[] = "Theme {$t['name']} ({$tSlug}): excluded";
            continue;
        }

        $requestItems[] = [
            'requestParams' => ['upgrade_themes' => [$t]],
            'additionalData' => ['uniqueName' => $tSlug, 'detailedAction' => 'theme'],
            'extraTimeout' => 20,
            'label' => "Theme {$t['name']} {$t['old_version']} -> {$t['new_version']}"
        ];
    }
}

// Core
if (($type === 'core' || $type === 'all') && !empty($stats['core_updates'])) {
    $core = (array)$stats['core_updates'];
    $requestItems[] = [
        'requestParams' => ['wp_upgrade' => $stats['core_updates']],
        'additionalData' => ['uniqueName' => 'core', 'detailedAction' => 'core'],
        'extraTimeout' => 120,
        'label' => "WordPress Core -> " . ($core['new_version'] ?? $core['version'] ?? '?')
    ];
}

// Translations
if (($type === 'translations' || $type === 'all') && !empty($stats['upgradable_translations'])) {
    $requestItems[] = [
        'requestParams' => ['upgrade_translations' => true],
        'additionalData' => ['uniqueName' => 'translations', 'detailedAction' => 'translations'],
        'extraTimeout' => 60,
        'label' => "Translations"
    ];
}

if (empty($requestItems)) {
    echo "No updates to perform.\n";
    if (!empty($skipped)) echo "\nSkipped:\n" . implode("\n", array_map(fn($s) => "  - $s", $skipped)) . "\n";
    exit;
}

echo "Starting updates for {$siteData['name']}...\n\n";

// Build bulkActionParams
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
if (!empty($siteData['callOpt'])) $callOpt = @unserialize($siteData['callOpt']) ?: [];
if ($siteData['connectURL'] === 'adminURL') $callOpt['connectURL'] = 'adminURL';
if (!empty($siteData['httpAuth'])) $callOpt['httpAuth'] = @unserialize($siteData['httpAuth']);
$callOptSer = !empty($callOpt) ? serialize($callOpt) : '';

$isOpenSSL = $siteData['isOpenSSLActive'];
$privateKey = $siteData['privateKey'];
$randomSignature = $siteData['randomSignature'];

$actionID = md5(uniqid('mcp_', true));
$historyIDs = [];

foreach ($requestItems as $idx => $item) {
    $reqParams = $item['requestParams'];
    $reqParams['bulkActionParams'] = $bulkParams;
    $reqParams['username'] = $siteData['adminUsername'];

    $totalTimeout = DEFAULT_MAX_CLIENT_REQUEST_TIMEOUT + $item['extraTimeout'];
    $status = ($idx === 0) ? 'pending' : 'scheduled';

    // Insert history
    $db->query("INSERT INTO {$prefix}history
        (siteID, actionID, userID, type, action, events, URL, timeout, status, isPluginResponse, microtimeAdded, showUser, callOpt)
        VALUES ($siteId, '$actionID', $userID, 'PTC', 'update', " . count($requestItems) . ",
        '" . $db->real_escape_string(trim($URL)) . "', $totalTimeout, '$status', 1, " . microtime(true) . ", 'Y',
        '" . $db->real_escape_string($callOptSer) . "')");
    $historyID = $db->insert_id;

    if (!$historyID) {
        echo "  FAIL {$item['label']}: DB error\n";
        continue;
    }

    // Chaining
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

    // Sign
    $signString = 'do_upgrade' . $historyID;
    $signature = '';
    $signatureNew = '';

    if (function_exists('openssl_verify') && $isOpenSSL) {
        openssl_sign($signString, $sigBin, base64_decode($privateKey));
        $signature = base64_encode($sigBin);
    } elseif (!$isOpenSSL) {
        $signature = base64_encode(md5($signString . $randomSignature));
    }

    if (function_exists('openssl_verify') && $isOpenSSL && defined('OPENSSL_ALGO_SHA256')) {
        openssl_sign($signString, $sigBin256, base64_decode($privateKey), OPENSSL_ALGO_SHA256);
        $signatureNew = base64_encode($sigBin256);
    } elseif (!$isOpenSSL) {
        $signatureNew = base64_encode(md5($signString . $randomSignature));
    }

    // Encrypt secure data
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

    // Build request data
    $requestData = [
        'iwp_action' => 'do_upgrade',
        'params' => $reqParams,
        'id' => $historyID,
        'signature' => $signature,
        'signature_new' => $signatureNew,
        'iwp_admin_version' => APP_VERSION,
        'is_save_activity_log' => 1,
        'activities_log_datetime' => date('Y-m-d H:i:s')
    ];

    $db->query("INSERT INTO {$prefix}history_additional_data (historyID, uniqueName, detailedAction)
        VALUES ($historyID, '" . $db->real_escape_string($item['additionalData']['uniqueName']) . "',
                '" . $db->real_escape_string($item['additionalData']['detailedAction']) . "')");

    $db->query("INSERT INTO {$prefix}history_raw_details (historyID, request, panelRequest)
        VALUES ($historyID, '" . $db->real_escape_string(base64_encode(serialize($requestData))) . "', '')");

    $historyIDs[] = $historyID;
    echo "  QUEUED {$item['label']} (history #{$historyID})\n";
}

// Trigger execute.php for first item
if (!empty($historyIDs)) {
    $firstHID = $historyIDs[0];
    $executeUrl = 'https://' . APP_DOMAIN_PATH . 'execute.php';
    $ch = curl_init($executeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'historyID' => $firstHID,
        'actionID' => $actionID
    ]));
    $response = curl_exec($ch);
    curl_close($ch);

    // Wait briefly and check first result
    sleep(5);
    $row = $db->query("SELECT status FROM {$prefix}history WHERE historyID=$firstHID")->fetch_assoc();
    $addl = $db->query("SELECT status, errorMsg FROM {$prefix}history_additional_data WHERE historyID=$firstHID")->fetch_assoc();

    echo "\nFirst update status: {$row['status']}";
    if ($addl['status'] === 'success') echo " OK";
    elseif ($addl['status'] === 'error') echo " FAIL ({$addl['errorMsg']})";
    else echo " (processing...)";
    echo "\n";

    if (count($historyIDs) > 1) {
        echo "Remaining " . (count($historyIDs) - 1) . " updates will be processed sequentially by IWP.\n";
    }
}

if (!empty($skipped)) {
    echo "\nSkipped:\n" . implode("\n", array_map(fn($s) => "  - $s", $skipped)) . "\n";
}

echo "\nDone.\n";
$db->close();
