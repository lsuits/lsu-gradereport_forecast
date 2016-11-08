<?php

/**
 * Performs the necessary calculations on the grades_final referenced by this grade_item.
 * Also resets the needsupdate flag once successfully performed.
 *
 * This function must be used ONLY from lib/gradeslib.php/grade_regrade_final_grades(),
 * because the regrading must be done in correct order!!
 *
 * @param int $userid Supply a user ID to limit the regrading to a single user
 * @return bool true if ok, error string otherwise
 */
public function regrade_final_grades($userid=null) {
    global $CFG, $DB;

    // locked grade items already have correct final grades
    if ($this->is_locked()) {
        return true;
    }

    // noncalculated outcomes already have final values - raw grades not used
    if ($this->is_outcome_item()) {
        return true;

    // aggregate the category grade
    } else if ($this->is_category_item() or $this->is_course_item()) {
        // aggregate category grade item
        $category = $this->load_item_category();
        $category->grade_item =& $this;
        if ($category->generate_grades($userid)) {
            return true;
        } else {
            return "Could not aggregate final grades for category:".$this->id; // TODO: improve and localize
        }

    } else if ($this->is_manual_item()) {
        // manual items track only final grades, no raw grades
        return true;

    } else if (!$this->is_raw_used()) {
        // hmm - raw grades are not used- nothing to regrade
        return true;
    }

    // normal grade item - just new final grades
    $result = true;
    $grade_inst = new grade_grade();
    $fields = implode(',', $grade_inst->required_fields);
    $params = array($this->id, $userid);
    $rs = $DB->get_recordset_select('grade_grades', "itemid=? AND userid=?", $params, '', $fields);
    
    if ($rs) {
        foreach ($rs as $grade_record) {
            $grade = new grade_grade($grade_record, false);

            if (!empty($grade_record->locked) or !empty($grade_record->overridden)) {
                // this grade is locked - final grade must be ok
                continue;
            }

            $grade->finalgrade = $this->adjust_raw_grade($grade->rawgrade, $grade->rawgrademin, $grade->rawgrademax);
        }
        $rs->close();
    }

    return $result;
}