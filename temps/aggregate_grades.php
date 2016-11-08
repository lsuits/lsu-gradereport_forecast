<?php 
    
/**
 * Internal function for grade category grade aggregation
 *
 * @param int    $userid The User ID
 * @param array  $items Grade items
 * @param array  $grade_values Array of grade values
 * @param object $oldgrade Old grade
 * @param array  $excluded Excluded
 * @param array  $grademinoverrides User specific grademin values if different to the grade_item grademin (key is itemid)
 * @param array  $grademaxoverrides User specific grademax values if different to the grade_item grademax (key is itemid)
 */
private function aggregate_grades($userid,
                                  $items,
                                  $grade_values,
                                  $oldgrade,
                                  $excluded,
                                  $grademinoverrides,
                                  $grademaxoverrides) {
    global $CFG, $DB;

    // Remember these so we can set flags on them to describe how they were used in the aggregation.
    $novalue = array();
    $dropped = array();
    $extracredit = array();
    $usedweights = array();

    if (empty($userid)) {
        //ignore first call
        return;
    }

    if ($oldgrade) {
        $oldfinalgrade = $oldgrade->finalgrade;
        $grade = new grade_grade($oldgrade, false);
        $grade->grade_item =& $this->grade_item;

    } else {
        // insert final grade - it will be needed later anyway
        $grade = new grade_grade(array('itemid'=>$this->grade_item->id, 'userid'=>$userid), false);
        $grade->grade_item =& $this->grade_item;
        $grade->insert('system');
        $oldfinalgrade = null;
    }

    // no need to recalculate locked or overridden grades
    if ($grade->is_locked() or $grade->is_overridden()) {
        return;
    }

    // can not use own final category grade in calculation
    unset($grade_values[$this->grade_item->id]);

    // Make sure a grade_grade exists for every grade_item.
    // We need to do this so we can set the aggregationstatus
    // with a set_field call instead of checking if each one exists and creating/updating.
    if (!empty($items)) {
        list($ggsql, $params) = $DB->get_in_or_equal(array_keys($items), SQL_PARAMS_NAMED, 'g');


        $params['userid'] = $userid;
        $sql = "SELECT itemid
                  FROM {grade_grades}
                 WHERE itemid $ggsql AND userid = :userid";
        $existingitems = $DB->get_records_sql($sql, $params);

        $notexisting = array_diff(array_keys($items), array_keys($existingitems));
        foreach ($notexisting as $itemid) {
            $gradeitem = $items[$itemid];
            $gradegrade = new grade_grade(array('itemid' => $itemid,
                                                'userid' => $userid,
                                                'rawgrademin' => $gradeitem->grademin,
                                                'rawgrademax' => $gradeitem->grademax), false);
            $gradegrade->grade_item = $gradeitem;
            $gradegrade->insert('system');
        }
    }

    // if no grades calculation possible or grading not allowed clear final grade
    if (empty($grade_values) or empty($items) or ($this->grade_item->gradetype != GRADE_TYPE_VALUE and $this->grade_item->gradetype != GRADE_TYPE_SCALE)) {
        $grade->finalgrade = null;

        if (!is_null($oldfinalgrade)) {
            $grade->timemodified = time();
            $success = $grade->update('aggregation');

            // If successful trigger a user_graded event.
            if ($success) {
                \core\event\user_graded::create_from_grade($grade)->trigger();
            }
        }
        $dropped = $grade_values;
        $this->set_usedinaggregation($userid, $usedweights, $novalue, $dropped, $extracredit);
        return;
    }

    // Normalize the grades first - all will have value 0...1
    // ungraded items are not used in aggregation.
    foreach ($grade_values as $itemid=>$v) {
        if (is_null($v)) {
            // If null, it means no grade.
            if ($this->aggregateonlygraded) {
                unset($grade_values[$itemid]);
                // Mark this item as "excluded empty" because it has no grade.
                $novalue[$itemid] = 0;
                continue;
            }
        }
        if (in_array($itemid, $excluded)) {
            unset($grade_values[$itemid]);
            $dropped[$itemid] = 0;
            continue;
        }
        // Check for user specific grade min/max overrides.
        $usergrademin = $items[$itemid]->grademin;
        $usergrademax = $items[$itemid]->grademax;
        if (isset($grademinoverrides[$itemid])) {
            $usergrademin = $grademinoverrides[$itemid];
        }
        if (isset($grademaxoverrides[$itemid])) {
            $usergrademax = $grademaxoverrides[$itemid];
        }
        if ($this->aggregation == GRADE_AGGREGATE_SUM) {
            // Assume that the grademin is 0 when standardising the score, to preserve negative grades.
            $grade_values[$itemid] = grade_grade::standardise_score($v, 0, $usergrademax, 0, 1);
        } else {
            $grade_values[$itemid] = grade_grade::standardise_score($v, $usergrademin, $usergrademax, 0, 1);
        }

    }

    // For items with no value, and not excluded - either set their grade to 0 or exclude them.
    foreach ($items as $itemid=>$value) {
        if (!isset($grade_values[$itemid]) and !in_array($itemid, $excluded)) {
            if (!$this->aggregateonlygraded) {
                $grade_values[$itemid] = 0;
            } else {
                // We are specifically marking these items as "excluded empty".
                $novalue[$itemid] = 0;
            }
        }
    }

    // limit and sort
    $allvalues = $grade_values;
    if ($this->can_apply_limit_rules()) {
        $this->apply_limit_rules($grade_values, $items);
    }

    $moredropped = array_diff($allvalues, $grade_values);
    foreach ($moredropped as $drop => $unused) {
        $dropped[$drop] = 0;
    }

    foreach ($grade_values as $itemid => $val) {
        if (self::is_extracredit_used() && ($items[$itemid]->aggregationcoef > 0)) {
            $extracredit[$itemid] = 0;
        }
    }

    asort($grade_values, SORT_NUMERIC);

    // let's see we have still enough grades to do any statistics
    if (count($grade_values) == 0) {
        // not enough attempts yet
        $grade->finalgrade = null;

        if (!is_null($oldfinalgrade)) {
            $grade->timemodified = time();
            $success = $grade->update('aggregation');

            // If successful trigger a user_graded event.
            if ($success) {
                \core\event\user_graded::create_from_grade($grade)->trigger();
            }
        }
        $this->set_usedinaggregation($userid, $usedweights, $novalue, $dropped, $extracredit);
        return;
    }

    // do the maths
    $result = $this->aggregate_values_and_adjust_bounds($grade_values,
                                                        $items,
                                                        $usedweights,
                                                        $grademinoverrides,
                                                        $grademaxoverrides);
    $agg_grade = $result['grade'];

    // Set the actual grademin and max to bind the grade properly.
    $this->grade_item->grademin = $result['grademin'];
    $this->grade_item->grademax = $result['grademax'];

    if ($this->aggregation == GRADE_AGGREGATE_SUM) {
        // The natural aggregation always displays the range as coming from 0 for categories.
        // However, when we bind the grade we allow for negative values.
        $result['grademin'] = 0;
    }

    // Recalculate the grade back to requested range.
    $finalgrade = grade_grade::standardise_score($agg_grade, 0, 1, $result['grademin'], $result['grademax']);
    $grade->finalgrade = $this->grade_item->bounded_grade($finalgrade);

    $oldrawgrademin = $grade->rawgrademin;
    $oldrawgrademax = $grade->rawgrademax;
    $grade->rawgrademin = $result['grademin'];
    $grade->rawgrademax = $result['grademax'];

    // Update in db if changed.
    if (grade_floats_different($grade->finalgrade, $oldfinalgrade) ||
            grade_floats_different($grade->rawgrademax, $oldrawgrademax) ||
            grade_floats_different($grade->rawgrademin, $oldrawgrademin)) {
        $grade->timemodified = time();
        $success = $grade->update('aggregation');
    }

    return;
}