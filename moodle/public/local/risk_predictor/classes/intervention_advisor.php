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
 * Pedagogical explanation and intervention email draft generator.
 *
 * @package    local_risk_predictor
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_risk_predictor;

defined('MOODLE_INTERNAL') || die();

/**
 * Translates heuristic risk evaluations into localized (EN/VI) pedagogical advice and email drafts.
 */
class intervention_advisor {
    /**
     * Resolve target language ('en' or 'vi') from explicit override, course setting, or user UI.
     *
     * @param \stdClass|null $course Optional course record (uses $course->lang when set).
     * @param string|null $lang Optional explicit language code.
     * @return string Normalized language code ('en' or 'vi').
     */
    public static function resolve_language(?\stdClass $course = null, ?string $lang = null): string {
        $candidates = [];
        if ($lang !== null && $lang !== '') {
            $candidates[] = $lang;
        }
        if ($course !== null && !empty($course->lang)) {
            $candidates[] = $course->lang;
        }
        if ((!defined('CLI_SCRIPT') || !CLI_SCRIPT) && function_exists('current_language')) {
            $candidates[] = current_language();
        }

        foreach ($candidates as $candidate) {
            $short = strtolower(substr(trim((string)$candidate), 0, 2));
            if ($short === 'en' || $short === 'vi') {
                return $short;
            }
        }

        return 'vi';
    }

    /**
     * Generate plain-language pedagogical explanations (EN or VI) for a student's risk evaluation.
     *
     * @param \stdClass $evaluation Result returned by heuristic_evaluator::evaluate().
     * @param object|array $features Student feature snapshot.
     * @param string|null $lang Optional explicit language ('en' or 'vi').
     * @param \stdClass|null $course Optional course record for course-level language detection.
     * @return string Formatted pedagogical explanation.
     */
    public static function generateAdvice(
        \stdClass $evaluation,
        $features,
        ?string $lang = null,
        ?\stdClass $course = null
    ): string {
        $f = (object)$features;
        $targetlang = self::resolve_language($course, $lang);
        $rules = $evaluation->triggered_rules ?? [];

        $templates = [
            'vi' => [
                'inactivity_severe' => static fn($r): string => sprintf(
                    'Gián đoạn học tập nghiêm trọng: Không truy cập hệ thống %d ngày liên tiếp (vượt ngưỡng cảnh báo %d ngày).',
                    (int)$r->value,
                    (int)$r->threshold
                ),
                'inactivity_moderate' => static fn($r): string => sprintf(
                    'Tần suất truy cập giảm: Đã %d ngày liên tiếp chưa vào học (ngưỡng %d ngày).',
                    (int)$r->value,
                    (int)$r->threshold
                ),
                'missed_assigns_high' => static fn($r): string => sprintf(
                    'Bỏ lỡ nhiều bài tập: Chưa nộp %d bài tập được giao (ngưỡng cho phép %d bài).',
                    (int)$r->value,
                    (int)$r->threshold
                ),
                'missed_assigns_moderate' => static fn($r): string => sprintf(
                    'Cảnh báo tiến độ nộp bài: Hiện còn %d bài tập chưa hoàn thành.',
                    (int)$r->value
                ),
                'quiz_score_critical' => static fn($r): string => sprintf(
                    'Kết quả đánh giá ở mức báo động: Điểm trung bình hiện tại đạt %.1f/100 (dưới mức tối thiểu %.0f).',
                    (float)$r->value,
                    (float)$r->threshold
                ),
                'quiz_score_low' => static fn($r): string => sprintf(
                    'Kết quả học tập chưa ổn định: Điểm trung bình đạt %.1f/100 (cần cải thiện trên %.0f).',
                    (float)$r->value,
                    (float)$r->threshold
                ),
                'quiz_score_drop' => static fn($r): string => sprintf(
                    'Điểm kiểm tra giảm sút %.1f%% so với giai đoạn trước (ngưỡng cảnh báo %.0f%%).',
                    (float)$r->value,
                    (float)$r->threshold
                ),
                'low_access_frequency' => static fn($r): string => sprintf(
                    'Mức độ chuyên cần thấp: Trung bình chỉ hoạt động %.2f ngày/tuần.',
                    (float)$r->value
                ),
                'declining_access_frequency' => static fn($r): string => sprintf(
                    'Tần suất tương tác có dấu hiệu giảm (trung bình %.2f ngày/tuần).',
                    (float)$r->value
                ),
                'submission_delay_high' => static fn($r): string => sprintf(
                    'Thường xuyên nộp bài muộn: Độ trễ trung bình %.1f giờ so với hạn chót.',
                    (float)$r->value
                ),
                'submission_delay_moderate' => static fn($r): string => sprintf(
                    'Có dấu hiệu nộp bài sát hoặc trễ hạn (trung bình %.1f giờ).',
                    (float)$r->value
                ),
                'late_course_start' => static fn($r): string => sprintf(
                    'Tham gia khóa học muộn %d ngày kể từ ngày khai giảng.',
                    (int)$r->value
                ),
            ],
            'en' => [
                'inactivity_severe' => static fn($r): string => sprintf(
                    'Severe learning disengagement: No course access for %d consecutive days (exceeds %d-day threshold).',
                    (int)$r->value,
                    (int)$r->threshold
                ),
                'inactivity_moderate' => static fn($r): string => sprintf(
                    'Reduced access frequency: Inactive for %d consecutive days (threshold: %d days).',
                    (int)$r->value,
                    (int)$r->threshold
                ),
                'missed_assigns_high' => static fn($r): string => sprintf(
                    'Multiple missed assignments: %d assignment(s) unsubmitted (threshold: %d).',
                    (int)$r->value,
                    (int)$r->threshold
                ),
                'missed_assigns_moderate' => static fn($r): string => sprintf(
                    'Submission progress warning: %d assignment(s) currently unsubmitted.',
                    (int)$r->value
                ),
                'quiz_score_critical' => static fn($r): string => sprintf(
                    'Critical assessment performance: Current average score is %.1f/100 (below %.0f minimum).',
                    (float)$r->value,
                    (float)$r->threshold
                ),
                'quiz_score_low' => static fn($r): string => sprintf(
                    'Unsteady academic performance: Average score is %.1f/100 (needs improvement above %.0f).',
                    (float)$r->value,
                    (float)$r->threshold
                ),
                'quiz_score_drop' => static fn($r): string => sprintf(
                    'Quiz score declined by %.1f%% compared to earlier attempts (warning threshold: %.0f%%).',
                    (float)$r->value,
                    (float)$r->threshold
                ),
                'low_access_frequency' => static fn($r): string => sprintf(
                    'Low attendance/engagement: Averaging only %.2f active day(s) per week.',
                    (float)$r->value
                ),
                'declining_access_frequency' => static fn($r): string => sprintf(
                    'Signs of declining engagement (averaging %.2f active day(s) per week).',
                    (float)$r->value
                ),
                'submission_delay_high' => static fn($r): string => sprintf(
                    'Frequent late submissions: Average delay of %.1f hours past the deadline.',
                    (float)$r->value
                ),
                'submission_delay_moderate' => static fn($r): string => sprintf(
                    'Borderline or late submissions detected (average delay: %.1f hours).',
                    (float)$r->value
                ),
                'late_course_start' => static fn($r): string => sprintf(
                    'Joined or accessed the course %d day(s) after the course start date.',
                    (int)$r->value
                ),
            ],
        ];

        $reasons = [];
        $langmap = $templates[$targetlang];
        foreach ($rules as $rule) {
            if (isset($langmap[$rule->code])) {
                $reasons[] = $langmap[$rule->code]($rule);
            }
        }

        if (empty($reasons)) {
            $quizavg = (float)($f->quiz_avg_score ?? 0.0);
            $daysavg = (float)($f->days_with_logs_avg ?? 0.0);
            if ($targetlang === 'en') {
                return sprintf(
                    'Steady learning progress: Maintaining regular access (%.2f days/week), completing assignments on time, and averaging %.1f/100.',
                    $daysavg,
                    $quizavg
                );
            }
            return sprintf(
                'Tiến độ học tập ổn định: Sinh viên duy trì truy cập đều đặn (%.2f ngày/tuần), hoàn thành đầy đủ bài tập và đạt điểm trung bình %.1f/100.',
                $daysavg,
                $quizavg
            );
        }

        return implode("\n", array_map(static fn(string $r): string => '- ' . $r, $reasons));
    }

    /**
     * Draft a supportive intervention email (EN or VI) for the instructor to review and send.
     *
     * @param \stdClass $student Student user record.
     * @param \stdClass $course Course record.
     * @param \stdClass $evaluation Result returned by heuristic_evaluator::evaluate().
     * @param string $advice Pedagogical explanation from generateAdvice().
     * @param string|null $lang Optional explicit language ('en' or 'vi').
     * @return \stdClass Object containing subject and body.
     */
    public static function draftInterventionEmail(
        \stdClass $student,
        \stdClass $course,
        \stdClass $evaluation,
        string $advice,
        ?string $lang = null
    ): \stdClass {
        $targetlang = self::resolve_language($course, $lang);
        $studentname = trim(($student->firstname ?? '') . ' ' . ($student->lastname ?? ''));
        if ($studentname === '') {
            $studentname = $student->username ?? ($targetlang === 'en' ? 'Student' : 'bạn');
        }
        $coursename = $course->fullname ?? ($course->shortname ?? ($targetlang === 'en' ? 'the course' : 'khóa học'));
        $level = $evaluation->risk_level ?? heuristic_evaluator::RISK_BORDERLINE;

        if ($targetlang === 'en') {
            if ($level === heuristic_evaluator::RISK_AT_RISK) {
                $subject = "[Smart-LMS] Academic Check-in & Support for {$coursename}";
                $body = "Dear {$studentname},\n\n"
                    . "Your instructor for {$coursename} noticed that your recent learning progress shows a few challenges:\n"
                    . "{$advice}\n\n"
                    . "We want to support you in getting back on track. "
                    . "Please reply to this email or book a slot during this week's office hours so we can work through any difficulties with the lectures or assignments together.\n\n"
                    . "Best regards,\nInstructor, {$coursename}";
            } else if ($level === heuristic_evaluator::RISK_BORDERLINE) {
                $subject = "[Smart-LMS] Learning Progress Reminder for {$coursename}";
                $body = "Dear {$studentname},\n\n"
                    . "The learning system for {$coursename} flagged a few areas to keep an eye on:\n"
                    . "{$advice}\n\n"
                    . "Please set aside time to review this week's materials and submit upcoming assignments ahead of the deadline. "
                    . "If any topic is unclear, feel free to reach out or post in the course forum.\n\n"
                    . "Wishing you a great week,\nInstructor, {$coursename}";
            } else {
                $subject = "[Smart-LMS] Positive Learning Progress in {$coursename}";
                $body = "Dear {$studentname},\n\n"
                    . "Great job maintaining strong learning momentum in {$coursename}.\n"
                    . "{$advice}\n\n"
                    . "Keep up the proactive effort in the coming weeks!\n\n"
                    . "Best regards,\nInstructor, {$coursename}";
            }
        } else if ($level === heuristic_evaluator::RISK_AT_RISK) {
            $subject = "[Smart-LMS] Thăm hỏi và hỗ trợ học tập môn {$coursename}";
            $body = "Chào {$studentname},\n\n"
                . "Giảng viên phụ trách môn {$coursename} nhận thấy tiến độ học tập của bạn trong thời gian gần đây đang gặp một số trở ngại:\n"
                . "{$advice}\n\n"
                . "Thầy/Cô rất mong muốn đồng hành để giúp bạn kịp thời bắt nhịp lại với lớp. "
                . "Bạn vui lòng phản hồi email này hoặc đặt lịch trao đổi trực tiếp trong giờ tiếp sinh viên (office hours) tuần này để chúng ta cùng tháo gỡ khó khăn về bài giảng hoặc bài tập nhé.\n\n"
                . "Trân trọng,\nGiảng viên môn {$coursename}";
        } else if ($level === heuristic_evaluator::RISK_BORDERLINE) {
            $subject = "[Smart-LMS] Nhắc nhở tiến độ học tập môn {$coursename}";
            $body = "Chào {$studentname},\n\n"
                . "Hệ thống học tập môn {$coursename} ghi nhận một vài điểm bạn cần lưu ý để giữ vững kết quả:\n"
                . "{$advice}\n\n"
                . "Bạn hãy dành thời gian rà soát lại các tài liệu tuần hiện tại và hoàn thành sớm các bài tập sắp đến hạn. "
                . "Nếu có phần kiến thức nào chưa rõ, đừng ngần ngại nhắn tin hoặc trao đổi trên diễn đàn môn học nhé.\n\n"
                . "Chúc bạn học tốt,\nGiảng viên môn {$coursename}";
        } else {
            $subject = "[Smart-LMS] Ghi nhận tiến độ tích cực môn {$coursename}";
            $body = "Chào {$studentname},\n\n"
                . "Chúc mừng bạn đang duy trì phong độ học tập rất tốt ở môn {$coursename}.\n"
                . "{$advice}\n\n"
                . "Hãy tiếp tục phát huy tinh thần học tập chủ động này trong các tuần tiếp theo nhé!\n\n"
                . "Trân trọng,\nGiảng viên môn {$coursename}";
        }

        return (object)[
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
