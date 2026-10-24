<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\EventData;

/**
 * /event_data: data attached to events. Needs Events=View, and rows are visible
 * only with their monitor; changes need Events=Edit, edit rights on the event and
 * access to the monitor (also the event and monitor a row is moved to).
 */
class EventDataController extends Controller {
  /** @var EventData */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new EventData();
    require_once __DIR__.'/../../../includes/Event.php';
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Events', 'View');
  }

  /** Name:value filters, on the monitors the user may view. */
  public function index() {
    $conditions = \ZM\Api\Filter::conditions($this->route->named, $this->model);
    $monitors = $this->viewableMonitorCondition('EventData.MonitorId');
    if (count($monitors)) $conditions[] = $monitors;
    return array('event_data' => $this->model->find('all', array('conditions' => $conditions, 'recursive' => -1)));
  }

  public function view($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid event data');
    $row = $this->model->find('first', array('recursive' => -1,
      'conditions' => array('EventData.Id' => $id) + $this->viewableMonitorCondition('EventData.MonitorId')));
    // The row exists, so no result means the user may not view its monitor.
    if (!$row) throw new UnauthorizedException('Insufficient Privileges');
    return array('event_data' => $row);
  }

  /** {"event_data": the stored row} */
  public function add() {
    $this->allowMethod('POST');
    $this->requirePermission('Events', 'Edit');
    $this->requireRequestTargets(true);
    $data = $this->withoutId($this->request->data);
    if (!$this->model->save($data)) {
      throw new BadRequestException('Invalid '.implode(', ', array_keys($this->model->validationErrors)));
    }
    return array('event_data' => $this->stored($this->model->id));
  }

  /** {"event_data": the row as stored} */
  public function edit($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid event_data');
    $this->requireRowEdit($id);
    if ($this->request->is('POST') or $this->request->is('PUT')) {
      $this->requireRequestTargets(false);
      $this->model->save($this->withoutId($this->request->data), $id);
    }
    return array('event_data' => $this->stored($id));
  }

  public function delete($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid event_data');
    $this->allowMethod('POST', 'DELETE');
    $this->requireRowEdit($id);
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }

  // ------------------------------------------------------------------ helpers

  private function stored($id) {
    return $this->model->find('first', array('conditions' => array('EventData.Id' => $id), 'recursive' => -1));
  }


  /** Events=Edit, and the user may view the row's monitor. */
  private function requireRowEdit($id) {
    $this->requirePermission('Events', 'Edit');
    global $user;
    if ($user and $user->unviewableMonitorIds()) {
      $row = $this->stored($id);
      $monitorId = $row ? $row['EventData']['MonitorId'] : null;
      if (!in_array($monitorId, $user->viewableMonitorIds())) throw new UnauthorizedException('Insufficient Privileges');
    }
  }

  /** The EventId (required when $eventRequired) may be edited, the MonitorId viewed. */
  private function requireRequestTargets($eventRequired) {
    $data = $this->requestRecord('EventData');
    if (!isset($data['EventId'])) {
      if ($eventRequired) throw new BadRequestException('EventId is required');
    } else {
      $row = dbFetchOne('SELECT * FROM `Events` WHERE `Id` = ?', null, array($data['EventId']));
      if (!$row) throw new NotFoundException('Invalid event');
      $event = new \ZM\Event($row);
      if (!$event->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    }
    if (isset($data['MonitorId'])) {
      global $user;
      if ($user and $user->unviewableMonitorIds() and !in_array($data['MonitorId'], $user->viewableMonitorIds())) {
        throw new UnauthorizedException('Insufficient Privileges');
      }
    }
  }

  private function withoutId(array $data) {
    if (isset($data['EventData']) and is_array($data['EventData'])) unset($data['EventData']['Id']);
    unset($data['Id']);
    return $data;
  }
}
