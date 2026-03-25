<?php
// Helper: decode IWP serialized stats and return readable update info
// Usage: php get-updates.php <siteID> [full]

require_once(dirname(__FILE__) . '/../../config.php');

$siteId = (int)($argv[1] ?? 0);
$mode = $argv[2] ?? 'updates'; // 'updates' or 'full'

if (!$siteId) { echo "No site ID\n"; exit(1); }

$db = new mysqli($config['SQL_HOST'], $config['SQL_USERNAME'], $config['SQL_PASSWORD'], $config['SQL_DATABASE'], (int)$config['SQL_PORT']);
$db->set_charset('utf8mb4');
$prefix = $config['SQL_TABLE_NAME_PREFIX'];

$row = $db->query("SELECT stats FROM {$prefix}site_stats WHERE siteID = $siteId")->fetch_assoc();
if (!$row || !$row['stats']) { echo "No data available\n"; exit; }

$stats = @unserialize(@base64_decode($row['stats']));
if (!$stats) { echo "Could not decode stats\n"; exit; }

$output = [];

// Upgradable plugins
if (!empty($stats['upgradable_plugins'])) {
    $output[] = "**Plugin updates available:**";
    foreach ($stats['upgradable_plugins'] as $p) {
        $p = (array)$p;
        $output[] = "  - {$p['name']} ({$p['slug']}): {$p['old_version']} -> {$p['new_version']}";
    }
} else {
    $output[] = "**Plugins**: all up-to-date";
}

// Upgradable themes
if (!empty($stats['upgradable_themes'])) {
    $output[] = "\n**Theme updates available:**";
    foreach ($stats['upgradable_themes'] as $t) {
        $t = (array)$t;
        $output[] = "  - {$t['name']}: {$t['old_version']} -> {$t['new_version']}";
    }
} else {
    $output[] = "**Themes**: all up-to-date";
}

// Core
if (!empty($stats['core_updates'])) {
    $core = (array)$stats['core_updates'];
    $output[] = "\n**WordPress Core**: {$core['current']} -> " . ($core['new_version'] ?? $core['version'] ?? '?');
}

// Translations
if (!empty($stats['upgradable_translations'])) {
    $output[] = "**Translations**: update available";
}

// Full mode: also show installed plugins/themes
if ($mode === 'full') {
    if (!empty($stats['plugins_status'])) {
        $active = 0;
        $inactive = 0;
        foreach ($stats['plugins_status'] as $p) {
            if ($p['isActivated']) $active++;
            else $inactive++;
        }
        $output[] = "\n**Installed plugins**: " . count($stats['plugins_status']) . " total ($active active, $inactive inactive)";
    }

    if (!empty($stats['themes_status'])) {
        $activeThemes = count($stats['themes_status']['active'] ?? []);
        $inactiveThemes = count($stats['themes_status']['inactive'] ?? []);
        $output[] = "**Installed themes**: " . ($activeThemes + $inactiveThemes) . " total";
    }

    $output[] = "**PHP version**: " . ($stats['php_version'] ?? 'unknown');
    $output[] = "**MySQL version**: " . ($stats['mysql_version'] ?? 'unknown');
}

echo implode("\n", $output) . "\n";
$db->close();
