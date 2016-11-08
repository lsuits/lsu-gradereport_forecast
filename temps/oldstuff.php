<?php

/**
 * Adds grade information (both course and category level) to the response
 * 
 * @param  array  $response
 * @return array  $response
 */
private function addGradesToResponseOLD($response) {
    // get this course's "category" grade items
    $category_grade_items = $this->getCourseCategoryGradeItems();

    $categoryGradeItems = [];

    foreach ($category_grade_items as $category_grade_item_id => $category_grade_item) {
        $categoryGradeItems[] = $category_grade_item;

        // create a forecast_category from this category_grade_item
        $forecast_category = forecast_category::findByGradeItemId($category_grade_item_id);

        // get all nested grade_items for this category_grade_item
        $gradeItems = $forecast_category->getNestedGradeItems();

        $gradeValues = [];

        foreach ($gradeItems as $nested_item_id => $nested_item) {
            // if a grade has been input for this item, include it in the results
            if (array_key_exists($nested_item_id, $this->inputData)) {
                $gradeValues[$nested_item_id] = $this->inputData[$nested_item_id];
            } else {
                // otherwise, try to get a grade for this user
                $grade = $nested_item->get_grade($this->user->id);

                // if no grade, remove the item from the calculation, otherwise inclue it in the results
                if (is_null($grade->finalgrade)) {
                    unset($gradeItems[$nested_item_id]);
                } else {
                    $gradeValues[$nested_item_id] = $grade->finalgrade;
                }
            }
        }

        // get the forecasted (aggregated) category grade total value for these items and values
        $categoryTotalValue = $forecast_category->getForecastedValue($gradeItems, $gradeValues);

        // include this value for the final course aggregation
        $categoryGradeValues[] = $categoryTotalValue;

        // assign category grade total value to response array
        $response['cats'][$category_grade_item_id] = $this->formatCategoryGradeItemDisplay($categoryTotalValue, $category_grade_item);
    }

    // get this course's "course" grade item
    $course_grade_item = $this->getCourseGradeItem();  

    // create a forecast_category from this course_grade_item
    $forecast_category = forecast_category::findByGradeItemId($course_grade_item->id);

    // get the forecasted (aggregated) course grade total value for these items and values
    $response['course'] = $forecast_category->getForecastedValue($categoryGradeItems, $categoryGradeValues);

    return $response;
}