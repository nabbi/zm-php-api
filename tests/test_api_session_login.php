<?php
// Tests the API's stateful login (stateful=1 with ZM_OPT_USE_LEGACY_API_AUTH on)
// and the credentials it hands zmu, with ZM_AUTH_RELAY=plain, the mode that
// used to keep the password around:
//
//   - a login issues a new session id, so an id planted in the client before it
//     (CWE-384) doesn't become the logged-in session
//   - the session doesn't store the password (CWE-256)
//   - zmu gets a short-lived token, never the password: its arguments are
//     visible to every local user in the process list
//
// Run: php tests/php/test_api_session_login.php   (from the repo root)

define('ZM_OPT_USE_LEGACY_API_AUTH', true);
define('ZM_AUTH_RELAY', 'plain');

require_once __DIR__.'/support/api.php';

$password = 'correct horse';
$users = array(
  'admin' => array_merge(userRow('admin', array('Monitors' => 'Edit', 'System' => 'Edit')),
    array('Password' => password_hash($password, PASSWORD_BCRYPT))),
  'viewer' => userRow('viewer', array('Monitors' => 'View')),
);
// Sessions as the database holds them, by id.
$sessions = array('planted0123456789abcdef012345678' => 'username|s:6:"viewer";');
$db = new FakeDb();
$db->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/^SELECT 1 FROM Sessions WHERE id = :id/', function ($sql, $p) use (&$sessions) {
    return isset($sessions[$p[0]]) ? array(array(1)) : array();
  })
  ->on('/^SELECT data FROM Sessions WHERE id = :id/', function ($sql, $p) use (&$sessions) {
    return isset($sessions[$p[0]]) ? array(array('data' => $sessions[$p[0]])) : array();
  })
  ->on('/^REPLACE INTO Sessions/', function ($sql, $p) use (&$sessions) { $sessions[$p[0]] = $p[2]; return array(); })
  ->on('/^DELETE FROM Sessions WHERE Id = :id/', function ($sql, $p) use (&$sessions) { unset($sessions[$p[0]]); return array(); })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array());
apiBoot($db);

/** A request carrying session cookie $sid (PHP takes the id from session_id() outside a web server). */
function withSession($sid, $method, $uri, array $form = array()) {
  if (session_status() == PHP_SESSION_ACTIVE) session_write_close();
  session_id($sid);
  return api($method, $uri, null, $form, array('ZMSESSID' => $sid));
}

echo "stateful login carrying a planted session id\n";
$planted = 'planted0123456789abcdef012345678';
$a = answered(withSession($planted, 'POST', 'host/login.json', array('user' => 'admin', 'pass' => $password, 'stateful' => '1')));
check('login succeeds', $a[0], 200);
check('stream credentials name the user', array($a[1]['credentials'], $a[1]['append_password']), array('user=admin&pass=', 1));
$sid = session_id();
check('the login gets a new session id', $sid !== $planted and $sid !== '', true);
check('the planted session is gone', isset($sessions[$planted]), false);
check('the new session is stored, as admin', isset($sessions[$sid]) and strpos($sessions[$sid], 'username|s:5:"admin"') !== false, true);
check('no session holds the password', count(array_filter($sessions, function ($d) use ($password) {
  return strpos($d, 'password') !== false or strpos($d, $password) !== false;
})), 0);

echo "zmu credentials for that session\n";
$GLOBALS['user'] = null;
withSession($sid, 'GET', 'host/getVersion.json');
$args = \ZM\Api\Auth::zmuCredentials();
check('a token, nothing else', preg_match("/^ -T '[^']+'$/", $args), 1);
check('not the password', strpos($args, $password), false);
preg_match("/^ -T '([^']+)'$/", $args, $m);
$claims = (array)\Firebase\JWT\JWT::decode($m[1], ZM_AUTH_HASH_SECRET, array('HS256'));
check('an access token for the session user', array($claims['user'], $claims['type']), array('admin', 'access'));
check('that expires within a minute', $claims['exp'] - $claims['iat'] <= 60, true);

echo "stateful request with a token carrying a planted session id\n";
$planted = 'planted1123456789abcdef012345678';
$sessions[$planted] = '';
$access = \ZM\Api\Auth::jwt('viewer', 'access', 3600);
$a = answered(withSession($planted, 'GET', 'host/getVersion.json?stateful=1&token='.$access));
check('the request succeeds', $a[0], 200);
$sid = session_id();
check('the session gets a new id', $sid !== $planted and $sid !== '', true);
check('the planted session is gone', isset($sessions[$planted]), false);
check('the new session is stored, as viewer', isset($sessions[$sid]) and strpos($sessions[$sid], 'username|s:6:"viewer"') !== false, true);

echo "a stateful request that is refused stores no user in the planted session\n";
$planted = 'planted2123456789abcdef012345678';
$sessions[$planted] = '';
$a = answered(withSession($planted, 'POST', 'host/login.json', array('stateful' => '1', 'token' => $access)));
check('an access token on host/login is refused', $a[0], 401);
check('the planted session holds no user', isset($sessions[$planted]) ? strpos($sessions[$planted], 'username') : false, false);

echo "a password and another user's refresh token, both spellings\n";
$refresh = \ZM\Api\Auth::jwt('admin', 'refresh', 3600);
$a = answered(api('POST', 'host/login.json', null, array('user' => 'viewer', 'pass' => $password, 'token' => $refresh)));
check('user/pass with a refresh token is refused', $a[0], 401);
$a = answered(api('POST', 'host/login.json', null, array('username' => 'nobody', 'password' => 'wrong', 'token' => $refresh)));
check('username/password with a refresh token is refused', $a[0], 401);
check('no tokens were minted', isset($a[1]['refresh_token']), false);
$a = answered(api('POST', 'host/login.json', null, array('token' => $refresh)));
check('the refresh token alone still gives an access token', array($a[0], isset($a[1]['access_token'])), array(200, true));

done();
