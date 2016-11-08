<?php

/**
 * Internal function that calculates the aggregated grade and new min/max for this grade category
 *
 * Must be public as it is used by grade_grade::get_hiding_affected()
 *
 * @param array $grade_values An array of values to be aggregated
 * @param array $items The array of grade_items
 * @since Moodle 2.6.5, 2.7.2
 * @param array & $weights If provided, will be filled with the normalized weights
 *                         for each grade_item as used in the aggregation.
 *                         Some rules for the weights are:
 *                         1. The weights must add up to 1 (unless there are extra credit)
 *                         2. The contributed points column must add up to the course
 *                         final grade and this column is calculated from these weights.
 * @param array  $grademinoverrides User specific grademin values if different to the grade_item grademin (key is itemid)
 * @param array  $grademaxoverrides User specific grademax values if different to the grade_item grademax (key is itemid)
 * @return array containing values for:
 *                'grade' => the new calculated grade
 *                'grademin' => the new calculated min grade for the category
 *                'grademax' => the new calculated max grade for the category
 */
public function aggregate_values_and_adjust_bounds($grade_values,
                                                   $items,
                                                   & $weights = null,
                                                   $grademinoverrides = array(),
                                                   $grademaxoverrides = array()) {
    $category_item = $this->load_grade_item();
    $grademin = $category_item->grademin;
    $grademax = $category_item->grademax;

    switch ($this->aggregation) {

        case GRADE_AGGREGATE_MEDIAN: // Middle point value in the set: ignores frequencies
            $num = count($grade_values);
            $grades = array_values($grade_values);

            // The median gets 100% - others get 0.
            if ($weights !== null && $num > 0) {
                $count = 0;
                foreach ($grade_values as $itemid=>$grade_value) {
                    if (($num % 2 == 0) && ($count == intval($num/2)-1 || $count == intval($num/2))) {
                        $weights[$itemid] = 0.5;
                    } else if (($num % 2 != 0) && ($count == intval(($num/2)-0.5))) {
                        $weights[$itemid] = 1.0;
                    } else {
                        $weights[$itemid] = 0;
                    }
                    $count++;
                }
            }
            if ($num % 2 == 0) {
                $agg_grade = ($grades[intval($num/2)-1] + $grades[intval($num/2)]) / 2;
            } else {
                $agg_grade = $grades[intval(($num/2)-0.5)];
            }

            break;

        case GRADE_AGGREGATE_MIN:
            $agg_grade = reset($grade_values);
            // Record the weights as used.
            if ($weights !== null) {
                foreach ($grade_values as $itemid=>$grade_value) {
                    $weights[$itemid] = 0;
                }
            }
            // Set the first item to 1.
            $itemids = array_keys($grade_values);
            $weights[reset($itemids)] = 1;
            break;

        case GRADE_AGGREGATE_MAX:
            // Record the weights as used.
            if ($weights !== null) {
                foreach ($grade_values as $itemid=>$grade_value) {
                    $weights[$itemid] = 0;
                }
            }
            // Set the last item to 1.
            $itemids = array_keys($grade_values);
            $weights[end($itemids)] = 1;
            $agg_grade = end($grade_values);
            break;

        case GRADE_AGGREGATE_MODE:       // the most common value
            // array_count_values only counts INT and STRING, so if grades are floats we must convert them to string
            $converted_grade_values = array();

            foreach ($grade_values as $k => $gv) {

                if (!is_int($gv) && !is_string($gv)) {
                    $converted_grade_values[$k] = (string) $gv;

                } else {
                    $converted_grade_values[$k] = $gv;
                }
                if ($weights !== null) {
                    $weights[$k] = 0;
                }
            }

            $freq = array_count_values($converted_grade_values);
            arsort($freq);                      // sort by frequency keeping keys
            $top = reset($freq);               // highest frequency count
            $modes = array_keys($freq, $top);  // search for all modes (have the same highest count)
            rsort($modes, SORT_NUMERIC);       // get highest mode
            $agg_grade = reset($modes);
            // Record the weights as used.
            if ($weights !== null && $top > 0) {
                foreach ($grade_values as $k => $gv) {
                    if ($gv == $agg_grade) {
                        $weights[$k] = 1.0 / $top;
                    }
                }
            }
            break;

        case GRADE_AGGREGATE_WEIGHTED_MEAN: // Weighted average of all existing final grades, weight specified in coef
            $weightsum = 0;
            $sum       = 0;

            foreach ($grade_values as $itemid=>$grade_value) {
                if ($weights !== null) {
                    $weights[$itemid] = $items[$itemid]->aggregationcoef;
                }
                if ($items[$itemid]->aggregationcoef <= 0) {
                    continue;
                }
                $weightsum += $items[$itemid]->aggregationcoef;
                $sum       += $items[$itemid]->aggregationcoef * $grade_value;
            }
            if ($weightsum == 0) {
                $agg_grade = null;

            } else {
                $agg_grade = $sum / $weightsum;
                if ($weights !== null) {
                    // Normalise the weights.
                    foreach ($weights as $itemid => $weight) {
                        $weights[$itemid] = $weight / $weightsum;
                    }
                }

            }
            break;

        case GRADE_AGGREGATE_WEIGHTED_MEAN2:
            // Weighted average of all existing final grades with optional extra credit flag,
            // weight is the range of grade (usually grademax)
            $this->load_grade_item();
            $weightsum = 0;
            $sum       = null;

            foreach ($grade_values as $itemid=>$grade_value) {
                if ($items[$itemid]->aggregationcoef > 0) {
                    continue;
                }

                $weight = $items[$itemid]->grademax - $items[$itemid]->grademin;
                if ($weight <= 0) {
                    continue;
                }

                $weightsum += $weight;
                $sum += $weight * $grade_value;
            }

            // Handle the extra credit items separately to calculate their weight accurately.
            foreach ($grade_values as $itemid => $grade_value) {
                if ($items[$itemid]->aggregationcoef <= 0) {
                    continue;
                }

                $weight = $items[$itemid]->grademax - $items[$itemid]->grademin;
                if ($weight <= 0) {
                    $weights[$itemid] = 0;
                    continue;
                }

                $oldsum = $sum;
                $weightedgrade = $weight * $grade_value;
                $sum += $weightedgrade;

                if ($weights !== null) {
                    if ($weightsum <= 0) {
                        $weights[$itemid] = 0;
                        continue;
                    }

                    $oldgrade = $oldsum / $weightsum;
                    $grade = $sum / $weightsum;
                    $normoldgrade = grade_grade::standardise_score($oldgrade, 0, 1, $grademin, $grademax);
                    $normgrade = grade_grade::standardise_score($grade, 0, 1, $grademin, $grademax);
                    $boundedoldgrade = $this->grade_item->bounded_grade($normoldgrade);
                    $boundedgrade = $this->grade_item->bounded_grade($normgrade);

                    if ($boundedgrade - $boundedoldgrade <= 0) {
                        // Nothing new was added to the grade.
                        $weights[$itemid] = 0;
                    } else if ($boundedgrade < $normgrade) {
                        // The grade has been bounded, the extra credit item needs to have a different weight.
                        $gradediff = $boundedgrade - $normoldgrade;
                        $gradediffnorm = grade_grade::standardise_score($gradediff, $grademin, $grademax, 0, 1);
                        $weights[$itemid] = $gradediffnorm / $grade_value;
                    } else {
                        // Default weighting.
                        $weights[$itemid] = $weight / $weightsum;
                    }
                }
            }

            if ($weightsum == 0) {
                $agg_grade = $sum; // only extra credits

            } else {
                $agg_grade = $sum / $weightsum;
            }

            // Record the weights as used.
            if ($weights !== null) {
                foreach ($grade_values as $itemid=>$grade_value) {
                    if ($items[$itemid]->aggregationcoef > 0) {
                        // Ignore extra credit items, the weights have already been computed.
                        continue;
                    }
                    if ($weightsum > 0) {
                        $weight = $items[$itemid]->grademax - $items[$itemid]->grademin;
                        $weights[$itemid] = $weight / $weightsum;
                    } else {
                        $weights[$itemid] = 0;
                    }
                }
            }
            break;

        case GRADE_AGGREGATE_EXTRACREDIT_MEAN: // special average
            $this->load_grade_item();
            $num = 0;
            $sum = null;

            foreach ($grade_values as $itemid=>$grade_value) {
                if ($items[$itemid]->aggregationcoef == 0) {
                    $num += 1;
                    $sum += $grade_value;
                    if ($weights !== null) {
                        $weights[$itemid] = 1;
                    }
                }
            }

            // Treating the extra credit items separately to get a chance to calculate their effective weights.
            foreach ($grade_values as $itemid=>$grade_value) {
                if ($items[$itemid]->aggregationcoef > 0) {
                    $oldsum = $sum;
                    $sum += $items[$itemid]->aggregationcoef * $grade_value;

                    if ($weights !== null) {
                        if ($num <= 0) {
                            // The category only contains extra credit items, not setting the weight.
                            continue;
                        }

                        $oldgrade = $oldsum / $num;
                        $grade = $sum / $num;
                        $normoldgrade = grade_grade::standardise_score($oldgrade, 0, 1, $grademin, $grademax);
                        $normgrade = grade_grade::standardise_score($grade, 0, 1, $grademin, $grademax);
                        $boundedoldgrade = $this->grade_item->bounded_grade($normoldgrade);
                        $boundedgrade = $this->grade_item->bounded_grade($normgrade);

                        if ($boundedgrade - $boundedoldgrade <= 0) {
                            // Nothing new was added to the grade.
                            $weights[$itemid] = 0;
                        } else if ($boundedgrade < $normgrade) {
                            // The grade has been bounded, the extra credit item needs to have a different weight.
                            $gradediff = $boundedgrade - $normoldgrade;
                            $gradediffnorm = grade_grade::standardise_score($gradediff, $grademin, $grademax, 0, 1);
                            $weights[$itemid] = $gradediffnorm / $grade_value;
                        } else {
                            // Default weighting.
                            $weights[$itemid] = 1.0 / $num;
                        }
                    }
                }
            }

            if ($weights !== null && $num > 0) {
                foreach ($grade_values as $itemid=>$grade_value) {
                    if ($items[$itemid]->aggregationcoef > 0) {
                        // Extra credit weights were already calculated.
                        continue;
                    }
                    if ($weights[$itemid]) {
                        $weights[$itemid] = 1.0 / $num;
                    }
                }
            }

            if ($num == 0) {
                $agg_grade = $sum; // only extra credits or wrong coefs

            } else {
                $agg_grade = $sum / $num;
            }

            break;

        case GRADE_AGGREGATE_SUM:    // Add up all the items.
            $this->load_grade_item();
            $num = count($grade_values);
            $sum = 0;

            // This setting indicates if we should use algorithm prior to MDL-49257 fix for calculating extra credit weights.
            // Even though old algorith has bugs in it, we need to preserve existing grades.
            $gradebookcalculationfreeze = (int)get_config('core', 'gradebook_calculations_freeze_' . $this->courseid);
            $oldextracreditcalculation = $gradebookcalculationfreeze && ($gradebookcalculationfreeze <= 20150619);

            $sumweights = 0;
            $grademin = 0;
            $grademax = 0;
            $extracredititems = array();
            foreach ($grade_values as $itemid => $gradevalue) {
                // We need to check if the grademax/min was adjusted per user because of excluded items.
                $usergrademin = $items[$itemid]->grademin;
                $usergrademax = $items[$itemid]->grademax;
                if (isset($grademinoverrides[$itemid])) {
                    $usergrademin = $grademinoverrides[$itemid];
                }
                if (isset($grademaxoverrides[$itemid])) {
                    $usergrademax = $grademaxoverrides[$itemid];
                }

                // Keep track of the extra credit items, we will need them later on.
                if ($items[$itemid]->aggregationcoef > 0) {
                    $extracredititems[$itemid] = $items[$itemid];
                }

                // Ignore extra credit and items with a weight of 0.
                if (!isset($extracredititems[$itemid]) && $items[$itemid]->aggregationcoef2 > 0) {
                    $grademin += $usergrademin;
                    $grademax += $usergrademax;
                    $sumweights += $items[$itemid]->aggregationcoef2;
                }
            }
            $userweights = array();
            $totaloverriddenweight = 0;
            $totaloverriddengrademax = 0;
            // We first need to rescale all manually assigned weights down by the
            // percentage of weights missing from the category.
            foreach ($grade_values as $itemid => $gradevalue) {
                if ($items[$itemid]->weightoverride) {
                    if ($items[$itemid]->aggregationcoef2 <= 0) {
                        // Records the weight of 0 and continue.
                        $userweights[$itemid] = 0;
                        continue;
                    }
                    $userweights[$itemid] = $sumweights ? ($items[$itemid]->aggregationcoef2 / $sumweights) : 0;
                    if (!$oldextracreditcalculation && isset($extracredititems[$itemid])) {
                        // Extra credit items do not affect totals.
                        continue;
                    }
                    $totaloverriddenweight += $userweights[$itemid];
                    $usergrademax = $items[$itemid]->grademax;
                    if (isset($grademaxoverrides[$itemid])) {
                        $usergrademax = $grademaxoverrides[$itemid];
                    }
                    $totaloverriddengrademax += $usergrademax;
                }
            }
            $nonoverriddenpoints = $grademax - $totaloverriddengrademax;

            // Then we need to recalculate the automatic weights except for extra credit items.
            foreach ($grade_values as $itemid => $gradevalue) {
                if (!$items[$itemid]->weightoverride && ($oldextracreditcalculation || !isset($extracredititems[$itemid]))) {
                    $usergrademax = $items[$itemid]->grademax;
                    if (isset($grademaxoverrides[$itemid])) {
                        $usergrademax = $grademaxoverrides[$itemid];
                    }
                    if ($nonoverriddenpoints > 0) {
                        $userweights[$itemid] = ($usergrademax/$nonoverriddenpoints) * (1 - $totaloverriddenweight);
                    } else {
                        $userweights[$itemid] = 0;
                        if ($items[$itemid]->aggregationcoef2 > 0) {
                            // Items with a weight of 0 should not count for the grade max,
                            // though this only applies if the weight was changed to 0.
                            $grademax -= $usergrademax;
                        }
                    }
                }
            }

            // Now when we finally know the grademax we can adjust the automatic weights of extra credit items.
            if (!$oldextracreditcalculation) {
                foreach ($grade_values as $itemid => $gradevalue) {
                    if (!$items[$itemid]->weightoverride && isset($extracredititems[$itemid])) {
                        $usergrademax = $items[$itemid]->grademax;
                        if (isset($grademaxoverrides[$itemid])) {
                            $usergrademax = $grademaxoverrides[$itemid];
                        }
                        $userweights[$itemid] = $grademax ? ($usergrademax / $grademax) : 0;
                    }
                }
            }

            // We can use our freshly corrected weights below.
            foreach ($grade_values as $itemid => $gradevalue) {
                if (isset($extracredititems[$itemid])) {
                    // We skip the extra credit items first.
                    continue;
                }
                $sum += $gradevalue * $userweights[$itemid] * $grademax;
                if ($weights !== null) {
                    $weights[$itemid] = $userweights[$itemid];
                }
            }

            // No we proceed with the extra credit items. They might have a different final
            // weight in case the final grade was bounded. So we need to treat them different.
            // Also, as we need to use the bounded_grade() method, we have to inject the
            // right values there, and restore them afterwards.
            $oldgrademax = $this->grade_item->grademax;
            $oldgrademin = $this->grade_item->grademin;
            foreach ($grade_values as $itemid => $gradevalue) {
                if (!isset($extracredititems[$itemid])) {
                    continue;
                }
                $oldsum = $sum;
                $weightedgrade = $gradevalue * $userweights[$itemid] * $grademax;
                $sum += $weightedgrade;

                // Only go through this when we need to record the weights.
                if ($weights !== null) {
                    if ($grademax <= 0) {
                        // There are only extra credit items in this category,
                        // all the weights should be accurate (and be 0).
                        $weights[$itemid] = $userweights[$itemid];
                        continue;
                    }

                    $oldfinalgrade = $this->grade_item->bounded_grade($oldsum);
                    $newfinalgrade = $this->grade_item->bounded_grade($sum);
                    $finalgradediff = $newfinalgrade - $oldfinalgrade;
                    if ($finalgradediff <= 0) {
                        // This item did not contribute to the category total at all.
                        $weights[$itemid] = 0;
                    } else if ($finalgradediff < $weightedgrade) {
                        // The weight needs to be adjusted because only a portion of the
                        // extra credit item contributed to the category total.
                        $weights[$itemid] = $finalgradediff / ($gradevalue * $grademax);
                    } else {
                        // The weight was accurate.
                        $weights[$itemid] = $userweights[$itemid];
                    }
                }
            }
            $this->grade_item->grademax = $oldgrademax;
            $this->grade_item->grademin = $oldgrademin;

            if ($grademax > 0) {
                $agg_grade = $sum / $grademax; // Re-normalize score.
            } else {
                // Every item in the category is extra credit.
                $agg_grade = $sum;
                $grademax = $sum;
            }

            break;

        case GRADE_AGGREGATE_MEAN:    // Arithmetic average of all grade items (if ungraded aggregated, NULL counted as minimum)
        default:
            $num = count($grade_values);
            $sum = array_sum($grade_values);
            $agg_grade = $sum / $num;
            // Record the weights evenly.
            if ($weights !== null && $num > 0) {
                foreach ($grade_values as $itemid=>$grade_value) {
                    $weights[$itemid] = 1.0 / $num;
                }
            }
            break;
    }

    return array('grade' => $agg_grade, 'grademin' => $grademin, 'grademax' => $grademax);
}