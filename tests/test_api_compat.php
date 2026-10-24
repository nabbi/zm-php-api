<?php
// Tests the schema differences between ZoneMinder 1.38 and master that
// api/src/Compat.php answers (the function differences are covered by running
// every test file against a 1.38 tree: ZM_SRC=<release-1.38> tests/run.sh).
//
// What must hold, on a master schema and on a 1.38 one:
//   - encoder_templates and notifications are routed only where their table
//     exists; without it they are the 404 of a missing controller, as 1.38's
//     CakePHP API answered
//   - frames/view/<EventId>/<FrameId> finds the frame on both
//   - frames/<n> is the frame with Id n where Frames has an Id (1.38), and no
//     frame where it doesn't (master)
//   - edits and deletes select the frame by its key: Id on 1.38, (EventId,
//     FrameId) on master; an edit never changes Id
//
// Run: tests/run.sh test_api_compat.php

require_once __DIR__.'/support/api.php';

$admin = userRow('admin', array('Events' => 'Edit', 'Monitors' => 'Edit', 'System' => 'Edit'));
// Frame 2 of event 10; on 1.38 its row also has Id 7.
$frame = array('EventId' => 10, 'FrameId' => 2, 'Type' => 'Normal', 'Score' => 0);

/** A FakeDb with master's schema, or with 1.38's when $is138. */
function schemaDb($is138) {
  global $admin, $frame;
  $row = $is138 ? array('Id' => 7) + $frame : $frame;
  $columns = array('EventId' => 'bigint(20) unsigned', 'FrameId' => 'int(10) unsigned',
    'Type' => "enum('Normal','Bulk','Alarm')", 'Score' => 'smallint(5) unsigned');
  if ($is138) $columns = array('Id' => 'bigint(20) unsigned') + $columns;
  $db = new FakeDb();
  $db->table('Frames', $columns);
  if (!$is138) {
    $db->table('EncoderTemplates', array('Id' => 'int(10) unsigned', 'Encoder' => 'varchar(32)', 'Name' => 'varchar(64)'))
      ->table('Notifications', array('Id' => 'int(10) unsigned', 'UserId' => 'int(10) unsigned', 'Token' => 'varchar(255)'));
  }
  $db->on('/FROM Users WHERE Enabled=1 AND Username=\?/', array($admin))
    ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
    ->on('/FROM `Frames` AS `Frame`/', function ($sql, $p) use ($row) {
      $match = preg_match('/WHERE .*`Frame`\.`Id`/', $sql)
        ? (isset($row['Id']) and $p == array($row['Id']))
        : ($p == array($row['EventId'], $row['FrameId']));
      $out = array();
      foreach ($row as $k => $v) $out['Frame.'.$k] = $v;
      return $match ? array($out) : array();
    })
    ->on('/FROM `Events` WHERE `Id`\s*=\s*\?/', array(array('Id' => 10, 'MonitorId' => 1, 'Name' => 'Event 10', 'StorageId' => 0)))
    ->on('/FROM `Monitors` WHERE `Id`\s*=\s*\?/', array(array('Id' => 1, 'Name' => 'm1', 'Deleted' => 0, 'ServerId' => null)))
    ->on('/^(DELETE|INSERT|UPDATE)/', array())
    ->on('/./', array());
  return $db;
}

function writes(FakeDb $db) {
  return array_values(array_filter($db->queries, function ($q) { return preg_match('/^(DELETE|INSERT|UPDATE)/', $q[0]); }));
}

apiBoot(schemaDb(false));

foreach (array('master' => false, '1.38' => true) as $series => $is138) {
  echo "$series schema\n";
  $db = schemaDb($is138);
  useDb($db);

  foreach (array('encoder_templates', 'notifications') as $what) {
    $a = answered(api('GET', "$what.json", 'admin'));
    check("$what: ".($is138 ? '404, no table' : 'routed'), $is138 ? array($a[0], $a[1]['name']) : $a[0],
      $is138 ? array(404, 'Not Found') : 200);
  }

  $a = answered(api('GET', 'frames/view/10/2.json', 'admin'));
  check('frames/view/<EventId>/<FrameId> finds the frame', array($a[0], $a[1]['frame']['Frame']['FrameId']), array(200, 2));

  $a = answered(api('GET', 'frames/7.json', 'admin'));
  check('frames/7: '.($is138 ? 'the frame with Id 7' : 'no frame'),
    $is138 ? array($a[0], $a[1]['frame']['Frame']['Id']) : array($a[0], $a[1]['data']['name']),
    $is138 ? array(200, 7) : array(404, 'Invalid frame'));

  $db->queries = array();
  $a = answered(apiJson('PUT', 'frames/edit/10/2.json', 'admin', '{"Frame": {"Score": 5, "Id": 99}}'));
  check('edit by event and frame: by the key, Id unchanged', array($a[0], writes($db)), array(200, array(
    $is138 ? array('UPDATE `Frames` SET `Score` = ? WHERE `Id` = ?', array(5, 7))
           : array('UPDATE `Frames` SET `Score` = ? WHERE `EventId` = ? AND `FrameId` = ?', array(5, 10, 2)))));

  $db->queries = array();
  $a = answered(api('POST', 'frames/delete/10/2.json', 'admin'));
  check('delete by event and frame: by the key', array($a[0], writes($db)), array(200, array(
    $is138 ? array('DELETE FROM `Frames` WHERE `Id` = ?', array(7))
           : array('DELETE FROM `Frames` WHERE `EventId` = ? AND `FrameId` = ?', array(10, 2)))));

  if ($is138) {
    $db->queries = array();
    $a = answered(api('DELETE', 'frames/7.json', 'admin'));
    check('DELETE frames/7: by Id', array($a[0], writes($db)), array(200, array(array('DELETE FROM `Frames` WHERE `Id` = ?', array(7)))));
  }
}

done();
