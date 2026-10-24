<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A storage area for events (Storage). */
class Storage extends Model {
  const TABLE = 'Storage';
  const ALIAS = 'Storage';

  protected $belongsTo = array(
    'Server' => array('model' => Server::class, 'foreignKey' => 'ServerId'),
  );
  protected $validate = array(
    'Id' => array(array('rule' => 'numeric')),
  );
}
