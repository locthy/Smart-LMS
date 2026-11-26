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
use local_ai_qa\scope;

/**
 * List the documents indexed for the course of a context.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_documents extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context of the page the user is on'),
        ]);
    }

    /**
     * List documents.
     *
     * @param int $contextid
     * @return array
     */
    public static function execute(int $contextid): array {
        ['contextid' => $contextid] = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid]);
        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        if (!api_client::is_configured()) {
            throw new \moodle_exception('notconfigured', 'local_ai_qa');
        }
        $scope = scope::for_context($context);
        if (!$scope->is_usable()) {
            throw new \required_capability_exception($context, 'local/ai_qa:use', 'nopermissions', '');
        }

        $client = new api_client();
        $documents = [];
        foreach ($scope->courses as $courseid => $coursename) {
            foreach ($client->documents($courseid) as $doc) {
                $documents[] = [
                    'name' => (string) $doc['name'],
                    'url' => (string) ($doc['url'] ?? ''),
                    'coursename' => $coursename,
                    'pages' => (int) $doc['pages'],
                    'chunks' => (int) $doc['chunks'],
                ];
            }
        }
        return ['documents' => $documents, 'canmanage' => $scope->canmanage];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'documents' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Document name'),
                'url' => new external_value(PARAM_URL, 'Link to the activity'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'pages' => new external_value(PARAM_INT, 'Pages'),
                'chunks' => new external_value(PARAM_INT, 'Indexed passages'),
            ])),
            'canmanage' => new external_value(PARAM_BOOL, 'Whether the user can sync'),
        ]);
    }
}
