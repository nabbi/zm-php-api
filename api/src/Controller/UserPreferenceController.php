<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Model\UserPreference;

/**
 * /user_preference: a user's saved settings (the montage layout, ...). A
 * preference belongs to the user who set it: everyone manages their own, and
 * System=Edit manages everyone's.
 */
class UserPreferenceController extends Controller {
  /** @var UserPreference */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new UserPreference();
  }

  /** Name:value filters; a non-admin sees only their own. */
  public function index() {
    $conditions = \ZM\Api\Filter::conditions($this->route->named, $this->model);
    global $user;
    if ($user and $user->System() != 'Edit') $conditions['UserPreference.UserId'] = $user->Id();
    return array('user_preferences' => $this->model->find('all', array('conditions' => $conditions, 'recursive' => -1)));
  }

  public function view($id = null) {
    $this->requireExists($id, 'Invalid user preference');
    $this->requireOwned($id);
    return array('user_preference' => $this->model->find('first', array(
      'conditions' => array('UserPreference.Id' => $id), 'recursive' => -1)));
  }

  /**
   * Set the caller's preference Name to Value (replacing it if set):
   * {"message": "Success"} or "Failure". The UserId is always the caller's.
   */
  public function add() {
    if (!$this->request->is('POST')) {
      \ZM\Error('NOT POST in add()');
      return array('message' => '');
    }
    $record = $this->record(array('Name', 'Value'));
    global $user;
    if ($user) $record['UserId'] = $user->Id();
    $existing = $this->model->find('first', array('conditions' => array(
      'UserId' => isset($record['UserId']) ? $record['UserId'] : null,
      'Name' => isset($record['Name']) ? $record['Name'] : null,
    ), 'recursive' => -1));
    $id = $existing ? $existing['UserPreference']['Id'] : null;
    if ($this->model->save(array('UserPreference' => $record), $id)) return array('message' => 'Success');
    \ZM\Warning(print_r($this->model->validationErrors, true));
    return array('message' => 'Failure');
  }

  /** {"message": "Saved"} or "Error". */
  public function edit($id = null) {
    $this->requireExists($id, 'Invalid user_preference');
    $this->requireOwned($id);
    $this->allowMethod('POST', 'PUT');
    global $user;
    $record = $this->record(($user and $user->System() != 'Edit') ? array('Name', 'Value') : array('Name', 'Value', 'UserId'));
    return array('message' => $this->model->save(array('UserPreference' => $record), $id) ? 'Saved' : 'Error');
  }

  /**
   * The request's preference record ({"UserPreference": {...}} or bare fields),
   * with only $fields: never an Id, and a UserId only where $fields allows it.
   * @throws BadRequestException for a value that is not a string or number
   */
  private function record(array $fields) {
    $data = $this->request->data;
    if (isset($data['UserPreference']) and is_array($data['UserPreference'])) $data = $data['UserPreference'];
    $record = array_intersect_key($data, array_flip($fields));
    foreach ($record as $name => $value) {
      if (!is_scalar($value) and $value !== null) throw new BadRequestException("Invalid $name");
    }
    return $record;
  }

  public function delete($id = null) {
    $this->requireExists($id, 'Invalid user_preference');
    $this->requireOwned($id);
    $this->allowMethod('POST', 'DELETE');
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }

  private function requireExists($id, $message) {
    if (!$this->model->exists($id)) throw new NotFoundException($message);
  }

  private function requireOwned($id) {
    global $user;
    if (!$user or $user->System() == 'Edit') return;
    $row = $this->model->find('first', array('conditions' => array('UserPreference.Id' => $id), 'recursive' => -1));
    if (!$row or $row['UserPreference']['UserId'] != $user->Id()) {
      throw new UnauthorizedException('Insufficient Privileges');
    }
  }
}
