<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\Notification;

/**
 * /notifications: push-notification registrations of client devices. Each user
 * manages their own; System=Edit manages everyone's. Registering a Token that is
 * already registered updates that registration.
 */
class NotificationsController extends Controller {
  /** 1.38: no Notifications table (master 2fce145a9), so no route (Router::route()). */
  const REQUIRES_TABLE = 'Notifications';

  /** @var Notification */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Notification();
  }

  public function index() {
    $conditions = $this->isAdmin() ? array() : array('Notification.UserId' => $this->userId());
    return array('notifications' => $this->model->find('all', array('conditions' => $conditions, 'recursive' => -1)));
  }

  public function view($id = null) {
    return array('notification' => $this->owned($id));
  }

  /** {"notification": row}; a validation failure answers 400 with the errors. */
  public function add() {
    if (!$this->request->is('POST')) throw new BadRequestException('POST required');
    $data = $this->record();
    if (!$this->isAdmin() or !isset($data['UserId'])) $data['UserId'] = $this->userId();
    if (!isset($data['CreatedOn'])) $data['CreatedOn'] = date('Y-m-d H:i:s');
    $id = null;
    if (isset($data['Token'])) {
      $existing = $this->model->find('first', array('conditions' => array('Notification.Token' => $data['Token']), 'recursive' => -1));
      if ($existing) {
        if (!$this->isAdmin() and $existing['Notification']['UserId'] != $this->userId()) {
          throw new UnauthorizedException('Token belongs to another user');
        }
        $id = $existing['Notification']['Id'];
        unset($data['CreatedOn']);
      }
    }
    unset($data['Id']);
    return $this->saveAndAnswer($data, $id);
  }

  public function edit($id = null) {
    $this->requireExists($id);
    $this->allowMethod('POST', 'PUT');
    $this->owned($id);
    $data = $this->record();
    if (!$this->isAdmin()) unset($data['UserId']);
    unset($data['Id']);
    return $this->saveAndAnswer($data, $id);
  }

  public function delete($id = null) {
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    $this->owned($id);
    if (!$this->model->delete($id)) {
      return Response::data(array('message' => 'Could not delete notification'), $this->request->format, 400);
    }
    return array('message' => 'Notification deleted');
  }

  private function saveAndAnswer(array $data, $id) {
    if (!$this->model->save(array('Notification' => $data), $id)) {
      return Response::data(array('message' => 'Could not save notification', 'errors' => $this->model->validationErrors),
        $this->request->format, 400);
    }
    return array('notification' => $this->model->find('first', array(
      'conditions' => array('Notification.Id' => $this->model->id), 'recursive' => -1)));
  }

  /** The row, if the user may manage it. */
  private function owned($id) {
    $this->requireExists($id);
    $row = $this->model->find('first', array('conditions' => array('Notification.Id' => $id), 'recursive' => -1));
    if (!$this->isAdmin() and $row['Notification']['UserId'] != $this->userId()) {
      throw new UnauthorizedException('Insufficient Privileges');
    }
    return $row;
  }

  private function requireExists($id) {
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid notification');
  }

  private function record() {
    $data = $this->request->data;
    return (isset($data['Notification']) and is_array($data['Notification'])) ? $data['Notification'] : $data;
  }

  private function isAdmin() {
    global $user;
    return !$user or $user->System() == 'Edit';
  }

  private function userId() {
    global $user;
    return $user ? $user->Id() : null;
  }
}
