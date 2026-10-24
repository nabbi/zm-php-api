<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\Group;

/**
 * /groups: monitor groups and their members. Needs Groups=View; changes need
 * Groups=Edit. Membership decides per-monitor access, so changing a group's
 * members (or moving the group, or deleting it) also needs edit rights on every
 * monitor that moves. Monitors the user may not view are left out of member lists.
 *
 * Members are sent as Group[MonitorIds]=1,2,3 or Monitor[]=1&Monitor[]=2.
 */
class GroupsController extends Controller {
  /** @var Group */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Group();
  }

  public function beforeFilter() {
    parent::beforeFilter();
    $this->requirePermission('Groups', 'View');
    require_once __DIR__.'/../../../includes/Group.php';
  }

  /** Each group with its member monitors (all their columns). Name:value filters. */
  public function index() {
    $groups = $this->model->find('all', array(
      'conditions' => \ZM\Api\Filter::conditions($this->route->named, $this->model, array('bare' => array('MonitorId'))),
      'recursive' => 0,
      'contain' => array('Monitor' => true),
      'joins' => array(array('table' => 'Groups_Monitors', 'conditions' => '`Groups_Monitors`.`GroupId` = `Group`.`Id`')),
      'group' => 'Group.Id',
    ));
    return array('groups' => $this->filterMembers($groups));
  }

  /** groups/associations: each group with its members' Id and Name. */
  public function associations() {
    $groups = $this->model->find('all', array('recursive' => -1, 'contain' => array('Monitor' => array('Id', 'Name'))));
    return array('groups' => $this->filterMembers($groups));
  }

  public function view($id = null) {
    $this->requireExists($id);
    return array('group' => $this->model->find('first', array('conditions' => array('Group.Id' => $id), 'recursive' => -1)));
  }

  public function add() {
    $this->allowMethod('POST');
    $this->requirePermission('Groups', 'Edit');
    $ids = $this->requestAssociatedIds('Group', 'MonitorIds', 'Monitor');
    if ($ids) $this->requireMembershipEdit($ids);
    if (!$this->model->save($this->withMembers($this->request->data))) {
      \ZM\Error('Failed to save Group');
      throw new \ZM\Api\Http\BadRequestException('Invalid '.implode(', ', array_keys($this->model->validationErrors)));
    }
    return Response::nothing($this->request->format);
  }

  /** {"group": the group as stored} */
  public function edit($id = null) {
    $this->requireExists($id);
    if ($this->request->is('POST') or $this->request->is('PUT')) {
      $this->requirePermission('Groups', 'Edit');
      $group = new \ZM\Group($id);
      $moved = array();
      $newIds = $this->requestAssociatedIds('Group', 'MonitorIds', 'Monitor');
      if ($newIds !== null) {
        $oldIds = dbFetchAll('SELECT `MonitorId` FROM `Groups_Monitors` WHERE `GroupId`=?', 'MonitorId', array($id));
        $moved = array_merge(array_diff($oldIds, $newIds), array_diff($newIds, $oldIds));
      }
      // Moving the group moves every monitor of it and its children between ancestors.
      $parentId = $this->requestField('Group', 'ParentId');
      if ($parentId !== null and $parentId != $group->ParentId()) {
        $moved = array_merge($moved, $group->MonitorIds());
      }
      $this->requireMembershipEdit($moved);
      $this->model->save($this->withMembers($this->request->data), $id);
    }
    return array('group' => $this->model->find('first', array('conditions' => array('Group.Id' => $id), 'recursive' => -1)));
  }

  /** Deleting a group removes its monitors from it, which can lift a deny on them. */
  public function delete($id = null) {
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    $this->requirePermission('Groups', 'Edit');
    $group = new \ZM\Group($id);
    $this->requireMembershipEdit($group->MonitorIds());
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }

  private function requireExists($id) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid group');
  }


  private function requireMembershipEdit(array $monitorIds) {
    if (!\ZM\Group::canEditMembership(array_map('intval', $monitorIds))) {
      throw new UnauthorizedException('Insufficient Privileges');
    }
  }

  /**
   * The data with the member list as requestAssociatedIds() read it (the list the
   * membership check saw) as the Monitor link list, or none, and no group Id.
   * Whatever else the client sent as Monitor is dropped: {"Monitor": null} is no
   * list to the check, so it must not be one (an empty one) to the save.
   */
  private function withMembers(array $data) {
    $ids = $this->requestAssociatedIds('Group', 'MonitorIds', 'Monitor');
    if (isset($data['Group']) and is_array($data['Group'])) unset($data['Group']['MonitorIds'], $data['Group']['Id']);
    unset($data['MonitorIds'], $data['Id'], $data['Monitor']);
    if ($ids !== null) $data['Monitor'] = $ids;
    return $data;
  }

  /** Each group's Monitor list without the monitors the user may not view. */
  private function filterMembers(array $groups) {
    $condition = $this->viewableMonitorCondition('Monitor.Id');
    if (!count($condition)) return $groups;
    $allowed = $condition['Monitor.Id'];
    foreach ($groups as &$group) {
      if (!isset($group['Monitor'])) continue;
      $group['Monitor'] = array_values(array_filter($group['Monitor'], function ($m) use ($allowed) {
        return in_array($m['Id'], $allowed);
      }));
    }
    return $groups;
  }
}
