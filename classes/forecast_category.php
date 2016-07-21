<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/grade/grade_category.php');

/**
 * A wrapper class for grade_category that represents a "category" which value may be forecasted/manipulated
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
     * Returns a formatted, forecasted grade value given grade_items and corresponding values to consider
     * 
     * @param  array  $grade_items grade_item_id => grade_item
     * @param  array  $values      grade_item_id => value
     * @param  string $type        number|percentage
     * @return decimal
     */
    public function getForecastedGrade($grade_items, $values, $type) {
        $aggregate = $this->getAggregate($grade_items, $values);

        if ( ! $aggregate) {
            return $this->formatNumberByType(0, $type);
        }

        $aggregated_value = $this->getAggregatedValue($aggregate, true);

        return $this->formatNumberByType($aggregated_value, $type);
    }

    /**
     * Returns a number formatted in specified "type"
     * 
     * @param  mixed  $number
     * @param  string  $type  number|percentage
     * @return string
     */
    private function formatNumberByType($number, $type) {
        switch ($type) {
            case 'number':
                return $this->formatNumber($number);
                break;
            
            case 'percentage':
                return $this->formatPercentage($number);
                break;
            default:
                return $number;
                break;
        }
    }

    /**
     * Calculates a current "aggregate" for this category given grade_items and corresponding values to consider
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
     * Returns an aggregated value given an "aggregate" array, optionally "standarizes" value
     * 
     * @param  array  $aggregate  [grade|grademin|grademax]
     * @param  bool  $standardize
     * @return mixed
     */
    private function getAggregatedValue($aggregate, $standardize = false) {
        if ( ! $standardize)
            return $aggregate['grade'];

        // Set the actual grademin and max to bind the grade properly.
        $this->gradeItem->grademin = $aggregate['grademin'];
        $this->gradeItem->grademax = $aggregate['grademax'];

        if ($this->gradeCategory->aggregation == GRADE_AGGREGATE_SUM) {
            // The natural aggregation always displays the range as coming from 0 for categories.
            // However, when we bind the grade we allow for negative values.
            $aggregate['grademin'] = 0;
        }

        // Recalculate the grade back to requested range.
        $finalgrade = grade_grade::standardise_score($aggregate['grade'], 0, 1, $aggregate['grademin'], $aggregate['grademax']);
        
        // TODO: do we bind or not?
        return $finalgrade;
        return $this->gradeItem->bounded_grade($finalgrade);
    }

    /**
     * Helper for rounding a number to 4 places
     * 
     * @param  mixed  $number
     * @return decimal
     */
    private function formatNumber($number) {
        return number_format($number, 4);
    }

    /**
     * Helper for displaying a percentage
     * 
     * @param  float $percentage
     * @return string
     */
    private function formatPercentage($percentage) {
        return sprintf("%.2f%%", $percentage * 100);
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
