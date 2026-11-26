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

namespace local_ai_qa\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_ai_qa\api_client;
use local_ai_qa\course_sync;

/**
 * Index a course's files into the document assistant.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_course extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Sync.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        ['courseid' => $courseid] = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('local/ai_qa:manage', $context);
        if (!api_client::is_configured()) {
            throw new \moodle_exception('notconfigured', 'local_ai_qa');
        }
        return course_sync::sync($courseid);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'added' => new external_value(PARAM_INT, 'Newly indexed files'),
            'unchanged' => new external_value(PARAM_INT, 'Files already indexed'),
            'skipped' => new external_value(PARAM_INT, 'Unsupported files'),
            'removed' => new external_value(PARAM_INT, 'Documents removed from the index'),
            'errors' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Error')),
        ]);
    }
}
