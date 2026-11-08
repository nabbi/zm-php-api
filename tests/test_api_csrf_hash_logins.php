<?php
// Tests the cross-origin write check (api/src/Auth.php) with ZM_AUTH_HASH_LOGINS
// on: an auth= hash makes a request explicit only when zm_authenticate_request()
// used it, i.e. it is a top-level parameter, not data[auth]. The user is
// authenticated by REMOTE_USER (ZM_AUTH_TYPE=remote). See test_api_csrf.php.
//
// Run: tests/run.sh test_api_csrf_hash_logins.php

define('ZM_AUTH_TYPE', 'remote');
define('ZM_AUTH_HASH_LOGINS', true);
require_once __DIR__.'/support/api.php';

$users = array('admin' => userRow('admin', array('System' => 'Edit')));
$db = new FakeDb();
$db->table('Users', USERS_COLUMNS)
  ->on('/FROM Users WHERE Enabled=1 AND (LOWER\()?Username/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Users`/', array(array('COUNT(*)' => 0)))
  ->on('/^(INSERT|UPDATE|DELETE)/', array())
  ->on('/./', array());
apiBoot($db);

$newUser = array('User' => array('Username' => 'x', 'Password' => 'y', 'System' => 'Edit'));
$server = array('REMOTE_USER' => 'admin', 'HTTP_HOST' => 'zm.example', 'HTTP_ORIGIN' => 'https://evil.example');

$a = answered(api('POST', 'users.json', null, $newUser + array('data' => array('auth' => 'junk')), array(), $server));
check('data[auth] (never checked) from another origin: 403', $a[0], 403);
$a = answered(api('POST', 'users.json', null, $newUser + array('auth' => '0'), array(), $server));
check('auth=0 (empty to ZoneMinder) from another origin: 403', $a[0], 403);
$a = answered(api('POST', 'users.json', null, $newUser + array('auth' => 'junk'), array(), $server));
check('a top-level auth= that does not authenticate: 401, not an ambient pass', $a[0], 401);

done();
