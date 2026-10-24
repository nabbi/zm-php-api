<?php
// Tests that loading ZoneMinder's auth.php authenticates nothing (Compat::requireAuth()).
//
// 1.38's auth.php authenticates the request when it is included: it reads
// user=/pass=, username=/password=, token= and auth= from $_REQUEST, may run
// validateUser() and rewrite the stored password hash. The API must
// authenticate only later, after its own checks (credential types, the login
// throttle), so the include must see no request. On master the include only
// defines functions, and this passes trivially.
//
// What must hold, with credentials in the request when auth.php is first loaded:
//   - no Users lookup happens during the load
//   - $_REQUEST and REMOTE_USER are back as they were afterwards
//   - the global $user is not set by it
//
// Run: tests/run.sh test_api_compat_auth_include.php

require_once __DIR__.'/support/api.php';

$admin = userRow('admin', array('System' => 'Edit'));
$db = new FakeDb();
$db->on('/FROM Users/', array($admin))->on('/./', array());

$request = array('user' => 'admin', 'pass' => 'secret', 'username' => 'admin', 'password' => 'secret');
$_REQUEST = $request;
$_SERVER['REMOTE_USER'] = 'admin';

apiBoot($db);  // the first load of auth.php, through Compat::requireAuth()

$users = array_values(array_filter($db->queries, function ($q) { return stripos($q[0], 'Users') !== false; }));
check('no Users lookup while auth.php loads', $users, array());
check('$_REQUEST restored', $_REQUEST, $request);
check('REMOTE_USER restored', isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : null, 'admin');
check('no global $user from the load', isset($GLOBALS['user']) ? $GLOBALS['user'] : null, null);

done();
