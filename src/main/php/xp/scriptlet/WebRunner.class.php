<?php namespace xp\scriptlet;

use io\Path;
use lang\XPClass;
use lang\ClassLoader;
use lang\IllegalArgumentException;
use util\cmd\Console;
use util\PropertyManager;
use util\log\Logger;
use util\log\context\EnvironmentAware;
use rdbms\ConnectionManager;
use scriptlet\HttpScriptlet;
new import('lang.ResourceProvider');

/**
 * Web server
 * ==========
 *
 * - Run a webserver [locally](http://localhost:8080/) using the "dev"
 *   profile. If a file named *etc/web.ini* exists, uses configuration
 *   from there, otherwise serves files from the document root *./static*.
 *   ```sh
 *   $ xp web
 *   ```
 * - Only serve static content from given directory
 *   ```sh
 *   $ xp web -r doc_root -
 *   ```
 * - Run a single scriptlet
 *   ```sh
 *   $ xp web com.example.scriptlet.Service
 *   ```
 * - Run a web layout
 *   ```sh
 *   $ xp web com.example.scriptlet.Layout
 *   ```
 * - Run a [configured web layout](xp help web/config)
 *   ```sh
 *   $ xp web etc/web.ini
 *   ```
 * - On Un*x systems, start multiprocess server with 50 children:
 *   ```sh
 *   $ xp web -m prefork,50
 *   ```
 * - Use [event-based I/O](http://pecl.php.net/package/event):
 *   ```sh
 *   $ xp web -m event
 *   ```
 * The address the server listens to can be supplied via *-a {host}:{port}*.
 * The profile can be changed via *-p {profile}* (and can be anything!). One
 * or more configuration sources may be passed via *-c {file.ini|dir}*.
 */
class WebRunner {
  private static $modes= [
    'serve'   => 'peer.server.Server',
    'prefork' => 'peer.server.PreforkingServer',
    'fork'    => 'peer.server.ForkingServer',
    'event'   => 'peer.server.EventServer'
  ];

  /**
   * Creates a server instance
   *
   * @param  string $mode
   * @param  string $address
   * @param  string[] $arguments
   * @return peer.server.Server
   * @throws lang.IllegalArgumentException
   */
  private static function server($mode, $address, $arguments) {
    if (!($class= @self::$modes[$mode])) {
      throw new IllegalArgumentException(sprintf(
        'Unkown server mode "%s", supported: [%s]',
        $mode,
        implode(', ', array_keys(self::$modes))
      ));
    }

    sscanf($address, '%[^:]:%d', $host, $port);
    return XPClass::forName($class)->getConstructor()->newInstance(array_merge(
      [$host, $port],
      $arguments
    ));
  }

  /**
   * Entry point method
   *
   * @param  string[] $args
   * @return int
   */
  public static function main(array $args) {
    $webroot= new Path(getcwd());
    $docroot= new Path($webroot, 'static');
    $address= 'localhost:8080';
    $profile= 'dev';
    $mode= 'serve';
    $arguments= [];

    $expand= function($in) use(&$webroot, &$docroot, &$profile) {
      return is_string($in) ? strtr($in, [
        '{WEBROOT}'       => $webroot,
        '{PROFILE}'       => $profile,
        '{DOCUMENT_ROOT}' => $docroot
      ]) : $in;
    };

    $config= new Config([], $expand);
    $layout= new BasedOnWebroot($webroot, $config);

    for ($i= 0; $i < sizeof($args); $i++) {
       if ('-r' === $args[$i]) {
        $docroot= $webroot->resolve($args[++$i]);
      } else if ('-a' === $args[$i]) {
        $address= $args[++$i];
      } else if ('-p' === $args[$i]) {
        $profile= $args[++$i];
      } else if ('-c' === $args[$i]) {
        $config->append($args[++$i]);
      } else if ('-m' === $args[$i]) {
        $arguments= explode(',', $args[++$i]);
        $mode= array_shift($arguments);
      } else {
        $layout= (new Source($args[$i], $config))->layout();
        break;
      }
    }

    Console::writeLine('--> Startup ', $mode, '(', $address, ' & ', $arguments ?: '[]', ')');
    Console::writeLine('--> Layout ', $layout);
    $server= self::server($mode, $address, $arguments);
    Console::writeLine('--> ', $server);

    with ($protocol= $server->setProtocol(new HttpProtocol())); {
      $pm= PropertyManager::getInstance();

      $resources= $layout->staticResources($profile);
      if (null === $resources) {
        $protocol->setUrlHandler('default', '#^/#', new FileHandler(
          $docroot,
          $notFound= function() { return false; }
        ));
      } else {
        foreach ($resources as $pattern => $location) {
          $protocol->setUrlHandler('default', '#'.strtr($pattern, ['#' => '\\#']).'#', new FileHandler($expand($location)));
        }
      }

      foreach ($layout->mappedApplications($profile) as $url => $application) {
        $protocol->setUrlHandler('default', '/' == $url ? '##' : '#^('.preg_quote($url, '#').')($|/.+)#', new ScriptletHandler(
          $application->scriptlet(),
          array_map($expand, $application->arguments()),
          array_map($expand, array_merge($application->environment(), ['DOCUMENT_ROOT' => $docroot])),
          $application->filters()
        ));
        foreach ($application->config()->sources() as $source) {
          $pm->appendSource($source);
        }
      }

      $l= Logger::getInstance();
      $pm->hasProperties('log') && $l->configure($pm->getProperties('log'));
      $cm= ConnectionManager::getInstance();
      $pm->hasProperties('database') && $cm->configure($pm->getProperties('database'));
      Console::writeLine('--> ', $protocol);
    }
    $server->init();

    Console::writeLine('==> Server started');
    $server->service();
    $server->shutdown();
    return 0;
  }
}