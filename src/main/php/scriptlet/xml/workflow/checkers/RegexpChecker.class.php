<?php namespace scriptlet\xml\workflow\checkers;



/**
 * Checks given values for pattern matches
 *
 * Error codes returned are:
 * <ul>
 *   <li>nomatch - if the given value doesn't match the regular expression</li>
 * </ul>
 *
 * @see      php://preg_match
 * @purpose  Checker
 */
class RegexpChecker extends ParamChecker {
  public
    $pattern  = '';
  
  /**
   * Construct
   *
   * @param   string pattern including the delimiters
   */
  public function __construct($pattern) {
    $this->pattern= $pattern;
  }
  
  /**
   * Check a given value
   *
   * @param   array value
   * @return  string error or NULL on success
   */
  public function check($value) { 
    foreach ($value as $v) {
      if (!preg_match($this->pattern, $v)) return 'nomatch';
    }    
  }
}
