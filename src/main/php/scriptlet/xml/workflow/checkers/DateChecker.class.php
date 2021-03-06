<?php namespace scriptlet\xml\workflow\checkers;
 
use text\parser\DateParser;


/**
 * Checks given date on validity
 *
 * Error codes returned are:
 * <ul>
 *   <li>invalid - if the given value is no valid date</li>
 * </ul>
 *
 * @purpose  Checker
 */
class DateChecker extends \lang\Object {

  /**
   * Check a given value
   *
   * @param   array value
   * @return  string error or NULL on success
   */
  public function check($value) { 
    foreach ($value as $v) {
      try {
        DateParser::parse($v);
      } catch (\lang\FormatException $e) {
        return 'invalid';
      }
    }    
  }
}
