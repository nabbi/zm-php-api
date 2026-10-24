<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A named setting a user saved for themselves, e.g. a montage layout (User_Preferences). */
class UserPreference extends Model {
  const TABLE = 'User_Preferences';
  const ALIAS = 'UserPreference';

  protected $belongsTo = array(
    'User' => array('model' => User::class, 'foreignKey' => 'UserId'),
  );
  protected $validate = array(
    'UserId' => array(array('rule' => 'numeric')),
  );
}
