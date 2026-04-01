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
 * This script audits question categories in course category context.
 *
 * A category is considered eligible for cleanup when all its questions are
 * unused in quiz slots and the category context is not referenced by random
 * question usage.
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
$long = array('fix'  => false, 'help' => false, 'categoryid' => null);
$short = array('f' => 'fix', 'h' => 'help', 'g' => 'categoryid');

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
                "Audit and optionally clean question categories created in a
                course category context.

                Categories with random question usage context are excluded from
                cleanup. With --fix, only categories eligible for cleanup are removed
                using Moodle safe deletion routines.

        Options:
        -h, --help            Print out this help
                -g, --categoryid      Course category ID to analyze
        -f, --fix             Remove eligible question categories from the DB.
                      If not specified only check and report findings.
        Example:
                \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cat.php --categoryid=5
                \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cat.php --categoryid=5 --fix
        ";

    echo $help;
    die;
}

// Validate target category.
$hascategoryid = !empty($options['categoryid']);

if (!$hascategoryid) {
    cli_error('You must provide --categoryid=<id>.');
}

if (!is_numeric($options['categoryid'])) {
    cli_error('You must provide a valid category ID via --categoryid=<id>.');
}

$categoryid = (int)$options['categoryid'];
$coursecategory = $DB->get_record('course_categories', array('id' => $categoryid), 'id, name');
if (!$coursecategory) {
    cli_error("Category ID {$categoryid} does not exist.");
}

echo "Checking course category context: {$coursecategory->name}\n";

$targetstocheck = array(
    (object)array(
        'title' => "course category context: {$coursecategory->id} - {$coursecategory->name}",
        'contextlevel' => CONTEXT_COURSECAT,
        'instanceid' => (int)$coursecategory->id,
        'fixcommand' => "\$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cat.php --categoryid={$coursecategory->id} --fix"
    )
);

// Collect context IDs used by random quiz questions to avoid deleting categories that
// still feed random question slots; indexing them enables quick eligibility checks per category.
$sqlrandom = 'SELECT DISTINCT qsr.questionscontextid
                FROM {question_set_references} qsr
               WHERE qsr.component = :component
                 AND qsr.questionarea = :questionarea';
$randomcontexts = $DB->get_records_sql($sqlrandom, array('component' => 'mod_quiz', 'questionarea' => 'slot'));

$randomcontextids = array();
foreach ($randomcontexts as $randomcontext) {
    echo (int)$randomcontext->questionscontextid . "\n";
    $randomcontextids[(int)$randomcontext->questionscontextid] = true;
}

$totaltargets = count($targetstocheck);
$processedtargets = 0;
$recentthreshold = time() - (60 * DAYSECS);
foreach ($targetstocheck as $target) {
    $processedtargets += 1;
    if ($totaltargets > 1) {
        echo "Checking target {$processedtargets}/{$totaltargets}\n";
    }

    $targetstartdatetime = date('Y-m-d H:i:s');
    $targetstarttime = microtime(true);
    echo "{$targetstartdatetime} - Start processing {$target->title}\n";

    // Fetch all question categories for the target context, ordered by category name.
    $sql = 'SELECT qc.id, qc.name, qc.contextid
              FROM {question_categories} qc
              JOIN {context} c ON c.id = qc.contextid
             WHERE c.contextlevel = :contextlevel
               AND c.instanceid = :instanceid
          ORDER BY qc.name';
    $params = array('contextlevel' => $target->contextlevel, 'instanceid' => $target->instanceid);
    $categories = $DB->get_records_sql($sql, $params);

    // Skip targets without question categories.
    if (empty($categories)) {
        echo "No question categories found for this target.\n";
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

        echo $category->name . "\n";

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

    cli_heading("Categories eligible for cleanup in this course category context");

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
        echo "{$target->fixcommand}\n";
    } else {
        echo "No eligible question categories found.\n";
    }

    $targetenddatetime = date('Y-m-d H:i:s');
    $targetelapsedseconds = microtime(true) - $targetstarttime;
    echo "{$targetenddatetime} - Finished processing {$target->title} ({$targetelapsedseconds}s)\n";
}
