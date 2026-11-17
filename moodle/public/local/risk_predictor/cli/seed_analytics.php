<?php
define('CLI_SCRIPT', true);

// Fast-path help output without requiring DB connection
if (isset($argv) && (in_array('--help', $argv, true) || in_array('-h', $argv, true))) {
    $help = <<<HELP
Synthetic Moodle Learning Analytics Seeder (LOCAL DEVELOPMENT & TESTBED)

Seeds realistic test student profiles (Safe, Borderline, At-Risk) with backdated
course logins, assignments, gradebook records, and activity completion states.

Options:
  -h, --help            Show this help message.
  --students=N          Number of synthetic students to generate (default: 15).
                        Automatically partitioned into 3 equal archetypes:
                        Safe (100% completion, high grades),
                        Borderline (50-60% completion, average grades),
                        At-Risk (<30% completion, low grades/inactivity).
  --courses=N           Number of synthetic test courses to create (default: 2).
  --prefix=TEXT         Prefix for usernames and course shortnames (default: AISEED).
  --categoryid=ID       Target course category ID in Moodle (default: 1).

Example:
  php moodle/public/local/risk_predictor/cli/seed_analytics.php --students=15 --courses=2
HELP;
    echo $help . PHP_EOL;
    exit(0);
}

// Include Moodle config from public root
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/gradelib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');

global $DB, $CFG;

if (!function_exists('cli_get_params')) {
    fwrite(STDERR, "Run this script using Moodle CLI.\n");
    exit(1);
}

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'categoryid' => 1,
        'students' => 15,
        'courses' => 2,
        'prefix' => 'AISEED',
    ],
    ['h' => 'help']
);

if ($options['help']) {
    $help = <<<HELP
Synthetic Moodle Learning Analytics Seeder (LOCAL DEVELOPMENT & TESTBED)

Seeds realistic test student profiles (Safe, Borderline, At-Risk) with backdated
course logins, assignments, gradebook records, and activity completion states.

Options:
  -h, --help            Show this help message.
  --students=N          Number of synthetic students to generate (default: 15).
                        Automatically partitioned into 3 equal archetypes:
                        Safe (100% completion, high grades),
                        Borderline (50-60% completion, average grades),
                        At-Risk (<30% completion, low grades/inactivity).
  --courses=N           Number of synthetic test courses to create (default: 2).
  --prefix=TEXT         Prefix for usernames and course shortnames (default: AISEED).
  --categoryid=ID       Target course category ID in Moodle (default: 1).

Example:
  php moodle/public/local/risk_predictor/cli/seed_analytics.php --students=15 --courses=2
HELP;
    echo $help . PHP_EOL;
    exit(0);
}

$categoryid = (int)$options['categoryid'];
$studentcount = max(3, (int)$options['students']);
$coursecount = max(1, (int)$options['courses']);
$prefix = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$options['prefix']));

echo "=== Seeding Synthetic Analytics Data ===\n";
echo "Courses: {$coursecount}, Students: {$studentcount}, Prefix: {$prefix}\n";

$startdate = strtotime('-3 months');
$enddate = strtotime('+1 month');
$studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$manualplugin = enrol_get_plugin('manual');

// 1. Create or get synthetic users with 3 Profiles
// Partition: 1/3 Safe, 1/3 Borderline, 1/3 At-Risk
$third = (int)ceil($studentcount / 3);
$users = [];

for ($i = 1; $i <= $studentcount; $i++) {
    $uname = strtolower($prefix) . '_student_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $existing = $DB->get_record('user', ['username' => $uname, 'deleted' => 0]);

    if ($i <= $third) {
        $profile = 'safe';
    } else if ($i <= $third * 2) {
        $profile = 'borderline';
    } else {
        $profile = 'at_risk';
    }

    if ($existing) {
        $user = $existing;
    } else {
        $userrecord = (object)[
            'auth' => 'manual',
            'confirmed' => 1,
            'username' => $uname,
            'password' => 'ChangeMe_2026!',
            'firstname' => ucfirst($profile),
            'lastname' => 'Student ' . $i,
            'email' => $uname . '@example.invalid',
            'mnethostid' => $CFG->mnet_localhost_id,
            'lang' => $CFG->lang,
        ];
        $userrecord->id = user_create_user($userrecord, false, false);
        $user = $DB->get_record('user', ['id' => $userrecord->id], '*', MUST_EXIST);
    }
    $user->profile = $profile;
    $users[] = $user;
}

echo "Created/Reused " . count($users) . " students.\n";

// 2. Create Courses and Assignments
$assignmodule = $DB->get_record('modules', ['name' => 'assign'], '*', MUST_EXIST);

for ($c = 1; $c <= $coursecount; $c++) {
    $cshortname = "{$prefix}_COURSE_{$c}";
    $course = $DB->get_record('course', ['shortname' => $cshortname]);

    if (!$course) {
        $coursedata = (object)[
            'category' => $categoryid,
            'fullname' => "Synthetic Test Course {$c}",
            'shortname' => $cshortname,
            'summary' => 'Synthetic course for AI risk prediction evaluation.',
            'format' => 'topics',
            'numsections' => 4,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'enablecompletion' => 1,
            'visible' => 1,
        ];
        $course = create_course($coursedata);
        echo "Created course {$cshortname} (ID: {$course->id})\n";
    }

    // Enrol students
    $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
    if (!$instance) {
        $instid = $manualplugin->add_default_instance($course);
        $instance = $DB->get_record('enrol', ['id' => $instid], '*', MUST_EXIST);
    }

    foreach ($users as $u) {
        $manualplugin->enrol_user($instance, $u->id, $studentroleid, $startdate, $enddate, ENROL_USER_ACTIVE);
    }

    // 3. Create 3 assignments in course if not exist
    $assignments = [];
    for ($a = 1; $a <= 3; $a++) {
        $aname = "Assignment {$a} - {$cshortname}";
        $assign = $DB->get_record('assign', ['course' => $course->id, 'name' => $aname]);

        if (!$assign) {
            $assigndata = (object)[
                'course' => $course->id,
                'name' => $aname,
                'intro' => "<p>Synthetic assignment {$a}</p>",
                'introformat' => FORMAT_HTML,
                'duedate' => $startdate + ($a * 20 * 86400),
                'allowsubmissionsfromdate' => $startdate,
                'grade' => 100,
                'submissiondrafts' => 0,
                'requiresubmissionstatement' => 0,
                'sendnotifications' => 0,
                'sendlatenotifications' => 0,
                'timemodified' => time(),
                'completionsubmit' => 1,
            ];
            $assignid = $DB->insert_record('assign', $assigndata);
            $assign = $DB->get_record('assign', ['id' => $assignid]);

            // Add course module
            $cm = (object)[
                'course' => $course->id,
                'module' => $assignmodule->id,
                'instance' => $assign->id,
                'section' => 1,
                'visible' => 1,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completiongradeitemnumber' => 0,
                'completionview' => 0,
            ];
            $cm->id = add_course_module($cm);
            course_add_cm_to_section($course, $cm->id, 1);
        }
        $assignments[] = $assign;
    }

    rebuild_course_cache($course->id, true);

    // 4. Seed Log views, Grades and Activity Completion based on Profile
    $context = context_course::instance($course->id);
    $completioninfo = new completion_info($course);

    foreach ($users as $u) {
        // Log views
        $logcount = ($u->profile === 'safe') ? 25 : (($u->profile === 'borderline') ? 10 : 3);
        for ($k = 0; $k < $logcount; $k++) {
            $logtime = ($u->profile === 'at_risk') 
                ? $startdate + ($k * 86400 * 2) 
                : $startdate + ($k * 86400 * 3);

            $logrow = (object)[
                'eventname' => '\\core\\event\\course_viewed',
                'component' => 'core',
                'action' => 'viewed',
                'target' => 'course',
                'objecttable' => 'course',
                'objectid' => $course->id,
                'crud' => 'r',
                'edulevel' => 2,
                'contextid' => $context->id,
                'contextlevel' => CONTEXT_COURSE,
                'contextinstanceid' => $course->id,
                'userid' => $u->id,
                'courseid' => $course->id,
                'timecreated' => $logtime,
                'origin' => 'web',
                'ip' => '127.0.0.1',
            ];
            $DB->insert_record('logstore_standard_log', $logrow);
        }

        // Grades and Completions for the 3 assignments
        foreach ($assignments as $idx => $assign) {
            $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id);
            $gradeval = null;
            $completed = false;

            if ($u->profile === 'safe') {
                $gradeval = rand(80, 100);
                $completed = true;
            } else if ($u->profile === 'borderline') {
                if ($idx < 2) {
                    $gradeval = rand(45, 60);
                    $completed = true;
                }
            } else { // at_risk
                if ($idx === 0) {
                    $gradeval = rand(20, 35);
                    $completed = true;
                }
            }

            if ($gradeval !== null) {
                // Update Gradebook
                $grade = (object)[
                    'userid' => $u->id,
                    'rawgrade' => $gradeval,
                    'timemodified' => $assign->duedate - 3600,
                ];
                assign_grade_item_update($assign, $grade);
            }

            if ($completed && $cm) {
                // Mark completion in Moodle
                $completioninfo->update_state($cm, COMPLETION_COMPLETE, $u->id);
            }
        }
    }
}

echo "\n Seeding completed successfully!\n";
echo "Profiles: Safe (100% completion, high grades), Borderline (50% completion), At-Risk (<30% completion, low logs).\n";