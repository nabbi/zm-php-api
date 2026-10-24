<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A ZoneMinder server of a multi-server install (Servers). */
class Server extends Model {
  const TABLE = 'Servers';
  const ALIAS = 'Server';

  protected $hasMany = array(
    'Monitor' => array('model' => Monitor::class, 'foreignKey' => 'ServerId'),
  );
  protected $validate = array(
    'Id' => array(array('rule' => 'numeric')),
  );
}
