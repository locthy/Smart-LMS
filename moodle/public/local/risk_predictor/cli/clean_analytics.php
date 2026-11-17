<?php
define('CLI_SCRIPT', true);

// Fast-path help output without requiring DB connection
if (isset($argv) && (in_array('--help', $argv, true) || in_array('-h', $argv, true))) {
    $help = <<<HELP
Synthetic Analytics Data Cleanup CLI (LOCAL TEST SITE ONLY)

Purges synthetic test courses, users, grade items, and generated logs
matching the specified prefix without affecting existing site data.

Options:
  -h, --help            Show this help message.
  --prefix=TEXT         Prefix of synthetic users and courses to clean (default: AISEED).

Example:
  php moodle/public/local/risk_predictor/cli/clean_analytics.php --prefix=AISEED
HELP;
    echo $help . PHP_EOL;
    exit(0);
}

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

global $DB;

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'prefix' => 'AISEED',
    ],
    ['h' => 'help']
);

if ($options['help']) {
    $help = <<<HELP
Synthetic Analytics Data Cleanup CLI (LOCAL TEST SITE ONLY)

Purges synthetic test courses, users, grade items, and generated logs
matching the specified prefix without affecting existing site data.

Options:
  -h, --help            Show this help message.
  --prefix=TEXT         Prefix of synthetic users and courses to clean (default: AISEED).

Example:
  php moodle/public/local/risk_predictor/cli/clean_analytics.php --prefix=AISEED
HELP;
    echo $help . PHP_EOL;
    exit(0);
}

$prefix = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$options['prefix']));
echo "Cleaning up synthetic analytics data with prefix: {$prefix}...\n";

// 1. Delete Courses with prefix
$courses = $DB->get_records_select('course', "shortname LIKE :prefix", ['prefix' => "{$prefix}%"]);
foreach ($courses as $c) {
    echo "Deleting course {$c->shortname} (ID: {$c->id})...\n";
    delete_course($c->id, false);
}

// 2. Delete Users with prefix
$users = $DB->get_records_select('user', "username LIKE :prefix AND deleted = 0", ['prefix' => strtolower($prefix) . '%']);
foreach ($users as $u) {
    echo "Deleting user {$u->username} (ID: {$u->id})...\n";
    user_delete_user($u);
}

// 3. Purge standard logstore rows generated for this prefix
$DB->delete_records_select('logstore_standard_log', "origin = 'web' AND ip = '127.0.0.1' AND courseid NOT IN (SELECT id FROM {course})");

echo "\n Cleanup completed successfully!\n";