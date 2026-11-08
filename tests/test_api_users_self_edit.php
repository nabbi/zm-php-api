<?php
// Tests self edit in /users (api/src/Controller/UsersController.php) with
// ZM_USER_SELF_EDIT on, through the whole API in this process.
//
// What must hold:
//   - a user without System permission reaches their own record (view, edit)
//     and nobody else's, and no other users action
//   - they change only Password, Language and HomeView, what the web UI's self
//     edit allows; never Username, permissions or anything else
//   - an empty Password leaves the password alone; a new one also sets
//     TokenMinExpiry, revoking the user's tokens issued so far (as ZoneMinder
//     master does, GHSA-5w6w); nothing else writes it
//   - a Language must be a lang file's name: no paths, no missing files
//
// Run: tests/run.sh test_api_users_self_edit.php

define('ZM_USER_SELF_EDIT', true);
define('ZM_PATH_WEB', realpath(__DIR__.'/../../web'));
require_once __DIR__.'/support/api.php';

$users = array(
  'self' => userRow('self', array('Monitors' => 'View')),
  'other' => userRow('other', array('Monitors' => 'View')),
);
$byId = array();
foreach ($users as $u) $byId[$u['Id']] = $u;
$selfId = $users['self']['Id'];
$otherId = $users['other']['Id'];

$db = new FakeDb();
$db->table('Users', array('Id' => 'int(10) unsigned', 'Username' => 'varchar(32)', 'Password' => 'varchar(64)',
    'Name' => 'varchar(64)', 'Language' => 'varchar(8)', 'Enabled' => 'tinyint(3) unsigned', 'Stream' => "enum('None','View')",
    'Events' => "enum('None','View','Edit')", 'Monitors' => "enum('None','View','Edit','Create')",
    'System' => "enum('None','View','Edit')", 'HomeView' => 'varchar(64)', 'APIEnabled' => 'tinyint(3) unsigned',
    'TokenMinExpiry' => 'int(10) unsigned'))
  ->table('Users_Roles', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Users` WHERE `Id` = \?/', function ($sql, $p) use ($byId) {
    return array(array('COUNT(*)' => isset($byId[$p[0]]) ? 1 : 0));
  })
  ->on('/FROM `Users` AS `User`/', function ($sql, $p) use ($byId) {
    $rows = array();
    foreach ($byId as $id => $u) {
      if ($p and !in_array($id, $p)) continue;
      $row = array();
      foreach ($u as $k => $v) $row["User.$k"] = $v;
      $rows[] = $row;
    }
    return $rows;
  })
  ->on('/^UPDATE `Users` SET/', array());
apiBoot($db);

function writes(FakeDb $db) {
  return array_values(array_filter($db->queries, function ($q) { return strpos($q[0], 'UPDATE') === 0; }));
}

echo "reach\n";
check('own record: 200', answered(api('GET', "users/view/$selfId.json", 'self'))[0], 200);
check('another user: 401', answered(api('GET', "users/view/$otherId.json", 'self'))[0], 401);
check('the index: 401', answered(api('GET', 'users.json', 'self'))[0], 401);
check('editing another user: 401', answered(api('PUT', "users/edit/$otherId.json", 'self', array('User' => array('HomeView' => 'console'))))[0], 401);
check('deleting oneself: 401', answered(api('DELETE', "users/$selfId.json", 'self'))[0], 401);

echo "fields\n";
$db->queries = array();
$a = answered(api('PUT', "users/edit/$selfId.json", 'self', array('User' => array(
  'Password' => 'new-secret', 'Language' => 'en_gb', 'HomeView' => 'montage',
  'Username' => 'admin', 'System' => 'Edit', 'Monitors' => 'Create', 'APIEnabled' => 1, 'Name' => 'x'))));
check('saved', array($a[0], $a[1]['message']), array(200, 'Saved'));
$w = writes($db);
check('one UPDATE', count($w), 1);
preg_match_all('/`(\w+)` = \?/', strstr($w[0][0], 'WHERE', true), $m);
$set = $m[1];
sort($set);
check('only Password, Language and HomeView written, plus TokenMinExpiry', $set, array('HomeView', 'Language', 'Password', 'TokenMinExpiry'));
check('the password hashed', password_verify('new-secret', $w[0][1][array_search('Password', $m[1])]), true);
$minExpiry = $w[0][1][array_search('TokenMinExpiry', $m[1])];
check('TokenMinExpiry is now (tokens issued before the change are revoked)', ($minExpiry >= time() - 5 and $minExpiry <= time()), true);

$db->queries = array();
answered(api('PUT', "users/edit/$selfId.json", 'self', array('User' => array('HomeView' => 'console'))));
$w = writes($db);
check('without a Password, TokenMinExpiry is not written', strpos($w[0][0], '`TokenMinExpiry`'), false);

$db->queries = array();
answered(api('PUT', "users/edit/$selfId.json", 'self', array('User' => array('Password' => '', 'HomeView' => 'console'))));
$w = writes($db);
check('an empty Password is left alone', strpos($w[0][0], '`Password`'), false);
check('and does not revoke tokens', strpos($w[0][0], '`TokenMinExpiry`'), false);

$db->queries = array();
$a = answered(api('PUT', "users/edit/$selfId.json", 'self', array('User' => array('System' => 'Edit'))));
check('nothing self-editable: Error, nothing written', array($a[1]['message'], count(writes($db))), array('Error', 0));

echo "language\n";
foreach (array('../../../../etc/passwd', 'en_gb/../../x', 'xx_yy', 'default', 'en_gb.php') as $lang) {
  $db->queries = array();
  $a = answered(api('PUT', "users/edit/$selfId.json", 'self', array('User' => array('Language' => $lang))));
  check("Language '$lang': 400, nothing written", array($a[0], count(writes($db))), array(400, 0));
}
$a = answered(api('PUT', "users/edit/$selfId.json", 'self', array('User' => array('Language' => array('en_gb')))));
check('Language as an array: 400', $a[0], 400);

done();
