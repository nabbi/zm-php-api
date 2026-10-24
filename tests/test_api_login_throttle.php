<?php
// Tests the API's failed-login throttle (api/src/Auth.php) through the whole
// API in this process. APCu is replaced by an in-memory stand-in when the CLI
// has none.
//
// What must hold:
//   - a wrong password counts on any action, host/logout included
//   - after 10 failures for a name from an address, logins as that name answer
//     429, also with the right password
//   - logging in to another account does not reset the count for a name
//   - a successful login clears its own name's count
//   - 50 failures from an address, whatever the names, block every name
//
// Run: tests/run.sh test_api_login_throttle.php

if (!function_exists('apcu_fetch')) {
  $GLOBALS['apcu'] = array();
  function apcu_fetch($k) { return isset($GLOBALS['apcu'][$k]) ? $GLOBALS['apcu'][$k] : false; }
  function apcu_store($k, $v, $ttl = 0) { $GLOBALS['apcu'][$k] = $v; return true; }
  function apcu_add($k, $v, $ttl = 0) { if (isset($GLOBALS['apcu'][$k])) return false; $GLOBALS['apcu'][$k] = $v; return true; }
  function apcu_inc($k) { $GLOBALS['apcu'][$k]++; return $GLOBALS['apcu'][$k]; }
  function apcu_delete($k) { unset($GLOBALS['apcu'][$k]); return true; }
  function apcu_reset() { $GLOBALS['apcu'] = array(); }
} else {
  function apcu_reset() { apcu_clear_cache(); }
}
require_once __DIR__.'/support/api.php';

$passwords = array('admin' => 'admin-pw', 'mallory' => 'mallory-pw');
$users = array();
foreach ($passwords as $name => $pw) {
  $users[$name] = array_merge(userRow($name, array('System' => 'View')), array('Password' => password_hash($pw, PASSWORD_BCRYPT)));
}
$db = new FakeDb();
$db->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/UPDATE Users SET/', array());
apiBoot($db);

function login($name, $password) {
  return answered(api('POST', 'host/login.json', null, array('user' => $name, 'pass' => $password)))[0];
}
function logoutGuess($name, $password) {
  return answered(api('GET', 'host/logout.json?username='.urlencode($name).'&password='.urlencode($password)))[0];
}

echo "guesses through logout count\n";
apcu_reset();
for ($i = 0; $i < 10; $i++) logoutGuess('admin', "guess$i");
check('after 10 wrong passwords at host/logout, the right one is refused', login('admin', 'admin-pw'), 429);
check('another name is not blocked', login('mallory', 'mallory-pw'), 200);

echo "one's own login doesn't reset another name's count\n";
apcu_reset();
for ($i = 0; $i < 9; $i++) login('admin', "guess$i");
check('own login succeeds', login('mallory', 'mallory-pw'), 200);
check('10th wrong guess still counted: 401', login('admin', 'guess9'), 401);
check('then admin is blocked', login('admin', 'admin-pw'), 429);

echo "success clears its own name\n";
apcu_reset();
for ($i = 0; $i < 9; $i++) login('admin', "guess$i");
check('right password before the limit: 200', login('admin', 'admin-pw'), 200);
for ($i = 0; $i < 9; $i++) login('admin', "again$i");
check('the count started over', login('admin', 'admin-pw'), 200);

echo "spraying names from one address\n";
apcu_reset();
for ($i = 0; $i < 50; $i++) login("user$i", 'x');
check('after 50 failures, a new name is blocked', login('mallory', 'mallory-pw'), 429);

done();
