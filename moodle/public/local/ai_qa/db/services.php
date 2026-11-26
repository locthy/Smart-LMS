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
 * External functions for local_ai_qa.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ai_qa_ask' => [
        'classname' => 'local_ai_qa\external\ask',
        'description' => 'Ask the document assistant a question about course documents.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_ai_qa_get_documents' => [
        'classname' => 'local_ai_qa\external\get_documents',
        'description' => 'List the course documents indexed by the assistant.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_ai_qa_sync_course' => [
        'classname' => 'local_ai_qa\external\sync_course',
        'description' => 'Index the files of a course into the document assistant.',
        'type' => 'write',
        'ajax' => true,
    ],
];
