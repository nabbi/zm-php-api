<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/**
 * A user account (Users). The password hash is never read into answers, also
 * when users are listed under another record (a role's users); a password sent
 * in a save is stored bcrypt-hashed and revokes the user's tokens issued so far
 * (TokenMinExpiry). Saving or deleting one empties the TokenCache, so the
 * change applies to tokens in use at once.
 */
class User extends Model {
  const TABLE = 'Users';
  const ALIAS = 'User';
  const DISPLAY_FIELD = 'Username';

  public $recursive = 1;

  protected $belongsTo = array(
    'Role' => array('model' => Role::class, 'foreignKey' => 'RoleId'),
  );
  protected $validate = array(
    'Username' => array(
      'required' => array('rule' => 'notEmpty', 'message' => 'A username is required', 'required' => true),
      'unique' => array('rule' => 'isUnique', 'message' => 'This username is already in use'),
    ),
    'Password' => array('required' => array('rule' => 'notEmpty', 'message' => 'A password is required', 'required' => true)),
  );

  /** Only the user's own columns: answers have never included the joined Role. */
  protected function defaultFields() {
    return array_map(function ($c) { return 'User.'.$c; }, $this->readColumns());
  }

  protected function readColumns() {
    return array_values(array_diff($this->columns(), array('Password')));
  }

  /** A saved user (permissions, APIEnabled, TokenMinExpiry, ...) applies to tokens in use at once. */
  protected function afterSave($id, $created) {
    \ZM\Api\TokenCache::clear();
  }

  public function delete($id) {
    $deleted = parent::delete($id);
    \ZM\Api\TokenCache::clear();
    return $deleted;
  }

  protected function beforeSave(array $fields, $update) {
    if (!empty($fields['Password'])) {
      $fields['Password'] = password_hash($fields['Password'], PASSWORD_BCRYPT);
      // A password change revokes the user's tokens: validateToken() refuses any
      // token issued before TokenMinExpiry, as the options page's revoke does.
      // Ported from ZoneMinder master (refs GHSA-5w6w-qp25-2qqh, #5455).
      $fields['TokenMinExpiry'] = time();
    }
    return $fields;
  }
}
