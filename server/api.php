<?php
/************************************************************
 * InfiniteWP MCP - API Backend
 *
 * Provides a JSON API on top of the IWP database for the
 * MCP client. Must be placed inside your IWP installation
 * directory (e.g. /scheduler/api.php).
 ************************************************************/

session_start();
header('Content-Type: application/json');

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
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed']));
}

$db->set_charset('utf8mb4');
$prefix = $config['SQL_TABLE_NAME_PREFIX'];

// API token — set via environment variable IWP_SCHEDULER_TOKEN
$envToken = getenv('IWP_SCHEDULER_TOKEN');
if (!$envToken) {
    error_log('IWP_SCHEDULER_TOKEN env var is not set. Token-based API access will be unavailable.');
}
define('API_TOKEN', $envToken ?: '');

function checkAuth($db, $prefix) {
    // Bearer token auth
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($authHeader && API_TOKEN !== '') {
        $token = str_replace('Bearer ', '', $authHeader);
        if (hash_equals(API_TOKEN, $token)) {
            return true;
        }
    }
    // Token via query param
    if (!empty($_GET['token']) && API_TOKEN !== '' && hash_equals(API_TOKEN, $_GET['token'])) {
        return true;
    }
    // IWP cookie auth (for browser access)
    if (!empty($_COOKIE['userCookie'])) {
        $parts = explode('||', $_COOKIE['userCookie']);
        if (count($parts) >= 3) {
            $stmt = $db->prepare("SELECT password FROM {$prefix}users WHERE email = ? AND accessLevel = 'admin'");
            $stmt->bind_param('s', $parts[0]);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (md5($parts[0] . $row['password']) === $parts[1]) {
                    return true;
                }
            }
        }
    }
    if (!empty($_SESSION['scheduler_auth'])) {
        return true;
    }
    return false;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!checkAuth($db, $prefix)) {
    http_response_code(401);
    die(json_encode(['error' => 'Not authenticated']));
}

// Route actions
switch ($action) {
    case 'dashboard':       getDashboard($db, $prefix); break;
    case 'sites':           getSites($db, $prefix); break;
    case 'updates':         getUpdates($db, $prefix); break;
    case 'run-update':      runSiteUpdate($db, $prefix); break;
    case 'site-history':    getSiteHistory($db, $prefix); break;
    case 'generate-report': generateReport(); break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

$db->close();

// ============================================================
// Handlers
// ============================================================

function getDashboard($db, $prefix) {
    $totalSites = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}sites")->fetch_assoc()['cnt'];
    $sitesWithUpdates = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}site_stats WHERE updatePluginCounts > 0 OR updateThemeCounts > 0 OR isCoreUpdateAvailable = 1")->fetch_assoc()['cnt'];
    $totalPluginUpdates = $db->query("SELECT COALESCE(SUM(updatePluginCounts),0) as cnt FROM {$prefix}site_stats")->fetch_assoc()['cnt'];
    $totalThemeUpdates = $db->query("SELECT COALESCE(SUM(updateThemeCounts),0) as cnt FROM {$prefix}site_stats")->fetch_assoc()['cnt'];

    echo json_encode([
        'totalSites' => (int)$totalSites,
        'sitesWithUpdates' => (int)$sitesWithUpdates,
        'totalPluginUpdates' => (int)$totalPluginUpdates,
        'totalThemeUpdates' => (int)$totalThemeUpdates,
    ]);
}

function getSites($db, $prefix) {
    $result = $db->query("
        SELECT s.siteID, s.name, s.URL, s.WPVersion, s.connectionStatus, s.favicon,
               ss.updatePluginCounts, ss.updateThemeCounts, ss.isCoreUpdateAvailable,
               ss.isTranslationUpdateAvailable, ss.lastUpdatedTime
        FROM {$prefix}sites s
        LEFT JOIN {$prefix}site_stats ss ON s.siteID = ss.siteID
        ORDER BY s.name ASC
    ");

    $sites = [];
    while ($row = $result->fetch_assoc()) {
        $sites[] = [
            'id' => (int)$row['siteID'],
            'name' => $row['name'],
            'url' => $row['URL'],
            'wpVersion' => $row['WPVersion'],
            'connectionStatus' => $row['connectionStatus'],
            'pluginUpdates' => (int)($row['updatePluginCounts'] ?? 0),
            'themeUpdates' => (int)($row['updateThemeCounts'] ?? 0),
            'coreUpdate' => (int)($row['isCoreUpdateAvailable'] ?? 0),
            'translationUpdates' => (int)($row['isTranslationUpdateAvailable'] ?? 0),
            'lastChecked' => $row['lastUpdatedTime'],
            'totalUpdates' => (int)($row['updatePluginCounts'] ?? 0) + (int)($row['updateThemeCounts'] ?? 0) + (int)($row['isCoreUpdateAvailable'] ?? 0)
        ];
    }
    echo json_encode($sites);
}

function getUpdates($db, $prefix) {
    $siteId = isset($_GET['site_id']) ? (int)$_GET['site_id'] : null;
    $where = $siteId ? "WHERE s.siteID = $siteId" : "";

    $result = $db->query("
        SELECT s.siteID, s.name, s.URL, ss.stats
        FROM {$prefix}sites s
        LEFT JOIN {$prefix}site_stats ss ON s.siteID = ss.siteID
        $where ORDER BY s.name ASC
    ");

    $updates = [];
    while ($row = $result->fetch_assoc()) {
        $stats = @unserialize(@base64_decode($row['stats']));
        $siteUpdates = [
            'siteId' => (int)$row['siteID'],
            'siteName' => $row['name'],
            'siteUrl' => $row['URL'],
            'plugins' => [], 'themes' => [], 'core' => null, 'translations' => false
        ];

        if (!empty($stats['upgradable_plugins'])) {
            foreach ($stats['upgradable_plugins'] as $p) {
                $p = (array)$p;
                $siteUpdates['plugins'][] = [
                    'name' => $p['name'] ?? $p['Name'] ?? basename($p['file']),
                    'slug' => $p['slug'] ?? '',
                    'file' => $p['file'] ?? '',
                    'oldVersion' => $p['old_version'] ?? '',
                    'newVersion' => $p['new_version'] ?? '',
                ];
            }
        }
        if (!empty($stats['upgradable_themes'])) {
            foreach ($stats['upgradable_themes'] as $t) {
                $t = (array)$t;
                $siteUpdates['themes'][] = [
                    'name' => $t['name'] ?? '',
                    'slug' => $t['theme_tmp'] ?? $t['slug'] ?? '',
                    'oldVersion' => $t['old_version'] ?? '',
                    'newVersion' => $t['new_version'] ?? ''
                ];
            }
        }
        if (!empty($stats['core_updates'])) {
            $core = (array)$stats['core_updates'];
            $siteUpdates['core'] = ['current' => $core['current'] ?? '', 'new' => $core['new_version'] ?? $core['version'] ?? ''];
        }
        if (!empty($stats['upgradable_translations'])) {
            $siteUpdates['translations'] = true;
        }
        $updates[] = $siteUpdates;
    }
    echo json_encode($updates);
}

function runSiteUpdate($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);
    $siteId = (int)($input['siteId'] ?? 0);
    $type = $input['type'] ?? 'all';
    $slugs = $input['slugs'] ?? [];
    $exclude = $input['exclude'] ?? [];

    if (!$siteId) {
        http_response_code(400);
        echo json_encode(['error' => 'siteId required']);
        return;
    }

    $root = APP_ROOT;
    $args = escapeshellarg($siteId) . ' ' . escapeshellarg($type);
    if (!empty($slugs)) $args .= ' --slugs=' . escapeshellarg(implode(',', $slugs));
    if (!empty($exclude)) $args .= ' --exclude=' . escapeshellarg(implode(',', $exclude));

    $output = shell_exec("php $root/scheduler/mcp/run-update.php $args 2>&1");
    echo json_encode(['success' => true, 'output' => $output]);
}

function getSiteHistory($db, $prefix) {
    $siteId = (int)($_GET['site_id'] ?? 0);
    $search = $_GET['search'] ?? '';
    $type = $_GET['type'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 30), 200);

    if (!$siteId) {
        http_response_code(400);
        echo json_encode(['error' => 'site_id required']);
        return;
    }

    $where = "h.siteID = $siteId AND h.showUser = 'Y'";

    if ($type) {
        $typeMap = [
            'plugin' => "had.detailedAction = 'plugin'",
            'theme' => "had.detailedAction = 'theme'",
            'core' => "had.detailedAction = 'core'",
            'backup' => "h.type = 'backup'",
            'clientPlugin' => "h.type = 'clientPlugin'"
        ];
        if (isset($typeMap[$type])) $where .= " AND " . $typeMap[$type];
    }

    if ($search) {
        $searchEsc = $db->real_escape_string($search);
        $where .= " AND (had.uniqueName LIKE '%$searchEsc%' OR had.detailedAction LIKE '%$searchEsc%')";
    }

    $result = $db->query("
        SELECT h.historyID, h.type, h.action, h.status, h.microtimeAdded,
               had.uniqueName, had.detailedAction, had.status as itemStatus,
               had.errorMsg, had.successMsg
        FROM {$prefix}history h
        JOIN {$prefix}history_additional_data had ON h.historyID = had.historyID
        WHERE $where
        ORDER BY h.microtimeAdded DESC
        LIMIT $limit
    ");

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'historyId' => (int)$row['historyID'],
            'type' => $row['type'],
            'action' => $row['action'],
            'status' => $row['status'],
            'date' => date('Y-m-d H:i', (int)$row['microtimeAdded']),
            'timestamp' => (int)$row['microtimeAdded'],
            'uniqueName' => $row['uniqueName'],
            'detailedAction' => $row['detailedAction'],
            'itemStatus' => $row['itemStatus'],
            'errorMsg' => $row['errorMsg'],
            'successMsg' => $row['successMsg']
        ];
    }
    echo json_encode($history);
}

function generateReport() {
    $input = json_decode(file_get_contents('php://input'), true);
    $siteId = (int)($input['siteId'] ?? 0);

    if (!$siteId) {
        http_response_code(400);
        echo json_encode(['error' => 'siteId required']);
        return;
    }

    $root = APP_ROOT;
    $output = shell_exec("php $root/scheduler/mcp/generate-report.php " . escapeshellarg($siteId) . " 2>&1");
    $cleaned = preg_replace('/^(PHP\s|Deprecated|Warning|Notice).*\n/m', '', $output);
    echo trim($cleaned);
}
