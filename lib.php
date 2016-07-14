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
 * Definition of the grade_forecast_report class is defined
 *
 * @package    gradereport_forecast
 * @copyright  2016 Louisiana State University, Chad Mazilly, Robert Russo, Dave Elliott
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir.'/tablelib.php');

//showhiddenitems values
define("GRADE_REPORT_FORECAST_HIDE_HIDDEN", 0);
define("GRADE_REPORT_FORECAST_HIDE_UNTIL", 1);
define("GRADE_REPORT_FORECAST_SHOW_HIDDEN", 2);

/**
 * Class providing an API for the user report building and displaying.
 * @uses grade_report
 * @package gradereport_forecast
 */
class grade_report_forecast extends grade_report {

    /**
     * The user.
     * @var object $user
     */
    public $user;

    /**
     * A flexitable to hold the data.
     * @var object $table
     */
    public $table;

    /**
     * An array of table headers
     * @var array
     */
    public $tableheaders = array();

    /**
     * An array of table columns
     * @var array
     */
    public $tablecolumns = array();

    /**
     * An array containing rows of data for the table.
     * @var type
     */
    public $tabledata = array();

    /**
     * The grade tree structure
     * @var grade_tree
     */
    public $gtree;

    /**
     * Flat structure similar to grade tree
     */
    public $gseq;

    /**
     * Decimal points to use for values in the report, default 2
     * @var int
     */
    public $decimals = 2;

    /**
     * The number of decimal places to round range to, default 0
     * @var int
     */
    public $rangedecimals = 0;

    /**
     * Show letter grades in the report, default false
     * @var bool
     */
    public $showlettergrade = false;

    public $maxdepth;
    public $evenodd;

    public $canviewhidden;

    public $switch;

    /**
     * Show hidden items even when user does not have required cap
     */
    public $showhiddenitems;
    public $showtotalsifcontainhidden;

    public $baseurl;
    public $pbarurl;

    public $courseid;

    public $inputData;

    /**
     * The modinfo object to be used.
     *
     * @var course_modinfo
     */
    protected $modinfo = null;

    /**
     * View as user.
     *
     * When this is set to true, the visibility checks, and capability checks will be
     * applied to the user whose grades are being displayed. This is very useful when
     * a mentor/parent is viewing the report of their mentee because they need to have
     * access to the same information, but not more, not less.
     *
     * @var boolean
     */
    protected $viewasuser = false;

    /**
     * An array that collects the aggregationhints for every
     * grade_item. The hints contain grade, grademin, grademax
     * status, weight and parent.
     *
     * @var array
     */
    protected $aggregationhints = array();

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $userid The id of the user
     * @param bool $viewasuser Set this to true when the current user is a mentor/parent of the targetted user.
     * @param array $inputData
     */
    public function __construct($courseid, $gpr, $context, $userid, $viewasuser = null, $inputData = []) {
        global $DB, $CFG;
        parent::__construct($courseid, $gpr, $context);

        $this->showhiddenitems = grade_get_setting($this->courseid, 'report_forecast_showhiddenitems', $CFG->grade_report_forecast_showhiddenitems);
        $this->showtotalsifcontainhidden = array($this->courseid => grade_get_setting($this->courseid, 'report_forecast_showtotalsifcontainhidden', $CFG->grade_report_forecast_showtotalsifcontainhidden));

        $this->showlettergrade = grade_get_setting($this->courseid, 'report_forecast_showlettergrade', !empty($CFG->grade_report_forecast_showlettergrade));

        $this->viewasuser = $viewasuser;

        // The default grade decimals is 2
        $defaultdecimals = 2;
        if (property_exists($CFG, 'grade_decimalpoints')) {
            $defaultdecimals = $CFG->grade_decimalpoints;
        }
        $this->decimals = grade_get_setting($this->courseid, 'decimalpoints', $defaultdecimals);

        // The default range decimals is 0
        $defaultrangedecimals = 0;
        if (property_exists($CFG, 'grade_report_forecast_rangedecimals')) {
            $defaultrangedecimals = $CFG->grade_report_forecast_rangedecimals;
        }
        $this->rangedecimals = grade_get_setting($this->courseid, 'report_forecast_rangedecimals', $defaultrangedecimals);

        $this->switch = grade_get_setting($this->courseid, 'aggregationposition', $CFG->grade_aggregationposition);

        // Grab the grade_tree for this course
        $this->gtree = new grade_tree($this->courseid, false, $this->switch, null, !$CFG->enableoutcomes);

        // Get the user (for full name).
        $this->user = $DB->get_record('user', array('id' => $userid));

        // What user are we viewing this as?
        $coursecontext = context_course::instance($this->courseid);
        if ($viewasuser) {
            $this->modinfo = new course_modinfo($this->course, $this->user->id);
            $this->canviewhidden = has_capability('moodle/grade:viewhidden', $coursecontext, $this->user->id);
        } else {
            $this->modinfo = $this->gtree->modinfo;
            $this->canviewhidden = has_capability('moodle/grade:viewhidden', $coursecontext);
        }

        // Determine the number of rows and indentation.
        $this->maxdepth = 1;
        $this->inject_rowspans($this->gtree->top_element);
        $this->maxdepth++; // Need to account for the lead column that spans all children.
        for ($i = 1; $i <= $this->maxdepth; $i++) {
            $this->evenodd[$i] = 0;
        }

        $this->tabledata = array();

        // base url for sorting by first/last name
        $this->baseurl = $CFG->wwwroot.'/grade/report?id='.$courseid.'&amp;userid='.$userid;
        $this->pbarurl = $this->baseurl;

        $this->courseid = $courseid;

        $this->inputData = $inputData;

        // no groups on this report - rank is from all course users
        $this->setup_table();
    }

    /**
     * Recurses through a tree of elements setting the rowspan property on each element
     *
     * @param array $element Either the top element or, during recursion, the current element
     * @return int The number of elements processed
     */
    function inject_rowspans(&$element) {

        if ($element['depth'] > $this->maxdepth) {
            $this->maxdepth = $element['depth'];
        }
        if (empty($element['children'])) {
            return 1;
        }
        $count = 1;

        foreach ($element['children'] as $key=>$child) {
            // If category is hidden then do not include it in the rowspan.
            if ($child['type'] == 'category' && $child['object']->is_hidden() && !$this->canviewhidden
                    && ($this->showhiddenitems == GRADE_REPORT_FORECAST_HIDE_HIDDEN
                    || ($this->showhiddenitems == GRADE_REPORT_FORECAST_HIDE_UNTIL && !$child['object']->is_hiddenuntil()))) {
                // Just calculate the rowspans for children of this category, don't add them to the count.
                $this->inject_rowspans($element['children'][$key]);
            } else {
                $count += $this->inject_rowspans($element['children'][$key]);
            }
        }

        $element['rowspan'] = $count;
        return $count;
    }


    /**
     * Prepares the headers and attributes of the flexitable.
     */
    public function setup_table() {
        // setting up table headers
        $this->tablecolumns = [
            'itemname', 
            'grade'
        ];
        
        $this->tableheaders = [
            $this->get_lang_string('gradeitem', 'grades'), 
            $this->get_lang_string('grade', 'grades')
        ];
    }

    function fill_table() {
        // print "<pre>";
        // print_r($this->gtree->top_element);
        $this->fill_table_recursive($this->gtree->top_element);
        // print_r($this->tabledata);
        // print "</pre>";
        return true;
    }

    /**
     * Fill the table with data.
     *
     * @param $element - An array containing the table data for the current row.
     */
    private function fill_table_recursive(&$element) {
        global $DB, $CFG;

        $type = $element['type'];
        $depth = $element['depth'];
        $grade_object = $element['object'];
        $eid = $grade_object->id;
        $element['userid'] = $this->user->id;
        $fullname = $this->gtree->get_element_header($element, true, true, true, true, true);
        $data = array();
        $hidden = '';
        $excluded = '';
        $itemlevel = ($type == 'categoryitem' || $type == 'category' || $type == 'courseitem') ? $depth : ($depth + 1);
        $class = 'level' . $itemlevel . ' level' . ($itemlevel % 2 ? 'odd' : 'even');

        // If this is a hidden grade category, hide it completely from the user
        if ($type == 'category' && $grade_object->is_hidden() && !$this->canviewhidden && (
                $this->showhiddenitems == GRADE_REPORT_FORECAST_HIDE_HIDDEN ||
                ($this->showhiddenitems == GRADE_REPORT_FORECAST_HIDE_UNTIL && !$grade_object->is_hiddenuntil()))) {
            return false;
        }

        if ($type == 'category') {
            $this->evenodd[$depth] = (($this->evenodd[$depth] + 1) % 2);
        }
        $alter = ($this->evenodd[$depth] == 0) ? 'even' : 'odd';

        /// Process those items that have scores associated
        if ($type == 'item' or $type == 'categoryitem' or $type == 'courseitem') {
            $header_row = "row_{$eid}_{$this->user->id}";
            $header_cat = "cat_{$grade_object->categoryid}_{$this->user->id}";

            $grade_grade = grade_grade::fetch(array('itemid'=>$grade_object->id,'userid'=>$this->user->id));

            if ( ! $grade_grade) {
                $grade_grade = new grade_grade([
                    'userid' => $this->user->id,
                    'itemid' => $grade_object->id,
                    // 'finalgrade' => '8.5'
                ], false);
                // $grade_grade->userid = $this->user->id;
                // $grade_grade->itemid = $grade_object->id;
            }

            // $grade_grade = new grade_grade([
                // 'itemid' => $grade_object->id,
                // 'userid' => $this->user->id,
                // 'finalgrade' => '5.9',
                // // 'id' => '',
                // // 'rawgrade' => '',
                // // 'rawgrademax' => '',
                // // 'rawgrademin' => '',
                // // 'rawscaleid' => '',
                // // 'usermodified' => '',
                // // 'hidden' => '',
                // // 'locked', => '',
                // // 'locktime' => '',
                // // 'exported' => '',
                // // 'overridden' => '',
                // // 'excluded' => '',
                // // 'timecreated' => '',
                // // 'timemodified' => '',
                // // 'aggregationstatus' => '',
                // // 'aggregationweight' => '',
            // ]);

            $grade_grade->load_grade_item();

            /// Hidden Items
            if ($grade_grade->grade_item->is_hidden()) {
                $hidden = ' dimmed_text';
            }

            $hide = false;
            // If this is a hidden grade item, hide it completely from the user.
            if ($grade_grade->is_hidden() && !$this->canviewhidden && (
                    $this->showhiddenitems == GRADE_REPORT_FORECAST_HIDE_HIDDEN ||
                    ($this->showhiddenitems == GRADE_REPORT_FORECAST_HIDE_UNTIL && !$grade_grade->is_hiddenuntil()))) {
                $hide = true;
            } else if (!empty($grade_object->itemmodule) && !empty($grade_object->iteminstance)) {
                // The grade object can be marked visible but still be hidden if
                // the student cannot see the activity due to conditional access
                // and it's set to be hidden entirely.
                $instances = $this->modinfo->get_instances_of($grade_object->itemmodule);
                if (!empty($instances[$grade_object->iteminstance])) {
                    $cm = $instances[$grade_object->iteminstance];
                    if (!$cm->uservisible) {
                        // If there is 'availableinfo' text then it is only greyed
                        // out and not entirely hidden.
                        if (!$cm->availableinfo) {
                            $hide = true;
                        }
                    }
                }
            }

            // Actual Grade - We need to calculate this whether the row is hidden or not.
            $gradeval = $grade_grade->finalgrade;
            $hint = $grade_grade->get_aggregation_hint();
            if (!$this->canviewhidden) {
                /// Virtual Grade (may be calculated excluding hidden items etc).
                $adjustedgrade = $this->blank_hidden_total_and_adjust_bounds($this->courseid,
                                                                             $grade_grade->grade_item,
                                                                             $gradeval);

                $gradeval = $adjustedgrade['grade'];

                // We temporarily adjust the view of this grade item - because the min and
                // max are affected by the hidden values in the aggregation.
                $grade_grade->grade_item->grademax = $adjustedgrade['grademax'];
                $grade_grade->grade_item->grademin = $adjustedgrade['grademin'];
                $hint['status'] = $adjustedgrade['aggregationstatus'];
                $hint['weight'] = $adjustedgrade['aggregationweight'];
            } else {
                // The max and min for an aggregation may be different to the grade_item.
                if (!is_null($gradeval)) {
                    $grade_grade->grade_item->grademax = $grade_grade->get_grade_max();
                    $grade_grade->grade_item->grademin = $grade_grade->get_grade_min();
                }
            }


            if (!$hide) {
                /// Excluded Item
                /**
                if ($grade_grade->is_excluded()) {
                    $fullname .= ' ['.get_string('excluded', 'grades').']';
                    $excluded = ' excluded';
                }
                **/

                /// Other class information
                $class .= $hidden . $excluded;
                if ($this->switch) { // alter style based on whether aggregation is first or last
                   $class .= ($type == 'categoryitem' or $type == 'courseitem') ? " ".$alter."d$depth baggt b2b" : " item b1b";
                } else {
                   $class .= ($type == 'categoryitem' or $type == 'courseitem') ? " ".$alter."d$depth baggb" : " item b1b";
                }
                if ($type == 'categoryitem' or $type == 'courseitem') {
                    $header_cat = "cat_{$grade_object->iteminstance}_{$this->user->id}";
                }

                /// Name
                $data['itemname']['content'] = $fullname;
                $data['itemname']['class'] = $class;
                $data['itemname']['colspan'] = ($this->maxdepth - $depth);
                $data['itemname']['celltype'] = 'th';
                $data['itemname']['id'] = $header_row;

                $class .= " itemcenter ";
                $placeholder = '';
                $inputName = '';

                // get grade and value
                $gradeValue = grade_format_gradevalue($gradeval, $grade_grade->grade_item, true);
                $gradeLetter = grade_format_gradevalue($gradeval, $grade_grade->grade_item, true, GRADE_DISPLAY_TYPE_LETTER);
                
                // determine what type of grade item this is and apply the proper "fcst" class
                if ($type == 'item') {
                    // mark static/dynamic depending on whether there is a grade or not
                    $class .= ' fcst-' . (($this->valueIsGraded($gradeValue)) ? 'static' : 'dynamic' ) . '-item-' . $eid . ' ';
                    $class .= ' grade-max-' . $grade_grade->grade_item->grademax;
                    $class .= ' grade-min-' . $grade_grade->grade_item->grademin;
                    $placeholder = $grade_grade->grade_item->grademin . ' - ' . $grade_grade->grade_item->grademax;
                    $inputName = 'input-gradeitem-' . $eid;
                } elseif ($type == 'categoryitem') {
                    $class .= ' fcst-cat-' . $eid . ' ';
                } elseif ($type == 'courseitem') {
                    $class .= ' fcst-course-' . $eid . ' ';
                }

                // Grade and Letter display
                if ($grade_grade->grade_item->needsupdate) {
                    $data['grade']['class'] = $class.' gradingerror';
                    $data['grade']['content'] = get_string('error');
                } else if (!empty($CFG->grade_hiddenasdate) and $grade_grade->get_datesubmitted() and !$this->canviewhidden and $grade_grade->is_hidden()
                       and !$grade_grade->grade_item->is_category_item() and !$grade_grade->grade_item->is_course_item()) {
                    // the problem here is that we do not have the time when grade value was modified, 'timemodified' is general modification date for grade_grades records
                    $class .= ' datesubmitted';
                    $data['grade']['class'] = $class;
                    $data['grade']['content'] = get_string('submittedon', 'grades', userdate($grade_grade->get_datesubmitted(), get_string('strftimedatetimeshort')));

                } else if ($grade_grade->is_hidden()) {
                    $data['grade']['class'] = $class.' dimmed_text';
                    $data['grade']['content'] = '-';
                    if ($this->canviewhidden) {
                        $data['grade']['content'] = $this->formatGradeDisplay($gradeValue, $gradeLetter);
                        $data['grade']['placeholder'] = $placeholder;
                        $data['grade']['inputName'] = $inputName;
                    }
                } else {
                    $data['grade']['class'] = $class;
                    $data['grade']['content'] = $this->formatGradeDisplay($gradeValue, $gradeLetter);
                    $data['grade']['placeholder'] = $placeholder;
                    $data['grade']['inputName'] = $inputName;
                }
                $data['grade']['headers'] = "$header_cat $header_row grade";
            }
        }

        /// Category
        if ($type == 'category') {
            $data['leader']['class'] = $class.' '.$alter."d$depth b1t b2b b1l";
            $data['leader']['rowspan'] = $element['rowspan'];

            if ($this->switch) { // alter style based on whether aggregation is first or last
               $data['itemname']['class'] = $class.' '.$alter."d$depth b1b b1t";
            } else {
               $data['itemname']['class'] = $class.' '.$alter."d$depth b2t";
            }
            $data['itemname']['colspan'] = ($this->maxdepth - $depth + count($this->tablecolumns) - 1);
            $data['itemname']['content'] = $fullname;
            $data['itemname']['celltype'] = 'th';
            $data['itemname']['id'] = "cat_{$grade_object->id}_{$this->user->id}";
        }

        /// Add this row to the overall system
        foreach ($data as $key => $celldata) {
            $data[$key]['class'] .= ' column-' . $key;
        }
        $this->tabledata[] = $data;

        /// Recursively iterate through all child elements
        if (isset($element['children'])) {
            foreach ($element['children'] as $key=>$child) {
                $this->fill_table_recursive($element['children'][$key]);
            }
        }
    }

    /**
     * Prints or returns the HTML from the flexitable.
     * @param bool $return Whether or not to return the data instead of printing it directly.
     * @return string
     */
    public function print_table($return=false) {
         $maxspan = $this->maxdepth;

        /// Build table structure
        $html = "
            <form id='forecast-form' action='#'>
            <input type='hidden' name='courseid' value='" . $this->courseid . "'>
            <input type='hidden' name='userid' value='" . $this->user->id . "'>
            <table cellspacing='0'
                   cellpadding='0'
                   summary='" . s($this->get_lang_string('tablesummary', 'gradereport_forecast')) . "'
                   class='boxaligncenter generaltable user-grade'>
            <thead>
                <tr>
                    <th id='".$this->tablecolumns[0]."' class=\"header column-{$this->tablecolumns[0]}\" colspan='$maxspan'>".$this->tableheaders[0]."</th>\n";

        for ($i = 1; $i < count($this->tableheaders); $i++) {
            $html .= "<th id='".$this->tablecolumns[$i]."' class=\"header column-{$this->tablecolumns[$i]}\">".$this->tableheaders[$i]."</th>\n";
        }

        $html .= "
                </tr>
            </thead>
            <tbody>\n";

        /// Print out the table data
        for ($i = 0; $i < count($this->tabledata); $i++) {
            $html .= "<tr>\n";
            if (isset($this->tabledata[$i]['leader'])) {
                $rowspan = $this->tabledata[$i]['leader']['rowspan'];
                $class = $this->tabledata[$i]['leader']['class'];
                $html .= "<td class='$class' rowspan='$rowspan'></td>\n";
            }
            for ($j = 0; $j < count($this->tablecolumns); $j++) {
                $name = $this->tablecolumns[$j];
                $class = (isset($this->tabledata[$i][$name]['class'])) ? $this->tabledata[$i][$name]['class'] : '';
                $colspan = (isset($this->tabledata[$i][$name]['colspan'])) ? "colspan='".$this->tabledata[$i][$name]['colspan']."'" : '';
                
                // $content = (isset($this->tabledata[$i][$name]['content'])) ? $this->tabledata[$i][$name]['content'] : null;

                if ( ! isset($this->tabledata[$i][$name]['content'])) {
                    $content = null;
                } else {
                    if ($this->tabledata[$i][$name]['content'] == '-') {
                        if (strpos($this->tabledata[$i][$name]['class'], ' item ')) {
                            $placeholder = isset($this->tabledata[$i][$name]['placeholder']) ? $this->tabledata[$i][$name]['placeholder'] : 'Enter grade';
                            $inputName = isset($this->tabledata[$i][$name]['inputName']) ? $this->tabledata[$i][$name]['inputName'] : 'default-input-gradeitem';
                            $content = '<input type="text" name="' . $inputName . '" placeholder="' . $placeholder . '"><br>
                                        <span class="fcst-error fcst-error-invalid" style="display: none; color: red;">Invalid input!</span>
                                        <span class="fcst-error fcst-error-range" style="display: none; color: red;">Must be within range!</span>';
                        } else {
                            $content = 'NEED TOTAL';
                        }
                    } else {
                        $content = $this->tabledata[$i][$name]['content'];
                    }
                }

                $celltype = (isset($this->tabledata[$i][$name]['celltype'])) ? $this->tabledata[$i][$name]['celltype'] : 'td';
                $id = (isset($this->tabledata[$i][$name]['id'])) ? "id='{$this->tabledata[$i][$name]['id']}'" : '';
                $headers = (isset($this->tabledata[$i][$name]['headers'])) ? "headers='{$this->tabledata[$i][$name]['headers']}'" : '';
                if (isset($content)) {
                    $html .= "<$celltype $id $headers class='$class' $colspan>$content</$celltype>\n";
                }
            }
            $html .= "</tr>\n";
        }

        $html .= "</tbody></table></form>";

        if ($return) {
            return $html;
        } else {
            echo $html;
        }
    }

    /**
     * Formats a given grade value and letter for display in grade column of report
     * 
     * @param  string $gradeValue
     * @param  string $gradeLetter
     * @return string
     */
    private function formatGradeDisplay($gradeValue, $gradeLetter) {
        $output = $gradeValue;

        if ($this->valueIsGraded($gradeValue)) {
            if ($this->showlettergrade) {
                $output .= ' (' . $gradeLetter . ')';
            }
        }

        return $output;
    }

    /**
     * Reports whether or not a grade value has a real value
     * 
     * @param  string $gradeValue
     * @return bool
     */
    private function valueIsGraded($gradeValue) {
        return ($gradeValue !== '-') ? true : false;
    }

    /**
     * Processes the data sent by the form (grades and feedbacks).
     * @var array $data
     * @return bool Success or Failure (array of errors).
     */
    function process_data($data) {
    }
    function process_action($target, $action) {
    }

    /**
     * Trigger the grade_report_viewed event
     *
     * @since Moodle 2.9
     */
    public function viewed() {
        $event = \gradereport_forecast\event\grade_report_viewed::create(
            array(
                'context' => $this->context,
                'courseid' => $this->courseid,
                'relateduserid' => $this->user->id,
            )
        );
        $event->trigger();
    }

    /**
     * Fetches all of this course's categories, defaults to "flattened" array
     * 
     * @param  boolean $flattened
     * @return array
     */
    private function getCourseCategories($flattened = false) {
        $course_grade_categories = grade_category::fetch_all(array('courseid' => $this->courseid));
        
        if ( ! $flattened)
            return $course_grade_categories;

        $flatcattree = array();
        
        foreach ($course_grade_categories as $cat) {
            if (!isset($flatcattree[$cat->depth])) {
                $flatcattree[$cat->depth] = array();
            }
            $flatcattree[$cat->depth][] = $cat;
        }

        krsort($flatcattree);

        return $flatcattree;
    }

    public function getUpdatedTotalsResponse() {
        $categories = $this->getCourseCategories(false);

        $response = [
            'cats' => [
                47 => 'forty seven',
                48 => 'forty eight',
                49 => 'forty nine',
            ],
            'course' => 'total',
        ];

        return $response;

        // return $this->blank_hidden_total_and_adjust_bounds($courseid, $course_item, $finalgrade);

        // $this->fill_table_recursive($this->gtree->top_element);

        // return $this->tabledata;
    }

    /**
     * Updates all final grades in course.
     *
     * @param int $courseid The course ID
     * @param int $userid If specified try to do a quick regrading of the grades of this user only
     * @param object $updated_item Optional grade item to be marked for regrading
     * @return bool true if ok, array of errors if problems found. Grade item id => error message
     */
    function grade_regrade_final_grades($courseid, $userid, $updated_item = false) {
        
        $course_item = grade_item::fetch_course_item($courseid);

        // Categories might have to run some processing before we fetch the grade items.
        // This gives them a final opportunity to update and mark their children to be updated.
        // We need to work on the children categories up to the parent ones, so that, for instance,
        // if a category total is updated it will be reflected in the parent category.
        $course_grade_categories = grade_category::fetch_all(array('courseid' => $courseid));
        
        $flatcattree = array();
        
        foreach ($course_grade_categories as $cat) {
            if (!isset($flatcattree[$cat->depth])) {
                $flatcattree[$cat->depth] = array();
            }
            $flatcattree[$cat->depth][] = $cat;
        }
        krsort($flatcattree);

        foreach ($flatcattree as $depth => $course_grade_categories) {
            foreach ($course_grade_categories as $cat) {
                // $cat->pre_regrade_final_grades();
            }
        }

        $course_grade_items = grade_item::fetch_all(array('courseid' => $courseid));

        $depends_on = array();

        foreach ($course_grade_items as $gid=>$gitem) {
            if ((!empty($updated_item) and $updated_item->id == $gid) ||
                    $gitem->is_course_item() || $gitem->is_category_item() || $gitem->is_calculated()) {
                $course_grade_items[$gid]->needsupdate = 1;
            }

            // We load all dependencies of these items later we can discard some course_grade_items based on this.
            if ($course_grade_items[$gid]->needsupdate) {
                $depends_on[$gid] = $course_grade_items[$gid]->depends_on();
            }
        }

        $errors = array();
        $finalids = array();
        $updatedids = array();
        $gids     = array_keys($course_grade_items);
        $failed = 0;

        while (count($finalids) < count($gids)) { // work until all grades are final or error found
            $count = 0;
            foreach ($gids as $gid) {
                if (in_array($gid, $finalids)) {
                    continue; // already final
                }

                if (!$course_grade_items[$gid]->needsupdate) {
                    $finalids[] = $gid; // we can make it final - does not need update
                    continue;
                }
                
                foreach ($depends_on[$gid] as $did) {
                    if (!in_array($did, $finalids)) {
                        // This item depends on something that is not yet in finals array.
                        continue 2;
                    }
                }

                // If this grade item has no dependancy with any updated item at all, then remove it from being recalculated.

                // When we get here, all of this grade item's decendents are marked as final so they would be marked as updated too
                // if they would have been regraded. We don't need to regrade items which dependants (not only the direct ones
                // but any dependant in the cascade) have not been updated.

                // If $updated_item was specified we discard the grade items that do not depend on it or on any grade item that
                // depend on $updated_item.

                // Here we check to see if the direct decendants are marked as updated.
                if (!empty($updated_item) && $gid != $updated_item->id && !in_array($updated_item->id, $depends_on[$gid])) {

                    // We need to ensure that none of this item's dependencies have been updated.
                    // If we find that one of the direct decendants of this grade item is marked as updated then this
                    // grade item needs to be recalculated and marked as updated.
                    // Being marked as updated is done further down in the code.

                    $updateddependencies = false;
                    foreach ($depends_on[$gid] as $dependency) {
                        if (in_array($dependency, $updatedids)) {
                            $updateddependencies = true;
                            break;
                        }
                    }
                    if ($updateddependencies === false) {
                        // If no direct descendants are marked as updated, then we don't need to update this grade item. We then mark it
                        // as final.

                        $finalids[] = $gid;
                        continue;
                    }
                }

                // Let's update, calculate or aggregate.
                $result = $course_grade_items[$gid]->regrade_final_grades($userid);

                if ($result === true) {
                    $course_grade_items[$gid]->needsupdate = 0;
                    $count++;
                    $finalids[] = $gid;
                    $updatedids[] = $gid;
                } else {
                    $course_grade_items[$gid]->force_regrading();
                    $errors[$gid] = $result;
                }
            }

            if ($count == 0) {
                $failed++;
            } else {
                $failed = 0;
            }

            if ($failed > 1) {
                foreach($gids as $gid) {
                    if (in_array($gid, $finalids)) {
                        continue; // this one is ok
                    }
                    $course_grade_items[$gid]->force_regrading();
                    $errors[$course_grade_items[$gid]->id] = get_string('errorcalculationbroken', 'grades');
                }
                break; // Found error.
            }
        }

        if (count($errors) == 0) {
            return true;
        } else {
            return $errors;
        }
    }
}

// necessary for grade report...

function grade_report_forecast_settings_definition(&$mform) {
    global $CFG;

    $options = array(-1 => get_string('default', 'grades'),
                      0 => get_string('hide'),
                      1 => get_string('show'));

    if (empty($CFG->grade_report_forecast_showlettergrade)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[1]);
    }

    $options = array(0=>0, 1=>1, 2=>2, 3=>3, 4=>4, 5=>5);
    if (! empty($CFG->grade_report_forecast_rangedecimals)) {
        $options[-1] = $options[$CFG->grade_report_forecast_rangedecimals];
    }
    $mform->addElement('select', 'report_forecast_rangedecimals', get_string('rangedecimals', 'grades'), $options);

    $options = array(-1 => get_string('default', 'grades'),
                      0 => get_string('shownohidden', 'grades'),
                      1 => get_string('showhiddenuntilonly', 'grades'),
                      2 => get_string('showallhidden', 'grades'));

    if (empty($CFG->grade_report_forecast_showhiddenitems)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[$CFG->grade_report_forecast_showhiddenitems]);
    }

    $mform->addElement('select', 'report_forecast_showhiddenitems', get_string('showhiddenitems', 'grades'), $options);
    $mform->addHelpButton('report_forecast_showhiddenitems', 'showhiddenitems', 'grades');

    //showtotalsifcontainhidden
    $options = array(-1 => get_string('default', 'grades'),
                      GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN => get_string('hide'),
                      GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN => get_string('hidetotalshowexhiddenitems', 'grades'),
                      GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN => get_string('hidetotalshowinchiddenitems', 'grades') );

    if (empty($CFG->grade_report_forecast_showtotalsifcontainhidden)) {
        $options[-1] = get_string('defaultprev', 'grades', $options[0]);
    } else {
        $options[-1] = get_string('defaultprev', 'grades', $options[$CFG->grade_report_forecast_showtotalsifcontainhidden]);
    }

    $mform->addElement('select', 'report_forecast_showtotalsifcontainhidden', get_string('hidetotalifhiddenitems', 'grades'), $options);
    $mform->addHelpButton('report_forecast_showtotalsifcontainhidden', 'hidetotalifhiddenitems', 'grades');

}

/**
 * Profile report callback.
 *
 * @param object $course The course.
 * @param object $user The user.
 * @param boolean $viewasuser True when we are viewing this as the targetted user sees it.
 */
function grade_report_forecast_profilereport($course, $user, $viewasuser = false) {
    global $OUTPUT;
    if (!empty($course->showgrades)) {

        $context = context_course::instance($course->id);

        // get tracking object
        $gpr = new grade_plugin_return(array('type'=>'report', 'plugin'=>'forecast', 'courseid'=>$course->id, 'userid'=>$user->id));

        // create a report instance
        $report = new grade_report_forecast($course->id, $gpr, $context, $user->id, $viewasuser);

        // print the report
        echo '<div class="grade-report-forecast">';
        if ($report->fill_table()) {
            echo $report->print_table(true);
        }
        echo '</div>';
    }
}

/**
 * Add nodes to myprofile page.
 *
 * @param \core_user\output\myprofile\tree $tree Tree object
 * @param stdClass $user user object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 */
function gradereport_forecast_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $CFG, $USER;
    if (empty($course)) {
        // We want to display these reports under the site context.
        $course = get_fast_modinfo(SITEID)->get_course();
    }
    $usercontext = context_user::instance($user->id);
    $anyreport = has_capability('moodle/user:viewuseractivitiesreport', $usercontext);

    // Start capability checks.
    if ($anyreport || ($course->showreports && $user->id == $USER->id)) {
        // Add grade hardcoded grade report if necessary.
        $gradeaccess = false;
        $coursecontext = context_course::instance($course->id);
        if (has_capability('moodle/grade:viewall', $coursecontext)) {
            // Can view all course grades.
            $gradeaccess = true;
        } else if ($course->showgrades) {
            if ($iscurrentuser && has_capability('moodle/grade:view', $coursecontext)) {
                // Can view own grades.
                $gradeaccess = true;
            } else if (has_capability('moodle/grade:viewall', $usercontext)) {
                // Can view grades of this user - parent most probably.
                $gradeaccess = true;
            } else if ($anyreport) {
                // Can view grades of this user - parent most probably.
                $gradeaccess = true;
            }
        }
        if ($gradeaccess) {
            $url = new moodle_url('/course/user.php', array('mode' => 'grade', 'id' => $course->id, 'user' => $user->id));
            $node = new core_user\output\myprofile\node('reports', 'grade', get_string('grade'), null, $url);
            $tree->add_node($node);
        }
    }
}

/**
 * Returns an array of grade item inputs from POST data in form: (grade_item_id as int) => (input value as string), or empty array if null
 * 
 * @return array
 */
function getGradeItemInput() {
    if ( empty($_POST))
        return [];

    $gradeItemInput = [];

    foreach ($_POST as $key => $value) {
        // input-gradeitem-
        $itemId = substr($key, 16);

        if ($itemId)
            $gradeItemInput[$itemId] = $value;
    }

    return $gradeItemInput;
}
