<?php
//
// ZoneMinder API: base class of the controllers.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Controller;

use ZM\Api\Auth;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Request;
use ZM\Api\Http\Response;
use ZM\Api\Http\Route;
use ZM\Api\Http\RoutingException;
use ZM\Api\Http\UnauthorizedException;

/**
 * A controller answers the requests for one URL name (MonitorsController for
 * /api/monitors...). Each public method is an action, reached as
 * <name>/<action>[/<arg>...]; positional arguments are its parameters.
 *
 * An action returns the body as an array of top-level keys, e.g.
 *
 *   return array('monitor' => $row);         // {"monitor": {...}}
 *
 * or a Response, and reports failure by throwing an HttpException
 * (NotFoundException('Invalid monitor'), UnauthorizedException(...), ...).
 *
 * beforeFilter() runs first and authenticates. A controller whose every action
 * needs a permission overrides it and checks after calling parent::beforeFilter().
 */
abstract class Controller {
  /** @var Request */
  protected $request;
  /** @var Route */
  protected $route;
  /** @var Response headers set here (CORS) are added to the answer */
  public $response;

  public function __construct(Request $request, Route $route) {
    $this->request = $request;
    $this->route = $route;
    $this->response = new Response();
  }

  public function beforeFilter() {
    Auth::authenticate($this->request, $this->route->action);
    Auth::cors($this->response);
  }

  /**
   * The method answering $action (matched case-insensitively).
   * @throws RoutingException when there is none, or it is not an action
   */
  public function actionMethod($action) {
    $short = (new \ReflectionClass($this))->getShortName();
    if (!method_exists($this, $action)) {
      throw new RoutingException('MissingActionException', "Action $short::$action() could not be found.");
    }
    $method = new \ReflectionMethod($this, $action);
    // Not actions: what this class declares (beforeFilter(), actionMethod()),
    // also where a controller overrides it.
    if ($method->name[0] === '_' or !$method->isPublic() or $method->isStatic() or $method->isConstructor()
      or method_exists(__CLASS__, $method->name)) {
      throw new RoutingException('PrivateActionException',
        "Private Action $short::{$method->name}() is not directly accessible.");
    }
    return $method->name;
  }

  // ----------------------------------------------------------------- requests

  /** A named argument (Name:value), or $default. */
  protected function named($name, $default = null) {
    return isset($this->route->named[$name]) ? $this->route->named[$name] : $default;
  }

  /** A field from the body, sent as Model[Field] or bare Field, or null. */
  protected function requestField($model, $field) {
    $data = $this->request->data;
    if (isset($data[$model]) and is_array($data[$model])) $data = $data[$model];
    return isset($data[$field]) ? $data[$field] : null;
  }

  /** The body's fields for $model: the Model[...] group if sent, else the bare fields. */
  protected function requestRecord($model) {
    $data = $this->request->data;
    if (isset($data[$model]) and is_array($data[$model])) return $data[$model];
    return is_array($data) ? $data : array();
  }

  /**
   * The ids of the records a request associates with $model, sent either as
   * Model[$idsField] (comma separated or a list) or as $assoc data ($assoc[$assoc][]
   * or $assoc[]); null if neither.
   */
  protected function requestAssociatedIds($model, $idsField, $assoc) {
    $data = $this->request->data;
    if (isset($data[$model][$idsField])) {
      $ids = $data[$model][$idsField];
    } else if (isset($data[$assoc])) {
      $ids = (isset($data[$assoc][$assoc]) and is_array($data[$assoc][$assoc])) ? $data[$assoc][$assoc] : $data[$assoc];
    } else {
      return null;
    }
    if (!is_array($ids)) $ids = explode(',', $ids);
    return array_map(function ($id) {
      return intval(is_array($id) ? (isset($id['Id']) ? $id['Id'] : 0) : $id);
    }, $ids);
  }

  /** @throws \ZM\Api\Http\MethodNotAllowedException unless the request uses one of $methods */
  protected function allowMethod(...$methods) {
    foreach ($methods as $m) {
      if ($this->request->is($m)) return;
    }
    throw new \ZM\Api\Http\MethodNotAllowedException('Method Not Allowed');
  }

  // -------------------------------------------------------------- permissions

  /** The permission areas a user, or their role, holds a None/View/Edit/Create level for. */
  const PERMISSION_AREAS = array('Stream', 'Events', 'Control', 'Monitors', 'Groups', 'Devices', 'Snapshots', 'System');

  /**
   * @param string $area a permission of the user: System, Monitors, Events, Groups, ...
   * @param string $level View (View or better) or Edit (Edit, or Create for Monitors)
   * @throws UnauthorizedException unless the user, or their role, has it
   */
  protected function requirePermission($area, $level = 'View', $message = 'Insufficient Privileges') {
    global $user;
    if (!$user) return; // authentication is off: everyone may do everything
    $ok = false;
    foreach (self::permissionLevels($user, $area) as $have) {
      if (($level == 'View') ? ($have and $have != 'None') : ($have == 'Edit' || $have == 'Create')) $ok = true;
    }
    if (!$ok) throw new UnauthorizedException($message);
  }

  /**
   * The levels $user holds for $area: their own field and their role's
   * (User_Roles), the way ZM\User::canView()/canEdit() read them on master and
   * release-1.38 (web/includes/User.php, refs GHSA-ff93-w6fm-vqxr) and the web UI
   * grants access. Computed here rather than by calling those methods: on a tree
   * without them (1.38.6 and older) $user->canView() falls through to
   * ZM_Object::__call(), whose answer is truthy for everyone. An area off the
   * list has no levels, so a check on it fails closed.
   */
  private static function permissionLevels(\ZM\User $user, $area) {
    if (!in_array($area, self::PERMISSION_AREAS, true)) return array();
    $levels = array($user->$area());
    $role = $user->Role();
    if ($role) $levels[] = $role->$area();
    return $levels;
  }

  /**
   * A find() condition limiting $field to the monitors the user may view: empty
   * when the user is not restricted, matching nothing when no monitor is viewable.
   */
  protected function viewableMonitorCondition($field) {
    global $user;
    if (!$user or self::viewsEveryMonitor($user) or !$user->unviewableMonitorIds()) return array();
    $ids = $user->viewableMonitorIds();
    return array($field => count($ids) ? $ids : array(0));
  }

  /**
   * Whether ZoneMinder's visibleMonitor() lets $user (the request's user) view
   * every monitor, answered without listing the monitors: so when the user's
   * Monitors permission is not None and nothing narrows it, i.e. no monitor
   * permission other than Inherit, no group permissions and no role. False
   * means "maybe not": the caller then asks visibleMonitor() for each monitor.
   * The permission rows are the ones visibleMonitor() reads (and keeps).
   */
  private static function viewsEveryMonitor(\ZM\User $user) {
    global $monitor_permissions, $group_permissions;
    if ($user->Monitors() == 'None' or $user->RoleId()) return false;
    if ($monitor_permissions === null) {
      // As visibleMonitor() keeps them: by MonitorId.
      $monitor_permissions = array();
      foreach ($user->Monitor_Permissions() as $permission) $monitor_permissions[$permission->MonitorId()] = $permission;
    }
    foreach ($monitor_permissions as $permission) {
      if ($permission->Permission() != 'Inherit') return false;
    }
    if ($group_permissions === null) $group_permissions = $user->Group_Permissions();
    return !count($group_permissions);
  }

  /** @throws NotFoundException|UnauthorizedException unless monitor $id exists and may be viewed */
  protected function requireMonitorView($id) {
    $monitor = $this->monitor($id);
    if (!$monitor->canView()) throw new UnauthorizedException('Insufficient Privileges');
    return $monitor;
  }

  /** @throws NotFoundException|UnauthorizedException unless monitor $id exists and may be edited */
  protected function requireMonitorEdit($id) {
    $monitor = $this->monitor($id);
    if (!$monitor->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    return $monitor;
  }

  private function monitor($id) {
    require_once __DIR__.'/../../../includes/Monitor.php';
    $monitor = new \ZM\Monitor($id);
    if (!$monitor->Id()) throw new NotFoundException('Invalid monitor');
    return $monitor;
  }

  /** @throws NotFoundException|UnauthorizedException unless every event exists and may be viewed */
  protected function requireEventsView(array $eventIds) {
    require_once __DIR__.'/../../../includes/Event.php';
    foreach (array_unique($eventIds) as $eventId) {
      $event = new \ZM\Event($eventId);
      if (!$event->Id()) throw new NotFoundException('Invalid event');
      if (!$event->canView()) throw new UnauthorizedException('Insufficient Privileges');
    }
  }

  /**
   * Before replacing a record's event links ($joinTable rows whose $foreignKey is
   * $id) with $newIds: the user must view every event linked and every event
   * unlinked, so an edit can't drop links to events on monitors the user can't
   * see. An unlinked event that no longer exists needs nothing.
   * @throws NotFoundException|UnauthorizedException
   */
  protected function requireEventLinkChanges($joinTable, $foreignKey, $id, array $newIds) {
    $old = array_map('intval', array_column(\ZM\Api\Db\Db::rows('SELECT `EventId` FROM '
      .\ZM\Api\Db\Db::quoteName($joinTable).' WHERE '.\ZM\Api\Db\Db::quoteName($foreignKey).' = ?', array($id)), 'EventId'));
    $new = array_map('intval', $newIds);
    $added = array_diff($new, $old);
    if (count($added)) $this->requireEventsView($added);
    require_once __DIR__.'/../../../includes/Event.php';
    foreach (array_unique(array_diff($old, $new)) as $eventId) {
      $event = new \ZM\Event($eventId);
      if ($event->Id() and !$event->canView()) throw new UnauthorizedException('Insufficient Privileges');
    }
  }

  /** $row with the events on monitors the user may not view dropped from its $key list. */
  protected function filterContainedEvents(array $row, $key = 'Event') {
    $condition = $this->viewableMonitorCondition('MonitorId');
    if (!count($condition) or !isset($row[$key])) return $row;
    $allowed = $condition['MonitorId'];
    $row[$key] = array_values(array_filter($row[$key], function ($event) use ($allowed) {
      return isset($event['MonitorId']) and in_array($event['MonitorId'], $allowed);
    }));
    return $row;
  }
}
