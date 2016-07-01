<?php namespace xp\scriptlet;

use io\Path;
use util\Properties;
use lang\XPClass;
use lang\IllegalArgumentException;
use lang\ClassLoadingException;

/**
 * Represent the source argument
 *
 * @test  xp://scriptlet.unittest.SourceTest
 */
class Source extends \lang\Object {
  private $layout;

  /**
   * Creates a new instance
   *
   * @param  string $source
   * @param  xp.scriptlet.Config $config
   * @throws lang.IllegalArgumentException
   */
  public function __construct($source, Config $config= null) {
    if ('-' === $source) {
      $this->layout= new ServeDocumentRootStatically();
    } else if (is_file($source)) {
      $this->layout= new WebConfiguration(new Properties($source));
    } else if (is_dir($source)) {
      $this->layout= new WebConfiguration(new Properties(new Path($source, WebConfiguration::INI)));
    } else {
      $name= ltrim($source, ':');
      try {
        $class= XPClass::forName($name);
      } catch (ClassLoadingException $e) {
        throw new IllegalArgumentException('Cannot load '.$name, $e);
      }

      if ($class->isSubclassOf('scriptlet.HttpScriptlet')) {
        $this->layout= new SingleScriptlet($class->getName(), $config);
      } else if ($class->isSubclassOf('xp.scriptlet.WebLayout')) {
        if ($class->hasMethod('newInstance')) {
          $this->layout= $class->getMethod('newInstance')->invoke(null, [$config]);
        } else {
          $this->layout= $class->newInstance();
        }
      } else {
        throw new IllegalArgumentException('Expecting either a scriptlet or a weblayout, '.$class->getName().' given');
      }
    }
  }

  /** @return xp.scriptlet.WebLayout */
  public function layout() { return $this->layout; }
}