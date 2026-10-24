<?php
namespace ZM\Api\Controller;

use ZM\Api\Db\Db;
use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\Monitor;

/**
 * /monitors: monitors, and their daemons and alarms.
 *
 * Each monitor is visible only to users who may view it (Monitors permission and
 * the per-monitor and group ACLs); changes need edit rights on it. Creating
 * needs System=Edit or Monitors=Create, deleting System=Edit. Deleting
 * soft-deletes, as the console does: the row stays with Deleted=1, events kept.
 */
class MonitorsController extends Controller {
  /** Daemons and commands monitors/daemonControl accepts. */
  const DAEMONS = array('zmc', 'zma', 'zmfilter.pl', 'zmaudit.pl', 'zmtrigger.pl', 'zmwatch.pl', 'zmupdate.pl',
    'zmtrack.pl', 'zmcontrol.pl', 'zmstats.pl', 'zmeventnotification.pl');
  const COMMANDS = array('start', 'stop', 'restart', 'reload', 'status', 'check', 'logrot');

  /** @var Monitor */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Monitor();
    require_once __DIR__.'/../../../includes/Monitor.php';
  }

  /**
   * The monitors the user may view, each with Manufacturer, CameraModel,
   * Monitor_Status and Event_Summary. Name:value filters, including
   * GroupId:<id> for the members of a group.
   */
  public function index() {
    $conditions = \ZM\Api\Filter::conditions($this->route->named, $this->model, array('joined' => true, 'bare' => array('GroupId')));
    $options = array('conditions' => $conditions, 'recursive' => 0);
    if ($this->filtersOnGroup($conditions)) {
      $options['joins'] = array(array('table' => 'Groups_Monitors',
        'conditions' => '`Groups_Monitors`.`MonitorId` = `Monitor`.`Id`'));
    }
    $allowed = array();
    $seen = array();
    foreach ($this->model->find('all', $options) as $m) {
      // A monitor in several of the groups filtered on comes once per group.
      if (isset($seen[$m['Monitor']['Id']])) continue;
      $seen[$m['Monitor']['Id']] = true;
      $monitor = new \ZM\Monitor($m['Monitor']);
      if ($monitor->canView()) $allowed[] = $m;
    }
    return array('monitors' => $allowed);
  }

  public function view($id = null) {
    // No row is no such monitor (the exists() check, in the same statement).
    $row = ($id === null or $id === '' or is_array($id)) ? array()
      : $this->model->find('first', array('conditions' => array('Monitor.Id' => $id), 'recursive' => 0));
    if (!$row) throw new NotFoundException('Invalid monitor');
    $monitor = new \ZM\Monitor($row['Monitor']);
    if (!$monitor->canView()) throw new UnauthorizedException('Insufficient Privileges');
    if ($monitor->JanusEnabled()) $row['Monitor']['Janus_Pin'] = $monitor->Janus_Pin();
    return array('monitor' => $row);
  }

  /** {"message": "Saved"}, or the validation errors by field; the new monitor's zmc starts. */
  public function add() {
    $this->allowMethod('POST');
    global $user;
    if ($user and $user->System() != 'Edit' and $user->Monitors() != 'Create') {
      throw new UnauthorizedException('Insufficient privileges');
    }
    // A new monitor's command fields start empty, so any value is a change.
    if (count((new \ZM\Monitor())->commandFieldChangesNotAllowed($this->commandFields()))) {
      throw new UnauthorizedException('Setting the event commands requires System edit permission');
    }
    if ($this->model->save($this->withoutId($this->request->data))) {
      Monitor::daemonControl($this->daemonRow($this->model->id), 'start');
      return array('message' => 'Saved');
    }
    return array('message' => $this->model->validationErrors ? $this->model->validationErrors : 'Error');
  }

  /**
   * {"message": "Saved"}. The daemons restart as the console's save restarts them
   * (includes/actions/monitor.php): zmc and, for a controllable monitor, the
   * control server zmcontrol.pl, which otherwise keeps the ControlAddress,
   * ControlDevice and Control it read when it started. Stopped with the old row,
   * started with the saved one unless the monitor doesn't capture, is a WebSite
   * or is deleted. ZM\Monitor sends the commands to the monitor's own server in
   * a multi-server setup. (ZoneMinder 25dba3267 for CakePHP.)
   */
  public function edit($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid monitor');
    $monitor = new \ZM\Monitor($this->fullRow($id));
    if (!$monitor->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    if (count($monitor->commandFieldChangesNotAllowed($this->commandFields()))) {
      throw new UnauthorizedException('Changing the event commands requires System edit permission');
    }
    if (!$this->model->save($this->withoutId($this->request->data), $id)) {
      return array('message' => 'Error '.print_r($this->model->validationErrors, true));
    }
    if ($monitor->Type() != 'WebSite') {
      $monitor->zmcControl('stop');
      if ($monitor->Controllable()) $monitor->sendControlCommand('stop');
    }
    $saved = new \ZM\Monitor($this->fullRow($id));
    if ($saved->Capturing() != 'None' and $saved->Type() != 'WebSite' and !$saved->Deleted()) {
      $saved->zmcControl('start');
      if ($saved->Controllable()) $saved->sendControlCommand('start');
    }
    return array('message' => 'Saved');
  }

  /** Soft delete, as the console does: zmc and zmcontrol stop, Deleted=1, events stay. */
  public function delete($id = null) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid monitor');
    global $user;
    if ($user and $user->System() != 'Edit') throw new UnauthorizedException('Insufficient privileges');
    $this->allowMethod('POST', 'DELETE');
    $monitor = new \ZM\Monitor($id);
    $monitor->delete();
    return Response::nothing($this->request->format);
  }

  /** monitors/sourceTypes: the values of Monitors.Type. */
  public function sourceTypes() {
    $column = Db::rows("SHOW COLUMNS FROM `Monitors` LIKE 'Type'");
    preg_match('/^enum\((.*)\)$/', $column[0]['Type'], $m);
    return array('sourceTypes' => array_map(function ($v) { return trim($v, "'"); }, explode(',', $m[1])));
  }

  /**
   * monitors/alarm/id:<id>/command:<on|off|disable|status>: force, cancel or
   * disable an alarm, or report the alarm state, through zmu. status needs view
   * rights on the monitor, the rest edit rights.
   */
  public function alarm() {
    $id = $this->named('id');
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid monitor');
    $commands = array('on' => array('-a', '-v'), 'off' => array('-c', '-v'), 'disable' => array('-n', '-v'),
      'status' => array('-s', '')); // -v gives wrong output with -s
    $cmd = strtolower((string)$this->named('command'));
    if (!isset($commands[$cmd])) throw new BadRequestException('Invalid command');
    list($q, $verbose) = $commands[$cmd];
    // zmu only checks that the user can see the monitor, so changing alarm state is checked here.
    $monitor = new \ZM\Monitor($id);
    if (!(($cmd == 'status') ? $monitor->canView() : $monitor->canEdit())) {
      throw new UnauthorizedException('Insufficient Privileges');
    }
    if ($cmd != 'status') \ZM\Api\Auth::requireExplicitForGet($this->request);
    $command = ZM_PATH_BIN.'/zmu'.($verbose ? " $verbose" : '').' -m'.escapeshellarg($id)." $q";
    $password = $this->request->input('pass') ?: $this->request->input('password'); // used only without a token secret
    $status = exec($command.\ZM\Api\Auth::zmuCredentials(is_string($password) ? $password : null), $output, $rc);
    // Logged without the credentials.
    \ZM\Debug("Command: $command output: ".implode(PHP_EOL, $output)." rc: $rc");
    if ($rc) return array('status' => 'false', 'code' => $rc, 'error' => implode(PHP_EOL, $output));
    // Since 1.36.16 zmu reports states one higher; clients expect the old numbers.
    if ($cmd == 'status') return array('status' => intval($status) - 1, 'output' => intval($output[0]) - 1);
    return array('status' => $status, 'output' => implode(PHP_EOL, $output));
  }

  /**
   * monitors/daemonStatus/id:<id>/daemon:<name>: whether zmdc.pl reports the
   * daemon running for this monitor: {"status": bool, "statustext": zmdc's answer}.
   */
  public function daemonStatus() {
    $id = $this->named('id');
    $daemon = $this->named('daemon');
    $this->monitorFor($id, false);
    if (preg_match('/^[a-z]+$/i', (string)$daemon) !== 1) throw new BadRequestException('Invalid command');
    $monitor = $this->daemonRow($id);
    if ($monitor['Capturing'] == 'None') {
      return array('status' => false, 'statustext' => 'Monitor capturing is set to None');
    }
    $args = ($monitor['Type'] == 'Local') ? '-d '.escapeshellarg($monitor['Device']) : '-m '.escapeshellarg($monitor['Id']);
    $status = exec(ZM_PATH_BIN.'/zmdc.pl status '.escapeshellarg($daemon).' '.$args);
    // "not running" means not running; anything else (also "pending") counts as running.
    return array('status' => strpos($status, 'not') ? false : true, 'statustext' => $status);
  }

  /**
   * monitors/daemonControl/<id>/<command>[/<daemon>]: zmdc.pl <command> for the
   * monitor's zmc (or <daemon>). Needs edit rights on the monitor.
   */
  public function daemonControl($id, $command, $daemon = null) {
    if ($daemon !== null and !in_array($daemon, self::DAEMONS, true)) throw new BadRequestException('Invalid daemon');
    if (!in_array($command, self::COMMANDS, true)) throw new BadRequestException('Invalid command');
    $this->monitorFor($id, true);
    if ($command != 'status' and $command != 'check') \ZM\Api\Auth::requireExplicitForGet($this->request);
    return array('status' => 'ok', 'statustext' => Monitor::daemonControl($this->daemonRow($id), $command, $daemon));
  }

  // ------------------------------------------------------------------ helpers

  /** @throws NotFoundException|UnauthorizedException unless the user may view (or edit) monitor $id */
  private function monitorFor($id, $forEdit) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid monitor');
    $row = $this->model->find('first', array('conditions' => array('Monitor.Id' => $id), 'recursive' => -1));
    $monitor = new \ZM\Monitor($row['Monitor']);
    if ($forEdit ? !$monitor->canEdit() : !$monitor->canView()) throw new UnauthorizedException('Insufficient Privileges');
  }

  /** The monitor's whole Monitors row, read now (not from ZM\Monitor's object cache). */
  private function fullRow($id) {
    $row = $this->model->find('first', array('conditions' => array('Monitor.Id' => $id), 'recursive' => -1));
    return $row['Monitor'];
  }

  /** The columns daemonControl() and the restart decision need. */
  private function daemonRow($id) {
    $fields = array('Id', 'Type', 'Capturing', 'Device', 'ServerId');
    $row = $this->model->find('first', array('fields' => $fields, 'conditions' => array('Monitor.Id' => $id), 'recursive' => -1));
    return $row['Monitor'];
  }

  private function filtersOnGroup(array $conditions) {
    foreach ($conditions as $key => $value) {
      if (strpos((string)$key, 'GroupId') !== false) return true;
      if (is_array($value)) {
        foreach ($value as $subKey => $v) {
          if (strpos((string)$subKey, 'GroupId') !== false) return true;
        }
      }
    }
    return false;
  }

  /**
   * The EventStartCommand / EventEndCommand values the request supplies. zmc runs
   * them as the ZoneMinder user, so changing one needs System=Edit, not only edit
   * on the monitor (Monitor::commandFieldChangesNotAllowed(), GHSA-vcrj-35c6-g97c).
   */
  private function commandFields() {
    $data = $this->request->data;
    if (isset($data['Monitor']) and is_array($data['Monitor'])) $data = $data['Monitor'];
    return array_intersect_key($data, array_flip(\ZM\Monitor::$commandFields));
  }

  private function withoutId(array $data) {
    if (isset($data['Monitor']) and is_array($data['Monitor'])) unset($data['Monitor']['Id']);
    unset($data['Id']);
    return $data;
  }
}
