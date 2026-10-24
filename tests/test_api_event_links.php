<?php
// Tests the event links of snapshots and tags
// (api/src/Controller/SnapshotsController.php, TagsController.php): an edit
// replaces the record's links, so it must not drop links to events the user
// can't see.
//
// What must hold, for a user denied monitor 2 (Monitors_Permissions) and a record
// linked to event 10 (monitor 1) and event 20 (monitor 2):
//   - {"Event": []} or {"Event": [10]} would unlink event 20: 401, nothing written
//   - {"Event": [10, 20]} unlinks nothing: allowed
//   - linking an event the user can't see stays a 401
//   - an unrestricted user may unlink anything
//
// Run: tests/run.sh test_api_event_links.php

require_once __DIR__.'/support/api.php';

$users = array(
  'scoped' => userRow('scoped', array('Snapshots' => 'Edit', 'Events' => 'Edit', 'Monitors' => 'View')),
  'all' => userRow('all', array('Snapshots' => 'Edit', 'Events' => 'Edit', 'Monitors' => 'View')),
);
$events = array(10 => 1, 20 => 2);
$db = new FakeDb();
$db->table('Snapshots', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Tags', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  // scoped is denied monitor 2
  ->on('/FROM `Monitors_Permissions` WHERE/', function ($sql, $p) use ($users) {
    return ($p == array($users['scoped']['Id'])) ? array(array('Id' => 1, 'MonitorId' => 2, 'UserId' => $p[0], 'Permission' => 'None')) : array();
  })
  ->on('/FROM `Groups_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `(Snapshots|Tags)` WHERE `Id` = \?/', array(array('COUNT(*)' => 1)))
  ->on('/^SELECT `EventId` FROM `(Snapshots_Events|Events_Tags)` WHERE/', array(array('EventId' => 10), array('EventId' => 20)))
  ->on('/FROM `Events` WHERE `Id`\s*=\s*\?/', function ($sql, $p) use ($events) {
    return isset($events[$p[0]]) ? array(array('Id' => $p[0], 'MonitorId' => $events[$p[0]], 'Name' => "Event {$p[0]}", 'StorageId' => 0)) : array();
  })
  ->on('/FROM `Monitors` WHERE `Id`\s*=\s*\?/', function ($sql, $p) {
    return array(array('Id' => $p[0], 'Name' => "m{$p[0]}", 'Deleted' => 0, 'ServerId' => null));
  })
  ->on('/^(DELETE|INSERT|UPDATE)/', array())
  ->on('/./', array());
apiBoot($db);

function writes(FakeDb $db) {
  return array_values(array_filter($db->queries, function ($q) { return preg_match('/^(DELETE|INSERT|UPDATE)/', $q[0]); }));
}

foreach (array('snapshots', 'tags') as $what) {
  echo "$what\n";
  foreach (array('[]', '[10]') as $list) {
    $db->queries = array();
    $a = answered(apiJson('PUT', "$what/5.json", 'scoped', '{"Event": '.$list.'}'));
    check("restricted user, Event $list (drops event 20): 401, nothing written", array($a[0], count(writes($db))), array(401, 0));
  }
  $a = answered(apiJson('PUT', "$what/5.json", 'scoped', '{"Event": [10, 20]}'));
  check('restricted user, unlinking nothing: allowed', $a[0], 200);
  $a = answered(apiJson('PUT', "$what/5.json", 'scoped', '{"Event": [10, 20, 30]}'));
  check('linking an event that does not exist: 404', $a[0], 404);
  $db->queries = array();
  $a = answered(apiJson('PUT', "$what/5.json", 'all', '{"Event": []}'));
  check('unrestricted user may unlink all', array($a[0], count(writes($db)) > 0), array(200, true));
}

done();
