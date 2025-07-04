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

define('ENROL_MANUAL_ENROL_USERS_HELP_MESSAGE', '
TOOL BULK CLIENT ENROLMENT
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
');


class enrol_manual_enrol_users
{

    private $options = null;
    private $unrecognized = null;
    private $filePath = null;
    private $courses = [];
    private $course_shortname = null;
    private $course = null;
    private $row = null;
    private $user_username = null;
    private $user = null;
    private $enrol_type = null;
    private $role_name = null;
    private $role = null;

    public function __construct()
    {
        $this->get_options();
        $this->validate_options();
    }

    function get_options()
    {
        // Get the cli options.
        list($this->options, $this->unrecognized) = cli_get_params(
            ['filename' => null],
            ['help' => false],
            ['h' => 'help']
        );
    }

    function validate_options()
    {
        if ($this->unrecognized) {
            $unrecognized = implode("\n\t", $this->unrecognized);
            cli_error(get_string('cliunknowoption', 'admin', $this->unrecognized));
        }

        if ($this->options['help']) {
            cli_writeln(ENROL_MANUAL_ENROL_USERS_HELP_MESSAGE);
            die();
        }

        if (!$this->options['filename']) {
            cli_writeln(ENROL_MANUAL_ENROL_USERS_HELP_MESSAGE);
            die();
        }
        $this->filePath = $this->options['filename'];
    }

    function open_csv_file()
    {
        // Verifica se o arquivo existe e pode ser aberto.
        if (!file_exists($this->filePath)) {
            die("Arquivo CSV não encontrado: $this->filePath.\n");
        }

        $handle = fopen($this->filePath, 'rb');
        if (!$handle) {
            die("Erro ao abrir o arquivo $this->filePath.\n");
        }

        // Tratar BOM (Byte Order Mark) para arquivos UTF-8.
        $primeirosBytes = fread($handle, 3);
        $temBOM = ($primeirosBytes === "\xEF\xBB\xBF");
        if (!$temBOM) {
            rewind($handle);
        }

        return $handle;
    }

    function get_row($header, $data)
    {
        $this->row = array_combine($header, $data);

        $this->user_username = $this->row['user_username'];
        $this->course_shortname = $this->row['course_shortname'];

        return $this->row !== false && $this->row !== null && count($this->row) > 0;
    }

    function get_role()
    {
        global $DB;

        $this->role_name = isset($this->row['role_name']) ? $this->row['role_name'] : 'student';
        $this->role = $DB->get_record('role', ['shortname' => $this->role_name]);
        return $this->role != null;
    }

    function create_or_update_course()
    {
        global $DB;

        if (in_array($this->course_shortname, $this->courses)) {
            $this->course = $this->courses[$this->course_shortname];
            return false;
        }

        $this->course = $DB->get_record('course', ['shortname' => $this->course_shortname]);
        if ($this->course) {
            $this->course->context = \context_course::instance($this->course->id);
            $this->courses[$this->course_shortname] = $this->course;
            return false;
        }

        $data = [
            "category" => 1,
            "shortname" => $this->course_shortname,
            "fullname" => $this->course_shortname,
            "idnumber" => $this->course_shortname,
            "visible" => 0,
            "enablecompletion" => 1,
            // "startdate"=>time(),
            "showreports" => 1,
            "completionnotify" => 1,

            // "customfield_campus_id" => $this->json->campus->id,
            // "customfield_campus_descricao" => $this->json->campus->descricao,
            // "customfield_campus_sigla" => $this->json->campus->sigla,

            // "customfield_curso_id" => $this->json->curso->id,
            // "customfield_curso_codigo" => $this->json->curso->codigo,
            // "customfield_curso_descricao" => $this->json->curso->descricao,
            // "customfield_curso_nome" => $this->json->curso->nome,
        ];

        $this->course = \create_course((object)$data);
        return true;
    }

    function create_or_update_user()
    {
        global $DB;
        $this->user = $DB->get_record("user", ["username" => $user_username]);
        return $this->user != null;
    }

    function get_enrol()
    {
        $this->enrol_type = isset($this->row['enrol_type']) ? $this->row['enrol_type'] : 'manual';
        $this->course->enrol = enrol_get_plugin($this->enrol_type);
        foreach (\enrol_get_instances($this->course->id, FALSE) as $i) {
            if ($i->enrol == $this->enrol_type) {
                $this->course->enrol_instance = $i->enrol;
                return true;
            }
        }
        return false;
    }

    function create_or_update_enrolment()
    {
        if (!is_enrolled($this->course->context, $this->user)) {
            $this->course->enrol->enrol_user($this->course->enrol_instance, $this->user->id, 5, time(), 0, \ENROL_USER_ACTIVE);

            $enrol = new \enrol_manual_plugin();
            $enrol->enrol_user($this->course->enrol_instance, $this->user->id, $this->role->id);
        }

        return true;
    }

    function add_user_to_groups()
    {
        global $DB;

        foreach (explode($this->row['group_names'], ',') as $group_name) {
            $group = $DB->get_record('groups', ['courseid' => $this->course->id, 'name' => $group_name]);
            if ($group) {
                $groupid = $group->id;
                echo "Grupo $group_name 🆕. ";
            } else {
                $groupid = \groups_create_group((object)['courseid' => $this->course->id, 'name' => $group_names]);
                echo "Grupo $group_name ✅. ";
            }

            if ($DB->get_record('groups_members', ['groupid' => $groupid, 'userid' => $this->user->id])) {
                echo "Engrupamento $group_name ✅.\n";
            } else {
                \groups_add_member($groupid, $this->user->id);
                echo "Engrupamento $group_name 🆕.\n";
            }
        }
    }

    function process_csv()
    {
        $handle = $this->open_csv_file();

        $header = fgetcsv($handle);
        $i = 1;
        echo "Row #1. Header ✅.\n";
        while (($data = fgetcsv($handle)) !== false) {
            $i++;
            if (!$this->get_row($header, $data)) {
                echo "Row #$i ❌.\n";
                continue;
            } else {
                echo "Row #$i. ";
            }

            if (!$this->get_role()) {
                echo "Role '$this->role_name'❌. ";
                continue;
            } else {
                echo "Role '$this->role_name'✅. ";
            }

            echo "Course '$this->course_shortname'" . ($this->create_or_update_course() ? '❌' : '✅') . ". ";
            echo "User '$this->user_username'" . ($this->create_or_update_user() ? '❌' : '✅') . ". ";


            // if (!$this->get_enrol()) {
            //     echo "Enrol '$this->enrol_type' ❌.\n";
            //     continue;
            // } else {
            //     echo "Enrol '$this->enrol_type' ✅.\n";
            // }


            // if (!$this->create_or_update_enrolment()) {
            //     echo "Enrolment 🆕.";
            // } else {
            //     echo "Enrolment ✅.";
            // }

            // $this->add_user_to_groups();

            echo "\n";
        }
        fclose($handle);
    }
}

$enrol_manual_enrol_users = new enrol_manual_enrol_users();
$enrol_manual_enrol_users->process_csv();
