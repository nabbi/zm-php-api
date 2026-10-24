<?php
// Tests which methods of each controller (api/src/Controller) the router may
// call as actions: Controller::actionMethod().
//
// What must hold:
//   - each controller answers exactly the actions listed here; a public helper
//     added later is not silently reachable as a URL
//   - what the base Controller declares (beforeFilter, actionMethod), also when
//     a controller overrides it, is never an action
//   - matching is case-insensitive, and an unknown action is MissingActionException
//
// Run: tests/run.sh test_api_controller_actions.php

require_once __DIR__.'/support/api.php';

const CRUD = array('index', 'view', 'add', 'edit', 'delete');
$expected = array(
  'CameraModelsController' => CRUD,
  'ConfigsController' => array('index', 'view', 'viewByName', 'categories', 'edit', 'delete'),
  'ControlsController' => array('index', 'view'),
  'EncoderTemplatesController' => CRUD,
  'EventDataController' => CRUD,
  'EventsController' => array_merge(CRUD, array('archive', 'search', 'consoleEvents')),
  'FramesController' => CRUD,
  'GroupsController' => array_merge(CRUD, array('associations')),
  'HostController' => array('getVersion', 'getTimeZone', 'getLoad', 'login', 'logout', 'daemonCheck', 'daemonControl', 'getDiskPercent'),
  'LogsController' => CRUD,
  'ManufacturersController' => CRUD,
  'MonitorsController' => array_merge(CRUD, array('sourceTypes', 'alarm', 'daemonStatus', 'daemonControl')),
  'NotificationsController' => CRUD,
  'PagesController' => array('display'),
  'RolesController' => CRUD,
  'ServerStatsController' => CRUD,
  'ServersController' => CRUD,
  'SnapshotsController' => array_merge(CRUD, array('associations')),
  'StatesController' => array_merge(CRUD, array('change')),
  'StorageController' => CRUD,
  'TagsController' => array_merge(CRUD, array('associations')),
  'UserPreferenceController' => CRUD,
  'UsersController' => CRUD,
  'ZonePresetsController' => CRUD,
  'ZonesController' => array_merge(CRUD, array('forMonitor')),
);

function routable($class) {
  $rc = new ReflectionClass($class);
  $controller = $rc->newInstanceWithoutConstructor();
  $out = array();
  foreach ($rc->getMethods() as $m) {
    try {
      $out[] = $controller->actionMethod($m->name);
    } catch (\ZM\Api\Http\RoutingException $e) {
    }
  }
  sort($out);
  return $out;
}

$found = array();
foreach (glob(__DIR__.'/../../web/api/src/Controller/*Controller.php') as $file) {
  $class = 'ZM\\Api\\Controller\\'.basename($file, '.php');
  if ((new ReflectionClass($class))->isAbstract()) continue;
  $found[] = basename($file, '.php');
}
sort($found);
$listed = array_keys($expected);
sort($listed);
check('every controller is listed here', $found, $listed);

foreach ($expected as $short => $actions) {
  $class = 'ZM\\Api\\Controller\\'.$short;
  sort($actions);
  check("$short: actions", routable($class), $actions);
  $c = (new ReflectionClass($class))->newInstanceWithoutConstructor();
  foreach (array('beforeFilter', 'actionMethod', '__construct') as $not) {
    check("$short: $not is not an action", thrown(function () use ($c, $not) { $c->actionMethod($not); }) !== 'no exception', true);
  }
}

$events = (new ReflectionClass('ZM\\Api\\Controller\\EventsController'))->newInstanceWithoutConstructor();
check('case-insensitive', $events->actionMethod('CONSOLEEVENTS'), 'consoleEvents');
check('unknown action', thrown(function () use ($events) { $events->actionMethod('nosuch'); }),
  'ZM\Api\Http\RoutingException: Action EventsController::nosuch() could not be found.');
check('beforeFilter, overridden', thrown(function () use ($events) { $events->actionMethod('beforefilter'); }),
  'ZM\Api\Http\RoutingException: Private Action EventsController::beforeFilter() is not directly accessible.');

done();
