<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A PTZ control definition: how to drive a camera's pan/tilt/zoom (Controls). */
class Control extends Model {
  const TABLE = 'Controls';
  const ALIAS = 'Control';

  protected $validate = array(
    'Id' => array(array('rule' => 'numeric')),
  );
}
