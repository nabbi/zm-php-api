<?php
namespace ZM\Api\Controller;


/**
 * /states: run states, and switching ZoneMinder between them. Needs System=View;
 * changes (and states/change) need System=Edit.
 */
class StatesController extends CrudController {
  const MODEL = \ZM\Api\Model\State::class;
  const PLURAL = 'states';
  const SINGULAR = 'state';
  const NOT_FOUND = 'Invalid state';
  const VIEW_PERMISSION = array('System', 'View');
  const VIEW_RECURSIVE = -1;

  /** {"message": "Saved"}, or the validation errors by field. */
  public function add() {
    $this->allowMethod('POST');
    $this->requireEdit();
    return array('message' => $this->saveMessage($this->request->data));
  }

  public function edit($id = null) {
    $this->requireExists($id);
    $this->requireEdit();
    $this->allowMethod('POST', 'PUT');
    return array('message' => $this->saveMessage($this->withoutPrimaryKey($this->request->data), $id));
  }

  /**
   * states/change/<command>: run zmpkg.pl <command>: start, stop, restart, or the
   * name of a state to switch to. Answers {"blah": <zmpkg.pl's last line>}.
   */
  public function change($command = null) {
    $this->requireEdit();
    \ZM\Api\Auth::requireExplicitForGet($this->request);
    return array('blah' => exec(ZM_PATH_BIN.'/zmpkg.pl '.escapeshellarg((string)$command)));
  }

  private function saveMessage(array $data, $id = null) {
    if ($this->model->save($data, $id)) return 'Saved';
    return $this->model->validationErrors ? $this->model->validationErrors : 'Error';
  }
}
