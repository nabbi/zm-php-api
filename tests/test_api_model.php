<?php
// Tests ZM\Api\Db\Model (web/api/src/Db/Model.php) with the real Event model:
// how find() nests a row's associations in the answer, and the SQL it sends.
//
// The nesting is what clients walk:
//
//   {"Event": {..., "StartTimeSecs": ...},          the row and its virtual fields
//    "Monitor": {...}, "Storage": {...},            belongsTo: one object each
//    "Frame": [{...}, ...],                         hasMany: a list of flat rows
//    "Tag": [{..., "EventsTag": {...}}, ...]}       HABTM: the join row inside each
//
// `recursive` decides how much of it comes back. The database is FakeDb
// (tests/php/support/api.php), so this runs without one.
//
// Run: php tests/php/test_api_model.php   (from the repo root)

require_once __DIR__.'/support/api.php';

use ZM\Api\Db\Db;
use ZM\Api\Model\Event;

function fakeDb() {
  $db = new FakeDb();
  $db->table('Events', array('Id' => 'bigint(20) unsigned', 'MonitorId' => 'int(10) unsigned', 'StorageId' => 'smallint(5) unsigned',
      'Name' => 'varchar(64)', 'StartDateTime' => 'datetime', 'EndDateTime' => 'datetime', 'Length' => 'decimal(10,2)',
      'Archived' => 'tinyint(3) unsigned', 'DefaultVideo' => 'varchar(64)', 'Locked' => 'tinyint(1)'))
    ->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
    ->table('Storage', array('Id' => 'smallint(5) unsigned', 'Name' => 'varchar(64)'))
    ->table('Frames', array('Id' => 'bigint(20) unsigned', 'EventId' => 'bigint(20) unsigned', 'FrameId' => 'int(10) unsigned', 'Score' => 'smallint(5) unsigned'))
    ->table('Tags', array('Id' => 'bigint(20) unsigned', 'Name' => 'varchar(64)'))
    ->table('Events_Tags', array('TagId' => 'bigint(20) unsigned', 'EventId' => 'bigint(20) unsigned'));
  $event = function ($id, $monitor) {
    return array('Event.Id' => $id, 'Event.MonitorId' => $monitor, 'Event.StorageId' => 1, 'Event.Name' => "Event $id",
      'Event.StartDateTime' => '2026-01-01 10:00:00', 'Event.EndDateTime' => '2026-01-01 10:00:10', 'Event.Length' => '10.00',
      'Event.Archived' => 0, 'Event.DefaultVideo' => '', 'Event.Locked' => false,
      'Event__StartTimeSecs' => 1767261600, 'Event__EndTimeSecs' => 1767261610,
      'Event__StartTime' => '2026-01-01 10:00:00', 'Event__EndTime' => '2026-01-01 10:00:10');
  };
  $db->on('/^SELECT COUNT\(\*\) FROM `Events` AS `Event`/', array(array('COUNT(*)' => 2)))
    ->on('/^SELECT COUNT\(\*\) FROM `Events` WHERE/', array(array('COUNT(*)' => 1)))
    ->on('/FROM `Events` AS `Event` LEFT JOIN/', array(
      $event(1, 1) + array('Monitor.Id' => 1, 'Monitor.Name' => 'front', 'Storage.Id' => 1, 'Storage.Name' => 'Default'),
      $event(2, 1) + array('Monitor.Id' => 1, 'Monitor.Name' => 'front', 'Storage.Id' => 1, 'Storage.Name' => 'Default')))
    ->on('/FROM `Events` AS `Event`/', array($event(1, 1), $event(2, 1)))
    ->on('/FROM `Frames` AS `Frame`/', array(
      array('Frame.Id' => 10, 'Frame.EventId' => 1, 'Frame.FrameId' => 1, 'Frame.Score' => 0),
      array('Frame.Id' => 11, 'Frame.EventId' => 1, 'Frame.FrameId' => 2, 'Frame.Score' => 20)))
    ->on('/FROM `Tags` AS `Tag` JOIN `Events_Tags` AS `EventsTag`/', array(
      array('Tag.Id' => 7, 'Tag.Name' => 'person', 'EventsTag.TagId' => 7, 'EventsTag.EventId' => 2)));
  Db::$connection = $db;
  return $db;
}

/** The statements sent, without the SHOW COLUMNS the models read their schema with. */
function sqls(FakeDb $db, $withParams = false) {
  $out = array();
  foreach ($db->queries as $q) if (strpos($q[0], 'SHOW COLUMNS') !== 0) $out[] = $withParams ? $q : $q[0];
  return $out;
}

echo "recursive -1: the row only\n";
$db = fakeDb();
$rows = (new Event())->find('all', array('recursive' => -1));
check('one key per row', array_keys($rows[0]), array('Event'));
check('virtual fields are part of the row', array_slice(array_keys($rows[0]['Event']), -4), array('StartTimeSecs', 'EndTimeSecs', 'StartTime', 'EndTime'));
check('TINYINT(1) is a boolean, TINYINT(3) a number', array($rows[0]['Event']['Locked'], $rows[0]['Event']['Archived']), array(false, 0));
check('DECIMAL is a string', $rows[0]['Event']['Length'], '10.00');
check('one query, no joins', sqls($db), array('SELECT `Event`.`Id`, `Event`.`MonitorId`, `Event`.`StorageId`, `Event`.`Name`, `Event`.`StartDateTime`, `Event`.`EndDateTime`, `Event`.`Length`, `Event`.`Archived`, `Event`.`DefaultVideo`, `Event`.`Locked`, '
  .'(UNIX_TIMESTAMP(Event.StartDateTime)) AS `Event__StartTimeSecs`, '
  .'(CASE WHEN Event.EndDateTime IS NOT NULL THEN UNIX_TIMESTAMP(Event.EndDateTime) WHEN Event.Length > 0 THEN UNIX_TIMESTAMP(Event.StartDateTime) + Event.Length ELSE UNIX_TIMESTAMP(Event.StartDateTime) END) AS `Event__EndTimeSecs`, '
  .'(Event.StartDateTime) AS `Event__StartTime`, '
  .'(CASE WHEN Event.EndDateTime IS NOT NULL THEN Event.EndDateTime WHEN Event.Length > 0 THEN DATE_ADD(Event.StartDateTime, INTERVAL FLOOR(Event.Length) SECOND) ELSE NOW() END) AS `Event__EndTime` '
  .'FROM `Events` AS `Event`'));

echo "recursive 0: plus belongsTo, joined\n";
$db = fakeDb();
$rows = (new Event())->find('all', array('recursive' => 0));
check('Event, Monitor, Storage', array_keys($rows[0]), array('Event', 'Monitor', 'Storage'));
check('belongsTo is one object', $rows[0]['Monitor'], array('Id' => 1, 'Name' => 'front'));
check('joined on the foreign key', strstr(sqls($db)[0], 'FROM'),
  'FROM `Events` AS `Event` LEFT JOIN `Monitors` AS `Monitor` ON (`Event`.`MonitorId` = `Monitor`.`Id`) LEFT JOIN `Storage` AS `Storage` ON (`Event`.`StorageId` = `Storage`.`Id`)');

echo "recursive 1: plus hasMany and HABTM, as lists\n";
$db = fakeDb();
$rows = (new Event())->find('all', array('recursive' => 1));
check('Event, Monitor, Storage, Frame, Tag', array_keys($rows[0]), array('Event', 'Monitor', 'Storage', 'Frame', 'Tag'));
check('hasMany rows are flat, under their parent', $rows[0]['Frame'], array(
  array('Id' => 10, 'EventId' => 1, 'FrameId' => 1, 'Score' => 0),
  array('Id' => 11, 'EventId' => 1, 'FrameId' => 2, 'Score' => 20)));
check('no children: an empty list', $rows[1]['Frame'], array());
check('HABTM rows carry the join row', $rows[1]['Tag'], array(array('Id' => 7, 'Name' => 'person', 'EventsTag' => array('TagId' => 7, 'EventId' => 2))));
check('as JSON, an empty list is []', json_encode($rows[0]['Tag']), '[]');
$q = sqls($db);
check('children fetched once for all parents', array(count($q), strstr($q[1], 'WHERE'), sqls($db, true)[1][1]),
  array(3, 'WHERE `Frame`.`EventId` IN (?, ?)', array(1, 2)));
check('HABTM through the join table', strstr($q[2], 'FROM'),
  'FROM `Tags` AS `Tag` JOIN `Events_Tags` AS `EventsTag` ON (`EventsTag`.`TagId` = `Tag`.`Id`) WHERE `EventsTag`.`EventId` IN (?, ?)');

echo "first, count, list, exists\n";
$db = fakeDb();
check('first: the first row', (new Event())->find('first', array('conditions' => array('Event.Id' => 1)))['Event']['Id'], 1);
check('first asks for one', substr(sqls($db)[0], -8), ' LIMIT 1');
$db = fakeDb();
check('count is an int', (new Event())->find('count'), 2);
check('count SQL (Event reads with recursive -1 unless asked)', sqls($db)[0], 'SELECT COUNT(*) FROM `Events` AS `Event`');
$db = fakeDb();
$db->on('/^SELECT `Event`.`Id`, `Event`.`Name`/', array(array('Event.Id' => 1, 'Event.Name' => 'Event 1'), array('Event.Id' => 2, 'Event.Name' => 'Event 2')));
check('list: id => display field', (new Event())->find('list', array('recursive' => -1)), array(1 => 'Event 1', 2 => 'Event 2'));
$db = fakeDb();
check('exists', array((new Event())->exists(1), (new Event())->exists(null), (new Event())->exists(array(1))), array(true, false, false));
check('exists asks only for a real id', count(sqls($db)), 1);

echo "conditions, order, paging\n";
$db = fakeDb();
(new Event())->find('all', array('recursive' => -1, 'conditions' => array('MonitorId' => 1, 'Name LIKE' => '%x%'),
  'order' => array('Event.Id' => 'desc'), 'limit' => 2, 'page' => 3));
check('qualified, bound, ordered, offset from the page', array(strstr(sqls($db)[0], 'WHERE'), sqls($db, true)[0][1]),
  array("WHERE (`Event`.`MonitorId` = ?) AND (`Event`.`Name` LIKE ?) ORDER BY `Event`.`Id` DESC LIMIT 4, 2", array(1, '%x%')));
$db = fakeDb();
(new Event())->find('all', array('recursive' => -1, 'order' => array('StartTimeSecs' => 'asc')));
check('ordering by a virtual field orders by its expression', strstr(sqls($db)[0], 'ORDER BY'), 'ORDER BY (UNIX_TIMESTAMP(Event.StartDateTime)) ASC');
check('a bad direction is refused', thrown(function () { fakeDb(); (new Event())->find('all', array('order' => array('Id' => 'sideways'))); }),
  'ZM\Api\Http\BadRequestException: Invalid sort direction: SIDEWAYS');
check('an unknown field is refused', thrown(function () { fakeDb(); (new Event())->find('all', array('conditions' => array('Id; DROP' => 1))); }),
  'ZM\Api\Http\BadRequestException: Invalid operator: DROP');

echo "validation\n";
$db = fakeDb();
$e = new Event();
check('DefaultVideo must be a file name', array($e->save(array('Event' => array('DefaultVideo' => '../../etc/passwd')), 1), $e->validationErrors),
  array(false, array('DefaultVideo' => array('DefaultVideo must be a filename without a path'))));
check('nothing written when invalid', count(array_filter(sqls($db), function ($s) { return preg_match('/^(UPDATE|INSERT)/', $s); })), 0);

done();
