<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A push-notification registration of a client device (Notifications). */
class Notification extends Model {
  const TABLE = 'Notifications';
  const ALIAS = 'Notification';
  const DISPLAY_FIELD = 'Token';

  public $recursive = 1;

  protected $belongsTo = array(
    'User' => array('model' => User::class, 'foreignKey' => 'UserId'),
  );
  protected $validate = array(
    'Token' => array('notBlank' => array('rule' => 'notBlank', 'message' => 'Token is required')),
    'Platform' => array('inList' => array('rule' => array('inList', array('android', 'ios', 'web')),
      'message' => 'Platform must be android, ios, or web')),
    'PushState' => array('inList' => array('rule' => array('inList', array('enabled', 'disabled')),
      'message' => 'PushState must be enabled or disabled')),
  );
}
