<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A role's permission on a group of monitors (Role_Groups_Permissions). */
class RoleGroupPermission extends Model {
  const TABLE = 'Role_Groups_Permissions';
  const ALIAS = 'RoleGroupPermission';

  protected $belongsTo = array(
    'Role' => array('model' => Role::class, 'foreignKey' => 'RoleId'),
    'Group' => array('model' => Group::class, 'foreignKey' => 'GroupId'),
  );
}
