<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A monitor group (Groups), its monitors linked through Groups_Monitors. */
class Group extends Model {
  const TABLE = 'Groups';
  const ALIAS = 'Group';

  protected $habtm = array(
    'Monitor' => array('model' => Monitor::class, 'joinTable' => 'Groups_Monitors', 'foreignKey' => 'GroupId',
      'associationForeignKey' => 'MonitorId', 'with' => 'GroupsMonitor'),
  );
  protected $validate = array(
    'Name' => array(array('rule' => 'notBlank')),
    'Id' => array(array('rule' => 'numeric')),
  );
}
