<?php

/**
 * Updates all final grades in course.
 *
 * @param int $courseid The course ID
 * @param int $userid If specified try to do a quick regrading of the grades of this user only
 * 
 * @return bool true if ok, array of errors if problems found. Grade item id => error message
 */
function grade_regrade_final_grades($courseid, $userid=null) {

    $grade_items = grade_item::fetch_all(array('courseid'=>$courseid));
    $depends_on = array();

    foreach ($grade_items as $gid=>$gitem) {
        // We load all dependencies of these items later we can discard some grade_items based on this.
        if ($grade_items[$gid]->needsupdate) {
            $depends_on[$gid] = $grade_items[$gid]->depends_on();
        }
    }

    $finalids = array();
    $updatedids = array();
    $gids     = array_keys($grade_items);

    while (count($finalids) < count($gids)) { // work until all grades are final or error found
        foreach ($gids as $gid) {
            if (in_array($gid, $finalids)) {
                continue; // already final
            }

            if (!$grade_items[$gid]->needsupdate) {
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

            // Let's update, calculate or aggregate.
            $result = $grade_items[$gid]->regrade_final_grades($userid);

            $grade_items[$gid]->needsupdate = 0;
            $finalids[] = $gid;
            $updatedids[] = $gid;
        }
    }

    return true;
}