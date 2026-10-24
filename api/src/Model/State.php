<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A run state: a saved set of monitor functions (States). */
class State extends Model {
  const TABLE = 'States';
  const ALIAS = 'State';

  protected $validate = array(
    'Name' => array(
      'notBlank' => array('rule' => 'notBlank', 'message' => 'A state needs a name'),
      'isUnique' => array('rule' => 'isUnique', 'message' => 'A state with this name already exists'),
    ),
  );
}
