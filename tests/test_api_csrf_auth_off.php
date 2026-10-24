<?php
// Tests the cross-origin write check (api/src/Auth.php) with ZM_OPT_USE_AUTH
// off: every request is the default user, authenticated by nothing in it, so a
// page on another site must not change anything. See test_api_csrf.php.
//
// Run: tests/run.sh test_api_csrf_auth_off.php

define('ZM_OPT_USE_AUTH', false);
require_once __DIR__.'/support/api.php';

$db = new FakeDb();
$db->table('Users', array('Id' => 'int(10) unsigned', 'Username' => 'varchar(32)', 'Password' => 'varchar(64)'))
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Users`/', array(array('COUNT(*)' => 0)))
  ->on('/^(INSERT|UPDATE|DELETE)/', array())
  ->on('/./', array());
apiBoot($db);

function writes(FakeDb $db) {
  return count(array_filter($db->queries, function ($q) { return preg_match('/^(INSERT|UPDATE|DELETE)/', $q[0]); }));
}
$newUser = array('User' => array('Username' => 'x', 'Password' => 'y', 'System' => 'Edit'));
$host = array('HTTP_HOST' => 'zm.example');

$db->queries = array();
$a = answered(api('POST', 'users.json', null, $newUser, array(), $host + array('HTTP_ORIGIN' => 'https://evil.example')));
check('POST from another origin: 403, nothing written', array($a[0], writes($db)), array(403, 0));
check('GET states/change from an <img> on another site (Sec-Fetch-Site: cross-site): 403',
  answered(api('GET', 'states/change/stop.json', null, array(), array(), $host + array('HTTP_SEC_FETCH_SITE' => 'cross-site')))[0], 403);
check('GET states/change with a foreign Origin: 403',
  answered(api('GET', 'states/change/stop.json', null, array(), array(), $host + array('HTTP_ORIGIN' => 'https://evil.example')))[0], 403);
check('GET states/change from a script or native app (no Origin, no Sec-Fetch-Site): 200',
  answered(api('GET', 'states/change/stop.json', null, array(), array(), $host))[0], 200);
check('GET monitors/alarm by a script (pyzmNg, zm-py, server-to-server): 200',
  answered(api('GET', 'monitors/alarm/id:1/command:status.json', null, array(), array(), $host))[0] != 403, true);
check('GET states/change from this origin: 200',
  answered(api('GET', 'states/change/stop.json', null, array(), array(), $host + array('HTTP_SEC_FETCH_SITE' => 'same-origin')))[0], 200);
check('POST host/login from another origin (a web build behind its own proxy): not refused',
  answered(api('POST', 'host/login.json', null, array(), array(), $host + array('HTTP_ORIGIN' => 'http://localhost:5173')))[0] != 403, true);
$a = answered(api('POST', 'users.json', null, $newUser, array(), $host + array('HTTP_ORIGIN' => 'https://zm.example')));
check('POST from this origin: 200', $a[0], 200);
$a = answered(api('POST', 'users.json', null, $newUser, array(), $host));
check('POST with no Origin (a script, a native app): 200', $a[0], 200);
check('a read from another origin: 200', answered(api('GET', 'users.json', null, array(), array(), $host + array('HTTP_ORIGIN' => 'https://evil.example')))[0], 200);

done();
