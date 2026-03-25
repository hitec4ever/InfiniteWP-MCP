<?php
/************************************************************
 * IWP Update Scheduler - API Backend
 * Reads IWP data and manages scheduled updates.
 *
 * This file must be placed inside your IWP installation
 * directory (e.g. /scheduler/api.php).
 ************************************************************/

session_start();
header('Content-Type: application/json');

// Load IWP config (adjusts to your IWP installation path)
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

// API token for MCP / external access.
// IMPORTANT: Set the IWP_SCHEDULER_TOKEN environment variable — no fallback is provided.
$envToken = getenv('IWP_SCHEDULER_TOKEN');
if (!$envToken) {
    error_log('IWP_SCHEDULER_TOKEN env var is not set. Token-based API access will be unavailable.');
}
define('API_TOKEN', $envToken ?: '');

function checkAuth($db, $prefix) {
    // Token auth (Bearer token or X-API-Token header)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '';
    if ($authHeader && API_TOKEN !== '') {
        $token = str_replace('Bearer ', '', $authHeader);
        if (hash_equals(API_TOKEN, $token)) {
            return true;
        }
    }
    // Token via query param (for simple testing)
    if (!empty($_GET['token']) && API_TOKEN !== '' && hash_equals(API_TOKEN, $_GET['token'])) {
        return true;
    }

    // IWP cookie auth (browser sessions)
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
    // PHP session auth
    if (!empty($_SESSION['scheduler_auth'])) {
        return true;
    }
    return false;
}

// Login endpoint doesn't need auth
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'login') {
    handleLogin($db, $prefix);
    exit;
}

if (!checkAuth($db, $prefix)) {
    http_response_code(401);
    die(json_encode(['error' => 'Not authenticated']));
}

// Route actions
switch ($action) {
    case 'sites':
        getSites($db, $prefix);
        break;
    case 'updates':
        getUpdates($db, $prefix);
        break;
    case 'schedules':
        getSchedules($db, $prefix);
        break;
    case 'schedule-save':
        saveSchedule($db, $prefix);
        break;
    case 'schedule-delete':
        deleteSchedule($db, $prefix);
        break;
    case 'schedule-toggle':
        toggleSchedule($db, $prefix);
        break;
    case 'exceptions':
        getExceptions($db, $prefix);
        break;
    case 'exception-save':
        saveException($db, $prefix);
        break;
    case 'exception-update':
        updateException($db, $prefix);
        break;
    case 'exception-delete':
        deleteException($db, $prefix);
        break;
    case 'history':
        getHistory($db, $prefix);
        break;
    case 'run-now':
        runNow($db, $prefix);
        break;
    case 'all-plugins':
        getAllPlugins($db, $prefix);
        break;
    case 'dashboard':
        getDashboard($db, $prefix);
        break;
    case 'track-updates':
        trackUpdates($db, $prefix);
        break;
    case 'run-update':
        runSiteUpdate($db, $prefix);
        break;
    case 'site-history':
        getSiteHistory($db, $prefix);
        break;
    case 'generate-report':
        generateReport();
        break;
    case 'report-status':
        reportStatus($db, $prefix);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

$db->close();

// ============================================================
// Handler functions
// ============================================================

function handleLogin($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email and password required']);
        return;
    }

    $stmt = $db->prepare("SELECT userID, email, password, accessLevel FROM {$prefix}users WHERE email = ? AND accessLevel = 'admin'");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user || sha1($password) !== $user['password']) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        return;
    }

    $_SESSION['scheduler_auth'] = true;
    $_SESSION['scheduler_user'] = $user['email'];

    echo json_encode(['success' => true, 'email' => $user['email']]);
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
            'favicon' => $row['favicon'],
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
        $where
        ORDER BY s.name ASC
    ");

    $updates = [];
    while ($row = $result->fetch_assoc()) {
        $stats = @unserialize(@base64_decode($row['stats']));
        $siteUpdates = [
            'siteId' => (int)$row['siteID'],
            'siteName' => $row['name'],
            'siteUrl' => $row['URL'],
            'plugins' => [],
            'themes' => [],
            'core' => null,
            'translations' => false
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
                    'icon' => $p['icons']['1x'] ?? $p['icons']['2x'] ?? null
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
            $siteUpdates['core'] = [
                'current' => $core['current'] ?? '',
                'new' => $core['new_version'] ?? $core['version'] ?? ''
            ];
        }

        if (!empty($stats['upgradable_translations'])) {
            $siteUpdates['translations'] = true;
        }

        $updates[] = $siteUpdates;
    }

    echo json_encode($updates);
}

function getSchedules($db, $prefix) {
    $result = $db->query("SELECT * FROM {$prefix}scheduled_updates ORDER BY created_at DESC");
    $schedules = [];
    while ($row = $result->fetch_assoc()) {
        // Get exception count for this schedule
        $excResult = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}update_exceptions WHERE schedule_id = {$row['id']} OR schedule_id IS NULL");
        $excRow = $excResult->fetch_assoc();

        $schedules[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'scheduleTime' => $row['schedule_time'],
            'daysOfWeek' => $row['days_of_week'],
            'updatePlugins' => (bool)$row['update_plugins'],
            'updateThemes' => (bool)$row['update_themes'],
            'updateCore' => (bool)$row['update_core'],
            'updateTranslations' => (bool)$row['update_translations'],
            'minUpdateAgeHours' => (int)$row['min_update_age_hours'],
            'siteIds' => $row['site_ids'] ? array_map('intval', explode(',', $row['site_ids'])) : null,
            'isActive' => (bool)$row['is_active'],
            'lastRun' => $row['last_run'],
            'nextRun' => $row['next_run'],
            'exceptionCount' => (int)$excRow['cnt']
        ];
    }
    echo json_encode($schedules);
}

function saveSchedule($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);

    $id = isset($input['id']) ? (int)$input['id'] : null;
    $name = $db->real_escape_string($input['name'] ?? 'Unnamed Schedule');
    $time = $db->real_escape_string($input['scheduleTime'] ?? '02:00:00');
    $days = $db->real_escape_string($input['daysOfWeek'] ?? '1,2,3,4,5,6,0');
    $plugins = (int)($input['updatePlugins'] ?? 1);
    $themes = (int)($input['updateThemes'] ?? 1);
    $core = (int)($input['updateCore'] ?? 0);
    $translations = (int)($input['updateTranslations'] ?? 1);
    $minAge = (int)($input['minUpdateAgeHours'] ?? 0);
    $siteIds = !empty($input['siteIds']) ? $db->real_escape_string(implode(',', array_map('intval', $input['siteIds']))) : null;

    // Calculate next run
    $nextRun = calculateNextRun($time, $days);

    if ($id) {
        $siteIdsSQL = $siteIds !== null ? "'$siteIds'" : "NULL";
        $db->query("UPDATE {$prefix}scheduled_updates SET
            name='$name', schedule_time='$time', days_of_week='$days',
            update_plugins=$plugins, update_themes=$themes, update_core=$core,
            update_translations=$translations, min_update_age_hours=$minAge, site_ids=$siteIdsSQL,
            next_run='$nextRun'
            WHERE id=$id");
    } else {
        $siteIdsSQL = $siteIds !== null ? "'$siteIds'" : "NULL";
        $db->query("INSERT INTO {$prefix}scheduled_updates
            (name, schedule_time, days_of_week, update_plugins, update_themes, update_core, update_translations, min_update_age_hours, site_ids, next_run)
            VALUES ('$name', '$time', '$days', $plugins, $themes, $core, $translations, $minAge, $siteIdsSQL, '$nextRun')");
        $id = $db->insert_id;
    }

    echo json_encode(['success' => true, 'id' => $id, 'nextRun' => $nextRun]);
}

function deleteSchedule($db, $prefix) {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id) {
        $db->query("DELETE FROM {$prefix}update_exceptions WHERE schedule_id = $id");
        $db->query("DELETE FROM {$prefix}scheduled_updates WHERE id = $id");
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
    }
}

function toggleSchedule($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if ($id) {
        $db->query("UPDATE {$prefix}scheduled_updates SET is_active = NOT is_active WHERE id = $id");
        $row = $db->query("SELECT is_active FROM {$prefix}scheduled_updates WHERE id = $id")->fetch_assoc();
        echo json_encode(['success' => true, 'isActive' => (bool)$row['is_active']]);
    }
}

function getExceptions($db, $prefix) {
    $scheduleId = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : null;
    $where = $scheduleId ? "WHERE e.schedule_id = $scheduleId OR e.schedule_id IS NULL" : "";

    $result = $db->query("
        SELECT e.*, s.name as site_name, s.URL as site_url
        FROM {$prefix}update_exceptions e
        LEFT JOIN {$prefix}sites s ON e.site_id = s.siteID
        $where
        ORDER BY e.type, e.name
    ");

    $exceptions = [];
    while ($row = $result->fetch_assoc()) {
        $exceptions[] = [
            'id' => (int)$row['id'],
            'scheduleId' => $row['schedule_id'] ? (int)$row['schedule_id'] : null,
            'siteId' => $row['site_id'] ? (int)$row['site_id'] : null,
            'siteName' => $row['site_name'],
            'siteUrl' => $row['site_url'],
            'type' => $row['type'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'reason' => $row['reason'],
            'createdAt' => $row['created_at']
        ];
    }
    echo json_encode($exceptions);
}

function saveException($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);

    $scheduleId = isset($input['scheduleId']) ? (int)$input['scheduleId'] : null;
    $siteId = isset($input['siteId']) ? (int)$input['siteId'] : null;
    $type = $db->real_escape_string($input['type'] ?? 'plugin');
    $slug = $db->real_escape_string($input['slug'] ?? '');
    $name = $db->real_escape_string($input['name'] ?? '');
    $reason = $db->real_escape_string($input['reason'] ?? '');

    if (empty($slug)) {
        http_response_code(400);
        echo json_encode(['error' => 'Slug is required']);
        return;
    }

    $scheduleIdSQL = $scheduleId ? $scheduleId : 'NULL';
    $siteIdSQL = $siteId ? $siteId : 'NULL';

    // Check for duplicate
    $check = $db->query("SELECT id FROM {$prefix}update_exceptions
        WHERE schedule_id <=> $scheduleIdSQL AND site_id <=> $siteIdSQL AND type='$type' AND slug='$slug'");
    if ($check->num_rows > 0) {
        $existing = $check->fetch_assoc();
        echo json_encode(['success' => true, 'id' => (int)$existing['id'], 'duplicate' => true]);
        return;
    }

    $db->query("INSERT INTO {$prefix}update_exceptions
        (schedule_id, site_id, type, slug, name, reason)
        VALUES ($scheduleIdSQL, $siteIdSQL, '$type', '$slug', '$name', '$reason')");

    echo json_encode(['success' => true, 'id' => $db->insert_id]);
}

function updateException($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }

    $fields = [];
    if (isset($input['siteId'])) {
        $siteId = $input['siteId'] ? (int)$input['siteId'] : null;
        $fields[] = "site_id = " . ($siteId ? $siteId : "NULL");
    }
    if (isset($input['reason'])) {
        $fields[] = "reason = '" . $db->real_escape_string($input['reason']) . "'";
    }
    if (isset($input['scheduleId'])) {
        $scheduleId = $input['scheduleId'] ? (int)$input['scheduleId'] : null;
        $fields[] = "schedule_id = " . ($scheduleId ? $scheduleId : "NULL");
    }

    if (!empty($fields)) {
        $db->query("UPDATE {$prefix}update_exceptions SET " . implode(', ', $fields) . " WHERE id = $id");
    }

    echo json_encode(['success' => true]);
}

function deleteException($db, $prefix) {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id) {
        $db->query("DELETE FROM {$prefix}update_exceptions WHERE id = $id");
        echo json_encode(['success' => true]);
    }
}

function getHistory($db, $prefix) {
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $scheduleId = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : null;

    $where = $scheduleId ? "WHERE l.schedule_id = $scheduleId" : "";

    $result = $db->query("
        SELECT l.*, su.name as schedule_name
        FROM {$prefix}scheduled_update_log l
        LEFT JOIN {$prefix}scheduled_updates su ON l.schedule_id = su.id
        $where
        ORDER BY l.executed_at DESC
        LIMIT $limit
    ");

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = [
            'id' => (int)$row['id'],
            'scheduleId' => (int)$row['schedule_id'],
            'scheduleName' => $row['schedule_name'],
            'siteId' => (int)$row['site_id'],
            'siteName' => $row['site_name'],
            'type' => $row['type'],
            'itemSlug' => $row['item_slug'],
            'itemName' => $row['item_name'],
            'oldVersion' => $row['old_version'],
            'newVersion' => $row['new_version'],
            'status' => $row['status'],
            'message' => $row['message'],
            'executedAt' => $row['executed_at']
        ];
    }
    echo json_encode($logs);
}

function runNow($db, $prefix) {
    $input = json_decode(file_get_contents('php://input'), true);
    $scheduleId = (int)($input['scheduleId'] ?? 0);

    if (!$scheduleId) {
        http_response_code(400);
        echo json_encode(['error' => 'Schedule ID required']);
        return;
    }

    // Trigger the cron runner for this specific schedule
    $cronUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/cron-runner.php?schedule_id=' . $scheduleId;

    // Use non-blocking request
    $ch = curl_init($cronUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    echo json_encode(['success' => true, 'message' => 'Update run triggered']);
}

function getAllPlugins($db, $prefix) {
    // Get ALL installed plugins and themes across all sites (not just upgradable ones)
    $result = $db->query("SELECT siteID, stats FROM {$prefix}site_stats WHERE stats IS NOT NULL");

    $plugins = [];
    $themes = [];

    while ($row = $result->fetch_assoc()) {
        $stats = @unserialize(@base64_decode($row['stats']));

        // All installed plugins from plugins_status
        if (!empty($stats['plugins_status']) && is_array($stats['plugins_status'])) {
            foreach ($stats['plugins_status'] as $file => $p) {
                $p = (array)$p;
                // Derive slug from file path (e.g. "woocommerce/woocommerce.php" -> "woocommerce")
                $slug = explode('/', $file)[0];
                if (!$slug) continue;

                if (!isset($plugins[$slug])) {
                    $plugins[$slug] = [
                        'slug' => $slug,
                        'file' => $file,
                        'name' => $p['name'] ?? $p['Name'] ?? $slug,
                        'type' => 'plugin',
                        'siteCount' => 0,
                        'hasUpdate' => false
                    ];
                }
                $plugins[$slug]['siteCount']++;
            }
        }

        // Mark plugins that currently have updates available
        if (!empty($stats['upgradable_plugins'])) {
            foreach ($stats['upgradable_plugins'] as $p) {
                $p = (array)$p;
                $slug = $p['slug'] ?? '';
                if ($slug && isset($plugins[$slug])) {
                    $plugins[$slug]['hasUpdate'] = true;
                }
            }
        }

        // All installed themes from themes_status
        if (!empty($stats['themes_status']) && is_array($stats['themes_status'])) {
            $allThemes = array_merge(
                $stats['themes_status']['active'] ?? [],
                $stats['themes_status']['inactive'] ?? []
            );
            foreach ($allThemes as $t) {
                $t = (array)$t;
                $slug = $t['stylesheet'] ?? $t['path'] ?? '';
                if (!$slug) continue;

                if (!isset($themes[$slug])) {
                    $themes[$slug] = [
                        'slug' => $slug,
                        'name' => $t['name'] ?? $slug,
                        'type' => 'theme',
                        'siteCount' => 0,
                        'hasUpdate' => false
                    ];
                }
                $themes[$slug]['siteCount']++;
            }
        }

        // Mark themes with updates
        if (!empty($stats['upgradable_themes'])) {
            foreach ($stats['upgradable_themes'] as $t) {
                $t = (array)$t;
                $slug = $t['theme_tmp'] ?? $t['slug'] ?? '';
                if ($slug && isset($themes[$slug])) {
                    $themes[$slug]['hasUpdate'] = true;
                }
            }
        }
    }

    // Sort alphabetically by name
    usort($plugins, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($themes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    echo json_encode([
        'plugins' => array_values($plugins),
        'themes' => array_values($themes)
    ]);
}

function getDashboard($db, $prefix) {
    // Track updates on every dashboard load (lightweight - INSERT IGNORE)
    trackUpdatesSilent($db, $prefix);

    // Summary stats
    $totalSites = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}sites")->fetch_assoc()['cnt'];
    $sitesWithUpdates = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}site_stats WHERE updatePluginCounts > 0 OR updateThemeCounts > 0 OR isCoreUpdateAvailable = 1")->fetch_assoc()['cnt'];
    $totalPluginUpdates = $db->query("SELECT COALESCE(SUM(updatePluginCounts),0) as cnt FROM {$prefix}site_stats")->fetch_assoc()['cnt'];
    $totalThemeUpdates = $db->query("SELECT COALESCE(SUM(updateThemeCounts),0) as cnt FROM {$prefix}site_stats")->fetch_assoc()['cnt'];
    $activeSchedules = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}scheduled_updates WHERE is_active = 1")->fetch_assoc()['cnt'];
    $totalExceptions = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}update_exceptions")->fetch_assoc()['cnt'];

    // Last runs
    $lastRuns = [];
    $result = $db->query("SELECT schedule_id, MAX(executed_at) as last_run,
        SUM(status='success') as success_count, SUM(status='failed') as fail_count
        FROM {$prefix}scheduled_update_log
        GROUP BY schedule_id
        ORDER BY last_run DESC LIMIT 5");
    while ($row = $result->fetch_assoc()) {
        $lastRuns[] = $row;
    }

    echo json_encode([
        'totalSites' => (int)$totalSites,
        'sitesWithUpdates' => (int)$sitesWithUpdates,
        'totalPluginUpdates' => (int)$totalPluginUpdates,
        'totalThemeUpdates' => (int)$totalThemeUpdates,
        'activeSchedules' => (int)$activeSchedules,
        'totalExceptions' => (int)$totalExceptions,
        'lastRuns' => $lastRuns
    ]);
}

function trackUpdatesSilent($db, $prefix) {
    // Silent version - no output, used internally
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

function trackUpdates($db, $prefix) {
    // API endpoint version - with output
    $result = $db->query("SELECT siteID, stats FROM {$prefix}site_stats WHERE stats IS NOT NULL");
    $tracked = 0;

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
                    if ($db->affected_rows > 0) $tracked++;
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
                    if ($db->affected_rows > 0) $tracked++;
                }
            }
        }
    }

    echo json_encode(['success' => true, 'newlyTracked' => $tracked]);
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
        if (isset($typeMap[$type])) {
            $where .= " AND " . $typeMap[$type];
        }
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

function reportStatus($db, $prefix) {
    $scheduleId = (int)($_GET['scheduleId'] ?? 0);
    if (!$scheduleId) {
        http_response_code(400);
        echo json_encode(['error' => 'scheduleId required']);
        return;
    }

    $report = $db->query("SELECT pdfURL, sentTime FROM {$prefix}client_report_sent_pdf
        WHERE clientReportScheduleID = $scheduleId
        ORDER BY sentTime DESC LIMIT 1")->fetch_assoc();

    if ($report && $report['sentTime'] > (time() - 300)) {
        $pdfUrl = @unserialize(@base64_decode($report['pdfURL']));
        if (!$pdfUrl) $pdfUrl = $report['pdfURL'];
        $url = 'https://' . APP_DOMAIN_PATH . 'uploads/' . $pdfUrl;
        echo json_encode(['status' => 'ready', 'url' => $url]);
    } else {
        $pending = $db->query("SELECT COUNT(*) as cnt FROM {$prefix}history
            WHERE type='clientReporting' AND status IN('pending','initiated','running')")->fetch_assoc();
        echo json_encode(['status' => 'generating', 'pendingJobs' => (int)$pending['cnt']]);
    }
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
    $args = escapeshellarg($siteId);
    $output = shell_exec("php $root/scheduler/mcp/generate-report.php $args 2>&1");

    // Output is already JSON from generate-report.php, pass through directly
    $cleaned = preg_replace('/^(PHP\s|Deprecated|Warning|Notice).*\n/m', '', $output);
    echo trim($cleaned);
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

    // Call the PHP helper script
    $root = APP_ROOT;
    $args = escapeshellarg($siteId) . ' ' . escapeshellarg($type);
    if (!empty($slugs)) $args .= ' --slugs=' . escapeshellarg(implode(',', $slugs));
    if (!empty($exclude)) $args .= ' --exclude=' . escapeshellarg(implode(',', $exclude));

    $output = shell_exec("php $root/scheduler/mcp/run-update.php $args 2>&1");

    echo json_encode(['success' => true, 'output' => $output]);
}

function calculateNextRun($time, $daysOfWeek) {
    $days = array_map('intval', explode(',', $daysOfWeek));
    $now = new DateTime('now', new DateTimeZone('Europe/Amsterdam'));
    $candidate = clone $now;
    $candidate->setTime(...array_map('intval', explode(':', $time)));

    // Check up to 8 days ahead
    for ($i = 0; $i < 8; $i++) {
        $dayOfWeek = (int)$candidate->format('w');
        if (in_array($dayOfWeek, $days) && $candidate > $now) {
            return $candidate->format('Y-m-d H:i:s');
        }
        $candidate->modify('+1 day');
        $candidate->setTime(...array_map('intval', explode(':', $time)));
    }

    return $candidate->format('Y-m-d H:i:s');
}
