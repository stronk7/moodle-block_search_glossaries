<?php
// This file is part of block_search_glossaries,
// a contrib block for Moodle - http://moodle.org/
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
 * Search glossaries main script.
 *
 * @package    block_search_glossaries
 * @copyright  2005 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/glossary/lib.php');

define('GLOSSARYMAXRESULTSPERPAGE', 100);  // Limit results per page.

$courseid = required_param('courseid', PARAM_INT);
$query    = required_param('bsquery', PARAM_NOTAGS);
$page     = optional_param('page', 0, PARAM_INT);

function search($query, $course, $offset, &$countentries) {

    global $CFG, $USER, $DB;

    $fullsearch = true;  // Search in definitions too. Parametrised, could go to config.

    // TODO: Use the search style @ sql.php and use placeholders!

    // Some differences in syntax for PostgreSQL.
    // TODO: Modify this to support also MSSQL and Oracle.
    if ($CFG->dbfamily == 'postgres') {
        $LIKE = "ILIKE";   // Case-insensitive.
        $NOTLIKE = "NOT ILIKE";   // Case-insensitive.
        $REGEXP = "~*";
        $NOTREGEXP = "!~*";
    } else {
        $LIKE = "LIKE";
        $NOTLIKE = "NOT LIKE";
        $REGEXP = "REGEXP";
        $NOTREGEXP = "NOT REGEXP";
    }

    // Perform the search only in glossaries fulfilling mod/glossary:view and (visible or moodle/course:viewhiddenactivities)
    $glossaryids = array();
    if (! $glossaries = get_all_instances_in_course('glossary', $course)) {
        notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'glossary')), "../../course/view.php?id=$course->id");
        die;
    }
    foreach ($glossaries as $glossary) {
        $cm = get_coursemodule_from_instance("glossary", $glossary->id, $course->id);
        $context = context_module::instance($cm->id);
        if ($cm->visible || has_capability('moodle/course:viewhiddenactivities', $context)) {
            if (has_capability('mod/glossary:view', $context)) {
                $glossaryids[] = $glossary->id;
            }
        }
    }

    // Search starts.
    $conceptsearch = "";
    $aliassearch = "";
    $definitionsearch = "";

    $searchterms = explode(" ",$query);

    foreach ($searchterms as $searchterm) {

        if ($conceptsearch) {
            $conceptsearch .= " AND ";
        }
        if ($aliassearch) {
            $aliassearch .= " AND ";
        }
        if ($definitionsearch) {
            $definitionsearch .= " AND ";
        }

        if (substr($searchterm,0,1) == "+") {
            $searchterm = substr($searchterm,1);
            $conceptsearch .= " ge.concept $REGEXP '(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)' ";
            $aliassearch .= " al.alias $REGEXP '(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)' ";
            $definitionsearch .= " ge.definition $REGEXP '(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)' ";
        } else if (substr($searchterm,0,1) == "-") {
            $searchterm = substr($searchterm,1);
            $conceptsearch .= " ge.concept $NOTREGEXP '(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)' ";
            $aliassearch .= " al.alias $NOTREGEXP '(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)' ";
            $definitionsearch .= " ge.definition $NOTREGEXP '(^|[^a-zA-Z0-9])$searchterm([^a-zA-Z0-9]|$)' ";
        } else {
            $conceptsearch .= " ge.concept $LIKE '%$searchterm%' ";
            $aliassearch .= " al.alias $LIKE '%$searchterm%' ";
            $definitionsearch .= " ge.definition $LIKE '%$searchterm%' ";
        }
    }

    // Approved or own entries.
    $userid = '';
    if (isset($USER->id)) {
        $userid = "OR ge.userid = $USER->id";
    }

    // Search in aliases first.
    $idaliases = '';
    $listaliases = array();
    $recaliases = $DB->get_records_sql("
        SELECT al.id, al.entryid
          FROM {glossary_alias} al,
               {glossary_entries} ge,
               {glossary} g
         WHERE g.course = $course->id AND
               (ge.glossaryid = g.id OR
               ge.sourceglossaryid = g.id) AND
               (ge.approved != 0 $userid) AND
               ge.id = al.entryid AND
               $aliassearch", array());
    // Process aliases id.
    if ($recaliases) {
        foreach ($recaliases as $recalias) {
            $listaliases[] = $recalias->entryid;
        }
        $idaliases = implode (',',$listaliases);
    }

    // Add seach conditions in concepts and, if needed, in definitions.
    $where = "AND (($conceptsearch) ";

    // Include aliases id if found.
    if (!empty($idaliases)) {
        $where .= " OR ge.id IN ($idaliases) ";
    }

    // Include search in definitions if requested.
    if ( $fullsearch ) {
        $where .= " OR ($definitionsearch))";
    } else {
        $where .= ")";
    }

    // Main query, only to allowed glossaries and to approved or own entries.
    $sqlselect  = "SELECT DISTINCT ge.*";
    $sqlfrom    = "FROM {glossary_entries} ge,
                        {glossary} g";
    $sqlwhere   = "WHERE g.course = $course->id AND
                         g.id IN (" . implode($glossaryids, ', ') . ") AND
                         (ge.glossaryid = g.id OR
                          ge.sourceglossaryid = g.id) AND
                         (ge.approved != 0 $userid)
                          $where";
    $sqlorderby = "ORDER BY ge.glossaryid, ge.concept";

    // Set page limits.
    $limitfrom = $offset;
    $limitnum = 0;
    if ($offset >= 0) {
        $limitnum = GLOSSARYMAXRESULTSPERPAGE;
    }

    $countentries = $DB->count_records_sql("select count(*) $sqlfrom $sqlwhere", array());
    $allentries = $DB->get_records_sql("$sqlselect $sqlfrom $sqlwhere $sqlorderby", array(), $limitfrom, $limitnum);

    return $allentries;
}

//////////////////////////////////////////////////////////
// The main part of this script

$PAGE->set_pagelayout('standard');
$PAGE->set_url($FULLME);

if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('invalidcourseid');
}

require_course_login($course);

$strglossaries = get_string('modulenameplural', 'glossary');
$searchglossaries = get_string('glossariessearch', 'block_search_glossaries');
$searchresults = get_string('searchresults', 'block_search_glossaries');
$strresults = get_string('results', 'block_search_glossaries');
$ofabout = get_string('ofabout', 'block_search_glossaries');
$for = get_string('for', 'block_search_glossaries');
$seconds = get_string('seconds', 'block_search_glossaries');

$PAGE->navbar->add($strglossaries, new moodle_url('/mod/glossary/index.php', array('id' => $course->id)));
$PAGE->navbar->add($searchresults);

$PAGE->set_title($searchresults);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();

// Get the format from CFG.
if (!empty($CFG->block_search_glossaries_format)) {
    $format = $CFG->block_search_glossaries_format;
} else {
    set_config('block_search_glossaries_format','dictionary');
    $format = "dictionary";
}

$start = (GLOSSARYMAXRESULTSPERPAGE*$page);

// Process the query.
$query = trim(strip_tags($query));

// Launch the SQL quey.
$glossarydata = search($query, $course, $start, $countentries);

$coursefield = '<input type="hidden" name="courseid" value="'.$course->id.'">';
$pagefield = '<input type="hidden" name="page" value="0">';
$searchbox = '<input type="text" name="bsquery" size="20" maxlength="255" value="'.s($query).'">';
$submitbutton = '<input type="submit" name="submit" value="'.$searchglossaries.'">';

$content = $coursefield.$pagefield.$searchbox.$submitbutton;

$form = '<form method="get" action="'.$CFG->wwwroot.'/blocks/search_glossaries/search_glossaries.php" name="form" id="form">'.$content.'</form>';

echo '<div style="margin-left: auto; margin-right: auto; width: 100%; text-align: center">' . $form . '</div>';

// Process $glossarydata, if present.
$startindex = $start;
$endindex = $start + count($glossarydata);

$countresults = $countentries;

// Print results page tip.
$page_bar = glossary_get_paging_bar($countresults, $page, GLOSSARYMAXRESULTSPERPAGE, "search_glossaries.php?bsquery=".urlencode(stripslashes($query))."&amp;courseid=$course->id&amp;");

// Iterate over results.
if (!empty($glossarydata)) {
    // Print header.
    echo '<p style="text-align: right">'.$strresults.' <b>'.($startindex+1).'</b> - <b>'.$endindex.'</b> '.$ofabout.'<b> '.$countresults.' </b>'.$for.'<b> "'.s($query).'"</b></p>';
    echo $page_bar;
    // Prepare each entry (hilight, footer...)
    echo '<ul>';
    foreach ($glossarydata as $entry) {
        $glossary = $DB->get_record('glossary', array('id' => $entry->glossaryid));
        $cm = get_coursemodule_from_instance("glossary", $glossary->id, $course->id);
        // Highlight!
        // We have to strip any word starting by + and take out words starting by -
        // to make highlight works properly.
        $searchterms = explode(' ', $query); // Search for words independently.
        foreach ($searchterms as $key => $searchterm) {
            if (preg_match('/^\-/',$searchterm)) {
                unset($searchterms[$key]);
            } else {
                $searchterms[$key] = preg_replace('/^\+/','',$searchterm);
            }
            // Avoid highlight of <2 len strings. It's a well known hilight limitation.
            if (strlen($searchterm) < 2) {
                unset($searchterms[$key]);
            }
        }
        $strippedsearch = implode(' ', $searchterms); // Rebuild the string.
        $entry->highlight = $strippedsearch;

        // To show where each match belongs to.
        $result = "<li><a href=\"$CFG->wwwroot/mod/glossary/view.php?g=$entry->glossaryid\">".format_string($glossary->name,true)."</a></p>";
        echo $result;
        // And the entry itself.
        glossary_print_entry($course, $cm, $glossary, $entry, '', '', 0, $format);
        echo '</li>';
    }
    echo '</ul>';
    echo $page_bar;
} else {
    echo '<br />';
    echo $OUTPUT->box(get_string("norecordsfound","block_search_glossaries"),'CENTER');
}

echo $OUTPUT->footer();
