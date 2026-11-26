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
 * English strings for local_ai_qa.
 *
 * @package    local_ai_qa
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allmycourses'] = 'All my courses ({$a})';
$string['apikey'] = 'Service API key';
$string['apikey_desc'] = 'Shared secret sent in the X-RAG-Key header; must match RAG_API_KEY of the AI service. Defaults to the AI_SERVICE_KEY environment variable.';
$string['ask'] = 'Send';
$string['askplaceholder'] = 'Ask about the course documents… (Enter to send, Shift+Enter for a new line)';
$string['close'] = 'Collapse assistant';
$string['documents'] = 'Indexed documents';
$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Show the document assistant to users.';
$string['error'] = 'Something went wrong: {$a}';
$string['greeting'] = 'Hi! I answer questions using the documents of **{$a}**, and I cite the passages I used, like this [1].';
$string['loading'] = 'Searching the documents…';
$string['newchat'] = 'New conversation';
$string['nodocuments'] = 'No documents indexed yet.';
$string['nodocumentsteacher'] = 'No documents indexed yet. Click "Sync course files" to index the File, Folder and Page activities of this course.';
$string['notconfigured'] = 'The document assistant service is not configured.';
$string['open'] = 'Open document assistant';
$string['page'] = 'page {$a}';
$string['pin'] = 'Pin beside the page';
$string['pluginname'] = 'AI Course Q&A';
$string['privacy:metadata:ragservice'] = 'Questions are sent to the RAG service configured by the administrator to search the course documents.';
$string['privacy:metadata:ragservice:history'] = 'The recent messages of the conversation, to understand follow-up questions.';
$string['privacy:metadata:ragservice:question'] = 'The question typed by the user.';
$string['ai_qa:manage'] = 'Index course files in the document assistant';
$string['ai_qa:use'] = 'Use the document assistant';
$string['resize'] = 'Drag to resize';
$string['serviceerror'] = 'The document assistant service returned an error: {$a}';
$string['serviceunavailable'] = 'The document assistant service cannot be reached.';
$string['serviceurl'] = 'Service URL';
$string['serviceurl_desc'] = 'Base URL of the Smart-LMS AI service, as seen from the Moodle server. Full Docker: http://ai-service:8001. Hybrid mode (Moodle on host PHP): http://localhost:8001. Defaults to the AI_SERVICE_URL environment variable.';
$string['showoutsidecourse'] = 'Show outside courses';
$string['showoutsidecourse_desc'] = 'On pages outside a course (dashboard, profile…) the assistant searches all the courses the user is enrolled in.';
$string['sources'] = 'Sources ({$a})';
$string['suggestion1student'] = 'Summarise the main points of the course documents';
$string['suggestion1teacher'] = 'Write 5 multiple-choice review questions from the documents';
$string['suggestion2student'] = 'Explain the most important concept in chapter 1';
$string['suggestion2teacher'] = 'Turn the documents into a lecture outline';
$string['suggestion3student'] = 'What are the assessment rules and deadlines?';
$string['suggestion3teacher'] = 'Which concepts are students likely to confuse?';
$string['sync'] = 'Sync course files';
$string['syncdone'] = 'Done: {$a->added} added, {$a->unchanged} unchanged, {$a->removed} removed, {$a->skipped} unsupported.';
$string['syncing'] = 'Indexing course files…';
$string['tablabel'] = 'Assistant';
