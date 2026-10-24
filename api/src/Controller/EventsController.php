<?php
//
// ZoneMinder API: /events - recorded events.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Controller;

use ZM\Api\Db\Db;
use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\Event;
use ZM\Api\Paginator;

/**
 * /events: recorded events. Needs Events=View, and an event is visible only to
 * users who may view its monitor; changes need Events=Edit and edit rights on the
 * monitor (archiving needs only view rights, see archive()).
 */
class EventsController extends Controller {
  /** Query parameters that are never event filters (besides paging): credentials, a cache-buster. */
  const QUERY_NOT_FILTERS = array('auth', 'user', 'pass', 'username', 'password', 'stateful', 'token', '_');

  /**
   * The pagination block of an unpaged index. Its values describe nothing; they
   * are what clients have always received there, so they stay.
   */
  const UNPAGED = array('prevPage' => false, 'nextPage' => true, 'paramType' => 'named', 'pageCount' => 1,
    'options' => array(), 'page' => 1);

  /** @var Event */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Event();
    require_once __DIR__.'/../../../includes/Event.php';
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Events', 'View');
  }

  /**
   * The events the user may view: {"events": [...], "pagination": {...}}.
   *
   * Filters are named arguments (events/index/MonitorId:3/StartDateTime >=:...),
   * or, without any, query parameters (events?MonitorId=3). Besides the columns:
   *  - DateTime <op>:<time> matches events running at that time: with >=/>, events
   *    that had not ended by then; with </<=, events that had started by then.
   *  - GroupId:<id>, the events of a group's monitors (each event then lists Group).
   *  - TagId:<ids>, Tag:<name> or Tags:<name>, the events carrying those tags.
   *  - StartTime/EndTime are the old names of StartDateTime/EndDateTime.
   *
   * Paged with ?limit=&page= (20 a page by default, at most 100); without either,
   * all matching events. ?sort=&direction= order the list.
   */
  public function index() {
    list($conditions, $tagField, $tagValue) = $this->indexConditions();
    $settings = array('paramType' => 'querystring', 'recursive' => -1, 'joins' => array());
    $groupFilter = isset($conditions['GroupId']);
    if ($groupFilter) {
      $settings['joins'][] = array('table' => 'Groups_Monitors', 'type' => 'INNER',
        'conditions' => '`Groups_Monitors`.`MonitorId` = `Event`.`MonitorId`');
    }
    if ($tagValue !== null) {
      $settings['joins'][] = array('table' => 'Events_Tags', 'type' => 'INNER', 'conditions' => '`Events_Tags`.`EventId` = `Event`.`Id`');
      $settings['joins'][] = array('table' => 'Tags', 'type' => 'INNER', 'conditions' => '`Tags`.`Id` = `Events_Tags`.`TagId`');
      $conditions[] = ($tagField === 'Id')
        ? array('Tags.Id' => array_map('intval', is_array($tagValue) ? $tagValue : explode(',', $tagValue)))
        : array('Tags.Name' => $tagValue);
      $settings['group'] = 'Event.Id';
    }
    $settings['conditions'] = array($conditions, $this->viewableMonitorCondition('Event.MonitorId'));

    if ($this->request->query('limit') or $this->request->query('page')) {
      $paginator = new Paginator($settings);
      list($events, $paging) = $paginator->paginate($this->model, $this->route->named, $this->request->query);
    } else {
      $options = $settings;
      $order = $this->requestOrder();
      if ($order) $options['order'] = $order;
      $events = $this->model->find('all', $options);
      $paging = self::UNPAGED;
    }

    $seen = array();
    $out = array();
    foreach ($events as $row) {
      // The group join gives an event once per group of its monitor that matched.
      if (isset($seen[$row['Event']['Id']])) continue;
      $seen[$row['Event']['Id']] = true;
      $event = new \ZM\Event($row['Event']);
      if ($event->MaxScoreFrameId() == null) {
        $row['Event']['MaxScoreFrameId'] = $this->maxScoreFrameId($row['Event']);
      }
      $row['Event']['FileSystemPath'] = $event->Path();
      if ($groupFilter) $row['Group'] = $this->monitorGroups($row['Event']['MonitorId']);
      $out[] = $row;
    }
    return array('events' => $out, 'pagination' => $paging);
  }

  /**
   * One event with its monitor, storage, frames (not with ?noframes=true), groups
   * and tags, and: Next/Prev (the neighbouring events), NextOfMonitor/PrevOfMonitor
   * (of the same monitor), fileExists, fileSize, FileSystemPath, MaxScoreFrameId
   * and AlarmFrameId.
   */
  public function view($id = null) {
    if ($id === null or $id === '' or is_array($id)) throw new NotFoundException('Invalid event');
    $monitors = $this->viewableMonitorCondition('Event.MonitorId');
    $options = array('recursive' => 1, 'conditions' => array(array('Event.Id' => $id), $monitors));
    // ?noframes=true: the frames aren't read, only the tags.
    $noFrames = ($this->request->query('noframes') == 'true');
    if ($noFrames) $options['contain'] = array('Tag' => true);
    $event = $this->model->find('first', $options);
    if (!$event) {
      // No such event, or the user may not view its monitor.
      if (!$this->model->exists($id)) throw new NotFoundException('Invalid event');
      throw new UnauthorizedException('Insufficient Privileges');
    }
    $tags = $event['Tag'];
    unset($event['Tag']);
    $event['Group'] = $this->monitorGroups($event['Event']['MonitorId']);
    $event['Tag'] = $tags;

    $e = &$event['Event'];
    $near = $this->neighbors($id, $monitors, $e['MonitorId']);
    list($e['Next'], $e['Prev']) = array($near['Next'], $near['Prev']);
    $object = new \ZM\Event($e);
    $e['fileExists'] = $this->fileExists($object, $e);
    $e['fileSize'] = @filesize($object->Path().'/'.basename((string)$e['DefaultVideo']));
    $e['FileSystemPath'] = $object->Path();
    list($e['NextOfMonitor'], $e['PrevOfMonitor']) = array($near['NextOfMonitor'], $near['PrevOfMonitor']);
    $e['MaxScoreFrameId'] = $near['MaxScoreFrameId'] === null ? null : (int)$near['MaxScoreFrameId'];
    $e['AlarmFrameId'] = $near['AlarmFrameId'] === null ? null : (int)$near['AlarmFrameId'];
    return array('event' => $event);
  }

  /** Event[MonitorId] and the other columns; needs view rights on the monitor. */
  public function add() {
    $this->requirePermission('Events', 'Edit', 'Insufficient privileges');
    $this->allowMethod('POST');
    $monitorId = $this->requestField('Event', 'MonitorId');
    if ($monitorId === null) throw new BadRequestException('MonitorId is required');
    $this->requireMonitorView($monitorId);
    if (!$this->model->save($this->withoutId($this->request->data))) {
      throw new BadRequestException('Invalid '.implode(', ', array_keys($this->model->validationErrors)));
    }
    return Response::nothing($this->request->format);
  }

  /** {"message": "Saved"} or "Error". Moving an event to another monitor needs view rights on it. */
  public function edit($id = null) {
    $this->requirePermission('Events', 'Edit', 'Insufficient privileges');
    $event = $this->editable($id);
    $monitorId = $this->requestField('Event', 'MonitorId');
    if ($monitorId !== null and $monitorId != $event['MonitorId']) $this->requireMonitorView($monitorId);
    return array('message' => $this->model->save($this->withoutId($this->request->data), $id) ? 'Saved' : 'Error');
  }

  /** Deletes the event with its frames and files, as the console does. */
  public function delete($id = null) {
    $this->requirePermission('Events', 'Edit', 'Insufficient privileges');
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid event');
    $this->allowMethod('POST', 'DELETE');
    $this->editable($id);
    $event = \ZM\Event::find_one(array('Id' => $id));
    if ($event) $event->delete();
    return Response::nothing($this->request->format);
  }

  /**
   * events/archive/<id> (POST or PUT): toggle Archived: {"archived": 1 or 0}.
   * Both ways need edit rights on the event: archiving changes the row and keeps
   * the event from being purged, which a viewer must not decide (as the web UI's
   * archive action, and ZoneMinder's fix for the same check in CakePHP).
   */
  public function archive($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid event');
    $this->allowMethod('POST', 'PUT');
    $row = $this->row($id);
    $event = new \ZM\Event($row);
    $archived = ($row['Archived'] == 0) ? 1 : 0;
    if (!$event->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    Db::execute('UPDATE `Events` SET `Archived` = ? WHERE `Id` = ?', array($archived, $id));
    return array('archived' => $archived);
  }

  /**
   * events/search/<Field>:<value>/...: {"results": [rows]}. A value
   * "interval <n> <unit>" means "within the last <n> <unit>s" (interval 1 day).
   */
  public function search() {
    $windows = array();
    $filters = array();
    foreach ($this->route->named as $field => $value) {
      if (!is_array($value) and preg_match('/^\s?interval\s?/i', $value)) {
        if (preg_match('/^[a-z0-9]+$/i', $field) !== 1 or !$this->model->hasField($field)) {
          throw new BadRequestException('Invalid field name: '.$field);
        }
        list($expr, $unit) = self::interval(preg_replace('/^\s?interval\s?/i', '', $value));
        $windows[] = Db::quoteName("Event.$field")." >= DATE_SUB(NOW(), INTERVAL $expr $unit)";
      } else {
        $filters[$field] = $value;
      }
    }
    $conditions = \ZM\Api\Filter::conditions($filters, $this->model);
    $conditions[] = $windows;
    $conditions[] = $this->viewableMonitorCondition('Event.MonitorId');
    return array('results' => $this->model->find('all', array('conditions' => $conditions, 'recursive' => -1)));
  }

  /**
   * events/consoleEvents/<interval>[/<filters>]: the number of events per monitor
   * that started within the interval (1 hour, 7 day, ...): {"results": {MonitorId: n}}.
   */
  public function consoleEvents($interval = null) {
    list($expr, $unit) = self::interval((string)$interval);
    $conditions = \ZM\Api\Filter::conditions($this->route->named, $this->model);
    $conditions[] = "`Event`.`StartDateTime` >= DATE_SUB(NOW(), INTERVAL $expr $unit)";
    $conditions[] = $this->viewableMonitorCondition('Event.MonitorId');
    $where = new \ZM\Api\Db\Conditions('Event', $this->model->columns());
    $sql = $where->sql($conditions);
    $results = array();
    foreach (Db::rows('SELECT `Event`.`MonitorId`, COUNT(*) AS `Count` FROM `Events` AS `Event`'
        .($sql !== '' ? " WHERE $sql" : '').' GROUP BY `Event`.`MonitorId`', $where->params) as $row) {
      $results[$row['MonitorId']] = $row['Count'];
    }
    return array('results' => $results);
  }

  // ------------------------------------------------------------------ helpers

  /**
   * The index's filter conditions and its tag filter: (conditions, 'Id'|'Name'|null, value).
   */
  private function indexConditions() {
    $named = $this->route->named;
    $fromQuery = !count($named);
    $params = $fromQuery ? $this->request->query : $named;
    $tagField = null;
    $tagValue = null;
    // Tags.Id:<id>[,<id>...] is how CakePHP's index filters by tag through the
    // Tags join too (zmNinjaNg sends it), so it means the same as TagId.
    foreach (array('Tags.Id' => 'Id', 'TagId' => 'Id', 'Tag' => 'Name', 'Tags' => 'Name') as $name => $field) {
      if (isset($params[$name])) {
        $tagField = $field;
        $tagValue = $params[$name];
        unset($params[$name]);
        break;
      }
    }
    foreach ($params as $k => $v) {
      if (strpos((string)$k, 'Tags.Id') === 0) {
        throw new BadRequestException('Tags.Id takes tag ids and no operator');
      }
    }
    // StartTime/EndTime were renamed StartDateTime/EndDateTime in 1.35.13.
    foreach ($params as $k => $v) {
      if (strpos($k, 'StartTime') !== false or strpos($k, 'EndTime') !== false) {
        unset($params[$k]);
        $params[str_replace(array('StartTime', 'EndTime'), array('StartDateTime', 'EndDateTime'), $k)] = $v;
      }
    }
    $options = array('bare' => array('DateTime', 'GroupId'));
    if ($fromQuery) {
      // Like CakePHP, the query string filters when there are no named
      // arguments, and a parameter that is no filter field is a 400: a filter
      // the client meant (a mangled ?StartTime%20%3E=...) is never silently
      // dropped. Credentials and a cache-busting _= are not filters.
      $params = array_diff_key($params, array_flip(self::QUERY_NOT_FILTERS));
    }
    $conditions = \ZM\Api\Filter::conditions($params, $this->model, $options);
    // GroupId joins Groups_Monitors only as GroupId:<id>; DateTime is rewritten
    // below for comparisons. Anything else would reach MySQL as an unknown column.
    foreach ($conditions as $k => $v) {
      $key = is_int($k) ? (is_array($v) ? (string)key($v) : '') : $k;
      if (preg_match('/^GroupId\b/', $key) and ($key !== 'GroupId' or is_int($k))) {
        throw new BadRequestException('GroupId takes one group id and no operator');
      }
      if (preg_match('/^DateTime\b(.*)$/', $key, $m)
          and (is_int($k) or !in_array(trim($m[1]), array('', '=', '!=', '<>', '<', '<=', '>', '>='), true))) {
        throw new BadRequestException('DateTime takes one value and a comparison');
      }
    }

    // DateTime is "the event was running then", so a window over it is an overlap
    // test. An open event's end is StartDateTime + Length (zmc keeps Length
    // current), or now if it has neither.
    $end = '(CASE WHEN Event.EndDateTime IS NOT NULL THEN Event.EndDateTime'
      .' WHEN Event.Length > 0 THEN DATE_ADD(Event.StartDateTime, INTERVAL FLOOR(Event.Length) SECOND) ELSE NOW() END)';
    foreach ($conditions as $k => $v) {
      if (!is_string($k) or strpos($k, 'DateTime') !== 0) continue;
      unset($conditions[$k]);
      $op = trim(substr($k, strlen('DateTime')));
      if ($op == '<' or $op == '<=') {
        $conditions[] = array("Event.StartDateTime $op" => $v);
      } else if ($op == '>' or $op == '>=') {
        $conditions[] = "$end $op ".Db::connection()->quote($v);
      } else {
        $conditions[] = array('OR' => array(array("Event.StartDateTime $op" => $v), array("Event.EndDateTime $op" => $v)));
      }
    }
    return array($conditions, $tagField, $tagValue);
  }

  /** ORDER BY from ?sort=&direction=, when sort names a column or computed field. */
  private function requestOrder() {
    $sort = $this->request->query('sort');
    if (!$sort or !is_string($sort)) return null;
    $direction = $this->request->query('direction');
    $direction = (is_string($direction) and strtolower($direction) == 'desc') ? 'desc' : 'asc';
    $field = (strpos($sort, '.') !== false) ? substr($sort, strpos($sort, '.') + 1) : $sort;
    if ($this->model->hasField($field)) return array("Event.$field" => $direction);
    if ($this->model->hasVirtualField($field)) return array($field => $direction);
    return null;
  }

  /** The groups of a monitor, each with its Groups_Monitors row (GroupsMonitor). */
  private function monitorGroups($monitorId) {
    $groups = array();
    foreach (Db::select('SELECT `Group`.*, `GroupsMonitor`.* FROM `Groups` AS `Group`'
        .' JOIN `Groups_Monitors` AS `GroupsMonitor` ON (`GroupsMonitor`.`GroupId` = `Group`.`Id`)'
        .' WHERE `GroupsMonitor`.`MonitorId` = ?', array($monitorId)) as $r) {
      $groups[] = $r['Group'] + array('GroupsMonitor' => $r['GroupsMonitor']);
    }
    return $groups;
  }

  /**
   * In one statement, each a subquery of its own: Next and Prev (the
   * neighbouring event Ids among the events matching $conditions),
   * NextOfMonitor and PrevOfMonitor (among monitor $monitorId's events), 0 for
   * none; MaxScoreFrameId (the frame with the highest score, the first of
   * equals) and AlarmFrameId (an alarm frame), null for none.
   */
  private function neighbors($id, array $conditions, $monitorId) {
    $params = array();
    $near = array();
    foreach (array('Next' => $conditions, 'Prev' => $conditions,
        'NextOfMonitor' => array('Event.MonitorId' => $monitorId), 'PrevOfMonitor' => array('Event.MonitorId' => $monitorId)) as $name => $c) {
      $where = new \ZM\Api\Db\Conditions('Event', $this->model->columns());
      $sql = $where->sql($c);
      $extra = ($sql !== '') ? " AND ($sql)" : '';
      $next = (strpos($name, 'Next') === 0);
      $near[] = '(SELECT `Event`.`Id` FROM `Events` AS `Event` WHERE `Event`.`Id` '.($next ? '>' : '<')." ?$extra"
        .' ORDER BY `Event`.`Id` '.($next ? 'ASC' : 'DESC').' LIMIT 1) AS '.Db::quoteName($name);
      $params = array_merge($params, array($id), $where->params);
    }
    $near[] = '(SELECT `FrameId` FROM `Frames` WHERE `EventId` = ? ORDER BY `Score` DESC, `FrameId` ASC LIMIT 1) AS `MaxScoreFrameId`';
    $near[] = "(SELECT `FrameId` FROM `Frames` WHERE `EventId` = ? AND `Type` = 'Alarm' LIMIT 1) AS `AlarmFrameId`";
    $params[] = $id;
    $params[] = $id;
    $row = Db::rows('SELECT '.implode(', ', $near), $params)[0];
    foreach (array('Next', 'Prev', 'NextOfMonitor', 'PrevOfMonitor') as $name) {
      if ($row[$name] === null) $row[$name] = 0;
    }
    return $row;
  }

  /**
   * 1 if the event's video file exists, 0 if it has none, null if it is missing.
   * A recording event's video is incomplete.* until it closes and is renamed,
   * so a stale name is looked up again.
   */
  private function fileExists(\ZM\Event $object, array $e) {
    if (!$e['DefaultVideo']) return 0;
    if (file_exists($object->Path().'/'.basename($e['DefaultVideo']))) return 1;
    if (preg_match('/^incomplete\./', basename($e['DefaultVideo']))) {
      \ZM\Event::clear_cache();
      $fresh = \ZM\Event::find_one(array('Id' => $e['Id']));
      if ($fresh and $fresh->DefaultVideo() and $fresh->DefaultVideo() != $e['DefaultVideo']
          and file_exists($fresh->Path().'/'.$fresh->DefaultVideo())) {
        return 1;
      }
    }
    \ZM\Warning('File does not exist at '.$object->Path().'/'.$e['DefaultVideo']);
    return null;
  }

  /** For an event whose MaxScoreFrameId isn't stored: the frame with its MaxScore. */
  private function maxScoreFrameId(array $e) {
    $frameId = Db::value('SELECT `FrameId` FROM `Frames` WHERE `EventId` = ? AND `Score` = ? ORDER BY `FrameId` LIMIT 1',
      array($e['Id'], $e['MaxScore']));
    return $frameId === null ? null : (int)$frameId;
  }

  private function row($id) {
    $row = $this->model->find('first', array('conditions' => array('Event.Id' => $id), 'recursive' => -1));
    return $row['Event'];
  }

  /** The event's row, if it exists and the user may edit it. */
  private function editable($id) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid event');
    $row = $this->row($id);
    $event = new \ZM\Event($row);
    if (!$event->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    return $row;
  }


  /**
   * (expression, unit) of a MySQL interval: "1 hour", "1-1 YEAR_MONTH", "1.5 SECOND".
   * @throws BadRequestException otherwise
   */
  private static function interval($interval) {
    if (preg_match('/^(?P<expr>[ \-.:0-9]+)\s+(?P<unit>[_a-z]+)$/i', trim($interval), $m) !== 1) {
      throw new BadRequestException('Invalid interval: '.$interval);
    }
    $units = array('MICROSECOND', 'SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK', 'MONTH', 'QUARTER', 'YEAR',
      'SECOND_MICROSECOND', 'MINUTE_MICROSECOND', 'MINUTE_SECOND', 'HOUR_MICROSECOND', 'HOUR_SECOND',
      'HOUR_MINUTE', 'DAY_MICROSECOND', 'DAY_SECOND', 'DAY_MINUTE', 'DAY_HOUR', 'YEAR_MONTH');
    $unit = strtoupper(trim($m['unit']));
    if (!in_array($unit, $units, true)) throw new BadRequestException('Invalid interval: '.$interval);
    return array("'".trim($m['expr'])."'", $unit);
  }

  private function withoutId(array $data) {
    if (isset($data['Event']) and is_array($data['Event'])) unset($data['Event']['Id']);
    unset($data['Id']);
    return $data;
  }
}
