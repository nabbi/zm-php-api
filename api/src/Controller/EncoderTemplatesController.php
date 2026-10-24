<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\Response;

/**
 * /encoder_templates: encoder parameter presets. Anyone may read; changes need
 * System=Edit. ?Encoder=<name> limits the list to one encoder. A save that fails
 * validation answers 422 {"message": "Error", "errors": {field: [messages]}}.
 */
class EncoderTemplatesController extends CrudController {
  const MODEL = \ZM\Api\Model\EncoderTemplate::class;
  const PLURAL = 'encoderTemplates';
  const SINGULAR = 'encoderTemplate';
  const NOT_FOUND = 'Invalid encoder template';
  /** 1.38: no EncoderTemplates table (master c4073c964), so no route (Router::route()). */
  const REQUIRES_TABLE = 'EncoderTemplates';
  const INDEX_RECURSIVE = -1;
  const VIEW_RECURSIVE = -1;

  public function index() {
    $conditions = array();
    $encoder = $this->request->query('Encoder');
    if (!empty($encoder)) $conditions['EncoderTemplate.Encoder'] = $encoder;
    return array(static::PLURAL => $this->model->find('all', array(
      'conditions' => $conditions,
      'order' => array('EncoderTemplate.Encoder' => 'ASC', 'EncoderTemplate.Name' => 'ASC'),
    )));
  }

  /** {"message": "Saved", "id": <new id>} */
  public function add() {
    $this->allowMethod('POST');
    $this->requireEdit();
    if (!$this->model->save($this->request->data)) return $this->invalid();
    return array('message' => 'Saved', 'id' => $this->model->id);
  }

  public function edit($id = null) {
    $this->requireEdit();
    $this->requireExists($id);
    if (!$this->model->save($this->withoutPrimaryKey($this->request->data), $id)) return $this->invalid();
    return array('message' => 'Saved');
  }

  public function delete($id = null) {
    $this->requireEdit();
    $this->requireExists($id);
    $this->allowMethod('POST', 'DELETE');
    $this->model->delete($id);
    return array('message' => 'Deleted');
  }

  private function invalid() {
    return Response::data(array('message' => 'Error', 'errors' => $this->model->validationErrors),
      $this->request->format, 422);
  }
}
