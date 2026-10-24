<?php
// Tests that a controller's permission checks read a request field from the
// same place the model saves it from, whatever shape the request takes.
//
// Controller::requestField() (what checks read) and Db\Model::fieldsFrom()
// (what save() writes) must agree on every shape of request: for each, the
// value a check sees is the value that would be saved.
//
// Run: tests/run.sh test_api_request_field.php

require_once __DIR__.'/support/api.php';

$db = new FakeDb();
$db->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'EventEndCommand' => 'varchar(255)'));
useDb($db);

$controller = (new ReflectionClass('ZM\Api\Controller\MonitorsController'))->newInstanceWithoutConstructor();
$requestField = new ReflectionMethod($controller, 'requestField');
$requestField->setAccessible(true);
$fieldsFrom = new ReflectionMethod('ZM\Api\Db\Model', 'fieldsFrom');
$fieldsFrom->setAccessible(true);
$model = new \ZM\Api\Model\Monitor();

/** [what the check reads, what the save writes] for EventEndCommand in $data */
function both(array $data) {
  global $controller, $requestField, $fieldsFrom, $model;
  $request = new \ZM\Api\Http\Request();
  $request->data = $data;
  $property = new ReflectionProperty('ZM\Api\Controller\Controller', 'request');
  $property->setAccessible(true);
  $property->setValue($controller, $request);
  $saved = $fieldsFrom->invoke($model, $data);
  return array($requestField->invoke($controller, 'Monitor', 'EventEndCommand'),
    isset($saved['EventEndCommand']) ? $saved['EventEndCommand'] : null);
}

$shapes = array(
  'nested' => array('Monitor' => array('EventEndCommand' => 'x')),
  'bare, no Monitor entry' => array('EventEndCommand' => 'x'),
  'bare beside an empty Monitor entry (the GHSA shape)' => array('Monitor' => array(), 'EventEndCommand' => 'x'),
  'bare beside an empty-string Monitor entry' => array('Monitor' => '', 'EventEndCommand' => 'x'),
  'bare beside a Monitor entry with data' => array('Monitor' => array('Name' => 'a'), 'EventEndCommand' => 'x'),
  'nested and bare' => array('Monitor' => array('EventEndCommand' => 'nested'), 'EventEndCommand' => 'bare'),
  'a scalar Monitor entry' => array('Monitor' => 'junk', 'EventEndCommand' => 'x'),
  'absent' => array('Monitor' => array('Name' => 'a')),
);
foreach ($shapes as $label => $data) {
  list($checked, $saved) = both($data);
  check("$label: the check sees what is saved", $checked, $saved);
}
check('the GHSA shape saves nothing', both($shapes['bare beside an empty Monitor entry (the GHSA shape)'])[1], null);

done();
