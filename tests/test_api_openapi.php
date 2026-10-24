<?php
// Tests the API reference, docs/openapi/openapi.json, against the code.
//
// What must hold:
//   - it is OpenAPI 3.1 JSON, for this release (VERSION)
//   - every action the router can reach (Controller::actionMethod()) has at least
//     one operation, and every operation's x-zm-action is such an action: adding
//     or removing an action means documenting it
//   - each operation has a unique operationId, a summary and its responses
//   - each documented path, with sample values for its parameters, is routed by
//     Router::resolve() to the action the operation names
//
// What the answers look like (statuses, bodies) is checked elsewhere: the
// end-to-end contract suite compares them with the document (docs/testing.md).
//
// Run: tests/run.sh test_api_openapi.php

require_once __DIR__.'/support/api.php';

use ZM\Api\Http\Request;
use ZM\Api\Http\Router;
use ZM\Api\Http\RoutingException;

$file = __DIR__.'/../../docs/openapi/openapi.json';
$spec = json_decode((string)@file_get_contents($file), true);
check('openapi.json parses', is_array($spec), true);
if (!is_array($spec)) done();
check('OpenAPI 3.1', substr($spec['openapi'], 0, 4), '3.1.');
$version = @file_get_contents(__DIR__.'/../../VERSION');
check('info.version is VERSION', $spec['info']['version'], trim((string)$version));

// The tables of 1.38-only gaps (REQUIRES_TABLE) exist, so their controllers route.
$tables = new ReflectionProperty('ZM\\Api\\Compat', 'tables');
$tables->setAccessible(true);
$tables->setValue(null, array('EncoderTemplates' => true, 'Notifications' => true));

// Every routable action, as Short::action (Monitors::index).
$actions = array();
foreach (glob(__DIR__.'/../../web/api/src/Controller/*Controller.php') as $path) {
  $class = 'ZM\\Api\\Controller\\'.basename($path, '.php');
  $rc = new ReflectionClass($class);
  if (!$rc->isInstantiable() or !$rc->isSubclassOf('ZM\\Api\\Controller\\Controller')) continue;
  $controller = $rc->newInstanceWithoutConstructor();
  foreach ($rc->getMethods() as $m) {
    try {
      $actions[substr($rc->getShortName(), 0, -10).'::'.$controller->actionMethod($m->name)] = true;
    } catch (RoutingException $e) {
    }
  }
}

// Sample values for the path parameters, so the router sees a real URL.
const SAMPLES = array(
  'id' => '1', 'eventId' => '1', 'frameId' => '1', 'mid' => '1', 'page' => 'home',
  'filters' => 'Id:1', 'paging' => 'page:1', 'name' => 'ZM_LANG_DEFAULT', 'category' => 'web',
  'interval' => '1 hour', 'daemon' => 'zmc', 'args' => '-m 1', 'command' => 'status',
);

$documented = array();
$ids = array();
$problems = array();
foreach ($spec['paths'] as $path => $operations) {
  foreach ($operations as $method => $op) {
    $where = strtoupper($method)." $path";
    $action = isset($op['x-zm-action']) ? $op['x-zm-action'] : null;
    if (!$action) { $problems[] = "$where: no x-zm-action"; continue; }
    $documented[$action] = true;
    $id = isset($op['operationId']) ? $op['operationId'] : '';
    if ($id === '' or isset($ids[$id])) $problems[] = "$where: operationId '$id' missing or not unique";
    $ids[$id] = true;
    if (empty($op['summary'])) $problems[] = "$where: no summary";
    if (empty($op['responses'])) $problems[] = "$where: no responses";

    $url = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$problems, $where) {
      if (!isset(SAMPLES[$m[1]])) { $problems[] = "$where: no sample value for {{$m[1]}}"; return '1'; }
      return SAMPLES[$m[1]];
    }, $path);
    $_SERVER = array('REQUEST_METHOD' => strtoupper($method), 'REQUEST_URI' => '/zm/api'.$url);
    $_GET = $_POST = array();
    try {
      $route = Router::resolve(Request::fromGlobals());
      $controller = (new ReflectionClass($route->class))->newInstanceWithoutConstructor();
      $got = substr((new ReflectionClass($route->class))->getShortName(), 0, -10).'::'.$controller->actionMethod($route->action);
    } catch (RoutingException $e) {
      $got = $e->clientName();
    }
    if ($got !== $action) $problems[] = "$where routes to $got, documented as $action";
  }
}

foreach (array_keys($actions) as $a) {
  if (!isset($documented[$a])) $problems[] = "action $a is not documented";
}
foreach (array_keys($documented) as $a) {
  if (!isset($actions[$a])) $problems[] = "documented $a is not an action";
}

check('operations', count($ids) > 0, true);
check('every action documented, every operation an action, each path routed to it', $problems, array());
done();
