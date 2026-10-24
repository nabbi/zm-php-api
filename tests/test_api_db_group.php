<?php
// Tests Db::group() (web/api/src/Db/Db.php): how the API's rows are laid out
// and typed from what MySQL returns.
//
// Clients read rows keyed by model alias, with associated rows beside them:
//
//   {"Monitor": {"Id": 1, "Enabled": true, ...}, "Monitor_Status": {...}}
//
// and a TINYINT(1) column as a JSON boolean (CakePHP 2 did the same). Numbers
// are already PHP numbers when they arrive (mysqlnd); group() must leave them
// alone, and leave every other column (DECIMAL, dates, text) a string.
//
// Run: php tests/php/test_api_db_group.php   (from the repo root)

require_once __DIR__.'/support/api.php';

use ZM\Api\Db\Db;

function col($name, $table, $type = 'LONG', $len = 11) {
  return array('name' => $name, 'table' => $table, 'native_type' => $type, 'len' => $len);
}

echo "layout\n";
check('columns grouped by table alias',
  Db::group(array(col('Id', 'Monitor'), col('Name', 'Monitor', 'VAR_STRING', 64), col('Status', 'Monitor_Status', 'STRING', 9)),
    array(array(1, 'front', 'Connected'))),
  array(array('Monitor' => array('Id' => 1, 'Name' => 'front'), 'Monitor_Status' => array('Status' => 'Connected'))));
check('Alias__Field (a virtual field) goes under Alias',
  Db::group(array(col('Id', 'Event'), col('Event__FileSystemPath', '', 'VAR_STRING', 255)), array(array(5, '/a/b'))),
  array(array('Event' => array('Id' => 5, 'FileSystemPath' => '/a/b'))));
check('a computed column goes under 0',
  Db::group(array(col('COUNT(*)', '', 'LONGLONG', 21)), array(array(3))),
  array(array(0 => array('COUNT(*)' => 3))));
check('rows in order', Db::group(array(col('Id', 'Zone')), array(array(2), array(1))),
  array(array('Zone' => array('Id' => 2)), array('Zone' => array('Id' => 1))));
check('no rows', Db::group(array(col('Id', 'Zone')), array()), array());
check('a later column of the same name wins',
  Db::group(array(col('Id', 'Monitor'), col('Id', 'Monitor')), array(array(1, 2))),
  array(array('Monitor' => array('Id' => 2))));

echo "types\n";
$meta = array(
  col('Enabled', 'Monitor', 'TINY', 1),        // TINYINT(1)
  col('Colours', 'Monitor', 'TINY', 3),        // TINYINT(3): a number, not a flag
  col('Width', 'Monitor', 'SHORT', 5),
  col('MaxFPS', 'Monitor', 'NEWDECIMAL', 7),   // DECIMAL arrives as a string
  col('Score', 'Frame', 'DOUBLE', 22),
  col('StartDateTime', 'Event', 'DATETIME', 19),
  col('Notes', 'Event', 'BLOB', 65535),
);
$row = Db::group($meta, array(array(1, 4, 1280, '5.00', 0.4, '2026-01-01 10:00:00', 'x')))[0];
check('TINYINT(1) 1 -> true', $row['Monitor']['Enabled'], true);
check('TINYINT(1) 0 -> false', Db::group(array(col('Enabled', 'Monitor', 'TINY', 1)), array(array(0)))[0]['Monitor']['Enabled'], false);
check('TINYINT(1) NULL stays null', Db::group(array(col('Enabled', 'Monitor', 'TINY', 1)), array(array(null)))[0]['Monitor']['Enabled'], null);
check('TINYINT(3) stays a number', $row['Monitor']['Colours'], 4);
check('integers left as numbers', $row['Monitor']['Width'], 1280);
check('DECIMAL left a string', $row['Monitor']['MaxFPS'], '5.00');
check('floats left as numbers', $row['Frame']['Score'], 0.4);
check('dates left strings', $row['Event']['StartDateTime'], '2026-01-01 10:00:00');
check('text left a string', $row['Event']['Notes'], 'x');
check('NULL stays null', Db::group(array(col('Notes', 'Event', 'BLOB', 65535)), array(array(null)))[0]['Event']['Notes'], null);

echo "JSON as clients receive it\n";
check('encoded row', json_encode(Db::group($meta, array(array(0, 4, 640, '0.00', 1.5, '2026-01-01 10:00:00', null)))[0]),
  '{"Monitor":{"Enabled":false,"Colours":4,"Width":640,"MaxFPS":"0.00"},"Frame":{"Score":1.5},"Event":{"StartDateTime":"2026-01-01 10:00:00","Notes":null}}');

done();
