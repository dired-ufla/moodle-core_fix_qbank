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
 * Cleanup-only script for question categories in course category context.
 *
 * Dry-run is the default mode. Use --fix to apply deletions.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2013 Tyler Bannister (tyler.bannister@remote-learner.net)
 * @copyright  2026 Paulo Junior (pauloa.junior@ufla.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');

// Global Moodle objects used in this script:
// - $CFG: Moodle runtime configuration and library paths.
// - $DB: Moodle database API used by all SQL and transaction operations.
//
// High-level flow:
// 1) Read CLI arguments and validate target course category.
// 2) Build random-usage maps (explicit category and context fallback).
// 3) Analyze each question category and collect keep reasons.
// 4) Split categories into spared vs cleanup candidates.
// 5) Dry-run by default; with --fix, delete only eligible categories.

/**
 * Extract question category IDs from random-slot filter payload.
 *
 * @param string|null $filtercondition Raw filter JSON/text.
 * @return int[]
 */
function extract_random_category_ids_from_filtercondition($filtercondition) {
    if (empty($filtercondition) || !is_string($filtercondition)) {
        return array();
    }

    $categoryids = array();
    $decoded = json_decode($filtercondition, true);

    // Preferred path: decode JSON and recursively inspect nested structures.
    if (is_array($decoded)) {
        $stack = array($decoded);
        while (!empty($stack)) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            foreach ($current as $key => $value) {
                $key = strtolower((string)$key);

                if (($key === 'categoryid' || $key === 'questioncategoryid') && is_numeric($value)) {
                    $categoryids[(int)$value] = true;
                    continue;
                }

                if (($key === 'categoryids' || $key === 'questioncategoryids') && is_array($value)) {
                    foreach ($value as $id) {
                        if (is_numeric($id)) {
                            $categoryids[(int)$id] = true;
                        }
                    }
                    continue;
                }

                if ($key === 'category') {
                    if (is_numeric($value)) {
                        $categoryids[(int)$value] = true;
                        continue;
                    }
                    if (is_array($value)) {
                        if (!empty($value['id']) && is_numeric($value['id'])) {
                            $categoryids[(int)$value['id']] = true;
                        }
                        if (!empty($value['value']) && is_numeric($value['value'])) {
                            $categoryids[(int)$value['value']] = true;
                        }
                        if (!empty($value['ids']) && is_array($value['ids'])) {
                            foreach ($value['ids'] as $id) {
                                if (is_numeric($id)) {
                                    $categoryids[(int)$id] = true;
                                }
                            }
                        }
                        if (!empty($value['values']) && is_array($value['values'])) {
                            foreach ($value['values'] as $id) {
                                if (is_numeric($id)) {
                                    $categoryids[(int)$id] = true;
                                }
                            }
                        }
                    }
                }

                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }

    // Fallback path: regex extraction for legacy/non-standard payloads.
    if (empty($categoryids)) {
        if (preg_match_all('/"(?:question)?categoryid"\s*:\s*(\d+)/i', $filtercondition, $matches)) {
            foreach ($matches[1] as $id) {
                $categoryids[(int)$id] = true;
            }
        }
        if (preg_match_all('/"category"\s*:\s*(\d+)/i', $filtercondition, $matches)) {
            foreach ($matches[1] as $id) {
                $categoryids[(int)$id] = true;
            }
        }
        if (preg_match_all('/"(?:question)?categoryids"\s*:\s*\[([^\]]+)\]/i', $filtercondition, $matches)) {
            foreach ($matches[1] as $csv) {
                if (preg_match_all('/\d+/', $csv, $ids)) {
                    foreach ($ids[0] as $id) {
                        $categoryids[(int)$id] = true;
                    }
                }
            }
        }
    }

    return array_keys($categoryids);
}

$long = array('fix' => false, 'help' => false, 'categoryid' => null);
$short = array('f' => 'fix', 'h' => 'help', 'g' => 'categoryid');
list($options, $unrecognized) = cli_get_params($long, $short);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
        "Cleanup unused question categories in course category context.

        Default mode is dry-run. Use --fix to apply deletions.

        Options:
        -h, --help            Print out this help
        -g, --categoryid      Course category ID to analyze
        -f, --fix             Apply cleanup for eligible categories.
                              Without --fix, this runs in dry-run mode.

        Example:
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cclean.php --categoryid=5
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cclean.php --categoryid=5 --fix
        ";

    echo $help;
    die;
}

if (empty($options['categoryid']) || !is_numeric($options['categoryid'])) {
    cli_error('You must provide a valid category ID via --categoryid=<id>.');
}

$categoryid = (int)$options['categoryid'];
$coursecategory = $DB->get_record('course_categories', array('id' => $categoryid), 'id, name');
if (!$coursecategory) {
    cli_error("Category ID {$categoryid} does not exist.");
}

if (empty($options['fix'])) {
    echo "Running in dry-run mode. Add --fix to apply cleanup.\n";
}

echo "Checking course category context: {$coursecategory->name}\n";

$qsrcolumns = $DB->get_columns('question_set_references');
$hasfiltercondition = !empty($qsrcolumns['filtercondition']);
$sqlrandomfields = 'qsr.questionscontextid';
if ($hasfiltercondition) {
    $sqlrandomfields .= ', qsr.filtercondition';
}
$sqlrandom = "SELECT DISTINCT {$sqlrandomfields}
                FROM {question_set_references} qsr
               WHERE qsr.component = :component
                 AND qsr.questionarea = :questionarea";
$randomcontexts = $DB->get_records_sql($sqlrandom, array('component' => 'mod_quiz', 'questionarea' => 'slot'));

$randomcategoryids = array();
$randomcontextidsfallback = array();

// Loop 1: inspect each random slot reference.
// - randomcategoryids: categories explicitly referenced by filter payload.
// - randomcontextidsfallback: contexts used when category is not explicit.
foreach ($randomcontexts as $randomcontext) {
    $mappedcategoryids = array();
    if ($hasfiltercondition && property_exists($randomcontext, 'filtercondition')) {
        $mappedcategoryids = extract_random_category_ids_from_filtercondition($randomcontext->filtercondition);
    }

    if (!empty($mappedcategoryids)) {
        foreach ($mappedcategoryids as $mappedcategoryid) {
            $randomcategoryids[(int)$mappedcategoryid] = true;
        }
    } else {
        $randomcontextidsfallback[(int)$randomcontext->questionscontextid] = true;
    }
}

$recentthreshold = time() - (1 * DAYSECS);
$targetstartdatetime = date('Y-m-d H:i:s');
$targetstarttime = microtime(true);
echo "{$targetstartdatetime} - Start processing course category context: {$coursecategory->id} - {$coursecategory->name}\n";

$sql = 'SELECT qc.id, qc.name, qc.contextid
          FROM {question_categories} qc
          JOIN {context} c ON c.id = qc.contextid
         WHERE c.contextlevel = :contextlevel
           AND c.instanceid = :instanceid
      ORDER BY qc.name';
$params = array('contextlevel' => CONTEXT_COURSECAT, 'instanceid' => $coursecategory->id);
$categories = $DB->get_records_sql($sql, $params);

if (empty($categories)) {
    echo "No question categories found for this target.\n";
    exit(0);
}

$categoriestoclean = array();
$categoriestosave = array();

// Loop 2: classify each category by keep reasons.
// Categories without keep reasons are eligible for cleanup.
foreach ($categories as $category) {
    $keepreasons = array();

    // Reason 1: category has direct question usage in quiz slots.
    $sqlusedcourses = 'SELECT DISTINCT cr.fullname
                         FROM {question_bank_entries} qbe
                         JOIN {question_references} qr ON qr.questionbankentryid = qbe.id
                         JOIN {quiz_slots} qs ON qs.id = qr.itemid
                         JOIN {quiz} qz ON qz.id = qs.quizid
                         JOIN {course} cr ON cr.id = qz.course
                        WHERE qbe.questioncategoryid = :categoryid
                          AND qr.component = :component
                          AND qr.questionarea = :questionarea
                     ORDER BY cr.fullname';
    $usageparams = array(
        'categoryid' => $category->id,
        'component' => 'mod_quiz',
        'questionarea' => 'slot'
    );
    $usedcoursenames = $DB->get_fieldset_sql($sqlusedcourses, $usageparams);
    if (!empty($usedcoursenames)) {
        $keepreasons['courses'] = $usedcoursenames;
    }

    // Reason 2: category contains recently edited questions.
    $sqlrecent = 'SELECT DISTINCT q.id
                    FROM {question_bank_entries} qbe
                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    JOIN {question} q ON q.id = qv.questionid
                   WHERE qbe.questioncategoryid = :categoryid
                     AND q.timemodified >= :recentthreshold';
    $recentquestions = $DB->get_records_sql($sqlrecent, array(
        'categoryid' => $category->id,
        'recentthreshold' => $recentthreshold
    ));
    if (!empty($recentquestions)) {
        $keepreasons['recentedits'] = $recentquestions;
    }

    // Reason 3: category is used by random questions.
    // Check explicit category mapping first, then context fallback.
    $usedinrandom = !empty($randomcategoryids[(int)$category->id]);
    if (!$usedinrandom) {
        $usedinrandom = !empty($randomcontextidsfallback[(int)$category->contextid]);
        if ($usedinrandom) {
            $keepreasons['randomcontextfallback'] = true;
        }
    }
    if ($usedinrandom) {
        $keepreasons['randomusage'] = true;
    }

    if (empty($keepreasons)) {
        $categoriestoclean[] = $category;
    } else {
        $categoriestosave[] = array('category' => $category, 'reasons' => $keepreasons);
    }
}

cli_heading('Categories spared with reasons');
if (empty($categoriestosave)) {
    echo "No spared categories.\n";
} else {
    // Loop 3: print a human-readable diagnosis for spared categories.
    foreach ($categoriestosave as $savedcategory) {
        $category = $savedcategory['category'];
        $reasons = $savedcategory['reasons'];

        echo "--> {$category->name}\n";
        if (!empty($reasons['courses'])) {
            echo "    1) Used by quiz questions in courses: " . implode(', ', $reasons['courses']) . "\n";
        }
        if (!empty($reasons['recentedits'])) {
            $recentcount = count($reasons['recentedits']);
            echo "    2) {$recentcount} question(s) edited in the last 1 day\n";
        }
        if (!empty($reasons['randomusage'])) {
            echo "    3) Used as source for random quiz questions\n";
            if (!empty($reasons['randomcontextfallback'])) {
                echo "       (fallback by context when random filter did not expose a category ID)\n";
            }
        }
    }
}

cli_heading('Categories eligible for cleanup in this course category context');

$cleanedcount = 0;

// Loop 4: print cleanup candidates and optionally apply deletion (--fix).
foreach ($categoriestoclean as $category) {
    echo "--> {$category->name}\n";
    if (!empty($options['fix'])) {
        echo "Cleaning...";
        $transaction = $DB->start_delegated_transaction();
        question_category_delete_safe($category);
        $transaction->allow_commit();
        $cleanedcount += 1;
        echo " Done!\n";
    }
}

if (!empty($options['fix'])) {
    echo "Found and removed {$cleanedcount} eligible question categories\n";
} else if (!empty($categoriestoclean)) {
    echo "Found " . count($categoriestoclean) . " eligible question categories. To apply, run:\n";
    echo "\$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cclean.php --categoryid={$coursecategory->id} --fix\n";
} else {
    echo "No eligible question categories found.\n";
}

$targetenddatetime = date('Y-m-d H:i:s');
$targetelapsedseconds = microtime(true) - $targetstarttime;
echo "{$targetenddatetime} - Finished processing course category context: {$coursecategory->id} - {$coursecategory->name} ({$targetelapsedseconds}s)\n";
