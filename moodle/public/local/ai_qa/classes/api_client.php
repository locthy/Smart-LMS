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
 * HTTP client for the external RAG service.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    /** @var string Base URL of the service. */
    private string $baseurl;

    /** @var string Shared secret. */
    private string $apikey;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->baseurl = rtrim((string) get_config('local_ai_qa', 'serviceurl'), '/');
        $this->apikey = (string) get_config('local_ai_qa', 'apikey');
    }

    /**
     * Whether the plugin is enabled and the service is configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return get_config('local_ai_qa', 'enabled')
            && get_config('local_ai_qa', 'serviceurl')
            && get_config('local_ai_qa', 'apikey');
    }

    /**
     * Build a curl object.
     *
     * @return \curl
     */
    private function curl(): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        // The service URL is set by the site admin and usually points to an internal host,
        // which the default curl security helper would block.
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader(['X-RAG-Key: ' . $this->apikey, 'Accept: application/json']);
        return $curl;
    }

    /**
     * Decode a JSON response or throw.
     *
     * @param \curl $curl
     * @param string|bool $response
     * @return array
     */
    private function decode(\curl $curl, $response): array {
        $info = $curl->get_info();
        $code = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno() || $code === 0) {
            throw new \moodle_exception('serviceunavailable', 'local_ai_qa', '', null, $curl->error);
        }
        $data = json_decode((string) $response, true);
        if ($code >= 400 || !is_array($data)) {
            $detail = is_array($data) && isset($data['detail']) ? (is_string($data['detail']) ? $data['detail'] : json_encode($data['detail'])) : "HTTP $code";
            throw new \moodle_exception('serviceerror', 'local_ai_qa', '', $detail);
        }
        return $data;
    }

    /**
     * Ask a question.
     *
     * @param string $question
     * @param int[] $courseids Courses whose documents may be searched.
     * @param string $role 'student' or 'teacher'.
     * @param array $history Previous turns [['role' => 'user'|'assistant', 'content' => '...']].
     * @return array
     */
    public function ask(string $question, array $courseids, string $role, array $history): array {
        $curl = $this->curl();
        $curl->setHeader(['Content-Type: application/json']);
        $payload = json_encode([
            'question' => $question,
            'course_ids' => array_map('strval', array_values($courseids)),
            'role' => $role,
            'history' => $history,
        ]);
        $response = $curl->post($this->baseurl . '/rag/query', $payload, ['CURLOPT_TIMEOUT' => 120]);
        return $this->decode($curl, $response);
    }

    /**
     * List documents indexed for a course.
     *
     * @param int $courseid
     * @return array
     */
    public function documents(int $courseid): array {
        $curl = $this->curl();
        $response = $curl->get($this->baseurl . '/rag/documents', ['course_id' => $courseid],
            ['CURLOPT_TIMEOUT' => 20]);
        return $this->decode($curl, $response);
    }

    /**
     * Send a file to be indexed. The service skips files whose key it already has.
     *
     * @param int $courseid
     * @param string $coursename
     * @param string $extkey Unique key of this file version.
     * @param string $name File name (extension decides the parser).
     * @param string $url Moodle URL shown as the source link.
     * @param \stored_file|\CURLFile $file
     * @return array
     */
    public function add_file(int $courseid, string $coursename, string $extkey, string $name, string $url, $file): array {
        $curl = $this->curl();
        $params = [
            'file' => $file,
            'course_id' => (string) $courseid,
            'course_name' => $coursename,
            'ext_key' => $extkey,
            'name' => $name,
            'url' => $url,
        ];
        $response = $curl->post($this->baseurl . '/rag/documents', $params, ['CURLOPT_TIMEOUT' => 300]);
        return $this->decode($curl, $response);
    }

    /**
     * Remove documents of a course that are no longer present.
     *
     * @param int $courseid
     * @param string[] $keepkeys
     * @return array
     */
    public function prune(int $courseid, array $keepkeys): array {
        $curl = $this->curl();
        $curl->setHeader(['Content-Type: application/json']);
        $payload = json_encode(['course_id' => (string) $courseid, 'keep_keys' => array_values($keepkeys)]);
        $response = $curl->post($this->baseurl . '/rag/documents/prune', $payload, ['CURLOPT_TIMEOUT' => 60]);
        return $this->decode($curl, $response);
    }
}
