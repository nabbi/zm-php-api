<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\Zone;

/**
 * /zones: motion detection zones. Needs Monitors=View, and each zone is visible
 * only with its monitor; changes need Monitors=Edit and edit rights on the
 * monitor (and on the monitor a zone is moved to).
 */
class ZonesController extends Controller {
  /** @var Zone */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Zone();
    require_once __DIR__.'/../../../includes/Monitor.php';
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Monitors', 'View');
  }

  /** The zones of the monitors the user may view. */
  public function index() {
    $options = array('recursive' => -1);
    global $user;
    if ($user and $user->unviewableMonitorIds()) {
      $options['conditions'] = array('Zone.MonitorId' => $user->viewableMonitorIds());
    }
    return array('zones' => $this->model->find('all', $options));
  }

  /** zones/forMonitor/<monitor id> */
  public function forMonitor($id = null) {
    $this->requireMonitorView($id);
    return array('zones' => $this->model->find('all', array('conditions' => array('MonitorId' => $id), 'recursive' => -1)));
  }

  public function view($id = null) {
    $zone = $this->model->find('first', array('conditions' => array('Zone.Id' => $id), 'recursive' => -1));
    if (!$zone) throw new NotFoundException('Invalid zone');
    $monitor = new \ZM\Monitor($zone['Zone']['MonitorId']);
    if (!$monitor->canView()) throw new UnauthorizedException('Insufficient Privileges');
    return array('zone' => $zone);
  }

  /** {"message": "Saved", "zone": the saved zone}; the monitor's zmc restarts to take it on. */
  public function add() {
    if (!$this->request->is('POST')) throw new BadRequestException('Invalid method. Should be post');
    $this->requirePermission('Monitors', 'Edit');
    $monitorId = $this->requestField('Zone', 'MonitorId');
    if ($monitorId === null) throw new BadRequestException('MonitorId is required');
    $this->requireMonitorEdit($monitorId);
    $zone = $this->model->save($this->withoutId($this->request->data));
    if (!$zone) {
      return array('message' => $this->model->validationErrors ? $this->model->validationErrors : 'Error: ', 'zone' => false);
    }
    $monitor = new \ZM\Monitor($zone['Zone']['MonitorId']);
    $monitor->zmcControl('restart');
    return array('message' => 'Saved', 'zone' => $zone);
  }

  public function edit($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid zone');
    if (!$this->request->is('POST') and !$this->request->is('PUT')) return array('message' => '');
    $this->requirePermission('Monitors', 'Edit');
    $this->requireMonitorEdit($this->zoneMonitorId($id));
    $monitorId = $this->requestField('Zone', 'MonitorId');
    if ($monitorId !== null) $this->requireMonitorEdit($monitorId);
    if ($this->model->save($this->withoutId($this->request->data), $id)) {
      return array('message' => 'The zone has been saved.');
    }
    return array('message' => 'Error', 'errors' => $this->model->validationErrors);
  }

  public function delete($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid zone');
    $this->allowMethod('POST', 'DELETE');
    $this->requirePermission('Monitors', 'Edit');
    $this->requireMonitorEdit($this->zoneMonitorId($id));
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }


  private function zoneMonitorId($id) {
    $zone = $this->model->find('first', array('conditions' => array('Zone.Id' => $id), 'recursive' => -1));
    return $zone['Zone']['MonitorId'];
  }

  /** The data without the zone's Id, so a request can't name another zone. */
  private function withoutId(array $data) {
    if (isset($data['Zone']) and is_array($data['Zone'])) unset($data['Zone']['Id']);
    unset($data['Id']);
    return $data;
  }
}
