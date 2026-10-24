<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A role's permission on one monitor (Role_Monitors_Permissions). */
class RoleMonitorPermission extends Model {
  const TABLE = 'Role_Monitors_Permissions';
  const ALIAS = 'RoleMonitorPermission';

  protected $belongsTo = array(
    'Role' => array('model' => Role::class, 'foreignKey' => 'RoleId'),
    'Monitor' => array('model' => Monitor::class, 'foreignKey' => 'MonitorId'),
  );
}
