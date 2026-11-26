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
 * Admin settings for local_ai_qa.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ai_qa', get_string('pluginname', 'local_ai_qa'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox('local_ai_qa/enabled',
            get_string('enabled', 'local_ai_qa'), get_string('enabled_desc', 'local_ai_qa'), 1));
        $settings->add(new admin_setting_configtext('local_ai_qa/serviceurl',
            get_string('serviceurl', 'local_ai_qa'), get_string('serviceurl_desc', 'local_ai_qa'),
            getenv('AI_SERVICE_URL') ?: 'http://localhost:8001', PARAM_RAW_TRIMMED));
        $settings->add(new admin_setting_configpasswordunmask('local_ai_qa/apikey',
            get_string('apikey', 'local_ai_qa'), get_string('apikey_desc', 'local_ai_qa'),
            getenv('AI_SERVICE_KEY') ?: 'smartlms-dev-key'));
        $settings->add(new admin_setting_configcheckbox('local_ai_qa/showoutsidecourse',
            get_string('showoutsidecourse', 'local_ai_qa'), get_string('showoutsidecourse_desc', 'local_ai_qa'), 1));
    }
}
