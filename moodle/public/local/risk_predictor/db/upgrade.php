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
 * Upgrade script for local_risk_predictor.
 *
 * @package    local_risk_predictor
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_risk_predictor upgrade steps between versions.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_local_risk_predictor_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026112200) {
        // Define table local_risk_configs to be created.
        $table = new xmldb_table('local_risk_configs');

        // Adding fields to table local_risk_configs.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('total_weeks', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '15');
        $table->add_field('risk_threshold', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0.5000');
        $table->add_field('inactivity_days_threshold', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '7');
        $table->add_field('quiz_drop_pct_threshold', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '20.0000');
        $table->add_field('missed_assign_threshold', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2');
        $table->add_field('updated_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table local_risk_configs.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('course_id_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['course_id'], 'course', ['id']);

        // Conditionally launch create table for local_risk_configs.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_risk_snapshots to be created.
        $table = new xmldb_table('local_risk_snapshots');

        // Adding fields to table local_risk_snapshots.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('current_week', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('total_weeks', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('max_consecutive_days_without_access', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('days_with_logs_avg', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0.0000');
        $table->add_field('first_log_days_diff', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('missed_assigns', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('delay_avg', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0.0000');
        $table->add_field('quiz_avg_score', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, '0.0000');
        $table->add_field('snapshot_date', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_risk_snapshots.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('user_id_fk', XMLDB_KEY_FOREIGN, ['user_id'], 'user', ['id']);
        $table->add_key('course_id_fk', XMLDB_KEY_FOREIGN, ['course_id'], 'course', ['id']);

        // Adding indexes to table local_risk_snapshots.
        $table->add_index('user_course_week_uq', XMLDB_INDEX_UNIQUE, ['user_id', 'course_id', 'current_week']);
        $table->add_index('course_week_ix', XMLDB_INDEX_NOTUNIQUE, ['course_id', 'current_week']);
        $table->add_index('snapshot_date_ix', XMLDB_INDEX_NOTUNIQUE, ['snapshot_date']);

        // Conditionally launch create table for local_risk_snapshots.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table local_risk_insights to be created.
        $table = new xmldb_table('local_risk_insights');

        // Adding fields to table local_risk_insights.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('snapshot_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('user_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('course_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('risk_level', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('risk_score', XMLDB_TYPE_NUMBER, '10, 4', null, XMLDB_NOTNULL, null, null);
        $table->add_field('top_explanations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('shap_values', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('evaluated_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys to table local_risk_insights.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('snapshot_id_fk', XMLDB_KEY_FOREIGN, ['snapshot_id'], 'local_risk_snapshots', ['id']);
        $table->add_key('user_id_fk', XMLDB_KEY_FOREIGN, ['user_id'], 'user', ['id']);
        $table->add_key('course_id_fk', XMLDB_KEY_FOREIGN, ['course_id'], 'course', ['id']);

        // Adding indexes to table local_risk_insights.
        $table->add_index('user_course_ix', XMLDB_INDEX_NOTUNIQUE, ['user_id', 'course_id']);
        $table->add_index('course_risk_ix', XMLDB_INDEX_NOTUNIQUE, ['course_id', 'risk_level']);
        $table->add_index('evaluated_at_ix', XMLDB_INDEX_NOTUNIQUE, ['evaluated_at']);

        // Conditionally launch create table for local_risk_insights.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Risk predictor savepoint reached.
        upgrade_plugin_savepoint(true, 2026112200, 'local', 'risk_predictor');
    }

    return true;
}
