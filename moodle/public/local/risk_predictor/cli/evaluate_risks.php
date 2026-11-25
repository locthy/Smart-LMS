<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to run heuristic risk evaluation and store snapshots/insights.
 *
 * @package    local_risk_predictor
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

if (isset($argv) && (in_array('--help', $argv, true) || in_array('-h', $argv, true))) {
    $help = <<<HELP
Run Heuristic Early Warning Risk Evaluation (Branch 4)

Evaluates student feature snapshots against course thresholds in mdl_local_risk_configs,
generates Vietnamese pedagogical explanations and intervention email drafts, and saves
results into mdl_local_risk_snapshots and mdl_local_risk_insights.

Options:
  -h, --help            Show this help message.
  --courseid=ID         Target course ID (if omitted, resolves courses matching --prefix).
  --prefix=TEXT         Course shortname prefix to evaluate (default: AISEED).
  --week=N              Current evaluation week number (default: 10).
  --lang=CODE           Output language for advice/email drafts: en or vi (default: auto from course).
  --seed-snapshots      Compute fallback snapshots from Moodle logs/grades if none exist yet.

Example:
  php moodle/public/local/risk_predictor/cli/evaluate_risks.php --prefix=AISEED --seed-snapshots
  php moodle/public/local/risk_predictor/cli/evaluate_risks.php --prefix=AISEED --lang=en --seed-snapshots
HELP;
    echo $help . PHP_EOL;
    exit(0);
}

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_risk_predictor\heuristic_evaluator;
use local_risk_predictor\risk_manager;

global $DB;

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'courseid' => 0,
        'prefix' => 'AISEED',
        'week' => 10,
        'lang' => '',
        'seed-snapshots' => false,
    ],
    ['h' => 'help']
);

$courseid = (int)$options['courseid'];
$prefix = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$options['prefix']));
$week = max(1, (int)$options['week']);
$lang = trim((string)$options['lang']);
$lang = $lang !== '' ? $lang : null;
$seedsnapshots = !empty($options['seed-snapshots']);

if ($courseid > 0) {
    $courses = $DB->get_records('course', ['id' => $courseid]);
} else {
    $like = $DB->sql_like('shortname', ':prefix', false);
    $courses = $DB->get_records_sql(
        "SELECT * FROM {course} WHERE {$like} ORDER BY id ASC",
        ['prefix' => $DB->sql_like_escape($prefix) . '%']
    );
}

if (empty($courses)) {
    fwrite(STDERR, "No matching courses found.\n");
    exit(1);
}

foreach ($courses as $course) {
    echo "\n=== Evaluating Course: {$course->shortname} (ID: {$course->id}) ===\n";
    $config = heuristic_evaluator::get_course_config((int)$course->id);

    $existing = $DB->get_records('local_risk_snapshots', [
        'course_id' => $course->id,
        'current_week' => $week,
    ]);

    if (empty($existing) && $seedsnapshots) {
        $context = context_course::instance($course->id);
        $students = get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*');
        $now = time();
        $assigncount = (int)$DB->count_records('assign', ['course' => $course->id]);

        foreach ($students as $student) {
            $lastlog = (int)$DB->get_field_sql(
                'SELECT MAX(timecreated) FROM {logstore_standard_log} WHERE userid = :uid AND courseid = :cid',
                ['uid' => $student->id, 'cid' => $course->id]
            );
            $logcount = (int)$DB->count_records('logstore_standard_log', [
                'userid' => $student->id,
                'courseid' => $course->id,
            ]);
            $submitted = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT s.assignment)
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment
                  WHERE a.course = :cid AND s.userid = :uid AND s.status = 'submitted'",
                ['cid' => $course->id, 'uid' => $student->id]
            );
            $avggrade = (float)$DB->get_field_sql(
                "SELECT COALESCE(AVG(g.finalgrade), 0)
                   FROM {grade_grades} g
                   JOIN {grade_items} gi ON gi.id = g.itemid
                  WHERE gi.courseid = :cid AND g.userid = :uid AND gi.itemtype = 'mod'",
                ['cid' => $course->id, 'uid' => $student->id]
            );

            $inactivedays = $lastlog > 0 ? (int)floor(($now - $lastlog) / DAYSECS) : 30;
            $missed = max(0, $assigncount - $submitted);
            $daysavg = round(min(7.0, $logcount / max(1, $week)), 2);

            risk_manager::save_snapshot((object)[
                'user_id' => $student->id,
                'course_id' => $course->id,
                'current_week' => $week,
                'total_weeks' => (int)$config->total_weeks,
                'max_consecutive_days_without_access' => $inactivedays,
                'days_with_logs_avg' => $daysavg,
                'first_log_days_diff' => 1,
                'missed_assigns' => $missed,
                'delay_avg' => $missed > 0 ? 24.0 * $missed : 0.0,
                'quiz_avg_score' => round($avggrade, 2),
                'snapshot_date' => $now,
            ]);
        }
    }

    $results = risk_manager::evaluate_course((int)$course->id, $week, $lang);
    if (empty($results)) {
        echo "No snapshots found for week {$week}. Run Branch 3 extractor or pass --seed-snapshots.\n";
        continue;
    }

    $counts = [
        heuristic_evaluator::RISK_SAFE => 0,
        heuristic_evaluator::RISK_BORDERLINE => 0,
        heuristic_evaluator::RISK_AT_RISK => 0,
    ];

    foreach ($results as $res) {
        $student = $DB->get_record('user', ['id' => $res->user_id], 'id,username,firstname,lastname');
        $level = $res->evaluation->risk_level;
        $counts[$level] = ($counts[$level] ?? 0) + 1;
        echo sprintf(
            "- %-22s | %-10s | score=%.4f | snapshot=%d | insight=%d\n  Advice: %s\n  Email:  %s\n",
            $student->username ?? $res->user_id,
            $level,
            $res->evaluation->risk_score,
            $res->snapshot_id,
            $res->insight_id,
            str_replace("\n", "\n          ", $res->advice),
            $res->email_draft->subject
        );
    }

    echo sprintf(
        "Summary: SAFE=%d, BORDERLINE=%d, AT_RISK=%d (Total=%d)\n",
        $counts[heuristic_evaluator::RISK_SAFE],
        $counts[heuristic_evaluator::RISK_BORDERLINE],
        $counts[heuristic_evaluator::RISK_AT_RISK],
        count($results)
    );
}
