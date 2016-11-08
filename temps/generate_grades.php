<?php

/**
 * Generates and saves final grades in associated category grade item.
 * These immediate children must already have their own final grades.
 * The category's aggregation method is used to generate final grades.
 *
 * Please note that category grade is either calculated or aggregated, not both at the same time.
 *
 * This method must be used ONLY from grade_item::regrade_final_grades(),
 * because the calculation must be done in correct order!
 *
 * Steps to follow:
 *  1. Get final grades from immediate children
 *  3. Aggregate these grades
 *  4. Save them in final grades of associated category grade item
 *
 * @param int $userid The user ID if final grade generation should be limited to a single user
 * @return bool
 */
public function generate_grades($userid=null) {
    global $CFG, $DB;

    $this->load_grade_item();

    if ($this->grade_item->is_locked()) {
        return true; // no need to recalculate locked items
    }

    // find grade items of immediate children (category or grade items) and force site settings
    $depends_on = $this->grade_item->depends_on();

    if (empty($depends_on)) {
        $items = false;

    } else {
        list($usql, $params) = $DB->get_in_or_equal($depends_on);
        $sql = "SELECT *
                  FROM {grade_items}
                 WHERE id $usql";
        $items = $DB->get_records_sql($sql, $params);
        foreach ($items as $id => $item) {
            $items[$id] = new grade_item($item, false);
        }
    }

    $grade_inst = new grade_grade();
    $fields = 'g.'.implode(',g.', $grade_inst->required_fields);

    // where to look for final grades - include grade of this item too, we will store the results there
    $gis = array_merge($depends_on, array($this->grade_item->id));
    list($usql, $params) = $DB->get_in_or_equal($gis);

    if ($userid) {
        $usersql = "AND g.userid=?";
        $params[] = $userid;

    } else {
        $usersql = "";
    }

    $sql = "SELECT $fields
              FROM {grade_grades} g, {grade_items} gi
             WHERE gi.id = g.itemid AND gi.id $usql $usersql
          ORDER BY g.userid";

    // group the results by userid and aggregate the grades for this user
    $rs = $DB->get_recordset_sql($sql, $params);
    if ($rs->valid()) {
        $prevuser = 0;
        $grade_values = array();
        $excluded     = array();
        $oldgrade     = null;
        $grademaxoverrides = array();
        $grademinoverrides = array();

        foreach ($rs as $used) {
            $grade = new grade_grade($used, false);
            if (isset($items[$grade->itemid])) {
                // Prevent grade item to be fetched from DB.
                $grade->grade_item =& $items[$grade->itemid];
            } else if ($grade->itemid == $this->grade_item->id) {
                // This grade's grade item is not in $items.
                $grade->grade_item =& $this->grade_item;
            }
            if ($grade->userid != $prevuser) {
                $this->aggregate_grades($prevuser,
                                        $items,
                                        $grade_values,
                                        $oldgrade,
                                        $excluded,
                                        $grademinoverrides,
                                        $grademaxoverrides);
                $prevuser = $grade->userid;
                $grade_values = array();
                $excluded     = array();
                $oldgrade     = null;
                $grademaxoverrides = array();
                $grademinoverrides = array();
            }
            $grade_values[$grade->itemid] = $grade->finalgrade;
            $grademaxoverrides[$grade->itemid] = $grade->get_grade_max();
            $grademinoverrides[$grade->itemid] = $grade->get_grade_min();

            if ($grade->excluded) {
                $excluded[] = $grade->itemid;
            }

            if ($this->grade_item->id == $grade->itemid) {
                $oldgrade = $grade;
            }
        }
        $this->aggregate_grades($prevuser,
                                $items,
                                $grade_values,
                                $oldgrade,
                                $excluded,
                                $grademinoverrides,
                                $grademaxoverrides);//the last one
    }
    $rs->close();

    return true;
}