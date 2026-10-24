<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** Named encoder parameter presets, per encoder (EncoderTemplates). */
class EncoderTemplate extends Model {
  const TABLE = 'EncoderTemplates';
  const ALIAS = 'EncoderTemplate';

  protected $validate = array(
    'Encoder' => array('notBlank' => array('rule' => 'notBlank', 'message' => 'Encoder is required')),
    'Name' => array(
      'notBlank' => array('rule' => 'notBlank', 'message' => 'Name is required', 'last' => true),
      'unique' => array('rule' => 'isUniqueByEncoder', 'message' => 'A template with that name already exists for this encoder'),
    ),
  );

  /** No other template of the same encoder has this name. */
  public function isUniqueByEncoder($name, array $fields) {
    if (empty($fields['Encoder'])) return true; // Encoder's own rule reports that
    $conditions = array('EncoderTemplate.Encoder' => $fields['Encoder'], 'EncoderTemplate.Name' => $name);
    if (!empty($this->id)) $conditions['EncoderTemplate.Id !='] = $this->id;
    return $this->find('count', array('conditions' => $conditions)) === 0;
  }
}
