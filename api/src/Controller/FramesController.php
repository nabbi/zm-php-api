<?php
namespace ZM\Api\Controller;

use ZM\Api\Compat;
use ZM\Api\Db\Db;
use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\Frame;

/**
 * /frames: the frames of events. Needs Events=View, and a frame is visible only
 * with its event's monitor; changes need Events=Edit and edit rights on the event.
 *
 * A frame is addressed by event and frame number: frames/view/<EventId>/<FrameId>
 * (also edit/ and delete/). frames/<id> takes one number, so it finds no frame.
 *
 * 1.38: Frames also has its own key, Id (master c4ef3fdd6 dropped it), and 1.38's
 * CakePHP API addressed frames by it: frames/<Id>, frames/view/<Id>, edit/ and
 * delete/. There, one number is that Id (frame()), and writes go by it.
 */
class FramesController extends Controller {
  /** @var Frame */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Frame();
    require_once __DIR__.'/../../../includes/Event.php';
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Events', 'View');
  }

  /** Name:value filters (frames/index/EventId:12), on the events the user may view. */
  public function index() {
    $options = array('recursive' => -1,
      'conditions' => \ZM\Api\Filter::conditions($this->route->named, $this->model));
    $monitors = $this->viewableMonitorCondition('Event.MonitorId');
    if (count($monitors)) {
      $options['joins'] = array(array('table' => 'Events', 'alias' => 'Event', 'type' => 'INNER',
        'conditions' => '`Event`.`Id` = `Frame`.`EventId`'));
      $options['conditions'][] = $monitors;
    }
    return array('frames' => $this->model->find('all', $options));
  }

  public function view($eventId = null, $frameId = null) {
    if (!$this->eventOf($eventId, $frameId)->canView()) throw new UnauthorizedException('Insufficient Privileges');
    return array('frame' => $this->frame($eventId, $frameId));
  }

  /** Frame[EventId], Frame[FrameId] and the other columns. A frame that exists already is a 400. */
  public function add() {
    $this->allowMethod('POST');
    $this->requirePermission('Events', 'Edit');
    $columns = $this->requestColumns();
    if (!isset($columns['EventId'])) throw new BadRequestException('EventId is required');
    $event = $this->event($columns['EventId']);
    if (!$event->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    if (!$this->model->validates($columns, false)) {
      throw new BadRequestException('Invalid '.implode(', ', array_keys($this->model->validationErrors)));
    }
    if (isset($columns['FrameId']) and $this->exists($columns['EventId'], $columns['FrameId'])) {
      throw new BadRequestException('Frame '.$columns['FrameId'].' of event '.$columns['EventId'].' exists already');
    }
    $this->write('INSERT INTO `Frames` (%s) VALUES (%s)', $columns);
    return Response::nothing($this->request->format);
  }

  /** Change the columns other than EventId and FrameId. */
  public function edit($eventId = null, $frameId = null) {
    $frame = $this->requireFrameEdit($eventId, $frameId);
    $this->allowMethod('POST', 'PUT');
    // 1.38: Id is a column there, and the key; it is not changed either.
    $columns = $this->requestColumns(array('EventId', 'FrameId', 'Id'));
    if (count($columns)) {
      $schema = $this->model->schema();
      $set = array();
      $params = array();
      foreach ($columns as $c => $v) {
        $set[] = Db::quoteName($c).' = ?';
        $params[] = \ZM\Api\Db\Model::sqlValue($schema[$c]['type'], $v);
      }
      list($where, $key) = $this->keyOf($frame);
      Db::execute('UPDATE `Frames` SET '.implode(', ', $set).' WHERE '.$where, array_merge($params, $key));
    }
    return Response::nothing($this->request->format);
  }

  public function delete($eventId = null, $frameId = null) {
    $this->allowMethod('POST', 'DELETE');
    list($where, $key) = $this->keyOf($this->requireFrameEdit($eventId, $frameId));
    Db::execute('DELETE FROM `Frames` WHERE '.$where, $key);
    return Response::nothing($this->request->format);
  }

  // ------------------------------------------------------------------ helpers

  private function frame($eventId, $frameId) {
    $conditions = array('Frame.EventId' => $eventId, 'Frame.FrameId' => $frameId);
    // 1.38: one number is the frame's own Id (see the class comment).
    if ($frameId === null and Compat::hasColumn('Frames', 'Id')) $conditions = array('Frame.Id' => $eventId);
    $frame = $this->model->find('first', array('recursive' => -1, 'conditions' => $conditions));
    if (!$frame) throw new NotFoundException('Invalid frame');
    return $frame;
  }

  private function exists($eventId, $frameId) {
    return Db::value('SELECT COUNT(*) FROM `Frames` WHERE `EventId` = ? AND `FrameId` = ?', array($eventId, $frameId)) > 0;
  }

  /** The event of an existing frame (frames carry no MonitorId; their event's ACL applies). */
  private function eventOf($eventId, $frameId) {
    $frame = $this->frame($eventId, $frameId);
    return $this->event($frame['Frame']['EventId']);
  }

  private function event($eventId) {
    $row = dbFetchOne('SELECT * FROM `Events` WHERE `Id` = ?', null, array($eventId));
    if (!$row) throw new NotFoundException('Invalid event');
    return new \ZM\Event($row);
  }


  /** @return array the frame, which the user may change */
  private function requireFrameEdit($eventId, $frameId) {
    $this->requirePermission('Events', 'Edit');
    $frame = $this->frame($eventId, $frameId);
    if (!$this->event($frame['Frame']['EventId'])->canEdit()) throw new UnauthorizedException('Insufficient Privileges');
    return $frame;
  }

  /**
   * [WHERE clause, its values] selecting exactly $frame: (EventId, FrameId), the
   * key on master. 1.38: by Id, its key there; (EventId, FrameId) isn't unique
   * on 1.38, whose CakePHP API added duplicates.
   */
  private function keyOf(array $frame) {
    if (isset($frame['Frame']['Id'])) return array('`Id` = ?', array($frame['Frame']['Id']));
    return array('`EventId` = ? AND `FrameId` = ?', array($frame['Frame']['EventId'], $frame['Frame']['FrameId']));
  }

  /** The request's Frame fields that are columns, but for $exclude. */
  private function requestColumns(array $exclude = array()) {
    $data = $this->requestRecord('Frame');
    return array_diff_key(array_intersect_key($data, $this->model->schema()), array_flip($exclude));
  }

  private function write($template, array $columns) {
    $schema = $this->model->schema();
    $params = array();
    foreach ($columns as $c => $v) $params[] = \ZM\Api\Db\Model::sqlValue($schema[$c]['type'], $v);
    Db::execute(sprintf($template, implode(', ', array_map(array(Db::class, 'quoteName'), array_keys($columns))),
      implode(', ', array_fill(0, count($columns), '?'))), $params);
  }
}
