/**
 * The gradebook forecast JS library
 *
 * @package    gradereport_forecast
 * @copyright  2016 Louisiana State University, Chad Mazilly, Robert Russo, Dave Elliott
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Helper for retrieving current course id from forecast form
 * 
 * @return string
 */
function getCourseId() {
    return $('input[name="courseid"]').val();
}

/**
 * Helper for retrieving current user id from forecast form
 * 
 * @return string
 */
function getUserId() {
    return $('input[name="userid"]').val();
}

/**
 * Fetches all category HTML elements from forecast form
 * 
 * @return object
 */
function getCategories() {
    return getElementsByType('cat');
}

/**
 * Fetches all grade item input HTML elements from forecast form
 * 
 * @return object
 */
function getGradeInputs() {
    return getElementsByType('dynamic-item');
}

/**
 * Fetches course category input HTML element from forecast form
 * 
 * @return object
 */
function getCourseCategory() {
    return $('td[class*="fcst-course"]'); 
}

/**
 * Fetches HTML element of given key
 * 
 * @param  string  cat|dynamic-item
 * @return object
 */
function getElementsByType(key) {
    return $('td[class*="fcst-' + key +'"]');
}

/**
 * Fetches "must make" HTML element from modal table
 * 
 * @return object
 */
function getMustMakeElement(id) {
    return $('td[id="' + id + '"]');
}

/**
 * Determines whether or a given event has left it's element value in an acceptable state
 *
 * Bypasses some old logic that may be pertinent
 * 
 * @param  object  event
 * @return bool
 */
function isValidEventInput(event) {
    return ($.isNumeric(event.target.value)) ? true : false;

    var key = event.keyCode;

    // Integer
    if (isFinite(parseInt(String.fromCharCode(key)))) { return true }

    // Numpad keys
    if (key >= 96 && key <= 105) { return true }

    // Ignored keys: tab, backspace, etc
    if ($.inArray(key, [8, 46]) != -1) { return true }

    // Modifier Keys
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return false }

    return false;
}

/**
 * Determines whether or not a given input value is within acceptable range for a specified grade element
 * 
 * @param  object  element
 * @param  string  inputValue
 * @return bool
 */
function isElementInputInGradeRange(element, inputValue) {
    var gradeMinRaw = getElementFcstValue(element, 'grade-mi');
    var gradeMaxRaw = getElementFcstValue(element, 'grade-ma');

    var gradeMin = roundGrade(gradeMinRaw);
    var gradeMax = roundGrade(gradeMaxRaw);
    var inputGrade = roundGrade(inputValue);

    if (inputGrade <= gradeMax && inputGrade >= gradeMin) {
        return true;
    }

    return false;
}
/**
 * Fetches a specified "fcst" value by key for a specified element, or null if no data available
 * 
 * @param  object  element
 * @param  string  key  fcst-cat|fcst-cou|fcst-dyn|grade-mi|grade-ma
 * @return string|null
 */
function getElementFcstValue(element, key) {
    var classes = element.classList;

    for (var i = 0; i != classes.length; i++) {
        var cl = classes[i];

        if (cl.substring(0, 8) === key) {
            return cl.split('-').reverse()[0];
        }
    }

    return null; 
}

/**
 * Returns a given numeric string rounded to 6 digits
 * 
 * @param  string  value
 * @return float
 */
function roundGrade(value) {
    return Math.round(parseFloat(value)*1000000)/1000000;
}

/**
 * Shows a speficied error by key for a given element
 * 
 * @param  object  element
 * @param  string  key  range|invalid
 * @return void
 */
function showGradeError(element, key) {
    $(element).find('span.fcst-error-' + key).show();
}

/**
 * Hides a speficied error by key for a given element
 * 
 * @param  object  element
 * @param  string  key  range|invalid
 * @return void
 */
function hideGradeError(element, key) {
    $(element).find('span.fcst-error-' + key).hide();
}

/**
 * Reports whether or not any errors are being displayed currently
 * 
 * @return bool
 */
function inputErrorsExist() {
    return Boolean($('span.fcst-error:visible').length);
}

/**
 * Fetches all forecast form input
 * 
 * @return object
 */
function collectFormInput() {
    var inputs = {};
    
    $('#forecast-form :input').each(function() {
        inputs[this.name] = $(this).val();
    });

    return inputs;
}

/**
 * Event listener: changes to forecast for input
 * 
 * @return void
 */
function listenForInputChanges() {
    getGradeInputs().keyup(function(event) {
        handleInputChange(event);

        return;
    });
}

/**
 * Event handler: validates input and refreshes report totals based on form input
 * 
 * @param  object  event
 * @return void
 */
function handleInputChange(event) {
    if ( ! validateInputChange(event)) {
        return;
    }

    hideGradeError(event.currentTarget, 'invalid');
    hideGradeError(event.currentTarget, 'range');

    if (inputErrorsExist()) {
        return;
    }

    postGradeInputs();
}

/**
 * Reports whether or not an event's input is valid and displays any necessary errors
 * 
 * @param  object  event
 * @return bool
 */
function validateInputChange(event) {
    var passedValidation = true;

    if ( ! event.target.value == '') {
        if ( ! isValidEventInput(event)) {
            showGradeError(event.currentTarget, 'invalid');
            hideGradeError(event.currentTarget, 'range');
            passedValidation = false;
        }

        if (passedValidation && ! isElementInputInGradeRange(event.currentTarget, event.target.value)) {
            showGradeError(event.currentTarget, 'range');
            hideGradeError(event.currentTarget, 'invalid');
            passedValidation = false;
        }
    }

    return passedValidation;
}

/**
 * Updates all report totals
 * 
 * @param  object response
 * @return void
 */
function updateTotals(response) {
    updateCategoryTotals(response.cats);
    updateCourseTotal(response.course);
}

/**
 * Updates all category totals on report
 * 
 * @param  object  cats
 * @return void
 */
function updateCategoryTotals(cats) {
    getCategories().each(function() {
        var categoryId = getElementFcstValue(this, 'fcst-cat');

        if (cats[categoryId]) {
            $(this).html(cats[categoryId]);
        }
    });
}

/**
 * Updates course category total on report
 * 
 * @param  string  value
 * @return void
 */
function updateCourseTotal(value) {
    getCourseCategory().html(value);
}

/**
 * Posts forecast form input, formats responses, handles response
 * 
 * @return void
 */
function postGradeInputs() {
    var inputs = collectFormInput();

    $.post('io.php', inputs, function(data) {
        var response = JSON.parse(data);
        
        handleGradeInputResponse(response);

        if (response.showMustMake) {
            renderMustMakeModal(response.mustMakeArray);
        }
    });
}

/**
 * Populates "must make" modal table and then shows the modal
 * 
 * @return void
 */
function renderMustMakeModal(values) {
    for (var id in values) {
        getMustMakeElement(id).html(values[id]);
    }

    // set a slight delay in triggering the modal to account for calculation time
    setTimeout(function() {
        $('#mustMakeModal').modal('show');
    }, 500);
}

/**
 * Handler for grade input remote response
 * 
 * @param  object  response
 * @return void
 */
function handleGradeInputResponse(response) {
    updateTotals(response);
}

//////////////////////////////////////////////////////////////////////////////

$(document).ready(function() {
    listenForInputChanges();
});