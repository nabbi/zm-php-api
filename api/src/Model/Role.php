<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A user role: a reusable permission template (User_Roles). */
class Role extends Model {
  const TABLE = 'User_Roles';
  const ALIAS = 'Role';

  protected $hasMany = array(
    'RoleGroupPermission' => array('model' => RoleGroupPermission::class, 'foreignKey' => 'RoleId', 'dependent' => true),
    'RoleMonitorPermission' => array('model' => RoleMonitorPermission::class, 'foreignKey' => 'RoleId', 'dependent' => true),
    'User' => array('model' => User::class, 'foreignKey' => 'RoleId'),
  );
}
