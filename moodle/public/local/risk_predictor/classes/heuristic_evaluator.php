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
 * Multi-level heuristic risk evaluator (Cold Start v0).
 *
 * @package    local_risk_predictor
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_risk_predictor;

defined('MOODLE_INTERNAL') || die();

/**
 * Evaluates student risk tiers (SAFE, BORDERLINE, AT_RISK) using course thresholds.
 */
class heuristic_evaluator {
    /** @var string Student is on track. */
    public const RISK_SAFE = 'SAFE';

    /** @var string Student shows moderate warning signs. */
    public const RISK_BORDERLINE = 'BORDERLINE';

    /** @var string Student is at high risk of failing or dropping out. */
    public const RISK_AT_RISK = 'AT_RISK';

    /**
     * Return default threshold configuration matching install.xml defaults.
     *
     * @param int $courseid Course ID.
     * @return \stdClass
     */
    public static function get_default_config(int $courseid = 0): \stdClass {
        return (object)[
            'course_id' => $courseid,
            'total_weeks' => 15,
            'risk_threshold' => 0.5000,
            'inactivity_days_threshold' => 7,
            'quiz_drop_pct_threshold' => 20.0000,
            'missed_assign_threshold' => 2,
            'updated_at' => 0,
        ];
    }

    /**
     * Load course-level early warning configuration or fall back to defaults.
     *
     * @param int $courseid Course ID.
     * @return \stdClass
     */
    public static function get_course_config(int $courseid): \stdClass {
        global $DB;

        $config = $DB->get_record('local_risk_configs', ['course_id' => $courseid]);
        if ($config) {
            return $config;
        }
        return self::get_default_config($courseid);
    }

    /**
     * Evaluate a student's feature snapshot against course heuristic thresholds.
     *
     * @param object|array $features Feature snapshot matching local_risk_snapshots fields.
     * @param \stdClass|null $config Course threshold configuration.
     * @return \stdClass Evaluation result with risk_level, risk_score, triggered_rules, feature_contributions.
     */
    public static function evaluate($features, ?\stdClass $config = null): \stdClass {
        $f = (object)$features;
        $courseid = (int)($f->course_id ?? 0);
        if ($config === null) {
            $config = $courseid > 0 ? self::get_course_config($courseid) : self::get_default_config();
        }

        $inactivitylimit = max(1, (int)$config->inactivity_days_threshold);
        $missedlimit = max(1, (int)$config->missed_assign_threshold);
        $quizdroplimit = max(1.0, (float)$config->quiz_drop_pct_threshold);
        $riskthreshold = min(0.95, max(0.10, (float)$config->risk_threshold));

        $maxinactive = (int)($f->max_consecutive_days_without_access ?? 0);
        $dayswithlogs = (float)($f->days_with_logs_avg ?? 0.0);
        $firstlogdiff = (int)($f->first_log_days_diff ?? 0);
        $missedassigns = (int)($f->missed_assigns ?? 0);
        $delayavg = (float)($f->delay_avg ?? 0.0);
        $quizavg = (float)($f->quiz_avg_score ?? 0.0);
        $quizdroppct = isset($f->quiz_drop_pct) ? (float)$f->quiz_drop_pct : 0.0;

        $contributions = [
            'max_consecutive_days_without_access' => 0.0,
            'missed_assigns' => 0.0,
            'quiz_avg_score' => 0.0,
            'days_with_logs_avg' => 0.0,
            'delay_avg' => 0.0,
            'first_log_days_diff' => 0.0,
        ];
        $triggered = [];
        $severecount = 0;
        $moderatecount = 0;

        // 1. Inactivity gap (max weight: 0.30).
        if ($maxinactive >= $inactivitylimit * 2) {
            $contributions['max_consecutive_days_without_access'] = 0.30;
            $severecount++;
            $triggered[] = (object)[
                'code' => 'inactivity_severe',
                'severity' => 'high',
                'feature' => 'max_consecutive_days_without_access',
                'value' => $maxinactive,
                'threshold' => $inactivitylimit * 2,
            ];
        } else if ($maxinactive >= $inactivitylimit) {
            $contributions['max_consecutive_days_without_access'] = 0.18;
            $moderatecount++;
            $triggered[] = (object)[
                'code' => 'inactivity_moderate',
                'severity' => 'medium',
                'feature' => 'max_consecutive_days_without_access',
                'value' => $maxinactive,
                'threshold' => $inactivitylimit,
            ];
        }

        // 2. Missed assignments (max weight: 0.28).
        if ($missedassigns >= $missedlimit) {
            $contributions['missed_assigns'] = 0.28;
            $severecount++;
            $triggered[] = (object)[
                'code' => 'missed_assigns_high',
                'severity' => 'high',
                'feature' => 'missed_assigns',
                'value' => $missedassigns,
                'threshold' => $missedlimit,
            ];
        } else if ($missedassigns >= 1) {
            $contributions['missed_assigns'] = 0.14;
            $moderatecount++;
            $triggered[] = (object)[
                'code' => 'missed_assigns_moderate',
                'severity' => 'medium',
                'feature' => 'missed_assigns',
                'value' => $missedassigns,
                'threshold' => 1,
            ];
        }

        // 3. Quiz / academic score performance (max weight: 0.24).
        if ($quizavg < 40.0) {
            $contributions['quiz_avg_score'] = 0.24;
            $severecount++;
            $triggered[] = (object)[
                'code' => 'quiz_score_critical',
                'severity' => 'high',
                'feature' => 'quiz_avg_score',
                'value' => round($quizavg, 2),
                'threshold' => 40.0,
            ];
        } else if ($quizavg < 65.0) {
            $contributions['quiz_avg_score'] = 0.12;
            $moderatecount++;
            $triggered[] = (object)[
                'code' => 'quiz_score_low',
                'severity' => 'medium',
                'feature' => 'quiz_avg_score',
                'value' => round($quizavg, 2),
                'threshold' => 65.0,
            ];
        }

        if ($quizdroppct >= $quizdroplimit) {
            $contributions['quiz_avg_score'] = min(0.28, $contributions['quiz_avg_score'] + 0.10);
            $moderatecount++;
            $triggered[] = (object)[
                'code' => 'quiz_score_drop',
                'severity' => 'medium',
                'feature' => 'quiz_drop_pct',
                'value' => round($quizdroppct, 2),
                'threshold' => $quizdroplimit,
            ];
        }

        // 4. Weekly active days frequency (max weight: 0.10).
        if ($dayswithlogs < 0.75) {
            $contributions['days_with_logs_avg'] = 0.10;
            $moderatecount++;
            $triggered[] = (object)[
                'code' => 'low_access_frequency',
                'severity' => 'medium',
                'feature' => 'days_with_logs_avg',
                'value' => round($dayswithlogs, 2),
                'threshold' => 0.75,
            ];
        } else if ($dayswithlogs < 1.5) {
            $contributions['days_with_logs_avg'] = 0.05;
            $triggered[] = (object)[
                'code' => 'declining_access_frequency',
                'severity' => 'low',
                'feature' => 'days_with_logs_avg',
                'value' => round($dayswithlogs, 2),
                'threshold' => 1.5,
            ];
        }

        // 5. Submission delay (max weight: 0.05).
        if ($delayavg > 48.0) {
            $contributions['delay_avg'] = 0.05;
            $moderatecount++;
            $triggered[] = (object)[
                'code' => 'submission_delay_high',
                'severity' => 'medium',
                'feature' => 'delay_avg',
                'value' => round($delayavg, 2),
                'threshold' => 48.0,
            ];
        } else if ($delayavg > 12.0) {
            $contributions['delay_avg'] = 0.02;
            $triggered[] = (object)[
                'code' => 'submission_delay_moderate',
                'severity' => 'low',
                'feature' => 'delay_avg',
                'value' => round($delayavg, 2),
                'threshold' => 12.0,
            ];
        }

        // 6. Late initial course access (max weight: 0.03).
        if ($firstlogdiff > 14) {
            $contributions['first_log_days_diff'] = 0.03;
            $triggered[] = (object)[
                'code' => 'late_course_start',
                'severity' => 'low',
                'feature' => 'first_log_days_diff',
                'value' => $firstlogdiff,
                'threshold' => 14,
            ];
        }

        $score = min(1.0, max(0.0, array_sum($contributions)));
        $score = round($score, 4);

        if ($score >= $riskthreshold || $severecount >= 2) {
            $level = self::RISK_AT_RISK;
        } else if ($score >= ($riskthreshold * 0.5) || $severecount === 1 || $moderatecount >= 1) {
            $level = self::RISK_BORDERLINE;
        } else {
            $level = self::RISK_SAFE;
        }

        return (object)[
            'risk_level' => $level,
            'risk_score' => $score,
            'triggered_rules' => $triggered,
            'feature_contributions' => $contributions,
        ];
    }
}
