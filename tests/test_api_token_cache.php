<?php
// Tests the token cache (api/src/TokenCache.php) through the whole API in this
// process. APCu is replaced by an in-memory stand-in when the CLI has none.
//
// What must hold:
//   - a second call with the same access token reads neither the user nor the
//     permissions again (token= and Authorization: Bearer alike), and answers
//     the same
//   - the cached permissions still apply (a monitor denied stays hidden)
//   - the APCu key is not the token
//   - a token is never accepted after its expiry, cached or not
//   - a call carrying a password as well, a refresh token, or a login is
//     checked in full every time
//   - saving or deleting a user through the API empties the cache, so a
//     disabled account is refused at once
//
// Run: tests/run.sh test_api_token_cache.php

if (!function_exists('apcu_fetch')) {
  $GLOBALS['apcu'] = array();
  function apcu_fetch($k, &$hit = null) { $hit = isset($GLOBALS['apcu'][$k]); return $hit ? $GLOBALS['apcu'][$k] : false; }
  function apcu_store($k, $v, $ttl = 0) { $GLOBALS['apcu'][$k] = $v; return true; }
  function apcu_add($k, $v, $ttl = 0) { if (isset($GLOBALS['apcu'][$k])) return false; $GLOBALS['apcu'][$k] = $v; return true; }
  function apcu_inc($k) { $GLOBALS['apcu'][$k]++; return $GLOBALS['apcu'][$k]; }
  function apcu_delete($k) { unset($GLOBALS['apcu'][$k]); return true; }
  function apcu_reset() { $GLOBALS['apcu'] = array(); }
  function apcu_keys() { return array_keys($GLOBALS['apcu']); }
} else {
  function apcu_reset() { apcu_clear_cache(); }
  function apcu_keys() { $k = array(); foreach (new APCUIterator('/./') as $e) $k[] = $e['key']; return $k; }
}
require_once __DIR__.'/support/api.php';

$users = array(
  'viewer' => userRow('viewer', array('Monitors' => 'View', 'Events' => 'View', 'Stream' => 'View')),
  'scoped' => userRow('scoped', array('Monitors' => 'Edit', 'Events' => 'Edit', 'Stream' => 'View')),
);
$monitor = function ($id, $name) {
  return array('Monitor.Id' => $id, 'Monitor.Name' => $name, 'Monitor.Deleted' => false, 'Monitor.ServerId' => null,
    'Monitor.Type' => 'Ffmpeg', 'Monitor.Capturing' => 'None', 'Monitor.Enabled' => true, 'Monitor.JanusEnabled' => false);
};
$db = new FakeDb();
$db->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'Deleted' => 'tinyint(1)', 'ServerId' => 'int(10) unsigned',
    'Type' => "enum('Local','Remote','File','Ffmpeg')", 'Capturing' => "enum('None','Always')", 'Enabled' => 'tinyint(1)',
    'JanusEnabled' => 'tinyint(1)'))
  ->table('Manufacturers', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Models', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Monitor_Status', array('MonitorId' => 'int(10) unsigned', 'Status' => 'varchar(16)'))
  ->table('Event_Summaries', array('MonitorId' => 'int(10) unsigned', 'TotalEvents' => 'int(10)'))
  ->table('Users', array_fill_keys(array_keys($users['viewer']), 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use (&$users) {
    return (isset($users[$p[0]]) and $users[$p[0]]['Enabled']) ? array($users[$p[0]]) : array();
  })
  // scoped is denied monitor 2
  ->on('/FROM `Monitors_Permissions` WHERE/', function ($sql, $p) use ($users) {
    return ($p == array($users['scoped']['Id'])) ? array(array('Id' => 1, 'MonitorId' => 2, 'UserId' => $p[0], 'Permission' => 'None')) : array();
  })
  ->on('/FROM `Groups_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/FROM `Monitors` AS `Monitor`/', array($monitor(1, 'front'), $monitor(2, 'hidden')));
apiBoot($db);

function token($name, $type = 'access', $ttl = 3600) {
  $now = time();
  return \Firebase\JWT\JWT::encode(array('iss' => 'ZoneMinder', 'iat' => $now, 'exp' => $now + $ttl, 'user' => $name, 'type' => $type),
    ZM_AUTH_HASH_SECRET, 'HS256');
}
/** [status, monitor names, Users lookups, permission lookups] of GET monitors.json with $token */
function listed($token, $bearer = false, $extra = '') {
  global $db;
  $db->queries = array();
  $r = $bearer ? api('GET', 'monitors.json'.($extra ? "?$extra" : ''), null, array(), array(), array('HTTP_AUTHORIZATION' => "Bearer $token"))
    : api('GET', 'monitors.json?token='.urlencode($token).($extra ? "&$extra" : ''));
  $a = answered($r);
  $count = function ($pattern) use ($db) { return count(array_filter($db->queries, function ($q) use ($pattern) { return preg_match($pattern, $q[0]); })); };
  return array($a[0], isset($a[1]['monitors']) ? array_map(function ($m) { return $m['Monitor']['Name']; }, $a[1]['monitors']) : null,
    $count('/FROM Users/'), $count('/_Permissions`/'));
}

echo "token=\n";
apcu_reset();
$t = token('viewer');
check('first call: checked in full', listed($t), array(200, array('front', 'hidden'), 2, 2));
check('second call: no user or permission lookup, same answer', listed($t), array(200, array('front', 'hidden'), 0, 0));
check('the key is not the token', count(array_filter(apcu_keys(), function ($k) use ($t) {
  return strpos($k, $t) !== false or strpos($k, explode('.', $t)[2]) !== false; })), 0);

echo "Authorization: Bearer\n";
apcu_reset();
$t = token('scoped');
check('first call: the token checked once (ZoneMinder reads only token=)', listed($t, true), array(200, array('front'), 1, 2));
check('second call: cached, the denied monitor still hidden', listed($t, true), array(200, array('front'), 0, 0));

echo "not cached\n";
apcu_reset();
$t = token('viewer');
listed($t, false, 'user=viewer&pass=x');
check('with a password too: checked in full again', listed($t, false, 'user=viewer&pass=x')[2] > 0, true);
apcu_reset();
$refresh = token('viewer', 'refresh');
check('a refresh token on an action: 401', listed($refresh)[0], 401);
check('again: 401', listed($refresh)[0], 401);

echo "expiry\n";
apcu_reset();
$t = token('viewer', 'access', 1);
check('a token about to expire: 200', listed($t)[0], 200);
sleep(2);
check('expired, cached or not: 401', listed($t)[0], 401);

echo "user edits empty the cache\n";
apcu_reset();
$t = token('viewer');
listed($t);
$users['viewer']['Enabled'] = 0;
check('disabled in the database: the cached user is used for up to TokenCache::TTL seconds', listed($t)[0], 200);
$save = new ReflectionMethod(\ZM\Api\Model\User::class, 'afterSave');
$save->setAccessible(true);
$save->invoke(new \ZM\Api\Model\User(), 1, false);
check('after a user is saved through the API: refused at once', listed($t)[0], 401);
$users['viewer']['Enabled'] = 1;
check('enabled again', listed($t)[0], 200);
check('and cached again', listed($t)[2], 0);

done();
