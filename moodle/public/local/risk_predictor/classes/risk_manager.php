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
 * Risk evaluation orchestrator and database persistence manager.
 *
 * @package    local_risk_predictor
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_risk_predictor;

defined('MOODLE_INTERNAL') || die();

/**
 * Coordinates feature evaluation, explanation generation, and DB persistence.
 */
class risk_manager {
    /**
     * Upsert a student feature snapshot into local_risk_snapshots.
     *
     * @param object|array $features Snapshot data matching local_risk_snapshots columns.
     * @return int The snapshot record ID.
     */
    public static function save_snapshot($features): int {
        global $DB;

        $f = (object)$features;
        $now = time();
        $currentweek = max(1, (int)($f->current_week ?? 1));

        $record = (object)[
            'user_id' => (int)$f->user_id,
            'course_id' => (int)$f->course_id,
            'current_week' => $currentweek,
            'total_weeks' => max(1, (int)($f->total_weeks ?? 15)),
            'max_consecutive_days_without_access' => (int)($f->max_consecutive_days_without_access ?? 0),
            'days_with_logs_avg' => round((float)($f->days_with_logs_avg ?? 0.0), 4),
            'first_log_days_diff' => (int)($f->first_log_days_diff ?? 0),
            'missed_assigns' => (int)($f->missed_assigns ?? 0),
            'delay_avg' => round((float)($f->delay_avg ?? 0.0), 4),
            'quiz_avg_score' => round((float)($f->quiz_avg_score ?? 0.0), 4),
            'snapshot_date' => (int)($f->snapshot_date ?? $now),
        ];

        $existing = $DB->get_record('local_risk_snapshots', [
            'user_id' => $record->user_id,
            'course_id' => $record->course_id,
            'current_week' => $record->current_week,
        ]);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_risk_snapshots', $record);
            return (int)$existing->id;
        }

        return (int)$DB->insert_record('local_risk_snapshots', $record);
    }

    /**
     * Record a risk insight evaluation into local_risk_insights.
     *
     * @param int $snapshotid Snapshot ID in local_risk_snapshots.
     * @param int $userid Student user ID.
     * @param int $courseid Course ID.
     * @param \stdClass $evaluation Result from heuristic_evaluator::evaluate().
     * @param string $advice Pedagogical explanation text.
     * @param \stdClass|null $emaildraft Optional email draft with subject and body.
     * @return int The insight record ID.
     */
    public static function save_insight(
        int $snapshotid,
        int $userid,
        int $courseid,
        \stdClass $evaluation,
        string $advice,
        ?\stdClass $emaildraft = null
    ): int {
        global $DB;

        $payload = [
            'engine' => 'heuristic_v0',
            'feature_contributions' => $evaluation->feature_contributions ?? [],
            'triggered_rules' => $evaluation->triggered_rules ?? [],
        ];
        if ($emaildraft !== null) {
            $payload['email_draft'] = [
                'subject' => $emaildraft->subject ?? '',
                'body' => $emaildraft->body ?? '',
            ];
        }

        $record = (object)[
            'snapshot_id' => $snapshotid,
            'user_id' => $userid,
            'course_id' => $courseid,
            'risk_level' => (string)$evaluation->risk_level,
            'risk_score' => round((float)$evaluation->risk_score, 4),
            'top_explanations' => $advice,
            'shap_values' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'evaluated_at' => time(),
        ];

        $existing = $DB->get_record('local_risk_insights', [
            'snapshot_id' => $snapshotid,
            'user_id' => $userid,
            'course_id' => $courseid,
        ]);

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_risk_insights', $record);
            return (int)$existing->id;
        }

        return (int)$DB->insert_record('local_risk_insights', $record);
    }

    /**
     * Evaluate a single student feature vector and persist both snapshot and insight.
     *
     * @param \stdClass $student Student user record.
     * @param \stdClass $course Course record.
     * @param object|array $features Feature snapshot.
     * @param \stdClass|null $config Optional course config override.
     * @param string|null $lang Optional explicit language ('en' or 'vi').
     * @return \stdClass Summary object with snapshot_id, insight_id, evaluation, advice, email_draft.
     */
    public static function evaluate_student(
        \stdClass $student,
        \stdClass $course,
        $features,
        ?\stdClass $config = null,
        ?string $lang = null
    ): \stdClass {
        $f = (object)$features;
        $f->user_id = (int)$student->id;
        $f->course_id = (int)$course->id;

        if ($config === null) {
            $config = heuristic_evaluator::get_course_config((int)$course->id);
        }
        if (empty($f->total_weeks)) {
            $f->total_weeks = (int)$config->total_weeks;
        }

        $evaluation = heuristic_evaluator::evaluate($f, $config);
        $advice = intervention_advisor::generateAdvice($evaluation, $f, $lang, $course);
        $emaildraft = intervention_advisor::draftInterventionEmail($student, $course, $evaluation, $advice, $lang);

        $snapshotid = self::save_snapshot($f);
        $insightid = self::save_insight(
            $snapshotid,
            (int)$student->id,
            (int)$course->id,
            $evaluation,
            $advice,
            $emaildraft
        );

        return (object)[
            'snapshot_id' => $snapshotid,
            'insight_id' => $insightid,
            'user_id' => (int)$student->id,
            'course_id' => (int)$course->id,
            'evaluation' => $evaluation,
            'advice' => $advice,
            'email_draft' => $emaildraft,
        ];
    }

    /**
     * Evaluate all students in a course using existing snapshots (or Branch 3 feature_extractor if available).
     *
     * @param int $courseid Course ID.
     * @param int|null $currentweek Optional specific week to evaluate.
     * @param string|null $lang Optional explicit language ('en' or 'vi').
     * @return array List of evaluation result objects.
     */
    public static function evaluate_course(int $courseid, ?int $currentweek = null, ?string $lang = null): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $config = heuristic_evaluator::get_course_config($courseid);

        // If Branch 3's feature_extractor is present, let it refresh snapshots first.
        if (class_exists('\\local_risk_predictor\\feature_extractor')
            && method_exists('\\local_risk_predictor\\feature_extractor', 'extract_course_snapshots')) {
            \local_risk_predictor\feature_extractor::extract_course_snapshots($courseid, $currentweek);
        }

        if ($currentweek === null) {
            $latestweek = $DB->get_field_sql(
                'SELECT MAX(current_week) FROM {local_risk_snapshots} WHERE course_id = :courseid',
                ['courseid' => $courseid]
            );
            $currentweek = $latestweek ? (int)$latestweek : 1;
        }

        $snapshots = $DB->get_records('local_risk_snapshots', [
            'course_id' => $courseid,
            'current_week' => $currentweek,
        ]);

        if (empty($snapshots)) {
            return [];
        }

        $results = [];
        foreach ($snapshots as $snapshot) {
            $student = $DB->get_record('user', ['id' => $snapshot->user_id, 'deleted' => 0]);
            if (!$student) {
                continue;
            }
            $results[] = self::evaluate_student($student, $course, $snapshot, $config, $lang);
        }

        return $results;
    }
}
