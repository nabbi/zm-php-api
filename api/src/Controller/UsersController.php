<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\Response;
use ZM\Api\Http\UnauthorizedException;
use ZM\Api\Paginator;

/**
 * /users: user accounts. Needs System=View; changes need System=Edit. With
 * ZM_USER_SELF_EDIT on, a user may also view and edit their own account whatever
 * their System permission, but change only what the web UI lets them change
 * about themselves (SELF_EDITABLE, includes/actions/user.php), never their
 * permissions. Password hashes are never answered.
 *
 * The index is paged with users/index/page:N/limit:M: {"users": [...], "pagination": {...}}.
 */
class UsersController extends CrudController {
  const MODEL = \ZM\Api\Model\User::class;
  const PLURAL = 'users';
  const SINGULAR = 'user';
  const NOT_FOUND = 'Invalid user';
  const VIEW_PERMISSION = array('System', 'View');
  const EDIT_MESSAGE = 'Insufficient Privileges';
  const VIEW_RECURSIVE = 1;

  /** What a user may change on their own account: what the web UI allows. */
  const SELF_EDITABLE = array('Password', 'Language', 'HomeView');

  /** System=None lets a user reach only their own record, and only while self edit is on. */
  public function beforeFilter() {
    Controller::beforeFilter(); // authenticates: isSelf() needs the user
    if (!$this->isSelf()) {
      $this->requirePermission(self::VIEW_PERMISSION[0], self::VIEW_PERMISSION[1], self::VIEW_MESSAGE);
    }
  }

  public function index() {
    $paginator = new Paginator(array('recursive' => 0));
    list($rows, $paging) = $paginator->paginate($this->model, $this->route->named, $this->request->query);
    return array('users' => $rows, 'pagination' => $paging);
  }

  /**
   * {"message": "Saved"}. Validation failures (no or duplicate Username, no
   * Password) answer 400 with {"message": {field: [messages]}}.
   */
  public function add() {
    if (!$this->request->is('POST')) return array('message' => 'Add without post data');
    $this->requireEdit();
    $this->model->id = null;
    return $this->saveAnswer($this->withoutPrimaryKey($this->request->data), null);
  }

  public function edit($id = null) {
    global $user;
    $self = ($user and $user->Id() == $id and ZM_USER_SELF_EDIT);
    if ($user and $user->System() != 'Edit' and !$self) {
      throw new UnauthorizedException('Insufficient Privileges');
    }
    $this->requireExists($id);
    // Only a POST or PUT changes anything; any other method answers this.
    if (!$this->request->is('POST') and !$this->request->is('PUT')) return array('message' => null);
    $data = $this->withoutPrimaryKey($this->request->data);
    if ($user and $user->System() != 'Edit') {
      $data = array('User' => $this->selfEditable($data));
      if (!count($data['User'])) return array('message' => 'Error');
    }
    return $this->saveAnswer($data, $id);
  }

  /** A user can't delete their own account. */
  public function delete($id = null) {
    global $user;
    if ($user and ($user->System() != 'Edit' or $user->Id() == $id)) {
      throw new UnauthorizedException('Insufficient Privileges');
    }
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    return array('message' => $this->model->delete($id)
      ? 'The user has been deleted.' : 'The user could not be deleted. Please, try again.');
  }

  /** True for users/view or users/edit of the logged in user's own record while ZM_USER_SELF_EDIT is on. */
  private function isSelf() {
    global $user;
    if (!$user or !ZM_USER_SELF_EDIT) return false;
    if (!in_array(strtolower($this->route->action), array('view', 'edit'), true)) return false;
    return isset($this->route->pass[0]) and is_scalar($this->route->pass[0])
      and (string)$this->route->pass[0] === (string)$user->Id();
  }

  /**
   * The SELF_EDITABLE fields of a self edit, as the web UI takes them: an empty
   * Password leaves the password alone; a Language must name a lang file.
   * @throws BadRequestException for a Language with no lang file
   */
  private function selfEditable(array $data) {
    $record = (isset($data['User']) and is_array($data['User'])) ? $data['User'] : $data;
    $fields = array_intersect_key($record, array_flip(self::SELF_EDITABLE));
    foreach ($fields as $name => $value) {
      if (!is_scalar($value)) throw new BadRequestException("Invalid $name");
    }
    if (isset($fields['Password']) and (string)$fields['Password'] === '') unset($fields['Password']);
    if (isset($fields['Language']) and (string)$fields['Language'] !== '') {
      // A Language names a file the web UI includes: only a lang file's own name.
      if (!preg_match('/^[a-z0-9]{2,5}_[a-z0-9]{2,5}$/', $fields['Language'])
          or !is_file(ZM_PATH_WEB.'/lang/'.$fields['Language'].'.php')) {
        throw new BadRequestException('Invalid Language');
      }
    }
    return $fields;
  }

  private function saveAnswer(array $data, $id) {
    if ($this->model->save($data, $id)) return array('message' => 'Saved');
    if ($this->model->validationErrors) {
      return Response::data(array('message' => $this->model->validationErrors), $this->request->format, 400);
    }
    return array('message' => 'Error');
  }
}
