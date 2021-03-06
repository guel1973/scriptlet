<?php namespace scriptlet\unittest;

use unittest\TestCase;
use xp\scriptlet\Runner;
use xml\Stylesheet;
use xml\Node;
use util\log\Traceable;
use util\log\BufferedAppender;
use scriptlet\HttpScriptlet;
use scriptlet\xml\XMLScriptlet;
use lang\Runtime;
use lang\System;

/**
 * TestCase
 *
 * @see   xp://xp.scriptlet.Runner
 */
class RunnerTest extends TestCase {
  private static $welcomeScriptlet, $errorScriptlet, $debugScriptlet, $xmlScriptlet, $exitScriptlet;
  private static $propertySource;
  private static $layout;
  
  #[@beforeClass]
  public static function defineScriptlets() {
    self::$errorScriptlet= \lang\ClassLoader::defineClass('ErrorScriptlet', 'scriptlet.HttpScriptlet', ['util.log.Traceable'], '{
      public function setTrace($cat) {
        $cat->debug("Injected", nameof($cat));
      }
      
      public function doGet($request, $response) {
        throw new \lang\IllegalAccessException("No shoes, no shorts, no service");
      }
    }');
    self::$welcomeScriptlet= \lang\ClassLoader::defineClass('WelcomeScriptlet', 'scriptlet.HttpScriptlet', [], '{
      private $config;
      public function __construct($config= null) {
        $this->config= $config;
      }
      public function doGet($request, $response) {
        $response->write("<h1>Welcome, we are open".($this->config ? " & ".$this->config->toString(): "")."</h1>");
      }
    }');
    self::$xmlScriptlet= \lang\ClassLoader::defineClass('XmlScriptletImpl', 'scriptlet.xml.XMLScriptlet', [], '{
      protected function _response() {
        $res= parent::_response();
        $stylesheet= (new \xml\Stylesheet())
          ->withEncoding("iso-8859-1")
          ->withOutputMethod("xml")
          ->withTemplate((new \xml\XslTemplate())->matching("/")
            ->withChild((new \xml\Node("h1"))
              ->withChild(new \xml\Node("xsl:value-of", NULL, ["select" => "/formresult/result"]))
            )
          )
        ;
        $res->setStylesheet($stylesheet, XSLT_TREE);
        return $res;
      }
      
      public function doGet($request, $response) {
        $response->addFormresult(new \xml\Node("result", "Welcome, we are open"));
      }
    }');
    self::$debugScriptlet= \lang\ClassLoader::defineClass('DebugScriptlet', 'scriptlet.HttpScriptlet', [], '{
      protected $title, $date;

      public function __construct($title, $date) {
        $this->title= $title;
        $this->date= $date;
      }
      
      public function doGet($request, $response) {
        $response->write("<h1>".$this->title." @ ".$this->date."</h1>");

        $response->write("<ul>");
        $response->write("  <li>ENV.DOMAIN = ".$request->getEnvValue("DOMAIN")."</li>");
        $response->write("  <li>ENV.ADMINS = ".$request->getEnvValue("ADMINS")."</li>");
        $response->write("</ul>");

        $config= \util\PropertyManager::getInstance()->getProperties("debug");
        $response->write("<h2>".nameof($config)."</h2>");
      }
    }');
    self::$exitScriptlet= \lang\ClassLoader::defineClass('ExitScriptlet', 'scriptlet.HttpScriptlet', [], '{
      public function doGet($request, $response) {
        \lang\Runtime::halt($request->getParam("code"), $request->getParam("message"));
      }
    }');
  }

  #[@beforeClass]
  public static function createLayout() {
    self::$layout= \lang\ClassLoader::defineClass('Layout', 'lang.Object', ['xp.scriptlet.WebLayout'], '{
      private $config;

      public function __construct($config) {
        $this->config= $config;
      }

      public function mappedApplications($profile= null) {
        return ["/" => (new \xp\scriptlet\WebApplication("test"))
          ->withScriptlet("WelcomeScriptlet")
          ->withArguments([$this->config])
        ];
      }

      public function staticResources($profile= null) {
        return null;
      }
    }');
  }

  #[@beforeClass]
  public static function setupPropertySource() {
    self::$propertySource= \util\PropertyManager::getInstance()->appendSource(newinstance('util.PropertySource', [], '{
      public function provides($name) { return "debug" === $name; }
      public function fetch($name) { return new Properties("/var/www/etc/dev/debug.ini"); }
    }'));
  }

  /**
   * Sets up property source
   *
   * @return void
   */
  #[@afterClass]
  public static function removePropertySource() {
    \util\PropertyManager::getInstance()->removeSource(self::$propertySource);
  }

  /**
   * Creates a new runner
   *
   */ 
  protected function newRunner($profile= null) {
    $r= new Runner('/var/www', $profile);
    
    // The debug application
    $r->mapApplication('/debug', (new \xp\scriptlet\WebApplication('debug'))
      ->withScriptlet(self::$debugScriptlet->getName())
      ->withConfig($r->expand('{WEBROOT}/etc/{PROFILE}'))
      ->withEnvironment(['DOMAIN' => 'example.com', 'ADMINS' => 'admin@example.com,root@localhost'])
      ->withArguments(['Debugging', 'today'])
    );

    // The error application
    $r->mapApplication('/error', (new \xp\scriptlet\WebApplication('error'))
      ->withScriptlet(self::$errorScriptlet->getName())
      ->withConfig($r->expand('{WEBROOT}/etc'))
      ->withDebug('dev' === $profile 
        ? \xp\scriptlet\WebDebug::XML | \xp\scriptlet\WebDebug::ERRORS | \xp\scriptlet\WebDebug::STACKTRACE | \xp\scriptlet\WebDebug::TRACE
        : \xp\scriptlet\WebDebug::NONE
      )
    );

    // The incomplete app (missing a scriptlet)
    $r->mapApplication('/incomplete', (new \xp\scriptlet\WebApplication('incomplete'))
      ->withScriptlet(null)
      ->withDebug(\xp\scriptlet\WebDebug::STACKTRACE)
    );

    // The XML application
    $r->mapApplication('/xml', (new \xp\scriptlet\WebApplication('xml'))
      ->withScriptlet(self::$xmlScriptlet->getName())
      ->withDebug('dev' === $profile 
        ? \xp\scriptlet\WebDebug::XML 
        : \xp\scriptlet\WebDebug::NONE
      )
    );
    
    // The exit scriptlet
    $r->mapApplication('/exit', (new \xp\scriptlet\WebApplication('exit'))
      ->withScriptlet(self::$exitScriptlet->getName())
    );

    // The welcome application
    $r->mapApplication('/', (new \xp\scriptlet\WebApplication('welcome'))
      ->withScriptlet(self::$welcomeScriptlet->getName())
      ->withConfig($r->expand('{WEBROOT}/etc'))
      ->withDebug('dev' === $profile 
        ? \xp\scriptlet\WebDebug::XML | \xp\scriptlet\WebDebug::ERRORS | \xp\scriptlet\WebDebug::STACKTRACE
        : \xp\scriptlet\WebDebug::NONE
      )
    );
    
    return $r;
  }

  /**
   * Invoke Runner::main() and return output
   *
   * @param  string $arg The web root
   * @param  string $arg The configuration directory
   * @param  string $arg The server profile
   * @param  string $arg The script URL
   * @return string
   */
  private function run($webroot, $config, $profile, $url) {
    ob_start();
    Runner::main([$webroot, $config, $profile, $url]);
    $content= ob_get_contents();
    ob_end_clean();
    return $content;
  }

  /**
   * Asserts a given buffer contains the given bytes       
   *
   * @param   string bytes
   * @param   string buffer
   * @throws  unittest.AssertionFailedError
   */
  protected function assertContained($bytes, $buffer, $message= 'Not contained') {
    strstr($buffer, $bytes) || $this->fail($message, $buffer, $bytes);
  }

  /**
   * Asserts a given buffer does not contain the given bytes       
   *
   * @param   string bytes
   * @param   string buffer
   * @throws  unittest.AssertionFailedError
   */
  protected function assertNotContained($bytes, $buffer, $message= 'Contained') {
    strstr($buffer, $bytes) && $this->fail($message, $buffer, $bytes);
  }

  /**
   * Runs a scriptlet
   *
   * @param   string profile
   * @param   string url
   * @param   [:string] params
   * @return  string content
   */
  protected function runWith($profile, $url, $params= []) {
    $_ENV= [];
    $_SERVER= [
      'SERVER_PROTOCOL' => 'HTTP/1.1',
      'REQUEST_METHOD'  => 'GET',
      'REQUEST_URI'     => $url,
      'HTTP_HOST'       => 'localhost',
    ];
    $_REQUEST= $params;

    ob_start();
    $this->newRunner($profile)->run($url);
    $content= ob_get_contents();
    ob_end_clean();
    return $content;
  }

  #[@test]
  public function expandServerProfile() {
    $this->assertEquals('etc/dev/', $this->newRunner('dev')->expand('etc/{PROFILE}/'));
  }

  #[@test]
  public function expandWebRoot() {
    $this->assertEquals('/var/www/htdocs', $this->newRunner('dev')->expand('{WEBROOT}/htdocs'));
  }

  #[@test]
  public function expandWebRootAndServerProfile() {
    $this->assertEquals('/var/www/etc/prod/', $this->newRunner('prod')->expand('{WEBROOT}/etc/{PROFILE}/'));
  }

  #[@test]
  public function expandUnknownVariable() {
    $this->assertEquals('{ROOT}', $this->newRunner('prod')->expand('{ROOT}'));
  }

  #[@test, @expect(class= 'lang.IllegalArgumentException', withMessage= 'Could not find app responsible for request to /')]
  public function noApplication() {
    with ($p= \util\Properties::fromString('')); {
      $p->writeSection('app');
      $p->writeString('app', 'map.service', '/service');
      $p->writeSection('app::service');

      $r= new Runner('/htdocs');
      $r->configure($p);
      $r->applicationAt('/');
    }
  }

  #[@test]
  public function welcomeApplication() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('welcome'))->withConfig('/var/www/etc')->withScriptlet('WelcomeScriptlet'),
      $this->newRunner()->applicationAt('/')
    );
  }

  #[@test]
  public function welcomeApplicationAtEmptyUrl() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('welcome'))->withConfig('/var/www/etc')->withScriptlet('WelcomeScriptlet'),
      $this->newRunner()->applicationAt('')
    );
  }

  #[@test]
  public function welcomeApplicationAtDoubleSlash() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('welcome'))->withConfig('/var/www/etc')->withScriptlet('WelcomeScriptlet'),
      $this->newRunner()->applicationAt('//')
    );
  }

  #[@test]
  public function errorApplication() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('error'))->withConfig('/var/www/etc')->withScriptlet('ErrorScriptlet'),
      $this->newRunner()->applicationAt('/error')
    );
  }

  #[@test]
  public function welcomeApplicationAtUrlEvenWithErrorInside() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('welcome'))->withConfig('/var/www/etc')->withScriptlet('WelcomeScriptlet'),
      $this->newRunner()->applicationAt('/url/with/error/inside')
    );
  }

  #[@test]
  public function welcomeApplicationAtUrlBeginningWithErrors() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('welcome'))->withConfig('/var/www/etc')->withScriptlet('WelcomeScriptlet'),
      $this->newRunner()->applicationAt('/errors')
    );
  }

  #[@test]
  public function errorApplicationAtErrorPath() {
    $this->assertEquals(
      (new \xp\scriptlet\WebApplication('error'))->withConfig('/var/www/etc')->withScriptlet('ErrorScriptlet'),
      $this->newRunner()->applicationAt('/error/happened')
    );
  }

  #[@test]  
  public function pageInProdMode() {
    $this->assertEquals(
      '<h1>Welcome, we are open</h1>', 
      $this->runWith('prod', '/')
    );
  }

  #[@test]
  public function pageWithWarningsInProdMode() {
    $warning= 'Warning! Do not read if you have work to do!';
    with (trigger_error($warning)); {
      preg_match(
        '#'.preg_quote($warning).'#', 
        $this->runWith('prod', '/'),
        $matches
      );
      \xp::gc(__FILE__);
    }
    $this->assertEquals([], $matches);
  }

  #[@test]
  public function pageWithWarningsInDevMode() {
    $warning= 'Warning! Do not read if you have work to do!';
    with (trigger_error($warning)); {
      preg_match(
        '#'.preg_quote($warning).'#', 
        $this->runWith('dev', '/'),
        $matches
      );
      \xp::gc(__FILE__);
    }
    $this->assertEquals($warning, $matches[0]);
  }

  #[@test]
  public function errorPageInProdMode() {
    $content= $this->runWith('prod', '/error');
    preg_match('#<xmp>(.+)</xmp>#', $content, $matches);
    preg_match('#ERROR ([0-9]+)#', $content, $error);

    $this->assertEquals('500', $error[1], 'error message');
    $this->assertEquals(
      'Request processing failed [doGet]: No shoes, no shorts, no service', 
      $matches[1]
    );
  }

  #[@test]
  public function errorPageLoggingInProdMode() {
    with ($cat= \util\log\Logger::getInstance()->getCategory('scriptlet')); {
      $appender= $cat->addAppender(new BufferedAppender());
      $this->runWith('prod', '/error');
      $buffer= $appender->getBuffer();
      $cat->removeAppender($appender);
      
      $this->assertNotContained(
        'Injected util.log.LogCategory',
        $buffer
      );
      $this->assertContained(
        'Exception scriptlet.ScriptletException (500:Request processing failed [doGet]: No shoes, no shorts, no service)', 
        $buffer
      );
    }
  }

  #[@test]
  public function errorPageLoggingInDevMode() {
    with ($cat= \util\log\Logger::getInstance()->getCategory('scriptlet')); {
      $appender= $cat->addAppender(new BufferedAppender());
      $this->runWith('dev', '/error');
      $buffer= $appender->getBuffer();
      $cat->removeAppender($appender);
      
      $this->assertContained(
        'Injected util.log.LogCategory',
        $buffer
      );
      $this->assertContained(
        'Exception scriptlet.ScriptletException (500:Request processing failed [doGet]: No shoes, no shorts, no service)', 
        $buffer
      );
    }
  }

  #[@test]
  public function errorPageInDevMode() {
    $content= $this->runWith('dev', '/error');
    preg_match('#ERROR ([0-9]+)#', $content, $error);
    preg_match('#<xmp>(.+)#', $content, $compound);
    preg_match('#Caused by (.+)#', $content, $cause);

    $this->assertEquals('500', $error[1], 'error message');
    $this->assertEquals(
      'Exception scriptlet.ScriptletException (500:Request processing failed [doGet]: No shoes, no shorts, no service)', 
      $compound[1],
      'exception compound message'
    );
    $this->assertEquals(
      'Exception lang.IllegalAccessException (No shoes, no shorts, no service)',
      $cause[1],
      'exception cause'
    );
  }

  #[@test]
  public function debugPage() {
    $content= $this->runWith('dev', '/debug');
    preg_match('#<h1>(.+)</h1>#', $content, $params);
    preg_match('#<h2>(.+)</h2>#', $content, $config);
    preg_match_all('#<li>(ENV\..+)</li>#U', $content, $env);

    $this->assertEquals('Debugging @ today', $params[1], 'params');
    $this->assertEquals('util.Properties', $config[1], 'config');
    $this->assertEquals(
      ['ENV.DOMAIN = example.com', 'ENV.ADMINS = admin@example.com,root@localhost'],
      $env[1],
      'environment'
    );
  }

  #[@test]
  public function incompleteApp() {
    $content= $this->runWith(null, '/incomplete');
    preg_match('#ERROR ([0-9]+)#', $content, $error);
    preg_match('#<xmp>(.+)#', $content, $compound);

    $this->assertEquals('412', $error[1], 'error message');
    $this->assertEquals(
      'Exception lang.IllegalStateException (No scriptlet in xp.scriptlet.WebApplication(incomplete)@{', 
      $compound[1],
      'exception compound message'
    );
  }

  #[@test]
  public function xmlScriptletAppInProdMode() {
    $content= $this->runWith('prod', '/xml');
    $this->assertEquals(
      '<?xml version="1.0" encoding="iso-8859-1"?><h1>Welcome, we are open</h1>',
      str_replace("\n", '', $content)
    );
  }

  #[@test]
  public function xmlScriptletAppInDevMode() {
    $content= $this->runWith('dev', '/xml');
    preg_match('#<h1>(.+)</h1>#', $content, $output);
    preg_match('#<result>(.+)</result>#', $content, $source);
    
    $this->assertEquals('Welcome, we are open', $output[1], 'output');
    $this->assertEquals('Welcome, we are open', $source[1], 'source');
    $this->assertContained('<formresult', $content, 'formresult');
    $this->assertContained('<formvalues', $content, 'formvalues');
    $this->assertContained('<formerrors', $content, 'formerrors');
  }

  #[@test]
  public function exitScriptletWithZeroExitCode() {
    $content= $this->runWith('dev', '/exit', ['code' => '0']);
    $this->assertEquals('', $content);
  }

  #[@test]
  public function exitScriptletWithZeroExitCodeAndMessage() {
    $content= $this->runWith('dev', '/exit', ['code' => '0', 'message' => 'Sorry']);
    $this->assertEquals('Sorry', $content);
  }

  #[@test]
  public function exitScriptletWithNonZeroExitCode() {
    $content= $this->runWith('dev', '/exit', ['code' => '1']);
    preg_match('#ERROR ([0-9]+)#', $content, $error);
    preg_match('#<xmp>(.+)</xmp>#', $content, $compound);

    $this->assertEquals('500', $error[1], 'error message');
    $this->assertEquals([], $compound, 'exception compound message');
  }

  #[@test]
  public function exitScriptletWithNonZeroExitCodeAndMessage() {
    $content= $this->runWith('dev', '/exit', ['code' => '1', 'message' => 'Sorry']);
    preg_match('#ERROR ([0-9]+)#', $content, $error);
    preg_match('#<xmp>(.+)</xmp>#', $content, $compound);

    $this->assertEquals('500', $error[1], 'error message');
    $this->assertEquals('Sorry', $compound[1], 'exception compound message');
  }

  #[@test]
  public function main_with_directory() {
    $temp= System::tempDir();

    // Create web.ini in system's temp dir
    $ini= new \io\File($temp, 'web.ini');
    $ini->open(\io\File::WRITE);
    $ini->write(
      "[app]\n".
      "mappings=\"/:welcome\"\n".
      "[app::welcome]\n".
      "class=undefined\n".
      "[app::welcome@dev]\n".
      "class=\"".self::$welcomeScriptlet->getName()."\"\n"
    );
    $ini->close();

    try {
      $this->assertEquals('<h1>Welcome, we are open</h1>', $this->run('.', $temp, 'dev', '/'));
    } finally {
      $ini->unlink();
    }
  }

  #[@test]
  public function main_with_scriptlet_class() {
    $this->assertEquals(
      '<h1>Welcome, we are open</h1>',
      $this->run('.', self::$welcomeScriptlet->getName(), 'dev', '/')
    );
  }

  #[@test]
  public function main_with_layout_class() {
    $this->assertEquals(
      '<h1>Welcome, we are open & xp.scriptlet.Config[]</h1>',
      $this->run('.', self::$layout->getName(), 'dev', '/')
    );
  }

  #[@test]
  public function main_with_layout_class_and_config_resources() {
    $this->assertEquals(
      '<h1>Welcome, we are open & xp.scriptlet.Config[util.ResourcePropertySource<res://config>]</h1>',
      $this->run('.', self::$layout->getName().PATH_SEPARATOR.'config', 'dev', '/')
    );
  }

  #[@test]
  public function main_with_layout_class_and_config_dir() {
    $temp= realpath('.');
    $this->assertEquals(
      '<h1>Welcome, we are open & xp.scriptlet.Config[util.FilesystemPropertySource<'.$temp.'>]</h1>',
      $this->run('.', self::$layout->getName().PATH_SEPARATOR.$temp, 'dev', '/')
    );
  }

  #[@test]
  public function main_with_layout_class_and_config_tilde() {
    $temp= realpath('.');
    $this->assertEquals(
      '<h1>Welcome, we are open & xp.scriptlet.Config[util.FilesystemPropertySource<'.$temp.'>]</h1>',
      $this->run('.', self::$layout->getName().PATH_SEPARATOR.'~', 'dev', '/')
    );
  }

  #[@test]
  public function main_with_layout_class_bc_colon_prefix() {
    $this->assertEquals(
      '<h1>Welcome, we are open & xp.scriptlet.Config[]</h1>',
      $this->run('.', ':'.self::$layout->getName(), 'dev', '/')
    );
  }

  #[@test]
  public function properties() {
    $r= new Runner('/var/www', 'dev');
    $r->mapApplication('/debug', (new \xp\scriptlet\WebApplication('debug'))
      ->withScriptlet(self::$debugScriptlet->getName())
      ->withConfig('res://user')
      ->withArguments(['Debugging', 'today'])
    );

    ob_start();
    $_REQUEST= [];
    $content= $r->run('/debug');
    $_REQUEST= [];
    $content= ob_get_contents();
    ob_end_clean();

    preg_match('#<h2>(.+)</h2>#', $content, $config);
    $this->assertEquals('util.CompositeProperties', $config[1], 'config');
  }
}
