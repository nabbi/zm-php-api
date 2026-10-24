<?php
//
// ZoneMinder API: which controller action answers a request.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Http;

use ZM\Api\Compat;

/**
 * Maps a request to a controller, an action and its arguments.
 *
 * The URL forms are the API's published ones and must not change:
 *
 *   GET    <resource>             index      (also PUT/DELETE: see below)
 *   POST   <resource>             add
 *   GET    <resource>/<id>        view       <id> is numeric (or a UUID; for configs, a name)
 *   PUT    <resource>/<id>        edit
 *   POST   <resource>/<id>        edit
 *   DELETE <resource>/<id>        delete
 *   any    <controller>/<action>[/<arg>...]
 *
 * The first block applies only to the names in RESOURCES (and ALIASES). Every controller also
 * answers the last form, which is the only way to reach the non-CRUD actions
 * (monitors/alarm/..., events/consoleEvents/...) and the controllers that are not
 * in RESOURCES (storage, camera_models, zone_presets). A request to a resource
 * with an unexpected method (PUT monitors) falls through to the index action.
 * In <arg>s, `Name:value` is a named parameter (Router::named()).
 */
class Router {
  /**
   * Names with the <resource>[/<id>] forms. storage was missing from the CakePHP
   * API's list, so storage/<id> was a 404.
   */
  const RESOURCES = array(
    'configs', 'controls', 'encoder_templates', 'events', 'event_data', 'frames', 'groups',
    'host', 'logs', 'manufacturers', 'models', 'cameramodels', 'monitors', 'servers',
    'server_stats', 'snapshots', 'states', 'storage', 'tags', 'roles', 'users', 'user_preference',
    'zonepresets', 'zones', 'notifications',
  );

  /**
   * Resource names that are spelled differently from their controller's URL name.
   * The CakePHP API listed them without a controller, so they answered 404.
   */
  const ALIASES = array('models' => 'camera_models', 'cameramodels' => 'camera_models', 'zonepresets' => 'zone_presets');

  const ID = '/^(?:[0-9]+|[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12})$/';
  /** Options are keyed by name, so configs/<id> also takes one: configs/ZM_LANG_DEFAULT */
  const CONFIG_NAME = '/^ZM_[A-Z0-9_]+$/';

  /**
   * @return Route
   * @throws RoutingException when no controller has that name
   */
  public static function resolve(Request $request) {
    $s = $request->segments;
    if (!count($s)) {
      return self::route($request, 'pages', 'display', array('home'));
    }
    $name = $s[0];
    if (in_array($name, self::RESOURCES, true)) {
      if (count($s) == 1) {
        if ($request->is('GET')) return self::route($request, $name, 'index', array());
        if ($request->is('POST')) return self::route($request, $name, 'add', array());
      } else if (count($s) == 2 and (preg_match(self::ID, $s[1])
          or ($name == 'configs' and preg_match(self::CONFIG_NAME, $s[1])))) {
        $actions = array('GET' => 'view', 'PUT' => 'edit', 'POST' => 'edit', 'DELETE' => 'delete');
        if (isset($actions[$request->method])) {
          return self::route($request, $name, $actions[$request->method], array($s[1]));
        }
      }
    }
    if ($name == 'pages') {
      return self::route($request, 'pages', 'display', array_slice($s, 1));
    }
    if (count($s) == 1) {
      return self::route($request, $name, 'index', array());
    }
    return self::route($request, $name, $s[1], array_slice($s, 2));
  }

  private static function route(Request $request, $name, $action, array $args) {
    if (isset(self::ALIASES[$name])) $name = self::ALIASES[$name];
    $class = self::controllerClass($name);
    // 1.38: a controller for a table that release doesn't have (REQUIRES_TABLE)
    // counts as missing, so it answers the 404 1.38's CakePHP API gave for it.
    if ($class !== null and defined($class.'::REQUIRES_TABLE') and !Compat::hasTable($class::REQUIRES_TABLE)) {
      $class = null;
    }
    if ($class === null) {
      throw new RoutingException('MissingControllerException',
        'Controller class '.self::camelize($name).'Controller could not be found.');
    }
    list($pass, $named) = self::splitArgs($args);
    return new Route($name, $class, $action, $pass, $named);
  }

  /** The controller class for a URL name (monitors, user_preference), or null. */
  public static function controllerClass($name) {
    $class = 'ZM\\Api\\Controller\\'.self::camelize($name).'Controller';
    // Class names are matched case-sensitively, as the controllers' file names are.
    $file = __DIR__.'/../Controller/'.self::camelize($name).'Controller.php';
    if (!is_file($file)) return null;
    // Only concrete controllers: read, crud and controller name abstract bases.
    $reflection = new \ReflectionClass($class);
    return ($reflection->isInstantiable() and $reflection->isSubclassOf(\ZM\Api\Controller\Controller::class)) ? $class : null;
  }

  /** user_preference -> UserPreference */
  public static function camelize($name) {
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
  }

  /**
   * Positional and named arguments, from already decoded path segments
   * (Request::parsePath()). `Name:value` segments are named (also
   * `Name[key]:value`, which makes an array).
   */
  public static function splitArgs(array $args) {
    $pass = array();
    $named = array();
    foreach ($args as $arg) {
      if ($arg === '') continue;
      if (strpos($arg, ':') === false) {
        $pass[] = $arg;
        continue;
      }
      list($key, $value) = explode(':', $arg, 2);
      if (preg_match_all('/\[([A-Za-z0-9_-]+)?\]/', $key, $m, PREG_SET_ORDER)) {
        $key = substr($key, 0, strpos($key, '['));
        $nest = $value;
        foreach (array_reverse($m) as $sub) {
          $nest = (isset($sub[1]) and $sub[1] !== '') ? array($sub[1] => $nest) : array($nest);
        }
        $named = array_merge_recursive($named, array($key => $nest));
      } else {
        $named = array_merge_recursive($named, array($key => $value));
      }
    }
    return array($pass, $named);
  }
}
