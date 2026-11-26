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
 * Library callbacks for local_ai_qa.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * User preferences the drawer may save through core_user_set_user_preferences.
 *
 * @return array
 */
function local_ai_qa_user_preferences(): array {
    $base = ['null' => NULL_NOT_ALLOWED, 'permissioncallback' => [core_user::class, 'is_current_user']];
    return [
        'local_ai_qa_width' => $base + ['type' => PARAM_INT, 'default' => 420],
        'local_ai_qa_open' => $base + ['type' => PARAM_BOOL, 'default' => 0],
        'local_ai_qa_pinned' => $base + ['type' => PARAM_BOOL, 'default' => 0],
    ];
}
