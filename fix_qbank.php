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
 * This script audits question categories for a specific course or an entire
 * course category.
 *
 * A category is considered eligible for cleanup when all its questions are
 * unused in quiz slots from the same course and the category context is not
 * referenced by random question usage.
 *
 * With --fix, eligible categories are removed using
 * question_category_delete_safe().
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
$long = array('fix'  => false, 'help' => false, 'courseid' => null, 'categoryid' => null);
$short = array('f' => 'fix', 'h' => 'help', 'c' => 'courseid', 'g' => 'categoryid');

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
        -c, --courseid        Course ID to analyze
        -g, --categoryid      Course category ID to analyze
        -f, --fix             Remove eligible question categories from the DB.
                      If not specified only check and report findings.
        Example:
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --courseid=2
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --categoryid=5
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --courseid=2 -f
        ";

    echo $help;
    die;
}

// Validate target scope: either a course or a course category must be provided.
$hascourseid = !empty($options['courseid']);
$hascategoryid = !empty($options['categoryid']);

if ($hascourseid && $hascategoryid) {
    cli_error('Provide either --courseid=<id> or --categoryid=<id>, not both.');
}

if (!$hascourseid && !$hascategoryid) {
    cli_error('You must provide --courseid=<id> or --categoryid=<id>.');
}

$iscategorymode = false;
$coursecategory = null;
$coursestoprocess = array();

if ($hascourseid) {
    if (!is_numeric($options['courseid'])) {
        cli_error('You must provide a valid course ID via --courseid=<id>.');
    }

    $courseid = (int)$options['courseid'];
    $course = $DB->get_record('course', array('id' => $courseid), 'id, fullname');
    if (!$course) {
        cli_error("Course ID {$courseid} does not exist.");
    }

    $coursestoprocess[] = $course;
} else {
    if (!is_numeric($options['categoryid'])) {
        cli_error('You must provide a valid category ID via --categoryid=<id>.');
    }

    $categoryid = (int)$options['categoryid'];
    $coursecategory = $DB->get_record('course_categories', array('id' => $categoryid), 'id, name');
    if (!$coursecategory) {
        cli_error("Category ID {$categoryid} does not exist.");
    }

    $coursestoprocess = $DB->get_records('course', array('category' => $categoryid), 'fullname ASC', 'id, fullname');
    if (empty($coursestoprocess)) {
        echo "No courses found in category {$coursecategory->name}.\n";
        exit(0);
    }

    $iscategorymode = true;
    echo "Checking courses in category: {$coursecategory->name}\n";
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

$totalcourses = count($coursestoprocess);
$processedcourses = 0;
$recentthreshold = time() - (60 * DAYSECS);
foreach ($coursestoprocess as $course) {
    if ($iscategorymode) {
        $processedcourses += 1;
        echo "Checking course {$processedcourses}/{$totalcourses}\n";
    }

    $courseid = (int)$course->id;
    $coursestartdatetime = date('Y-m-d H:i:s');
    $coursestarttime = microtime(true);
    echo "{$coursestartdatetime} - Start processing course: {$courseid} - {$course->fullname}\n";

    // Fetch all question categories for the target course context, ordered by category name.
    $sql = 'SELECT qc.id, qc.name, qc.contextid
              FROM {question_categories} qc
              JOIN {context} c ON c.id = qc.contextid
             WHERE c.contextlevel = :contextlevel
               AND c.instanceid = :courseid
          ORDER BY qc.name';
    $params = array('contextlevel' => CONTEXT_COURSE, 'courseid' => $courseid);
    $categories = $DB->get_records_sql($sql, $params);

    // Skip courses without question categories.
    if (empty($categories)) {
        echo "No question categories found for this course.\n";
        continue;
    }

    // Prepare result buckets: eligible and categories referenced by random usage.
    $categoriestoclean = array();
    $categoriesusedinrandom = array();

    // Iterate over each category to evaluate random usage and cleanup eligibility.
    foreach ($categories as $category) {
        // Count total questions in the current category for comparison with unused questions.
        $questioncount = $DB->count_records('question_bank_entries', array('questioncategoryid' => $category->id));

                // Build and run a query that counts category questions with no
                // quiz-slot references in any course and no updates in the last 60 days.
                $sqlunused = 'SELECT COUNT(qbe.id) FROM {question_bank_entries} qbe
                                WHERE qbe.questioncategoryid = :categoryid
                                    AND NOT EXISTS (
                                        SELECT 1
                                            FROM {question_references} qr
                                            JOIN {quiz_slots} qs ON qs.id = qr.itemid
                                                WHERE qr.questionbankentryid = qbe.id
                                                    AND qr.component = :component
                                                    AND qr.questionarea = :questionarea
                                    )
                                    AND NOT EXISTS (
                                        SELECT 1
                                            FROM {question_versions} qv
                                            JOIN {question} q ON q.id = qv.questionid
                                                WHERE qv.questionbankentryid = qbe.id
                                                    AND q.timemodified >= :recentthreshold
                                    )';
        $unusedparams = array(
            'categoryid' => $category->id,
            'component' => 'mod_quiz',
                        'questionarea' => 'slot',
                        'recentthreshold' => $recentthreshold
        );
        $unusedquestioncount = (int)$DB->count_records_sql($sqlunused, $unusedparams);

        // Flag categories whose context appears in random-question references.
        $usedinrandom = !empty($randomcontextids[(int)$category->contextid]);
        if ($usedinrandom) {
            $categoriesusedinrandom[] = $category->name;
        }

        // A category is cleanable only when it is not used by random slots and
        // all its questions are unused in quiz slots from any course.
        if (!$usedinrandom && ($unusedquestioncount === $questioncount)) {
            $categoriestoclean[] = $category;
        }
    }

    cli_heading("Categories eligible for cleanup in this course");

    $i = 0;
    // Walk through all eligible categories, report each one, and optionally delete it.
    foreach ($categoriestoclean as $category) {
        $i += 1;
        echo "--> {$category->name}\n";
        if (!empty($options['fix'])) {
            echo "Cleaning...";
            // One transaction per category.
            $transaction = $DB->start_delegated_transaction();
            question_category_delete_safe($category);
            $transaction->allow_commit();
            echo "  Done!\n";
        }
    }

    // Print a final summary: removed count with --fix, or guidance for a dry-run result.
    if (($i > 0) && !empty($options['fix'])) {
        echo "Found and removed {$i} eligible question categories\n";
    } else if ($i > 0) {
        echo "Found {$i} eligible question categories. To fix, run:\n";
        if ($iscategorymode) {
            echo "\$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --categoryid={$coursecategory->id} --fix\n";
        } else {
            echo "\$sudo -u www-data /usr/bin/php admin/cli/fix_qbank.php --courseid={$courseid} --fix\n";
        }
    } else {
        echo "No eligible question categories found.\n";
    }

    $courseenddatetime = date('Y-m-d H:i:s');
    $courseelapsedseconds = microtime(true) - $coursestarttime;
    echo "{$courseenddatetime} - Finished processing course: {$courseid} - {$course->fullname}\n";
}
