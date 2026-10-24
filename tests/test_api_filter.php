<?php
// Tests ZM\Api\Filter::conditions() (api/src/Filter.php): named-argument
// filters checked against the tables a query reads.
//
// What must hold:
//   - a field is a column of the queried model or, for a query that joins them,
//     of its belongsTo/hasOne models; it is written Alias.Column, spelled as the
//     schema spells it, so MySQL never sees an unknown or ambiguous column
//   - a bare field the queried model has is its own; one that only a single
//     joined model has (Status, CaptureFPS on monitors) is that model's
//   - unknown fields, unknown or wrong aliases, ambiguous bare fields and
//     operators off the allow list are 400s naming them
//
// Run: tests/run.sh test_api_filter.php

require_once __DIR__.'/support/api.php';

$db = new FakeDb();
$db->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'Type' => 'varchar(16)',
    'Enabled' => 'tinyint(1)', 'Deleted' => 'tinyint(1)', 'Capturing' => 'varchar(16)', 'ServerId' => 'int(10) unsigned'))
  ->table('Monitor_Status', array('MonitorId' => 'int(10) unsigned', 'Status' => 'varchar(16)',
    'CaptureFPS' => 'decimal(10,2)', 'AnalysisFPS' => 'decimal(10,2)'))
  ->table('Event_Summaries', array('MonitorId' => 'int(10) unsigned', 'TotalEvents' => 'int(10)'))
  ->table('Manufacturers', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->table('Models', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'ManufacturerId' => 'int(10) unsigned'))
  ->table('Frames', array('Id' => 'int(10) unsigned', 'EventId' => 'bigint(20) unsigned', 'Score' => 'smallint(5)'));
useDb($db);

$monitor = new \ZM\Api\Model\Monitor();
function run(array $named, array $options = array('joined' => true, 'bare' => array('GroupId'))) {
  global $monitor;
  try {
    return \ZM\Api\Filter::conditions($named, $monitor, $options);
  } catch (\ZM\Api\Http\HttpException $e) {
    return $e->getCode().' '.$e->getMessage();
  }
}

echo "the queried model\n";
check('bare column is qualified', run(array('Name' => 'hikvision-1')), array('Monitor.Name' => 'hikvision-1'));
check('operator is kept', run(array('Id >' => '0')), array('Monitor.Id >' => '0'));
check('LIKE is kept, as contains', run(array('Name LIKE' => 'hik')), array('Monitor.Name LIKE' => '%hik%'));
check('IN is kept', run(array('Id IN' => '1,2')), array(array('Monitor.Id IN' => array('1', '2'))));
check('Monitor.Field is accepted', run(array('Monitor.Deleted' => '0')), array('Monitor.Deleted' => '0'));
check('column case follows the schema', run(array('enabled' => '1')), array('Monitor.Enabled' => '1'));
check('alias case follows the model', run(array('monitor.name' => 'x')), array('Monitor.Name' => 'x'));
check('its own Id wins over joined Ids', run(array('Id' => '1')), array('Monitor.Id' => '1'));
check('GroupId stays bare', run(array('GroupId' => '3')), array('GroupId' => '3'));
check('paging parameters are skipped', run(array('Deleted' => '0', 'limit' => '5', 'page' => '2')), array('Monitor.Deleted' => '0'));

echo "joined models\n";
check('a joined model column, qualified', run(array('Manufacturer.Name' => 'Acme')), array('Manufacturer.Name' => 'Acme'));
check('bare column of one joined table', run(array('Status' => 'Connected')), array('Monitor_Status.Status' => 'Connected'));
check('bare joined column with an operator', run(array('CaptureFPS >' => '0')), array('Monitor_Status.CaptureFPS >' => '0'));
check('bare joined column case follows the schema', run(array('analysisfps <' => '5')), array('Monitor_Status.AnalysisFPS <' => '5'));
check('bare joined column next to a Monitors column', run(array('Status' => 'Connected', 'Deleted' => '0')),
  array('Monitor_Status.Status' => 'Connected', 'Monitor.Deleted' => '0'));
check('bare column of two joined tables is a 400', run(array('MonitorId' => '1')), '400 Ambiguous filter field: MonitorId');
check('it can be qualified', run(array('Event_Summary.MonitorId' => '1')), array('Event_Summary.MonitorId' => '1'));
check('without joins, a joined column is unknown', run(array('Status' => 'x'), array()), '400 Unknown filter field: Status');
check('without joins, a joined alias is unknown', run(array('Manufacturer.Name' => 'x'), array()), '400 Unknown filter field: Manufacturer.Name');

echo "refused\n";
check('unknown column', run(array('NoSuchField' => '1')), '400 Unknown filter field: NoSuchField');
check('unknown model', run(array('Event.Id' => '1')), '400 Unknown filter field: Event.Id');
check('made-up alias', run(array('Foo.Id' => '1')), '400 Unknown filter field: Foo.Id');
check('column of the wrong model', run(array('Manufacturer.Type' => '1')), '400 Unknown filter field: Manufacturer.Type');
check('two-level alias', run(array('A.Monitor.Id' => '1')), '400 Unknown filter field: A.Monitor.Id');
check('groupid in another case is not the join', run(array('groupid' => '1')), '400 Unknown filter field: groupid');
check('operator allow list applies', run(array('Id OR' => '1')), '400 Invalid operator: OR');
check('invalid characters rejected', run(array(';Id' => '1')), '400 Invalid argument before `:`: ;Id');
check('backtick rejected', run(array('Id`' => '1')), '400 Invalid operator: `');

echo "a model without joins\n";
$frame = new \ZM\Api\Model\Frame();
check('Frame column', \ZM\Api\Filter::conditions(array('EventId' => '7'), $frame), array('Frame.EventId' => '7'));
check('Frame.Score', \ZM\Api\Filter::conditions(array('Frame.Score >' => '5'), $frame), array('Frame.Score >' => '5'));
check('Event.Id on frames', thrown(function () use ($frame) { \ZM\Api\Filter::conditions(array('Event.Id' => '1'), $frame); }),
  'ZM\Api\Http\BadRequestException: Unknown filter field: Event.Id');

done();
