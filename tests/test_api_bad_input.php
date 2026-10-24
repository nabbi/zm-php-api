<?php
// Tests that malformed input is the client's error (4xx), never a 500 with an
// Error line in the log, and never a way around the token checks.
//
// What must hold:
//   - page numbers too large for an integer offset, page[], limit[]: no 500
//   - sort[] and direction[] (query or named): no 500
//   - token[], pass[]: a 401, not a TypeError
//   - an action called without its arguments: 400
//   - a refresh token is not an access token, also when a lowercase
//     _method=delete drops the body that carried it
//
// Run: tests/run.sh test_api_bad_input.php

require_once __DIR__.'/support/api.php';

$users = array('admin' => array_merge(userRow('admin', array('System' => 'Edit', 'Monitors' => 'Edit', 'Events' => 'Edit')),
  array('Password' => password_hash('admin-pw', PASSWORD_BCRYPT))));
$db = new FakeDb();
$db->table('Users', array('Id' => 'int(10) unsigned', 'Username' => 'varchar(32)', 'Password' => 'varchar(64)'))
  ->table('Events', array('Id' => 'bigint(20) unsigned', 'MonitorId' => 'int(10) unsigned', 'Name' => 'varchar(64)',
    'StartDateTime' => 'datetime', 'EndDateTime' => 'datetime', 'Length' => 'decimal(10,2)'))
  ->table('Tags', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Servers', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\)/', array(array('COUNT(*)' => 3)))
  ->on('/LIMIT 9\.|E\+/', function () { throw new \RuntimeException('FakeDb: a float in LIMIT'); })
  ->on('/./', array());
apiBoot($db);

function status($method, $uri, $user = 'admin', array $form = array()) {
  return answered(api($method, $uri, $user, $form))[0];
}
function no500($label, $status) {
  check("$label: not a 500 ($status)", $status < 500, true);
}

echo "paging and sorting\n";
no500('users page past PHP_INT_MAX', status('GET', 'users/index/page:99999999999999999999.json'));
no500('users page:-5', status('GET', 'users/index/page:-5.json'));
no500('users limit:abc', status('GET', 'users/index/limit:abc.json'));
no500('events ?page=99999999999999999999', status('GET', 'events.json?page=99999999999999999999'));
no500('events ?page[]=1', status('GET', 'events.json?page[]=1'));
no500('events ?limit[]=1', status('GET', 'events.json?limit[]=1'));
no500('events ?sort[]=Id', status('GET', 'events.json?sort[]=Id'));
no500('events ?sort=Id&direction[]=desc', status('GET', 'events.json?sort=Id&direction[]=desc'));
no500('users ?sort[]=Id (named paging ignores the query)', status('GET', 'users.json?sort[]=Id'));
no500('users sort:Id/direction:sideways', status('GET', 'users/index/sort:Id/direction:sideways.json'));

echo "credentials\n";
check('token[]: 401', status('GET', 'host/getVersion.json?token[]=x', null), 401);
check('pass[]: 401', status('POST', 'host/login.json', null, array('user' => 'admin', 'pass' => array('x'))), 401);
check('auth[]: 401', status('GET', 'host/getVersion.json?auth[]=x', null), 401);

echo "arguments\n";
check('monitors/daemonControl without arguments: 400', status('GET', 'monitors/daemonControl.json'), 400);
check('host/daemonControl without arguments: 400', status('GET', 'host/daemonControl.json'), 400);

echo "second review\n";
foreach (array('read.json', 'crud.json', 'read/index.json', 'controller.json') as $u) {
  check("GET $u (an abstract controller), no credentials: 404", status('GET', $u, null), 404);
}
no500('PUT servers/1.json with data=x', status('PUT', 'servers/1.json', 'admin', array('data' => 'x')));
check('events DateTime IN: 400', status('GET', 'events/index/DateTime%20IN:1,2.json'), 400);
check('events GroupId >: 400', status('GET', 'events/index/GroupId%20>:1.json'), 400);
check('events ?GroupId[]=1: 400', status('GET', 'events.json?GroupId[]=1'), 400);
check('events ?DateTime=...: a filter (200)', status('GET', 'events.json?DateTime=2026-01-01%2010:00:00'), 200);
check('events ?_=123&stateful=1&auth=x: not filters (200)', status('GET', 'events.json?_=123&stateful=1'), 200);
check('events ?NoSuchField=1: 400, as CakePHP', status('GET', 'events.json?NoSuchField=1'), 400);
check('events ?StartTime%20%3E=... (PHP mangles the key to StartTime_>): 400, not unfiltered', status('GET', 'events.json?StartTime%20%3E=2026-01-01'), 400);
check('events/index/NoSuchField:1 (named): still 400', status('GET', 'events/index/NoSuchField:1.json'), 400);
$db->queries = array();
check('tags/index/Events.Id:1: 200', status('GET', 'tags/index/Events.Id:1.json'), 200);
$q = array_values(array_filter($db->queries, function ($q) { return strpos($q[0], 'FROM `Tags` AS') !== false; }));
check('through the Events_Tags join', count($q) and strpos($q[0][0], 'JOIN `Events_Tags`') !== false, true);
foreach (array('Events.Id%20>:1', 'Events.Id%20IN:1,2', 'Events.Id[]:1') as $f) {
  check("tags/index/$f: 400, not SQL naming an unjoined table", status('GET', "tags/index/$f.json"), 400);
}
check('tags/index/Events.Id:1,2 (the documented list form): 200', status('GET', 'tags/index/Events.Id:1,2.json'), 200);

echo "refresh tokens\n";
$now = time();
$refresh = \Firebase\JWT\JWT::encode(array('iss' => 'ZoneMinder', 'iat' => $now, 'exp' => $now + 3600, 'user' => 'admin', 'type' => 'refresh'),
  ZM_AUTH_HASH_SECRET, 'HS256');
$db->queries = array();
check('a refresh token on an action: 401', status('POST', 'events/1.json', null, array('token' => $refresh, '_method' => 'DELETE')), 401);
check('the same, with _method=delete: 401', status('POST', 'events/1.json', null, array('token' => $refresh, '_method' => 'delete')), 401);
$writes = array_filter($db->queries, function ($q) { return preg_match('/^(DELETE|UPDATE)/', $q[0]); });
check('nothing deleted', count($writes), 0);
check('host/login with the password alone: 200', status('POST', 'host/login.json', null, array('user' => 'admin', 'pass' => 'admin-pw')), 200);
check('host/login with the refresh token alone: 200', status('POST', 'host/login.json', null, array('token' => $refresh)), 200);
check('host/login with a password and a refresh token: 401', status('POST', 'host/login.json', null,
  array('user' => 'admin', 'pass' => 'admin-pw', 'token' => $refresh)), 401);

done();
