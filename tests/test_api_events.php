<?php
// Tests /events (web/api/src/Controller/EventsController.php) through the
// whole API in this process, with FakeDb as the database.
//
// What clients rely on:
//   - events.json: {"events": [...], "pagination": {...}}. Unpaged, the
//     pagination block is the fixed one clients have always received; with
//     limit or page, the real one.
//   - each event carries FileSystemPath and a MaxScoreFrameId
//   - events on monitors the user may not view are left out (the query is
//     limited to the viewable monitors), and viewing one is a 401
//   - an unknown filter field is a 400, not SQL
//
// Run: php tests/php/test_api_events.php   (from the repo root)

require_once __DIR__.'/support/api.php';

$users = array(
  'viewer' => userRow('viewer', array('Monitors' => 'View', 'Events' => 'View')),
  'scoped' => userRow('scoped', array('Monitors' => 'View', 'Events' => 'View')),
  'none' => userRow('none', array('Monitors' => 'View')),
  'editor' => userRow('editor', array('Monitors' => 'View', 'Events' => 'Edit')),
);
$event = function ($id, $monitor) {
  return array('Event.Id' => $id, 'Event.MonitorId' => $monitor, 'Event.StorageId' => 1, 'Event.Name' => "Event $id",
    'Event.StartDateTime' => '2026-01-01 10:00:00', 'Event.EndDateTime' => '2026-01-01 10:00:10', 'Event.Length' => '10.00',
    'Event.Archived' => 0, 'Event.DefaultVideo' => '', 'Event.Scheme' => 'Medium', 'Event.MaxScore' => 20, 'Event.MaxScoreFrameId' => ($id == 1 ? 2 : null),
    'Event__StartTimeSecs' => 1767261600, 'Event__EndTimeSecs' => 1767261610,
    'Event__StartTime' => '2026-01-01 10:00:00', 'Event__EndTime' => '2026-01-01 10:00:10');
};
$events = array($event(1, 1), $event(2, 1), $event(3, 2));
$db = new FakeDb();
$db->table('Events', array('Id' => 'bigint(20) unsigned', 'MonitorId' => 'int(10) unsigned', 'StorageId' => 'smallint(5) unsigned',
    'Name' => 'varchar(64)', 'StartDateTime' => 'datetime', 'EndDateTime' => 'datetime', 'Length' => 'decimal(10,2)',
    'Archived' => 'tinyint(3) unsigned', 'DefaultVideo' => 'varchar(64)', 'Scheme' => "enum('Deep','Medium','Shallow')",
    'MaxScore' => 'smallint(5) unsigned', 'MaxScoreFrameId' => 'int(10) unsigned'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  // scoped is denied monitor 2
  ->on('/FROM `Monitors_Permissions` WHERE/', function ($sql, $p) use ($users) {
    return ($p == array($users['scoped']['Id'])) ? array(array('Id' => 1, 'MonitorId' => 2, 'UserId' => $p[0], 'Permission' => 'None')) : array();
  })
  ->on('/FROM `Groups_Permissions`/', array())
  ->on('/^UPDATE `Events` SET `Archived` = \?/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Events` WHERE `Id` = \?/', array(array('COUNT(*)' => 1)))
  ->on('/Sessions/', array())
  ->on('/FROM `Monitors`/', array(array('Id' => 1, 'Name' => 'front'), array('Id' => 2, 'Name' => 'hidden')))
  ->on('/FROM `Storage` WHERE/', array(array('Id' => 1, 'Name' => 'Default', 'Path' => '/var/cache/zoneminder/events', 'Scheme' => 'Medium')))
  ->on('/SELECT `FrameId` FROM `Frames` WHERE `EventId` = \? AND `Score` = \?/', array(array('FrameId' => 3)))
  ->on('/^SELECT COUNT\(\*\) FROM `Events` AS `Event`/', function ($sql, $p) use ($events) {
    return array(array('COUNT(*)' => strpos($sql, '`Event`.`MonitorId` IN') !== false ? 2 : 3));
  })
  ->on('/FROM `Events` AS `Event`/', function ($sql, $p) use ($events) {
    // The viewable-monitor condition is the query's; answer as the database would.
    $rows = (strpos($sql, '`Event`.`MonitorId` IN') !== false)
      ? array_values(array_filter($events, function ($e) { return $e['Event.MonitorId'] == 1; })) : $events;
    if (preg_match('/LIMIT (?:(\d+), )?(\d+)$/', $sql, $m)) $rows = array_slice($rows, (int)$m[1], (int)$m[2]);
    return $rows;
  });
apiBoot($db);

function ids($answer) {
  return array_map(function ($e) { return $e['Event']['Id']; }, $answer[1]['events']);
}

echo "index\n";
$a = answered(api('GET', 'events.json', 'viewer'));
check('200, events and pagination', array($a[0], array_keys($a[1]), ids($a)), array(200, array('events', 'pagination'), array(1, 2, 3)));
check('unpaged: the fixed block', $a[1]['pagination'],
  array('prevPage' => false, 'nextPage' => true, 'paramType' => 'named', 'pageCount' => 1, 'options' => array(), 'page' => 1));
check('FileSystemPath from the storage, scheme and date', $a[1]['events'][0]['Event']['FileSystemPath'],
  '/var/cache/zoneminder/events/1/2026-01-01/1');
check('MaxScoreFrameId kept when stored, looked up when not', array($a[1]['events'][0]['Event']['MaxScoreFrameId'], $a[1]['events'][1]['Event']['MaxScoreFrameId']),
  array(2, 3));
check('virtual fields in each event', array_key_exists('StartTimeSecs', $a[1]['events'][0]['Event']), true);
$a = answered(api('GET', 'events.json?limit=2&page=1', 'viewer'));
check('paged: the real block', array(ids($a), $a[1]['pagination']['pageCount'], $a[1]['pagination']['count'], $a[1]['pagination']['paramType']),
  array(array(1, 2), 2, 3, 'querystring'));
$a = answered(api('GET', 'events.json?limit=2&page=5', 'viewer'));
check('past the last page: 404', $a[0], 404);

echo "permissions\n";
$db->queries = array();
check('a monitor denied: its events left out', ids(answered(api('GET', 'events.json', 'scoped'))), array(1, 2));
$listed = array_values(array_filter($db->queries, function ($q) { return strpos($q[0], 'FROM `Events` AS `Event`') !== false; }));
check('by the query, with the viewable monitors bound', $listed[0][1], array(1));
check('no Events permission: 401', answered(api('GET', 'events.json', 'none'))[0], 401);

echo "filters\n";
$db->queries = array();
answered(api('GET', 'events/index/MonitorId:1/StartDateTime%20>=:2026-01-01.json', 'viewer'));
$listed = array_values(array_filter($db->queries, function ($q) { return strpos($q[0], 'FROM `Events` AS `Event`') !== false; }));
check('named filters become bound conditions', array(strstr($listed[0][0], 'WHERE'), $listed[0][1]),
  array('WHERE ((`Event`.`MonitorId` = ?) AND (`Event`.`StartDateTime` >= ?))', array('1', '2026-01-01')));
$db->queries = array();
answered(api('GET', 'events/index/MonitorId%3A1/StartDateTime%20%3E%3D%3A2026-01-01.json?limit=100&sort=StartDateTime&direction=desc', 'viewer'));
$listed = array_values(array_filter($db->queries, function ($q) { return strpos($q[0], 'FROM `Events` AS `Event`') !== false; }));
check('encoded named filters (zmNinjaNg) are applied too', array(strstr(strstr($listed[0][0], 'WHERE'), ' ORDER', true), $listed[0][1]),
  array('WHERE ((`Event`.`MonitorId` = ?) AND (`Event`.`StartDateTime` >= ?))', array('1', '2026-01-01')));
$db->queries = array();
$a = answered(api('GET', 'events/index/Tags.Id%3A14.json?page=1&limit=100', 'viewer'));
$listed = array_values(array_filter($db->queries, function ($q) { return strpos($q[0], 'FROM `Events` AS `Event`') !== false and strpos($q[0], 'COUNT') === false; }));
check('Tags.Id:<id> (zmNinjaNg\'s tag filter, as CakePHP takes it): through the tag joins, bound',
  array($a[0], strpos($listed[0][0], 'JOIN `Events_Tags`') !== false, strpos($listed[0][0], '`Tags`.`Id` IN (?)') !== false, in_array(14, $listed[0][1], true)),
  array(200, true, true, true));
check('Tags.Id with an operator: 400', answered(api('GET', 'events/index/Tags.Id%20>:1.json', 'viewer'))[0], 400);
$a = answered(api('GET', 'events/index/NoSuchField:1.json', 'viewer'));
check('an unknown field: 400', array($a[0], $a[1]['data']['name']), array(400, 'Unknown filter field: NoSuchField'));

echo "archive\n";
$db->queries = array();
$a = answered(api('POST', 'events/archive/1.json', 'viewer'));
$writes = array_filter($db->queries, function ($q) { return strpos($q[0], 'UPDATE') === 0; });
check('Events view only: archiving is a 401, nothing written', array($a[0], count($writes)), array(401, 0));
$a = answered(api('POST', 'events/archive/1.json', 'editor'));
check('Events edit: archived', array($a[0], $a[1]['archived']), array(200, 1));
check('GET does not archive', answered(api('GET', 'events/archive/1.json', 'editor'))[0], 405);

done();
