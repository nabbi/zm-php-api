<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Model\Snapshot;

/**
 * /snapshots: named sets of events. Needs Snapshots=View; changes need
 * Snapshots=Edit, and a snapshot can only include events the user may view.
 * Events on monitors the user may not view are left out of every answer.
 * Events are added with Snapshot[EventIds]=1,2 or Event[]=1&Event[]=2.
 */
class SnapshotsController extends Controller {
  /** @var Snapshot */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Snapshot();
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Snapshots', 'View');
  }

  /** Each snapshot with its events (all their fields). Name:value filters. */
  public function index() {
    $snapshots = $this->model->find('all', array(
      'conditions' => \ZM\Api\Filter::conditions($this->route->named, $this->model),
      'recursive' => 1,
    ));
    return array('snapshots' => array_map(array($this, 'filterContainedEvents'), $snapshots));
  }

  /** snapshots/associations: each snapshot with its events' Id, Name and MonitorId. */
  public function associations() {
    $snapshots = $this->model->find('all', array('recursive' => -1,
      'contain' => array('Event' => array('Id', 'Name', 'MonitorId'))));
    return array('snapshots' => array_map(array($this, 'filterContainedEvents'), $snapshots));
  }

  public function view($id = null) {
    $this->requireExists($id);
    $snapshot = $this->model->find('first', array('conditions' => array('Snapshot.Id' => $id), 'recursive' => 1));
    return array('snapshot' => $this->filterContainedEvents($snapshot));
  }

  public function add() {
    $this->allowMethod('POST');
    $this->requirePermission('Snapshots', 'Edit');
    $eventIds = $this->requestAssociatedIds('Snapshot', 'EventIds', 'Event');
    if ($eventIds) $this->requireEventsView($eventIds);
    if (!$this->model->save($this->withEvents($this->request->data, $eventIds))) {
      \ZM\Error('Failed to save Snapshot');
      throw new \ZM\Api\Http\BadRequestException('Invalid '.implode(', ', array_keys($this->model->validationErrors)));
    }
    return Response::nothing($this->request->format);
  }

  /** {"snapshot": the snapshot as stored} */
  public function edit($id = null) {
    $this->requireExists($id);
    if ($this->request->is('POST') or $this->request->is('PUT')) {
      $this->requirePermission('Snapshots', 'Edit');
      $eventIds = $this->requestAssociatedIds('Snapshot', 'EventIds', 'Event');
      if ($eventIds !== null) $this->requireEventLinkChanges('Snapshots_Events', 'SnapshotId', $id, $eventIds);
      $this->model->save($this->withEvents($this->request->data, $eventIds), $id);
    }
    return array('snapshot' => $this->model->find('first', array('conditions' => array('Snapshot.Id' => $id), 'recursive' => -1)));
  }

  public function delete($id = null) {
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    $this->requirePermission('Snapshots', 'Edit');
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }

  private function requireExists($id) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid snapshot');
  }


  private function withEvents(array $data, $eventIds) {
    $snapshot = (isset($data['Snapshot']) and is_array($data['Snapshot'])) ? $data['Snapshot'] : array_filter($data, 'is_scalar');
    unset($snapshot['EventIds'], $snapshot['Id']);
    $out = array('Snapshot' => $snapshot);
    if ($eventIds !== null) $out['Event'] = $eventIds;
    return $out;
  }
}
