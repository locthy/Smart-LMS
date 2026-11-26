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
 * Works out which courses' documents the current user may search from a page context.
 *
 * Inside a course (or one of its activities): that course only.
 * Elsewhere (dashboard, my courses…): every enrolled course where the user has local/ai_qa:use.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** @var int[] Course ids => course name. */
    public array $courses = [];

    /** @var int|null Course id when on a course page. */
    public ?int $courseid = null;

    /** @var bool User can sync documents (only in a course). */
    public bool $canmanage = false;

    /** @var bool User gets teacher-oriented answers. */
    public bool $isteacher = false;

    /**
     * Build the scope for a context.
     *
     * @param \context $context
     * @return self
     */
    public static function for_context(\context $context): self {
        $scope = new self();
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext && $coursecontext->instanceid != SITEID) {
            if (has_capability('local/ai_qa:use', $coursecontext)) {
                $course = get_course($coursecontext->instanceid);
                $scope->courseid = (int) $course->id;
                $scope->courses[$course->id] = format_string($course->fullname, true, ['context' => $coursecontext]);
                $scope->canmanage = has_capability('local/ai_qa:manage', $coursecontext);
                $scope->isteacher = $scope->canmanage || has_capability('moodle/course:viewhiddenactivities', $coursecontext);
            }
            return $scope;
        }
        foreach (enrol_get_my_courses(['fullname']) as $course) {
            $ctx = \context_course::instance($course->id);
            if (has_capability('local/ai_qa:use', $ctx)) {
                $scope->courses[$course->id] = format_string($course->fullname, true, ['context' => $ctx]);
                if (has_capability('local/ai_qa:manage', $ctx)) {
                    $scope->isteacher = true;
                }
            }
        }
        return $scope;
    }

    /**
     * Whether there is anything to search.
     *
     * @return bool
     */
    public function is_usable(): bool {
        return !empty($this->courses);
    }
}
