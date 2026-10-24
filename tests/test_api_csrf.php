<?php
// Tests the API's defence against cross-site request forgery (api/src/Auth.php)
// through the whole API in this process. The user is authenticated by
// REMOTE_USER (ZM_AUTH_TYPE=remote), as HTTP basic auth at the web server
// would: credentials a browser sends on its own, to any site's form too.
//
// What must hold:
//   - a POST/PUT/DELETE authenticated only that way is refused (403) when the
//     browser says it comes from another origin (Origin, else Sec-Fetch-Site)
//   - it passes from this origin, from another server of the install (CORS),
//     and without either header (scripts and old clients)
//   - a token, or a password in the request, is not ambient: cross-origin is fine
//   - a state-changing GET authenticated that way is refused, also when the
//     request carries an auth= the server ignores (ZM_AUTH_HASH_LOGINS off)
//
// Run: tests/run.sh test_api_csrf.php

define('ZM_AUTH_TYPE', 'remote');
define('ZM_AUTH_HASH_LOGINS', false);
require_once __DIR__.'/support/api.php';

$users = array('admin' => array_merge(userRow('admin', array('System' => 'Edit', 'Monitors' => 'Edit', 'Events' => 'Edit')),
  array('Password' => password_hash('admin-pw', PASSWORD_BCRYPT))));
$db = new FakeDb();
$db->table('Users', array('Id' => 'int(10) unsigned', 'Username' => 'varchar(32)', 'Password' => 'varchar(64)', 'Name' => 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND (LOWER\()?Username/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Users`/', array(array('COUNT(*)' => 0)))
  ->on('/^(INSERT|UPDATE|DELETE)/', array())
  ->on('/./', array());
apiBoot($db);

function writes(FakeDb $db) {
  return count(array_filter($db->queries, function ($q) { return preg_match('/^(INSERT|UPDATE|DELETE)/', $q[0]); }));
}
/** A request authenticated by REMOTE_USER, with extra $server headers. */
function ambient($method, $uri, array $server, array $form = array()) {
  return api($method, $uri, null, $form, array(), $server + array('REMOTE_USER' => 'admin', 'HTTP_HOST' => 'zm.example'));
}
$newUser = array('User' => array('Username' => 'x', 'Password' => 'y', 'System' => 'Edit'));

echo "cross-origin writes, browser credentials\n";
$db->queries = array();
$a = answered(ambient('POST', 'users.json', array('HTTP_ORIGIN' => 'https://evil.example'), $newUser));
check('Origin of another site: 403, nothing written', array($a[0], writes($db)), array(403, 0));
$a = answered(ambient('POST', 'users.json', array('HTTP_SEC_FETCH_SITE' => 'cross-site'), $newUser));
check('Sec-Fetch-Site cross-site: 403', $a[0], 403);
$a = answered(ambient('POST', 'users.json', array('HTTP_SEC_FETCH_SITE' => 'same-site'), $newUser));
check('Sec-Fetch-Site same-site (a sibling subdomain): 403', $a[0], 403);
$a = answered(ambient('POST', 'users.json', array('HTTP_ORIGIN' => 'null'), $newUser));
check('Origin null (sandboxed frame): 403', $a[0], 403);
$a = answered(ambient('POST', 'users.json', array('HTTP_ORIGIN' => 'https://zm.example.evil.example'), $newUser));
check('Origin with this host as a prefix: 403', $a[0], 403);
$a = answered(ambient('DELETE', 'users/5.json', array('HTTP_ORIGIN' => 'https://evil.example')));
check('DELETE: 403', $a[0], 403);
$a = answered(ambient('POST', 'users/5.json', array('HTTP_ORIGIN' => 'https://evil.example'), array('_method' => 'DELETE')));
check('POST with _method=DELETE: 403', $a[0], 403);

echo "credentials the server never checked don't make a request explicit\n";
foreach (array(
    'data[auth]' => array('data' => array('auth' => 'junk')),
    'data[username]/data[password]' => array('data' => array('username' => 'admin', 'password' => 'anything')),
    'data[user]/data[pass]' => array('data' => array('user' => 'admin', 'pass' => 'anything')),
  ) as $label => $extra) {
  $db->queries = array();
  $a = answered(ambient('POST', 'users.json', array('HTTP_ORIGIN' => 'https://evil.example'), array_merge_recursive($newUser, $extra)));
  check("$label from another origin: 403, nothing written", array($a[0], writes($db)), array(403, 0));
}

foreach (array('user=admin&pass=0' => array('user' => 'admin', 'pass' => '0'),
    'username=admin&password=0' => array('username' => 'admin', 'password' => '0')) as $label => $extra) {
  $db->queries = array();
  $a = answered(ambient('POST', 'users.json', array('HTTP_ORIGIN' => 'https://evil.example'), $newUser + $extra));
  check("$label (\"0\" is no password to ZoneMinder) from another origin: 403, nothing written", array($a[0], writes($db)), array(403, 0));
}
$a = answered(ambient('GET', 'states/change/stop.json?user=admin&pass=0', array()));
check('GET states/change with pass=0 by browser credentials: 403', $a[0], 403);

echo "method overrides\n";
foreach (array('HEAD', 'OPTIONS', 'head', 'GET') as $override) {
  $a = answered(ambient('POST', 'states/change/stop.json', array('HTTP_ORIGIN' => 'https://evil.example'), array('_method' => $override)));
  check("cross-site POST overridden to $override: 403", $a[0], 403);
}
$a = answered(ambient('POST', 'states/change/stop.json', array('HTTP_ORIGIN' => 'https://zm.example'), array('_method' => 'HEAD')));
check('same-origin POST overridden to HEAD on a state change: still 403 (send a POST)', $a[0], 403);

echo "allowed\n";
$db->queries = array();
$a = answered(ambient('POST', 'users.json', array('HTTP_ORIGIN' => 'https://zm.example'), $newUser));
check('this origin: saved', array($a[0], writes($db) > 0), array(200, true));
$a = answered(ambient('POST', 'users.json', array('HTTP_SEC_FETCH_SITE' => 'same-origin'), $newUser));
check('Sec-Fetch-Site same-origin: 200', $a[0], 200);
$a = answered(ambient('POST', 'users.json', array(), $newUser));
check('neither header (a script): 200', $a[0], 200);
$a = answered(ambient('GET', 'users.json', array('HTTP_ORIGIN' => 'https://evil.example')));
check('a read from another origin is not refused here (CORS keeps the answer from it)', $a[0], 200);
$a = answered(api('POST', 'users.json', 'admin', $newUser, array(), array('HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'zm.example')));
check('a token: not ambient, 200', $a[0], 200);
$a = answered(api('POST', 'users.json', null, $newUser + array('user' => 'admin', 'pass' => 'admin-pw'), array(),
  array('HTTP_ORIGIN' => 'https://evil.example', 'HTTP_HOST' => 'zm.example')));
check('a password in the request: not ambient, 200', $a[0], 200);

echo "state-changing GETs\n";
$a = answered(ambient('GET', 'states/change/stop.json', array()));
check('GET states/change by browser credentials: 403', $a[0], 403);
$a = answered(ambient('GET', 'states/change/stop.json?auth=0123456789abcdef', array()));
check('with an auth= the server ignores: still 403', $a[0], 403);

done();
