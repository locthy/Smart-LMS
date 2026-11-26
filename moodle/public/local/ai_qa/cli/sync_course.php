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
 * CLI: index the materials of one or more courses into the AI service (same as the
 * "Sync course files" button teachers see in the assistant drawer).
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    ['help' => false, 'courseid' => 0, 'shortname' => '', 'all' => false],
    ['h' => 'help']
);

if ($options['help'] || (!$options['courseid'] && !$options['shortname'] && !$options['all'])) {
    echo <<<HELP
Index course materials (visible File, Folder and Page activities) into the AI Course Q&A service.

Options:
  -h, --help          Show this help message.
  --courseid=ID       Course id to sync.
  --shortname=NAME    Course short name to sync.
  --all               Sync every course (except the site course).

Example:
  php moodle/public/local/ai_qa/cli/sync_course.php --shortname=CO3117

HELP;
    exit(0);
}

if (!\local_ai_qa\api_client::is_configured()) {
    cli_error('AI Course Q&A is not configured (Site administration > Plugins > Local plugins > AI Course Q&A).');
}

\core\session\manager::set_user(get_admin());

if ($options['all']) {
    $courseids = $DB->get_fieldset_select('course', 'id', 'id <> ?', [SITEID]);
} else if ($options['courseid']) {
    $courseids = [(int) $options['courseid']];
} else {
    $courseids = [(int) $DB->get_field('course', 'id', ['shortname' => $options['shortname']], MUST_EXIST)];
}

foreach ($courseids as $courseid) {
    $course = get_course($courseid);
    $start = microtime(true);
    $result = \local_ai_qa\course_sync::sync($courseid);
    mtrace(sprintf('%s (id %d): %d added, %d unchanged, %d removed, %d unsupported in %.1fs',
        $course->shortname, $courseid, $result['added'], $result['unchanged'], $result['removed'],
        $result['skipped'], microtime(true) - $start));
    foreach ($result['errors'] as $error) {
        mtrace('  ! ' . $error);
    }
}
