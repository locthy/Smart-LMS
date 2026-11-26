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
 * Ask the document assistant a question.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ask extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context of the page the user is on'),
            'question' => new external_value(PARAM_RAW, 'The question'),
            'history' => new external_value(PARAM_RAW, 'JSON list of previous turns', VALUE_DEFAULT, '[]'),
        ]);
    }

    /**
     * Ask.
     *
     * @param int $contextid
     * @param string $question
     * @param string $history
     * @return array
     */
    public static function execute(int $contextid, string $question, string $history = '[]'): array {
        [
            'contextid' => $contextid,
            'question' => $question,
            'history' => $history,
        ] = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'question' => $question,
            'history' => $history,
        ]);

        $context = \context::instance_by_id($contextid);
        self::validate_context($context);

        if (!api_client::is_configured()) {
            throw new \moodle_exception('notconfigured', 'local_ai_qa');
        }
        $scope = scope::for_context($context);
        if (!$scope->is_usable()) {
            throw new \required_capability_exception($context, 'local/ai_qa:use', 'nopermissions', '');
        }

        $question = trim(\core_text::substr($question, 0, 2000));
        if ($question === '') {
            throw new \invalid_parameter_exception('Empty question');
        }

        // Keep only well-formed recent turns.
        $turns = [];
        foreach (array_slice((array) json_decode($history, true), -6) as $turn) {
            if (is_array($turn) && in_array($turn['role'] ?? '', ['user', 'assistant']) && is_string($turn['content'] ?? null)) {
                $turns[] = ['role' => $turn['role'], 'content' => \core_text::substr($turn['content'], 0, 4000)];
            }
        }

        $client = new api_client();
        $response = $client->ask($question, array_keys($scope->courses), $scope->isteacher ? 'teacher' : 'student', $turns);

        $sources = [];
        foreach ($response['sources'] ?? [] as $src) {
            $url = (string) ($src['url'] ?? '');
            // Only link back into this Moodle site.
            if ($url !== '' && strpos($url, (new \moodle_url('/'))->out(false)) !== 0) {
                $url = '';
            }
            $sources[] = [
                'n' => (int) $src['n'],
                'title' => (string) $src['doc_name'],
                'page' => (int) $src['page'],
                'url' => $url,
                'coursename' => $scope->courses[(int) ($src['course_id'] ?? 0)] ?? '',
                'excerpt' => (string) $src['text'],
            ];
        }

        return [
            'answer' => (string) ($response['answer'] ?? ''),
            'mode' => (string) ($response['mode'] ?? ''),
            'sources' => $sources,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'answer' => new external_value(PARAM_RAW, 'Answer text (light markdown)'),
            'mode' => new external_value(PARAM_ALPHA, 'llm or extractive'),
            'sources' => new external_multiple_structure(new external_single_structure([
                'n' => new external_value(PARAM_INT, 'Citation number'),
                'title' => new external_value(PARAM_TEXT, 'Document name'),
                'page' => new external_value(PARAM_INT, 'Page number'),
                'url' => new external_value(PARAM_URL, 'Link to the activity', VALUE_DEFAULT, ''),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'excerpt' => new external_value(PARAM_TEXT, 'Matching passage'),
            ])),
        ]);
    }
}
