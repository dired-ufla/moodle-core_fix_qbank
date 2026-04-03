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
 * Move-only script for question categories in course category context.
 *
 * Dry-run is the default mode. Use --fix to apply moves.
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
// - $CFG: access to Moodle configuration and library paths.
// - $DB: database abstraction layer (queried inside functions and main flow).
// - $USER (implicit): CLI session user set to admin for capability-checked APIs.

// High-level flow:
// 1) Parse CLI options and validate target course category.
// 2) Build random-usage maps (explicit category and context fallback).
// 3) Build keep reasons per category (courses, recent edits, random usage).
// 4) Evaluate move eligibility and split into eligible vs blocked.
// 5) Dry-run report by default; with --fix, execute category moves.

/**
 * Move category to the target course context via Moodle API.
 *
 * @param stdClass $category Category record.
 * @param int $targetcourseid Target course ID.
 * @return string
 */
function move_category_to_course_context($category, $targetcourseid) {
    global $DB;

    $targetcontext = context_course::instance($targetcourseid);
    if ((int)$category->contextid === (int)$targetcontext->id) {
        return 'Already in target course context.';
    }

    // Capability and consistency checks are delegated to Moodle's manager API.
    if (!class_exists('core_question\\category_manager')) {
        throw new moodle_exception('Missing Moodle category manager API for move operation.');
    }

    $topcategory = question_get_top_category($targetcontext->id, true);
    if (empty($topcategory) || empty($topcategory->id)) {
        throw new moodle_exception("Could not resolve top question category for target context {$targetcontext->id}.");
    }

    $manager = new core_question\category_manager();
    if (!method_exists($manager, 'update_category')) {
        throw new moodle_exception('Moodle category manager does not provide update_category().');
    }

    // Keep each move atomic: either full success or full rollback.
    $transaction = $DB->start_delegated_transaction();
    try {
        $manager->update_category(
            (int)$category->id,
            (string)$topcategory->id . ',' . (string)$targetcontext->id,
            (string)$category->name,
            (string)$category->info,
            (int)$category->infoformat,
            $category->idnumber,
            null
        );
        $transaction->allow_commit();
    } catch (Exception $e) {
        $transaction->rollback($e);
    }

    return "Moved to course context {$targetcontext->id}.";
}

/**
 * Evaluate whether a spared category can be auto-moved.
 *
 * @param stdClass $category
 * @param array $reasons
 * @return array
 */
function evaluate_move_candidate($category, array $reasons) {
    $result = array(
        'eligible' => false,
        'targetcourseid' => null,
        'coursemap' => array(),
        'blockreasons' => array()
    );

    // Category must be linked to at least one consuming course.
    if (empty($reasons['coursemap']) || !is_array($reasons['coursemap'])) {
        $result['blockreasons'][] = 'No course usage found';
        return $result;
    }

    $result['coursemap'] = $reasons['coursemap'];
    $courseids = array_keys($reasons['coursemap']);
    // Auto-move only when target course is unambiguous.
    if (count($courseids) !== 1) {
        $result['blockreasons'][] = 'Category is used by multiple courses';
        return $result;
    }

    $targetcourseid = (int)reset($courseids);
    // If already in target context, do not include as a move candidate.
    if (!empty($targetcourseid)) {
        $targetcontext = context_course::instance($targetcourseid);
        if ((int)$category->contextid === (int)$targetcontext->id) {
            $result['blockreasons'][] = 'Category is already in target course context';
        }
    }

    if (empty($result['blockreasons'])) {
        $result['eligible'] = true;
        $result['targetcourseid'] = $targetcourseid;
    }

    return $result;
}

/**
 * Extract question category IDs from random-slot filter payload.
 *
 * @param string|null $filtercondition
 * @return int[]
 */
function extract_random_category_ids_from_filtercondition($filtercondition) {
    if (empty($filtercondition) || !is_string($filtercondition)) {
        return array();
    }

    $categoryids = array();
    $decoded = json_decode($filtercondition, true);

    // Preferred parsing path: JSON decode + recursive traversal.
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

    // Fallback path: regex extraction for legacy/partial payload formats.
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
        "Move question categories used by courses into course context.

        Default mode is dry-run. Use --fix to apply moves.

        Options:
        -h, --help            Print out this help
        -g, --categoryid      Course category ID to analyze
        -f, --fix             Apply moves for eligible categories.
                              Without --fix, this runs in dry-run mode.

        Example:
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cmove.php --categoryid=5
        \$sudo -u www-data /usr/bin/php admin/cli/fix_qbank_cmove.php --categoryid=5 --fix
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

// Run as admin in CLI so category_manager capability checks can pass.
$admin = get_admin();
if (!$admin) {
    cli_error('Error: No admin account was found.');
}
\core\session\manager::set_user($admin);

if (empty($options['fix'])) {
    echo "Running in dry-run mode. Add --fix to apply moves.\n";
}

echo "Checking course category context: {$coursecategory->name}\n";

$qsrcolumns = $DB->get_columns('question_set_references');
$hasfiltercondition = !empty($qsrcolumns['filtercondition']);
$sqlrandomfields = 'qsr.questionscontextid, qz.course AS courseid, cr.fullname AS coursename';
if ($hasfiltercondition) {
    $sqlrandomfields .= ', qsr.filtercondition';
}
$sqlrandom = "SELECT DISTINCT {$sqlrandomfields}
                FROM {question_set_references} qsr
                JOIN {quiz_slots} qs ON qs.id = qsr.itemid
                JOIN {quiz} qz ON qz.id = qs.quizid
                JOIN {course} cr ON cr.id = qz.course
               WHERE qsr.component = :component
                 AND qsr.questionarea = :questionarea";
$randomcontexts = $DB->get_records_sql($sqlrandom, array('component' => 'mod_quiz', 'questionarea' => 'slot'));

$randomcategoryids = array();
$randomcontextidsfallback = array();
$randomcategorycoursemap = array();
$randomcontextcoursemap = array();

// Loop 1: build random usage maps.
// - randomcategoryids: categories explicitly referenced in random slot filters.
// - randomcontextidsfallback: contexts referenced when filter has no category info.
// - randomcategorycoursemap/randomcontextcoursemap: consuming courses by category/context.
foreach ($randomcontexts as $randomcontext) {
    $mappedcategoryids = array();
    if ($hasfiltercondition && property_exists($randomcontext, 'filtercondition')) {
        $mappedcategoryids = extract_random_category_ids_from_filtercondition($randomcontext->filtercondition);
    }

    $courseid = !empty($randomcontext->courseid) ? (int)$randomcontext->courseid : 0;
    $coursename = !empty($randomcontext->coursename) ? $randomcontext->coursename : '';

    if (!empty($mappedcategoryids)) {
        foreach ($mappedcategoryids as $mappedcategoryid) {
            $randomcategoryids[(int)$mappedcategoryid] = true;
            if (!empty($courseid) && $coursename !== '') {
                if (empty($randomcategorycoursemap[(int)$mappedcategoryid])) {
                    $randomcategorycoursemap[(int)$mappedcategoryid] = array();
                }
                $randomcategorycoursemap[(int)$mappedcategoryid][$courseid] = $coursename;
            }
        }
    } else {
        $randomcontextidsfallback[(int)$randomcontext->questionscontextid] = true;
        if (!empty($courseid) && $coursename !== '') {
            if (empty($randomcontextcoursemap[(int)$randomcontext->questionscontextid])) {
                $randomcontextcoursemap[(int)$randomcontext->questionscontextid] = array();
            }
            $randomcontextcoursemap[(int)$randomcontext->questionscontextid][$courseid] = $coursename;
        }
    }
}

$recentthreshold = time() - (0 * DAYSECS);
$targetstartdatetime = date('Y-m-d H:i:s');
$targetstarttime = microtime(true);
echo "{$targetstartdatetime} - Start processing course category context: {$coursecategory->id} - {$coursecategory->name}\n";

$sql = 'SELECT qc.id, qc.name, qc.contextid, qc.parent, qc.info, qc.infoformat, qc.idnumber
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

$categoriestosave = array();

// Loop 2: classify each category with keep reasons.
// Categories with any keep reason are preserved and later evaluated for move.
foreach ($categories as $category) {
    $keepreasons = array();

    // Query direct usage: categories referenced by concrete quiz question slots.
    $sqlusedcourses = 'SELECT DISTINCT cr.id AS courseid, cr.fullname
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
    $usedcourserecords = $DB->get_records_sql($sqlusedcourses, $usageparams);
    $coursemap = array();
    if (!empty($usedcourserecords)) {
        foreach ($usedcourserecords as $usedcourse) {
            $coursemap[(int)$usedcourse->courseid] = $usedcourse->fullname;
        }
    }

    // Merge random-slot usage so categories used only by random quizzes still get a course map.
    if (!empty($randomcategorycoursemap[(int)$category->id])) {
        foreach ($randomcategorycoursemap[(int)$category->id] as $courseid => $coursename) {
            $coursemap[(int)$courseid] = $coursename;
        }
    }
    if (!empty($randomcontextcoursemap[(int)$category->contextid])) {
        foreach ($randomcontextcoursemap[(int)$category->contextid] as $courseid => $coursename) {
            $coursemap[(int)$courseid] = $coursename;
        }
    }

    if (!empty($coursemap)) {
        $keepreasons['courses'] = array_values($coursemap);
        $keepreasons['coursemap'] = $coursemap;
    }

    // Recent edits are tracked as a keep reason for auditing visibility.
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

    // Mark random usage by explicit category first, then fallback by context.
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

    if (!empty($keepreasons)) {
        $categoriestosave[] = array('category' => $category, 'reasons' => $keepreasons);
    }
}

cli_heading('Categories spared with reasons');
if (empty($categoriestosave)) {
    echo "No spared categories.\n";
} else {
    // Loop 3: human-readable audit output per spared category.
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

        $movestatus = evaluate_move_candidate($category, $reasons);
        if ($movestatus['eligible']) {
            $targetcoursename = reset($movestatus['coursemap']);
            echo "    4) Move candidate to course: {$targetcoursename}\n";
        } else {
            echo "    4) Not a move candidate: " . implode('; ', $movestatus['blockreasons']) . "\n";
        }
    }
}

$categoriestomove = array();
$moveblocked = array();

// Loop 4: split spared categories into move candidates and blocked items.
foreach ($categoriestosave as $savedcategory) {
    $category = $savedcategory['category'];
    $reasons = $savedcategory['reasons'];

    $movestatus = evaluate_move_candidate($category, $reasons);
    if (!$movestatus['eligible']) {
        if (empty($reasons['coursemap'])) {
            continue;
        }
        $moveblocked[] = array(
            'category' => $category,
            'coursemap' => $reasons['coursemap'],
            'reasons' => $movestatus['blockreasons']
        );
        continue;
    }

    $categoriestomove[] = array(
        'category' => $category,
        'coursemap' => $movestatus['coursemap'],
        'targetcourseid' => $movestatus['targetcourseid']
    );
}

cli_heading('Categories eligible for move');
if (empty($categoriestomove)) {
    echo "No categories eligible for move.\n";
} else {
    foreach ($categoriestomove as $movecandidate) {
        $category = $movecandidate['category'];
        $coursenames = implode(', ', array_values($movecandidate['coursemap']));
        echo "--> {$category->name} => {$coursenames}\n";
    }
}

cli_heading('Categories blocked for move');
if (empty($moveblocked)) {
    echo "No blocked categories for move.\n";
} else {
    foreach ($moveblocked as $blockedcandidate) {
        $category = $blockedcandidate['category'];
        $coursenames = implode(', ', array_values($blockedcandidate['coursemap']));
        echo "--> {$category->name} => {$coursenames}\n";
        foreach ($blockedcandidate['reasons'] as $blockedreason) {
            echo "    - {$blockedreason}\n";
        }
    }
}

$movedcount = 0;
if (!empty($options['fix'])) {
    // Loop 5: execute each eligible move when --fix is provided.
    foreach ($categoriestomove as $movecandidate) {
        $category = $movecandidate['category'];
        $targetcourseid = $movecandidate['targetcourseid'];
        $targetcoursename = reset($movecandidate['coursemap']);

        echo "Moving {$category->name} to course {$targetcoursename}... ";
        try {
            $status = move_category_to_course_context($category, $targetcourseid);
            $movedcount += 1;
            echo "Done! {$status}\n";
        } catch (Exception $e) {
            echo "Failed: {$e->getMessage()}\n";
        }
    }
    echo "Moved {$movedcount} categories successfully.\n";
} else {
    echo "Dry-run summary: " . count($categoriestomove) . " categories eligible for move.\n";
    echo "Use --fix to apply moves.\n";
}

$targetenddatetime = date('Y-m-d H:i:s');
$targetelapsedseconds = microtime(true) - $targetstarttime;
echo "{$targetenddatetime} - Finished processing course category context: {$coursecategory->id} - {$coursecategory->name} ({$targetelapsedseconds}s)\n";
