<?php
/************************************************************
 * IWP Update Scheduler - Database Installation
 * Creates required tables for scheduled updates.
 *
 * Run once after deploying the scheduler files.
 * Access is blocked by .htaccess — run via CLI:
 *   php install.php
 ************************************************************/

require_once(dirname(__FILE__) . '/../config.php');

$db = new mysqli(
    $config['SQL_HOST'],
    $config['SQL_USERNAME'],
    $config['SQL_PASSWORD'],
    $config['SQL_DATABASE'],
    (int)$config['SQL_PORT']
);

if ($db->connect_error) {
    die("DB connection failed: " . $db->connect_error);
}

$prefix = $config['SQL_TABLE_NAME_PREFIX'];

$queries = [
    // Scheduled update jobs
    "CREATE TABLE IF NOT EXISTS `{$prefix}scheduled_updates` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `schedule_time` TIME NOT NULL DEFAULT '02:00:00',
        `days_of_week` VARCHAR(50) NOT NULL DEFAULT '1,2,3,4,5,6,0' COMMENT 'comma-separated 0=Sun 6=Sat',
        `update_plugins` TINYINT(1) NOT NULL DEFAULT 1,
        `update_themes` TINYINT(1) NOT NULL DEFAULT 1,
        `update_core` TINYINT(1) NOT NULL DEFAULT 0,
        `update_translations` TINYINT(1) NOT NULL DEFAULT 1,
        `site_ids` TEXT DEFAULT NULL COMMENT 'comma-separated siteIDs, NULL=all sites',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `last_run` DATETIME DEFAULT NULL,
        `next_run` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Exceptions: plugins/themes to skip
    "CREATE TABLE IF NOT EXISTS `{$prefix}update_exceptions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `schedule_id` INT DEFAULT NULL COMMENT 'NULL=global exception for all schedules',
        `site_id` INT DEFAULT NULL COMMENT 'NULL=all sites',
        `type` ENUM('plugin','theme') NOT NULL,
        `slug` VARCHAR(255) NOT NULL,
        `name` VARCHAR(255) NOT NULL,
        `reason` VARCHAR(500) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_schedule` (`schedule_id`),
        INDEX `idx_site` (`site_id`),
        INDEX `idx_slug` (`type`, `slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Execution log
    "CREATE TABLE IF NOT EXISTS `{$prefix}scheduled_update_log` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `schedule_id` INT NOT NULL,
        `site_id` INT NOT NULL,
        `site_name` VARCHAR(255) DEFAULT NULL,
        `type` ENUM('plugin','theme','core','translation') NOT NULL,
        `item_slug` VARCHAR(255) DEFAULT NULL,
        `item_name` VARCHAR(255) DEFAULT NULL,
        `old_version` VARCHAR(50) DEFAULT NULL,
        `new_version` VARCHAR(50) DEFAULT NULL,
        `status` ENUM('queued','running','success','failed','skipped') NOT NULL DEFAULT 'queued',
        `message` TEXT DEFAULT NULL,
        `iwp_history_id` INT DEFAULT NULL,
        `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_schedule` (`schedule_id`),
        INDEX `idx_site` (`site_id`),
        INDEX `idx_executed` (`executed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

$results = [];
foreach ($queries as $sql) {
    if ($db->query($sql)) {
        preg_match('/`([^`]+)`/', $sql, $m);
        $results[] = "OK: {$m[1]}";
    } else {
        $results[] = "ERROR: " . $db->error;
    }
}

$db->close();

header('Content-Type: application/json');
echo json_encode(['status' => 'done', 'tables' => $results]);
