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
 * This script audits question categories for a specific course.
 *
 * A category is considered eligible for cleanup when all its questions are
 * unused in quiz slots from the same course and the category context is not
 * referenced by random question usage.
 *
 * With --fix, eligible categories are removed using question_category_delete_safe().
 *
 * @package    core
 * @subpackage cli
 * @copyright  2013 Tyler Bannister (tyler.bannister@remote-learner.net)
 * @copyright  2026 Paulo Júnior (pauloa.junior@ufla.br) - script based on Tyler Bannister's work
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Bootstrap Moodle and load CLI and question bank utilities.
require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/questionlib.php');

// Define supported CLI options in long and short forms.
$long = array('fix'  => false, 'help' => false, 'courseid' => null);
$short = array('f' => 'fix', 'h' => 'help', 'c' => 'courseid');

// Parse CLI arguments and capture any unrecognized options.
list($options, $unrecognized) = cli_get_params($long, $short);

// Fail fast when unsupported CLI flags are provided.
if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

// Show usage instructions and exit early when help is requested.
if ($options['help']) {
    $help =
        "Audit and optionally clean question categories for a course.

        This script inspects question categories in the course context and
        evaluates if every question in each category is unused by quiz slots
        in that same course.

        Categories with random question usage context are excluded from
        cleanup. With --fix, only categories eligible for cleanup are removed
        using Moodle safe deletion routines.

        Options:
        -h, --help            Print out this help
        -c, --courseid        Mandatory course ID
        -f, --fix             Remove eligible question categories from the DB.
                      If not specified only check and report findings.
        Example:
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --courseid=2
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --courseid=2 -f
        ";

    echo $help;
    die;
}

// Validate the required course ID input and ensure the target course exists.
if (empty($options['courseid']) || !is_numeric($options['courseid'])) {
    cli_error('You must provide a valid course ID via --courseid=<id>.');
}

$courseid = (int)$options['courseid'];
if (!$DB->record_exists('course', array('id' => $courseid))) {
    cli_error("Course ID {$courseid} does not exist.");
}

// Print a section header to indicate the start of the category audit phase.
cli_heading('Auditing question categories for cleanup eligibility');

// Fetch all question categories for the target course context, ordered by category name.
$sql = 'SELECT qc.id, qc.name, qc.contextid
          FROM {question_categories} qc
          JOIN {context} c ON c.id = qc.contextid
         WHERE c.contextlevel = :contextlevel
           AND c.instanceid = :courseid
      ORDER BY qc.name';
$params = array('contextlevel' => CONTEXT_COURSE, 'courseid' => $courseid);
$categories = $DB->get_records_sql($sql, $params);

// Exit early when the course has no question categories.
if (empty($categories)) {
    echo "No question categories found for course {$courseid}.\n";
    exit(0);
}

// Collect context IDs used by random quiz questions to avoid deleting categories that
// still feed random question slots; indexing them enables quick eligibility checks per category.
$sqlrandom = 'SELECT DISTINCT qsr.questionscontextid
                FROM {question_set_references} qsr
               WHERE qsr.component = :component
                 AND qsr.questionarea = :questionarea';
$randomcontexts = $DB->get_records_sql($sqlrandom, array('component' => 'mod_quiz', 'questionarea' => 'slot'));

$randomcontextids = array();
foreach ($randomcontexts as $randomcontext) {
    $randomcontextids[(int)$randomcontext->questionscontextid] = true;
}

// Prepare result buckets: eligible, ineligible, and categories referenced by random usage.
$categoriestoclean = array();
$categoriesnottoclean = array();
$categoriesusedinrandom = array();

foreach ($categories as $category) {
    $questioncount = $DB->count_records('question_bank_entries', array('questioncategoryid' => $category->id));

    $sqlunused = 'SELECT COUNT(qbe.id)
                    FROM {question_bank_entries} qbe
                   WHERE qbe.questioncategoryid = :categoryid
                     AND NOT EXISTS (
                         SELECT 1
                           FROM {question_references} qr
                           JOIN {quiz_slots} qs ON qs.id = qr.itemid
                           JOIN {quiz} qz ON qz.id = qs.quizid
                          WHERE qr.questionbankentryid = qbe.id
                            AND qr.component = :component
                            AND qr.questionarea = :questionarea
                            AND qz.course = :courseidforusage
                     )';
    $unusedparams = array(
        'categoryid' => $category->id,
        'component' => 'mod_quiz',
        'questionarea' => 'slot',
        'courseidforusage' => $courseid
    );
    $unusedquestioncount = (int)$DB->count_records_sql($sqlunused, $unusedparams);

    $usedinrandom = !empty($randomcontextids[(int)$category->contextid]);
    if ($usedinrandom) {
        $categoriesusedinrandom[] = $category->name;
    }

    if (!$usedinrandom && ($unusedquestioncount === $questioncount)) {
        $categoriestoclean[] = $category;
    } else {
        $categoriesnottoclean[] = $category;
    }

    $usedinrandomtext = $usedinrandom ? 'sim' : 'nao';

    echo "{$category->name} ({$unusedquestioncount} nao usadas de {$questioncount}, random: {$usedinrandomtext})\n";
}

echo "Categories that will NOT be cleaned:\n";
if (!empty($categoriesnottoclean)) {
    foreach ($categoriesnottoclean as $category) {
        echo "- {$category->name}\n";
    }
} else {
    echo "- none\n";
}

cli_heading('Checking categories eligible for cleanup');

$i = 0;
foreach ($categoriestoclean as $category) {
    $i += 1;
    echo "Found category eligible for cleanup: {$category->name}\n";
    if (!empty($options['fix'])) {
        echo "Cleaning...";
        // One transaction per category.
        $transaction = $DB->start_delegated_transaction();
        question_category_delete_safe($category);
        $transaction->allow_commit();
        echo "  Done!\n";
    }
}

if (($i > 0) && !empty($options['fix'])) {
    echo "Found and removed {$i} eligible question categories\n";
} else if ($i > 0) {
    echo "Found {$i} eligible question categories. To fix, run:\n";
    echo "\$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --courseid={$courseid} --fix\n";
} else {
    echo "No eligible question categories found.\n";
}
