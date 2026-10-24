<?php
// Tests /monitors (web/api/src/Controller/MonitorsController.php) through the
// whole API in this process: routing, token authentication, permissions and
// the answer, with FakeDb as the database.
//
// What clients rely on:
//   - monitors.json: {"monitors": [{Monitor, Manufacturer, CameraModel,
//     Monitor_Status, Event_Summary}, ...]}, only the monitors the user may view
//   - monitors/<id>.json: {"monitor": {...}}, 404 for none, 401 when denied
//   - editing needs edit rights on the monitor
//
// Run: php tests/php/test_api_monitors.php   (from the repo root)

require_once __DIR__.'/support/api.php';

$users = array(
  'viewer' => userRow('viewer', array('Monitors' => 'View', 'Events' => 'View', 'Stream' => 'View')),
  'scoped' => userRow('scoped', array('Monitors' => 'Edit', 'Events' => 'Edit', 'Stream' => 'View')),
  'none' => userRow('none'),
  'noapi' => userRow('noapi', array('Monitors' => 'View'), 0),
);
$monitor = function ($id, $name) {
  return array('Monitor.Id' => $id, 'Monitor.Name' => $name, 'Monitor.Deleted' => false, 'Monitor.ServerId' => null,
    'Monitor.Type' => 'Ffmpeg', 'Monitor.Capturing' => 'None', 'Monitor.Enabled' => true, 'Monitor.JanusEnabled' => false,
    'Monitor.Width' => 1280, 'Monitor.MaxFPS' => null, 'Monitor.Controllable' => false,
    'Manufacturer.Id' => null, 'Manufacturer.Name' => null, 'CameraModel.Id' => null, 'CameraModel.Name' => null,
    'Monitor_Status.MonitorId' => $id, 'Monitor_Status.Status' => 'NotRunning', 'Monitor_Status.CaptureFPS' => 0.0,
    'Event_Summary.MonitorId' => $id, 'Event_Summary.TotalEvents' => 3);
};
$db = new FakeDb();
$db->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'Deleted' => 'tinyint(1)', 'ServerId' => 'int(10) unsigned',
    'Type' => "enum('Local','Remote','File','Ffmpeg')", 'Capturing' => "enum('None','Always')", 'Enabled' => 'tinyint(1)',
    'JanusEnabled' => 'tinyint(1)', 'Width' => 'smallint(5) unsigned', 'MaxFPS' => 'decimal(5,3)', 'Controllable' => 'tinyint(1)'))
  ->table('Manufacturers', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Models', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Monitor_Status', array('MonitorId' => 'int(10) unsigned', 'Status' => 'varchar(16)', 'CaptureFPS' => 'decimal(10,2)'))
  ->table('Event_Summaries', array('MonitorId' => 'int(10) unsigned', 'TotalEvents' => 'int(10)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  // scoped is denied monitor 2
  ->on('/FROM `Monitors_Permissions` WHERE/', function ($sql, $p) use ($users) {
    return ($p == array($users['scoped']['Id'])) ? array(array('Id' => 1, 'MonitorId' => 2, 'UserId' => $p[0], 'Permission' => 'None')) : array();
  })
  ->on('/FROM `Groups_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Monitors` WHERE `Id` = \?/', function ($sql, $p) { return array(array('COUNT(*)' => in_array($p[0], array('1', '2')) ? 1 : 0)); })
  ->on('/FROM `Monitors` AS `Monitor`.* WHERE `Monitor`.`Id` = \?/', function ($sql, $p) use ($monitor) {
    return in_array($p[0], array('1', '2')) ? array($monitor((int)$p[0], $p[0] == 1 ? 'front' : 'hidden')) : array();
  })
  ->on('/FROM `Monitors` AS `Monitor`/', array($monitor(1, 'front'), $monitor(2, 'hidden')));
apiBoot($db);

function names($answer) {
  return array_map(function ($m) { return $m['Monitor']['Name']; }, $answer[1]['monitors']);
}

echo "index\n";
$a = answered(api('GET', 'monitors.json', 'viewer'));
check('200, every monitor for a viewer', array($a[0], names($a)), array(200, array('front', 'hidden')));
check('each monitor with its associations, in this order', array_keys($a[1]['monitors'][0]),
  array('Monitor', 'Manufacturer', 'CameraModel', 'Monitor_Status', 'Event_Summary'));
check('types as clients read them', array_intersect_key($a[1]['monitors'][0]['Monitor'], array_flip(array('Id', 'Deleted', 'Enabled', 'Width', 'MaxFPS'))),
  array('Id' => 1, 'Deleted' => false, 'Enabled' => true, 'Width' => 1280, 'MaxFPS' => null));
check('a missing association is an object of nulls', $a[1]['monitors'][0]['Manufacturer'], array('Id' => null, 'Name' => null));
check('per-monitor denial hides the monitor', names(answered(api('GET', 'monitors.json', 'scoped'))), array('front'));
check('no Monitors permission: none listed', answered(api('GET', 'monitors.json', 'none')), array(200, array('monitors' => array())));

echo "view\n";
$a = answered(api('GET', 'monitors/1.json', 'viewer'));
check('{"monitor": {...}}', array($a[0], array_keys($a[1]), $a[1]['monitor']['Monitor']['Name']), array(200, array('monitor'), 'front'));
$a = answered(api('GET', 'monitors/2.json', 'scoped'));
check('denied: 401', array($a[0], $a[1]['data']['name'], $a[1]['data']['exception']['class']),
  array(401, 'Insufficient Privileges', 'UnauthorizedException'));
$a = answered(api('GET', 'monitors/9.json', 'viewer'));
check('missing: 404', array($a[0], $a[1]['data']['name']), array(404, 'Invalid monitor'));

echo "authentication\n";
$a = answered(api('GET', 'monitors.json'));
check('no credentials: 401', array($a[0], $a[1]['data']['name']), array(401, 'Not Authenticated'));
$a = answered(api('GET', 'monitors.json', 'ghost'));
check('a token for an unknown user: 401', $a[0], 401);
$a = answered(api('GET', 'monitors.json', 'noapi'));
check('APIEnabled=0: 401', $a[0], 401);

echo "edit\n";
$a = answered(api('PUT', 'monitors/1.json', 'viewer', array('Monitor' => array('Name' => 'porch'))));
check('View only: 401, nothing written', array($a[0], count(array_filter($db->queries, function ($q) { return stripos($q[0], 'UPDATE') === 0; }))),
  array(401, 0));

done();
