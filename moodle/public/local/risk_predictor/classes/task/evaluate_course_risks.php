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
 * Scheduled task to periodically evaluate student academic risk across courses.
 *
 * @package    local_risk_predictor
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_risk_predictor\task;

use local_risk_predictor\risk_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Periodic batch task for course-level early warning risk evaluation.
 */
class evaluate_course_risks extends \core\task\scheduled_task {
    /**
     * Return localized task name for Moodle admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_evaluate_course_risks', 'local_risk_predictor');
    }

    /**
     * Execute periodic risk evaluation for all configured or snapshotted courses.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $sql = "SELECT DISTINCT c.id
                  FROM {course} c
             LEFT JOIN {local_risk_configs} rc ON rc.course_id = c.id
             LEFT JOIN {local_risk_snapshots} rs ON rs.course_id = c.id
                 WHERE c.id <> :siteid
                   AND (rc.id IS NOT NULL OR rs.id IS NOT NULL)";

        $courseids = $DB->get_fieldset_sql($sql, ['siteid' => SITEID]);
        if (empty($courseids)) {
            mtrace('local_risk_predictor: No courses configured or snapshotted for evaluation.');
            return;
        }

        foreach ($courseids as $courseid) {
            $results = risk_manager::evaluate_course((int)$courseid);
            mtrace("local_risk_predictor: Evaluated " . count($results) . " student(s) in course ID {$courseid}.");
        }
    }
}
