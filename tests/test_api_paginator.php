<?php
// Tests the Paginator (web/api/src/Paginator.php): the "pagination" block that
// paged clients read (zmNinja walks events and logs page by page on it), and
// how page, limit, sort and direction from the request reach the query.
//
//   {"page": 2, "current": 2, "count": 5, "prevPage": true, "nextPage": true,
//    "pageCount": 3, "order": {...}, "limit": 2, "options": {...},
//    "paramType": "named", "queryScope": null}
//
// The model is a stub that hands back canned rows and records what it was
// asked for, so this needs neither a database nor FakeDb.
//
// Run: php tests/php/test_api_paginator.php   (from the repo root)

require_once __DIR__.'/support/api.php';

use ZM\Api\Db\Model;
use ZM\Api\Paginator;

class StubLog extends Model {
  const TABLE = 'Logs';
  const ALIAS = 'Log';
  public $total = 5;
  public $asked = array();
  protected $virtualFields = array('Age' => 'NOW() - Log.TimeKey');
  public function schema() {
    return array('Id' => array(), 'TimeKey' => array(), 'Message' => array());
  }
  public function find($type = 'all', array $options = array()) {
    $this->asked[$type] = $options;
    if ($type == 'count') return $this->total;
    $limit = $options['limit'];
    $first = ($options['page'] - 1) * $limit;
    $rows = array();
    for ($i = $first; $i < min($first + $limit, $this->total); $i++) $rows[] = array('Log' => array('Id' => $i + 1));
    return $rows;
  }
}

function page(array $settings, array $named = array(), array $query = array(), $total = 5) {
  $model = new StubLog();
  $model->total = $total;
  list($rows, $block) = (new Paginator($settings))->paginate($model, $named, $query);
  return array($rows, $block, $model);
}

echo "the block\n";
list($rows, $block) = page(array('limit' => 2), array('page' => '2'));
// "options" holds what differs from the controller's settings. A controller that
// sets no conditions gets "conditions": [] there, as CakePHP's answers had it
// (users/index).
check('page 2 of 3', $block, array('page' => 2, 'current' => 2, 'count' => 5, 'prevPage' => true, 'nextPage' => true,
  'pageCount' => 3, 'order' => null, 'limit' => 2, 'options' => array('page' => 2, 'conditions' => array()), 'paramType' => 'named', 'queryScope' => null));
check('as JSON', json_encode($block),
  '{"page":2,"current":2,"count":5,"prevPage":true,"nextPage":true,"pageCount":3,"order":null,"limit":2,"options":{"page":2,"conditions":[]},"paramType":"named","queryScope":null}');
check('settings that match the defaults are left out of options', page(array('limit' => 2, 'conditions' => array('Level' => 1)), array('page' => '2'))[1]['options'],
  array('page' => 2));
check('the rows of that page', array_column(array_column($rows, 'Log'), 'Id'), array(3, 4));
list(, $block) = page(array('limit' => 2), array('page' => '3'));
check('last page', array($block['current'], $block['prevPage'], $block['nextPage']), array(1, true, false));
list(, $block) = page(array());
check('defaults: page 1, limit 20, everything on it', array($block['page'], $block['limit'], $block['pageCount'], $block['prevPage'], $block['nextPage'], $block['options']),
  array(1, 20, 1, false, false, array('conditions' => array())));
list($rows, $block, $model) = page(array(), array(), array(), 0);
check('no rows: count 0, page 1, no count query', array($rows, $block['count'], $block['page'], $block['pageCount'], isset($model->asked['count'])),
  array(array(), 0, 1, 0, false));

echo "page and limit from the request\n";
check('past the last page is a 404', thrown(function () { page(array('limit' => 2), array('page' => '4')); }),
  'ZM\Api\Http\NotFoundException: Not Found');
check('page 0 or junk is page 1', array(page(array(), array('page' => '0'))[1]['page'], page(array(), array('page' => 'x'))[1]['page']), array(1, 1));
check('limit from the request', page(array(), array('limit' => '3'))[1]['limit'], 3);
check('limit capped by maxLimit', page(array('maxLimit' => 100), array('limit' => '5000'))[1]['limit'], 100);
check('limit below 1 is 1', page(array(), array('limit' => '-5'))[1]['limit'], 1);
check('named arguments only when paramType is named', page(array(), array(), array('limit' => '3'))[1]['limit'], 20);
list(, $block) = page(array('paramType' => 'querystring'), array('limit' => '9'), array('limit' => '3'));
check('querystring: the query string, not named arguments', array($block['limit'], $block['paramType'], $block['options']),
  array(3, 'querystring', array('limit' => 3, 'conditions' => array())));
check('other parameters are ignored', page(array(), array('conditions' => 'x', 'recursive' => '2'))[2]->asked['all'],
  array('conditions' => array(), 'order' => null, 'limit' => 20, 'page' => 1));

echo "sort and direction\n";
list(, $block, $model) = page(array(), array('sort' => 'TimeKey', 'direction' => 'desc'));
check('sort on a column, qualified', array($block['order'], $model->asked['all']['order']), array(array('Log.TimeKey' => 'desc'), array('Log.TimeKey' => 'desc')));
check('a bad direction is asc', page(array(), array('sort' => 'Id', 'direction' => 'sideways'))[1]['order'], array('Log.Id' => 'asc'));
check('sort on a virtual field', page(array(), array('sort' => 'Age'))[1]['order'], array('Age' => 'asc'));
check('sort on an unknown field is dropped', page(array(), array('sort' => 'Password'))[1]['order'], array());
check('sort on another model is dropped', page(array(), array('sort' => 'User.Name'))[1]['order'], array());
check('the default order, written "Field dir"', page(array('order' => array('TimeKey desc')))[1]['order'], array('Log.TimeKey' => 'desc'));

echo "what reaches the query\n";
list(, , $model) = page(array('limit' => 2, 'conditions' => array('Level' => 1), 'recursive' => -1), array('page' => '2'));
check('find(all) gets conditions, order, limit, page and recursive', $model->asked['all'],
  array('conditions' => array('Level' => 1), 'order' => null, 'limit' => 2, 'page' => 2, 'recursive' => -1));
check('count gets the same conditions', $model->asked['count'], array('conditions' => array('Level' => 1), 'recursive' => -1));
$model = new StubLog();
(new Paginator(array('conditions' => array('Level' => 1))))->paginate($model, array(), array(), array('Component' => 'zmc'));
check('extra conditions are added', $model->asked['all']['conditions'], array('Level' => 1, 'Component' => 'zmc'));

done();
