<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Model\Tag;

/**
 * /tags: event tags. Needs Events=View; creating, changing and deleting tags
 * needs Events=Edit, and only events the user may view can be tagged.
 *
 * tags/index/Events.Id:<id>[,<id>...] lists the tags of those events, each with
 * the event it is on (Events_Tags.EventId), limited to the monitors the user may
 * view. Events are tagged with Tag[EventIds]=1,2 or Event[]=1&Event[]=2.
 */
class TagsController extends Controller {
  /** @var Tag */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Tag();
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Events', 'View');
  }

  public function index() {
    // Events.Id is the joined Events table below, not a column of Tags.
    $conditions = \ZM\Api\Filter::conditions($this->route->named, $this->model, array('bare' => array('Events.Id')));
    // Only Events.Id:<id>[,<id>...]: other forms would name Events without the
    // join below (a SQL error).
    foreach ($conditions as $k => $v) {
      $key = is_int($k) ? (is_array($v) ? (string)key($v) : '') : $k;
      if (strpos($key, 'Events.Id') === 0 and ($key !== 'Events.Id' or is_int($k))) {
        throw new \ZM\Api\Http\BadRequestException('Events.Id takes event ids and no operator');
      }
    }
    $options = array('conditions' => &$conditions, 'recursive' => -1);
    if (isset($conditions['Events.Id'])) {
      if (!is_array($conditions['Events.Id']) and strpos($conditions['Events.Id'], ',') !== false) {
        $conditions['Events.Id'] = array_map('intval', explode(',', $conditions['Events.Id']));
      }
      $options['fields'] = array_merge(array_map(function ($c) { return "Tag.$c"; }, $this->model->columns()),
        array('Events_Tags.EventId'));
      $options['joins'] = array(
        array('table' => 'Events_Tags', 'type' => 'INNER', 'conditions' => '`Events_Tags`.`TagId` = `Tag`.`Id`'),
        array('table' => 'Events', 'type' => 'INNER', 'conditions' => '`Events`.`Id` = `Events_Tags`.`EventId`'),
      );
      // Which tags are on which events is per-monitor data.
      $monitors = $this->viewableMonitorCondition('Events.MonitorId');
      if (count($monitors)) $conditions[] = $monitors;
    }
    return array('tags' => $this->model->find('all', $options));
  }

  /** tags/associations: each tag with the events it is on (Id, Name) that the user may view. */
  public function associations() {
    $tags = $this->model->find('all', array('recursive' => -1, 'contain' => array('Event' => array('Id', 'Name', 'MonitorId'))));
    foreach ($tags as &$tag) {
      $tag = $this->filterContainedEvents($tag);
      foreach ($tag['Event'] as &$event) unset($event['MonitorId']);
    }
    return array('tags' => $tags);
  }

  public function view($id = null) {
    $this->requireExists($id);
    return array('tag' => $this->model->find('first', array('conditions' => array('Tag.Id' => $id), 'recursive' => -1)));
  }

  public function add() {
    $this->allowMethod('POST');
    $this->requirePermission('Events', 'Edit');
    $eventIds = $this->requestAssociatedIds('Tag', 'EventIds', 'Event');
    if ($eventIds) $this->requireEventsView($eventIds);
    $data = $this->withEvents($this->request->data, $eventIds);
    global $user;
    if ($user and !isset($data['Tag']['CreatedBy'])) $data['Tag']['CreatedBy'] = $user->Id();
    $this->model->linkValues['Event'] = array('AssignedBy' => $user ? $user->Id() : null);
    if (!$this->model->save($data)) {
      \ZM\Error('Failed to save Tag');
      throw new \ZM\Api\Http\BadRequestException('Invalid '.implode(', ', array_keys($this->model->validationErrors)));
    }
    return Response::nothing($this->request->format);
  }

  /** {"tag": the tag as stored} */
  public function edit($id = null) {
    $this->requireExists($id);
    if ($this->request->is('POST') or $this->request->is('PUT')) {
      $this->requirePermission('Events', 'Edit');
      $eventIds = $this->requestAssociatedIds('Tag', 'EventIds', 'Event');
      if ($eventIds !== null) $this->requireEventLinkChanges('Events_Tags', 'TagId', $id, $eventIds);
      global $user;
      $this->model->linkValues['Event'] = array('AssignedBy' => $user ? $user->Id() : null);
      $this->model->save($this->withEvents($this->request->data, $eventIds), $id);
    }
    return array('tag' => $this->model->find('first', array('conditions' => array('Tag.Id' => $id), 'recursive' => -1)));
  }

  public function delete($id = null) {
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    $this->requirePermission('Events', 'Edit');
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }

  private function requireExists($id) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid tag');
  }


  /** The data as Tag fields plus the Event link list, without a tag Id. */
  private function withEvents(array $data, $eventIds) {
    $tag = (isset($data['Tag']) and is_array($data['Tag'])) ? $data['Tag'] : array_filter($data, 'is_scalar');
    unset($tag['EventIds'], $tag['Id']);
    $out = array('Tag' => $tag);
    if ($eventIds !== null) $out['Event'] = $eventIds;
    return $out;
  }
}
