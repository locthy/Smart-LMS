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

/**
 * Sends the course's learning materials to the RAG service.
 *
 * Indexed: visible File (mod_resource) and Folder (mod_folder) activities with
 * PDF/DOCX/TXT/MD files, and Page (mod_page) content. Hidden activities are not
 * indexed so students never get answers from material they cannot see.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_sync {
    /** @var string[] File extensions the service can read. */
    const EXTENSIONS = ['pdf', 'docx', 'txt', 'md'];

    /**
     * Sync a course.
     *
     * @param int $courseid
     * @return array Counters: added, unchanged, skipped, removed, errors (list of strings).
     */
    public static function sync(int $courseid): array {
        global $DB;

        \core_php_time_limit::raise(600);
        $course = get_course($courseid);
        $coursename = format_string($course->fullname, true, ['context' => \context_course::instance($courseid)]);
        $modinfo = get_fast_modinfo($course);
        $client = new api_client();
        $fs = get_file_storage();

        $result = ['added' => 0, 'unchanged' => 0, 'skipped' => 0, 'removed' => 0, 'errors' => []];
        $keep = [];

        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->visible || $cm->deletioninprogress || !in_array($cm->modname, ['resource', 'folder', 'page'])) {
                continue;
            }
            $url = $cm->url ? $cm->url->out(false) : '';
            $context = \context_module::instance($cm->id);

            if ($cm->modname === 'page') {
                $page = $DB->get_record('page', ['id' => $cm->instance], 'id, name, content, contentformat');
                $text = content_to_text($page->content, $page->contentformat);
                if (trim($text) === '') {
                    continue;
                }
                $text = '# ' . $cm->get_formatted_name() . "\n\n" . $text;
                $key = 'page-' . $cm->id . '-' . sha1($text);
                $keep[] = $key;
                $tmp = make_request_directory() . '/page.md';
                file_put_contents($tmp, $text);
                $name = clean_filename($cm->get_formatted_name()) . '.md';
                self::send($client, $result, $courseid, $coursename, $key, $name, $url,
                    new \CURLFile($tmp, 'text/markdown', $name));
                continue;
            }

            $files = $fs->get_area_files($context->id, 'mod_' . $cm->modname, 'content', 0, 'sortorder, filepath, filename', false);
            foreach ($files as $file) {
                $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                if (!in_array($ext, self::EXTENSIONS)) {
                    $result['skipped']++;
                    continue;
                }
                $key = 'file-' . $cm->id . '-' . $file->get_contenthash();
                $keep[] = $key;
                self::send($client, $result, $courseid, $coursename, $key, $file->get_filename(), $url, $file);
            }
        }

        $pruned = $client->prune($courseid, $keep);
        $result['removed'] = (int) ($pruned['deleted'] ?? 0);
        return $result;
    }

    /**
     * Send one file and update counters.
     *
     * @param api_client $client
     * @param array $result
     * @param int $courseid
     * @param string $coursename
     * @param string $key
     * @param string $name
     * @param string $url
     * @param \stored_file|\CURLFile $file
     */
    private static function send(api_client $client, array &$result, int $courseid, string $coursename,
            string $key, string $name, string $url, $file): void {
        try {
            $response = $client->add_file($courseid, $coursename, $key, $name, $url, $file);
        } catch (\moodle_exception $e) {
            $result['errors'][] = $name . ': ' . $e->getMessage();
            return;
        }
        switch ($response['status'] ?? '') {
            case 'added':
                $result['added']++;
                break;
            case 'unchanged':
                $result['unchanged']++;
                break;
            case 'skipped':
                $result['skipped']++;
                break;
            default:
                $result['errors'][] = $name . ': ' . ($response['reason'] ?? 'unknown error');
        }
    }
}
