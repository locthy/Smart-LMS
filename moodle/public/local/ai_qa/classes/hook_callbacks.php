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

namespace local_ai_qa;

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks: adds the document assistant drawer to every page the user can use it on.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string[] Layouts where the drawer is never shown. */
    const EXCLUDED_LAYOUTS = ['maintenance', 'print', 'redirect', 'embedded', 'popup', 'frametop', 'login', 'secure'];

    /**
     * Render the drawer before the footer.
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT;

        if (during_initial_install() || !get_config('local_ai_qa', 'version') || !api_client::is_configured()) {
            return;
        }
        if (!isloggedin() || isguestuser() || in_array($PAGE->pagelayout, self::EXCLUDED_LAYOUTS)) {
            return;
        }
        $scope = scope::for_context($PAGE->context);
        if (!$scope->is_usable() || ($scope->courseid === null && !get_config('local_ai_qa', 'showoutsidecourse'))) {
            return;
        }

        if ($scope->courseid) {
            $context = \context_course::instance($scope->courseid);
            $scopelabel = reset($scope->courses);
        } else {
            $context = \context_system::instance();
            $scopelabel = get_string('allmycourses', 'local_ai_qa', count($scope->courses));
        }

        $hook->add_html($OUTPUT->render_from_template('local_ai_qa/drawer', [
            'uniqid' => uniqid(),
            'contextid' => $context->id,
            'courseid' => (int) $scope->courseid,
            'scopelabel' => $scopelabel,
            'canmanage' => $scope->canmanage,
            'isteacher' => $scope->isteacher,
            'width' => (int) get_user_preferences('local_ai_qa_width', 420),
            'open' => (bool) get_user_preferences('local_ai_qa_open', 0),
            'pinned' => (bool) get_user_preferences('local_ai_qa_pinned', 0),
        ]));
    }
}
