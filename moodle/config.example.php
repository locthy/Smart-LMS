<?php  // Moodle configuration file template for Docker & Local environments

unset($CFG);
global $CFG;
$CFG = new stdClass();

// Database configuration (supports environment variables for Docker)
$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST') ?: 'localhost';
$CFG->dbname    = getenv('MOODLE_DB_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('MOODLE_DB_USER') ?: 'moodleuser';
$CFG->dbpass    = getenv('MOODLE_DB_PASS') ?: 'moodlepass';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array (
  'dbpersist' => 0,
  'dbport'    => getenv('MOODLE_DB_PORT') ? (int)getenv('MOODLE_DB_PORT') : 5432,
  'dbsocket'  => '',
);

// Moodle Web URL
$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'http://localhost:8000';

// Absolute path to the moodledata directory
$CFG->dataroot  = getenv('MOODLE_DATAROOT') ?: (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'moodledata');

$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');

// There is no php closing tag in this file,
// it is intentional because it prevents trailing whitespace problems!
