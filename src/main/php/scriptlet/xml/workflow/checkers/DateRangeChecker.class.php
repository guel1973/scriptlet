<?php namespace scriptlet\xml\workflow\checkers;

use text\parser\DateParser;
use util\DateUtil;


/**
 * Checks whether given values are within an date range
 *
 * Error codes returned are:
 * <ul>
 *   <li>tooearly - if the given value exceeds the lower boundary</li>
 *   <li>toolate - if the given value exceeds the upper boundary</li>
 * </ul>
 *
 * @purpose  Checker
 */
class DateRangeChecker extends ParamChecker {
  public
    $minValue  = null,
    $maxValue  = null;
  
  /**
   * Constructor
   *
   * For both min and max values, accepts one of the following:
   * <ul>
   *   <li>The special value "__NOW__" - the date must be today</li>
   *   <li>The special value "__FUTURE__" - the date must be in the future</li>
   *   <li>The special value "__UNLIMITED__" - no limit</li>
   *   <li>Anything parseable by DateParser</li>
   * </ul>
   *
   * @param   string min
   * @param   string max
   */
  public function __construct($min, $max) {
    $this->minValue= $this->parseDate($min, true);
    $this->maxValue= $this->parseDate($max, false);
  }
  
  /**
   * Helper method
   *
   * @param   string input
   * @param   bool lower whether this is the lower boundary
   * @return  util.Date
   */
  protected function parseDate($input, $lower) {
    switch ($input) {
      case '__NOW__': 
        if ($lower) {
          $r= DateUtil::getMidnight(\util\Date::now());
        } else {
          $r= DateUtil::getMidnight(DateUtil::addDays(\util\Date::now(), 1));
        }
        break;

      case '__FUTURE__': 
        $r= \util\Date::now(); 
        break;

      case '__UNLIMITED__': 
        $r= null;
        break;
      
      default:
        $r= DateParser::parse($input);
    }
    
    return $r;
  }
  
  /**
   * Check a given value
   *
   * @param   array value
   * @return  string error or NULL on success
   */
  public function check($value) {
    foreach ($value as $v) {
      if ($this->minValue && $v->isBefore($this->minValue)) {
        return 'tooearly';
      } else if ($this->maxValue && $v->isAfter($this->maxValue)) {
        return 'toolate';
      }
    }    
  }
}
