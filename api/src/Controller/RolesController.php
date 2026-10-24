<?php
namespace ZM\Api\Controller;

/**
 * /roles: user roles. Needs System=View; changes need System=Edit. A role's view
 * lists its group and monitor permissions and its users.
 */
class RolesController extends CrudController {
  const MODEL = \ZM\Api\Model\Role::class;
  const PLURAL = 'roles';
  const SINGULAR = 'role';
  const NOT_FOUND = 'Invalid role';
  const VIEW_PERMISSION = array('System', 'View');
  const EDIT_MESSAGE = 'Insufficient Privileges';
  const VIEW_RECURSIVE = 1;

  /** {"message": "Saved"} or "Error", or the validation errors by field. */
  public function add() {
    if (!$this->request->is('POST')) return array('message' => 'Add without post data');
    $this->requireEdit();
    return array('message' => $this->saveMessage($this->request->data));
  }

  public function edit($id = null) {
    $this->requireEdit();
    $this->requireExists($id);
    $this->allowMethod('POST', 'PUT');
    return array('message' => $this->saveMessage($this->withoutPrimaryKey($this->request->data), $id));
  }

  public function delete($id = null) {
    $this->requireEdit();
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    return array('message' => $this->model->delete($id)
      ? 'The role has been deleted.' : 'The role could not be deleted. Please, try again.');
  }

  private function saveMessage(array $data, $id = null) {
    if ($this->model->save($data, $id)) return 'Saved';
    return $this->model->validationErrors ? $this->model->validationErrors : 'Error';
  }
}
