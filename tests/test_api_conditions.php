<?php
// Tests web/api/src/Db/Conditions.php, which turns the API's filter arrays
// into a WHERE clause.
//
// Filters arrive from the URL as named parameters
// (events/index/MonitorId:1/StartDateTime >=:2026-01-01.json), so the field
// names and operators in them are request input. The CakePHP API passed them
// to Cake's ORM; the native one builds the SQL itself, and these checks hold
// it to two rules:
//
//   - values are only ever bound (? placeholders), never put into the SQL
//   - field names and operators come from a fixed grammar; anything else is a
//     400, not SQL
//
// Run: php tests/php/test_api_conditions.php   (from the repo root)

require_once(__DIR__.'/../../web/api/src/bootstrap.php');

use ZM\Api\Db\Conditions;
use ZM\Api\Http\BadRequestException;

$passed = 0;
$failed = 0;
function check($label, $got, $want) {
  global $passed, $failed;
  if ($got === $want) {
    $passed++;
    echo "  ok $label\n";
  } else {
    $failed++;
    echo "  FAIL $label\n";
    echo "       got:  ".var_export($got, true)."\n";
    echo "       want: ".var_export($want, true)."\n";
  }
}

// [sql, params] for a condition array on Event, or 'refused: <message>'.
function where($conditions) {
  $c = new Conditions('Event', array('Id', 'MonitorId', 'Name', 'StartDateTime', 'EndDateTime'));
  try {
    return array($c->sql($conditions), $c->params);
  } catch (BadRequestException $e) {
    return 'refused: '.$e->getMessage();
  }
}

echo "comparisons\n";
check('equality, own column qualified', where(array('MonitorId' => '1')), array('`Event`.`MonitorId` = ?', array('1')));
check('aliased field kept', where(array('Monitor.Id' => 5)), array('`Monitor`.`Id` = ?', array(5)));
check('other table unqualified', where(array('Width' => 5)), array('`Width` = ?', array(5)));
check('operator after the field', where(array('StartDateTime >=' => '2026-01-01')), array('`Event`.`StartDateTime` >= ?', array('2026-01-01')));
check('operator case does not matter', where(array('Name like' => '%x%')), array('`Event`.`Name` LIKE ?', array('%x%')));
check('list: IN', where(array('Id' => array(1, 2))), array('`Event`.`Id` IN (?, ?)', array(1, 2)));
check('list with !=: NOT IN', where(array('Id !=' => array(1))), array('`Event`.`Id` NOT IN (?)', array(1)));
check('empty list matches nothing', where(array('Id' => array())), array('1 = 0', array()));
check('empty NOT IN matches all', where(array('Id NOT IN' => array())), array('1 = 1', array()));
check('null: IS NULL', where(array('EndDateTime' => null)), array('`Event`.`EndDateTime` IS NULL', array()));
check('null with !=: IS NOT NULL', where(array('EndDateTime !=' => null)), array('`Event`.`EndDateTime` IS NOT NULL', array()));
check("IS NOT 'NULL' keyword", where(array('EndDateTime IS NOT' => 'null')), array('`Event`.`EndDateTime` IS NOT NULL', array()));
check('IS with a non-keyword is bound', where(array('Name IS' => 'x')), array('`Event`.`Name` IS ?', array('x')));

echo "grouping\n";
check('several terms AND, each parenthesised', where(array('MonitorId' => 1, 'Name' => 'a')),
  array('(`Event`.`MonitorId` = ?) AND (`Event`.`Name` = ?)', array(1, 'a')));
check('OR group', where(array('OR' => array('Id' => 1, 'Name' => 'a'))),
  array('(`Event`.`Id` = ?) OR (`Event`.`Name` = ?)', array(1, 'a')));
check('NOT group', where(array('NOT' => array('Id' => 1))), array('NOT (`Event`.`Id` = ?)', array(1)));
check('empty NOT dropped', where(array('NOT' => array())), array('', array()));
check('nothing: no condition', where(array()), array('', array()));
check('parameters in placeholder order', where(array(array('Id' => 1), array('OR' => array('Name' => 'a', 'MonitorId' => array(2, 3))))),
  array('(`Event`.`Id` = ?) AND ((`Event`.`Name` = ?) OR (`Event`.`MonitorId` IN (?, ?)))', array(1, 'a', 2, 3)));

echo "request input never becomes SQL\n";
$hostile = "1' OR '1'='1";
check('a quote in a value is bound', where(array('Name' => $hostile)), array('`Event`.`Name` = ?', array($hostile)));
check('values in a list are bound', where(array('Id' => array($hostile))), array('`Event`.`Id` IN (?)', array($hostile)));
check('unknown operator', where(array('Id OR 1=1 --' => 1)), 'refused: Invalid operator: OR 1=1 --');
check('field with a quote', where(array("Id`" => 1)), 'refused: Invalid field: Id`');
check('field with a paren', where(array('SLEEP(1)' => 1)), 'refused: Invalid field: SLEEP(1)');
check('field with three parts', where(array('a.b.c' => 1)), 'refused: Invalid field: a.b.c');
check('list with a scalar operator', where(array('Id >' => array(1))), 'refused: Invalid operator for a list: >');
check('IS with a keyword-looking value plus SQL is bound', where(array('Name IS' => 'NULL OR 1=1')), array('`Event`.`Name` IS ?', array('NULL OR 1=1')));

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
