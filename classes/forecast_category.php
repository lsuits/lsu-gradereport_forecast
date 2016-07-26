<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/grade/grade_category.php');

/**
 * A wrapper class for grade_category that represents a "category" or "course" which value may be forecasted/manipulated
 */
class forecast_category extends grade_category {

    /**
     * The grade_item_id for this (category) grade_item
     * 
     * @var int
     */
    public $grade_item_id;

    /**
     * This grade_item_id's grade_item object
     * 
     * @var grade_item
     */
    public $gradeItem;

    /**
     * This grade_item_id's corresponding grade_category object
     * 
     * @var grade_item
     */
    public $gradeCategory;

    /**
     * This grade_category's id
     * 
     * @var int
     */
    public $grade_category_id;

    /**
     * This grade_category's course id
     * 
     * @var int
     */
    public $course_id;

    /**
     * Constructor for forecast_category
     * 
     * @param int  $grade_item_id
     */
    public function __construct($grade_item_id) {
        $this->grade_item_id = $grade_item_id;
        
        $this->gradeItem = $this->loadGradeItem($this->grade_item_id);
        $this->gradeCategory = $this->loadGradeCategory($this->gradeItem);
        $this->grade_category_id = $this->gradeCategory->id;
        $this->course_id = $this->gradeCategory->courseid;
    }

    /**
     * Instantiate a new instance given a (category) grade_item_id
     * 
     * @param  int  $grade_item_id
     * @return forecast_category
     */
    public static function findByGradeItemId($grade_item_id) {
        $grade_item = grade_item::fetch(array('id' => $grade_item_id));

        if ( ! $grade_item) {
            return false;
        }

        return new self($grade_item_id);
    }

    /**
     * Fetches all immediately nested grade_items (not category/course) belonging to this forecast_category
     *
     * TODO: exception handling here!
     * TODO: make sure this ONLY items, not categoryitem, courseitem, etc.
     * 
     * @return array  grade_item_id => grade_item
     */
    public function getNestedGradeItems() {
        $children = $this->gradeCategory->get_children();
        $items = [];

        foreach ($children as $key => $value) {
            $items[$value['object']->id] = $value['object'];
        }

        return $items;
    }

    /**
     * Returns a forecasted grade value (a decimal from 0 to 1) given grade_items and corresponding grade values to consider
     * 
     * @param  array  $grade_items grade_item_id => grade_item
     * @param  array  $values      grade_item_id => value
     * @return decimal
     */
    public function getForecastedValue($grade_items, $values) {
        $normalizedValues = $this->normalizeGradeValues($grade_items, $values);

        $aggregate = $this->getAggregate($grade_items, $normalizedValues);

        if ( ! $aggregate) {
            return 0;
        }

        return $this->getAggregateGrade($aggregate);
    }

    /**
     * Normalizes given grade values by referencing their corresponding grade_items
     * 
     * @param  array  $grade_items grade_item_id => grade_item
     * @param  array  $values      grade_item_id => value
     * @return array
     */
    private function normalizeGradeValues($grade_items, $values) {
        $normalizedValues = [];
        
        foreach ($values as $id => $value) {
            $normalizedValues[$id] = $value / ($grade_items[$id]->grademax - $grade_items[$id]->grademin);
        }
        
        return $normalizedValues;
    }

    /**
     * Returns the "aggregate" for this category given grade_items and corresponding grade values to consider
     * 
     * @param  array  $grade_items grade_item_id => grade_item
     * @param  array  $values      grade_item_id => value
     * @return array [grade|grademin|grademax]  if null, returns false
     */
    private function getAggregate($grade_items, $values) {
        $aggregate = $this->gradeCategory->aggregate_values_and_adjust_bounds($values, $grade_items);

        return ( ! is_null($aggregate)) ? $aggregate : false;
    }

    /**
     * Getter for aggregate "grade" value
     * 
     * @param  array $aggregate [grade|grademin|grademax]
     * @return decimal
     */
    private function getAggregateGrade($aggregate) {
        $grade = $aggregate['grade'];

        return $grade;
    }

    /**
     * Helper for fetching relative grade_item
     * 
     * @param  int  $grade_item_id
     * @return grade_item
     */
    private function loadGradeItem($grade_item_id) {
        return grade_item::fetch(array('id' => $grade_item_id));
    }

    /**
     * Helper for fetching relative grade_category
     * 
     * @param  grade_item
     * @return grade_category
     */
    private function loadGradeCategory($grade_item) {
        return grade_category::fetch(array('id' => $grade_item->iteminstance));
    }

}
