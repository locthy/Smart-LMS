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
 * CLI (LOCAL DEVELOPMENT & TESTBED): create a course whose File activities are the
 * documents of a folder, enrol test users, and optionally index it for the assistant.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'dir' => '',
        'shortname' => '',
        'fullname' => '',
        'categoryid' => 1,
        'teacher' => '',
        'student' => '',
        'sync' => false,
    ],
    ['h' => 'help']
);

if ($options['help'] || !$options['dir'] || !$options['shortname']) {
    echo <<<HELP
Create a test course from a folder of documents (PDF, DOCX, TXT, MD) for the AI Course Q&A assistant.

Each file becomes a File activity named after the file. Existing courses are not modified.

Options:
  -h, --help          Show this help message.
  --dir=PATH          Folder containing the documents (readable by the Moodle server).
  --shortname=NAME    Course short name (required).
  --fullname=NAME     Course full name (default: short name).
  --categoryid=ID     Course category id (default: 1).
  --teacher=USERNAME  Existing user to enrol as editing teacher (optional).
  --student=USERNAME  Existing user to enrol as student (optional).
  --sync              Index the course into the AI service after creating it.

Example:
  php moodle/public/local/ai_qa/cli/import_course_files.php --dir=/tmp/ml --shortname=CO3117 \
      --fullname="Machine Learning" --teacher=teacher1 --student=student1 --sync

HELP;
    exit(0);
}

$dir = rtrim($options['dir'], '/\\');
if (!is_dir($dir)) {
    cli_error("Folder not found: $dir");
}
if ($DB->record_exists('course', ['shortname' => $options['shortname']])) {
    cli_error("Course {$options['shortname']} already exists.");
}

\core\session\manager::set_user(get_admin());
$generator = new testing_data_generator();

$course = $generator->create_course([
    'shortname' => $options['shortname'],
    'fullname' => $options['fullname'] ?: $options['shortname'],
    'category' => (int) $options['categoryid'],
]);
mtrace("Created course {$course->shortname} (id {$course->id})");

$files = glob($dir . '/*.{pdf,PDF,docx,DOCX,txt,TXT,md,MD}', GLOB_BRACE) ?: [];
sort($files);
$usercontext = context_user::instance(get_admin()->id);
foreach ($files as $path) {
    $filename = basename($path);
    $draftid = file_get_unused_draft_itemid();
    get_file_storage()->create_file_from_pathname([
        'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
        'itemid' => $draftid, 'filepath' => '/', 'filename' => $filename,
    ], $path);
    $name = trim(preg_replace('/[_-]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));
    $generator->create_module('resource', ['course' => $course->id, 'name' => $name, 'files' => $draftid]);
    mtrace("  + $name");
}

foreach (['teacher' => 'editingteacher', 'student' => 'student'] as $option => $role) {
    if ($options[$option]) {
        $user = $DB->get_record('user', ['username' => $options[$option], 'deleted' => 0], '*', MUST_EXIST);
        $generator->enrol_user($user->id, $course->id, $role);
        mtrace("  enrolled {$user->username} as $role");
    }
}

if ($options['sync']) {
    if (!\local_ai_qa\api_client::is_configured()) {
        cli_error('AI Course Q&A is not configured; course created but not indexed.');
    }
    $result = \local_ai_qa\course_sync::sync($course->id);
    mtrace("Indexed: {$result['added']} added, {$result['skipped']} unsupported");
    foreach ($result['errors'] as $error) {
        mtrace('  ! ' . $error);
    }
}
