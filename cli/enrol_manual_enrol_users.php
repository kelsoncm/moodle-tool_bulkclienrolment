<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * CLI script for tool_bulkclienrolment.
 *
 * @package     tool_bulkclienrolment
 * @subpackage  cli
 * @copyright   2025 Kelson Medeiros <kelsoncm@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');
require_once($CFG->dirroot . '/enrol/externallib.php');
require_once($CFG->dirroot . '/local/suap/locallib.php');
require_once($CFG->dirroot . '/local/suap/classes/Jsv4/Validator.php');
require_once($CFG->dirroot . '/local/suap/api/servicelib.php');

// Get the cli options.
list($options, $unrecognized) = cli_get_params(
    ['filename' => null],
    ['help' => false],
    ['h' => 'help']
);

$help =
    'TOOL BULK CLIENT ENROLMENT
========
Please include a list of options and associated actions.

Please include an example of usage.

`groupnames` are separated by commas and can be empty.

File format:
    course_shortname,username,userfirstname,userlastname,role,groupnames
    "course_shortname","username","userfirstname","userlastname","rolename","groupnames"

OPTIONS:
    --filename        CSV file containing user enrolment data (required)
    --help, -h        Print out this help

    --verbose, -V     Print verbose output
    --quiet, -q       Print less output
    --debug, -d       Print debug output
    --dryrun, -n      Perform a dry run without making any changes
';
if ($unrecognized) {
    $unrecognized = implode("\n\t", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}


if ($options['help']) {
    cli_writeln($help);
    die();
}

if (!$options['filename']) {
    cli_writeln($help);
    die();
}


// Verifica se o curso existe
function get_course_by_shortname($shortname)
{
    $response = moodle_api_call('core_course_get_courses_by_field', [
        'field' => 'shortname',
        'value' => $shortname
    ]);
    return $response['courses'][0] ?? null;
}

/*
// Configurações do Moodle
define('MOODLE_URL', 'https://seumoodle.com/webservice/rest/server.php');
define('MOODLE_TOKEN', 'SEU_TOKEN_AQUI');
define('MOODLE_FORMAT', 'json');

// Mapeamento de papéis
$roleMap = [
    'student' => 5,
    'teacher' => 3,
    'editingteacher' => 4
];

// Função para chamada à API do Moodle
function moodle_api_call($function, $params = [], $method = 'GET')
{
    $params['wstoken'] = MOODLE_TOKEN;
    $params['wsfunction'] = $function;
    $params['moodlewsrestformat'] = MOODLE_FORMAT;

    $url = MOODLE_URL;
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method
    ];

    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
    } else {
        $options[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Verifica se o usuário existe
function get_user_by_username($username)
{
    $response = moodle_api_call('core_user_get_users', [
        'criteria[0][key]' => 'username',
        'criteria[0][value]' => $username
    ]);
    return $response['users'][0] ?? null;
}

// Cria usuário com auth manual
function create_user($username, $firstname, $lastname)
{
    $email = $username . '@seudominio.com';
    $response = moodle_api_call('core_user_create_users', [
        'users[0][username]' => $username,
        'users[0][firstname]' => $firstname,
        'users[0][lastname]' => $lastname,
        'users[0][auth]' => 'manual',
        'users[0][password]' => 'SenhaForte123!',
        'users[0][email]' => $email
    ], 'POST');

    return $response[0]['id'] ?? null;
}

// Matricula o usuário
function enrol_user($userId, $courseId, $roleId)
{
    moodle_api_call('enrol_manual_enrol_users', [
        'enrolments[0][roleid]' => $roleId,
        'enrolments[0][userid]' => $userId,
        'enrolments[0][courseid]' => $courseId
    ], 'POST');
}
*/

function get_enrol_instance($course_id, $enrol_type = 'manual')
{
    foreach (\enrol_get_instances($course_id, FALSE) as $i) {
        if ($i->enrol == $enrol_type) {
            return $i;
        }
    }
    return null;
}

// Processa o CSV
function process_csv($filePath)
{
    global $DB;
    $courses = [];

    if (!file_exists($filePath)) {
        echo "Arquivo CSV não encontrado: $filePath\n";
        exit(1);
    }

    if (($handle = fopen($filePath, 'r')) !== false) {
        $header = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            $username = $row['username'];
            $courseShortname = $row['course_shortname'];
            $groupname = $row['groupname'];
            echo "Tentando matricular '$username' no curso '$courseShortname' como 'student' no grupo '$groupname'... ";
            if (!in_array($courseShortname, $courses)) {
                $course = $DB->get_record('course', ['shortname' => $courseShortname]);
                if (!$course) {
                    echo "Curso não encontrado.\n";
                    continue;
                }

                $course->context = \context_course::instance($course->id);
                $course->enrol = enrol_get_plugin('manual');
                $course->enrol_instance = get_enrol_instance($course->id, 'manual');
                if ($course->enrol_instance == null) {
                    echo "Curso não tem enrol 'manual'.\n";
                    continue;
                }
                $courses[$courseShortname] = $course;
            } else {
                $course = $courses[$courseShortname];
            }

            $user = $DB->get_record("user", ["username" => $username]);

            if (!$user) {
                echo "Usuário não encontrado.\n";
                continue;
            }

            if (is_enrolled($course->context, $user)) {
                echo "Usuário já está matriculado no curso. ";
            } else {
                $course->enrol->enrol_user($course->enrol_instance, $user->id, 5, time(), 0, \ENROL_USER_ACTIVE);
                echo "Usuário matriculado com sucesso. ";
            }

            $group = $DB->get_record('groups', ['courseid' => $course->id, 'name' => $groupname]);
            if (!$group) {
                $groupid = \groups_create_group((object)['courseid' => $course->id, 'name' => $groupname]);
                $group = $DB->get_record('groups', ['id' => $groupid]);
                echo "Grupo criado. ";
            }

            $group_membership = $DB->get_record('groups_members', ['groupid' => $group->id, 'userid' => $user->id]);
            if ($group_membership) {
                echo "Usuário já está no grupo.\n";
                continue;
            }
            \groups_add_member($group->id, $user->id);
            echo "Usuário inserido no grupo.\n";
        }
        fclose($handle);
    }
}


process_csv($options['filename']);
