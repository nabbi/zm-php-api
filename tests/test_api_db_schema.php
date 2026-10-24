<?php
// Tests how the API looks up table columns (web/api/src/Db/Schema.php) and
// how it prepares its statements (web/api/src/Db/Db.php).
//
// What must hold:
//   - within a request, a table's columns are looked up once, whoever asks
//     (a model, a join table, Compat::hasColumn())
//   - without APCu, each request looks them up again
//   - with APCu, the first request stores them for Schema::TTL seconds and the
//     next requests read them from there, under a key that names ZoneMinder's
//     version, the database schema version, the database and the table, so
//     columns stored for another version are never used
//   - the API's statements are prepared client-side, and ZoneMinder's
//     connection is left with emulated prepares off
//
// Run: tests/run.sh test_api_db_schema.php

define('ZM_VERSION', '1.39.0');
define('ZM_DYN_DB_VERSION', '1.39.0');
define('ZM_DB_HOST', 'db');
define('ZM_DB_NAME', 'zm');
require_once __DIR__.'/support/api.php';

use ZM\Api\Compat;
use ZM\Api\Db\Db;
use ZM\Api\Db\Schema;
use ZM\Api\Model\Frame;

function fakeDb() {
  $db = new FakeDb();
  $db->table('Frames', array('EventId' => 'bigint(20) unsigned', 'FrameId' => 'int(10) unsigned', 'Score' => 'smallint(5) unsigned'))
    ->table('Events_Tags', array('TagId' => 'bigint(20) unsigned', 'EventId' => 'bigint(20) unsigned'));
  $db->on('/^SELECT 1/', array(array('one' => 1)));
  return $db;
}

/** How many SHOW COLUMNS $db has answered. */
function lookups(FakeDb $db) {
  return count(array_filter($db->queries, function ($q) { return strpos($q[0], 'SHOW COLUMNS') === 0; }));
}

/** A new request: what PHP keeps per request is gone, APCu stays. */
function newRequest() {
  Schema::reset();
  Compat::reset();
}

$frames = array(
  'EventId' => array('type' => 'bigint(20) unsigned', 'null' => true, 'default' => null),
  'FrameId' => array('type' => 'int(10) unsigned', 'null' => true, 'default' => null),
  'Score' => array('type' => 'smallint(5) unsigned', 'null' => true, 'default' => null),
);

echo "within a request\n";
$db = fakeDb();
useDb($db);
check('columns from SHOW COLUMNS', Schema::columns('Frames'), $frames);
$model = new Frame();
check('the model reads the same', $model->columns(), array('EventId', 'FrameId', 'Score'));
check('Compat::hasColumn() too', array(Compat::hasColumn('Frames', 'FrameId'), Compat::hasColumn('Frames', 'Id')), array(true, false));
check('one lookup per table', lookups($db), 1);

echo "without APCu\n";
if (function_exists('apcu_fetch')) {
  echo "  (skipped: this PHP has APCu)\n";
} else {
  $db = fakeDb();
  useDb($db);
  Schema::columns('Frames');
  newRequest();
  check('the next request looks again', Schema::columns('Frames'), $frames);
  check('one lookup per request', lookups($db), 2);
}

// APCu: the real one when the CLI has it enabled, else an in-memory stand-in.
if (!function_exists('apcu_fetch')) {
  $GLOBALS['apcu'] = array();
  function apcu_fetch($k, &$hit = null) {
    $hit = isset($GLOBALS['apcu'][$k]);
    return $hit ? $GLOBALS['apcu'][$k]['value'] : false;
  }
  function apcu_store($k, $v, $ttl = 0) { $GLOBALS['apcu'][$k] = array('value' => $v, 'ttl' => $ttl); return true; }
  function apcu_ttl($k) { return isset($GLOBALS['apcu'][$k]) ? $GLOBALS['apcu'][$k]['ttl'] : null; }
  function apcu_reset() { $GLOBALS['apcu'] = array(); }
} elseif (apcu_enabled()) {
  function apcu_ttl($k) { $i = apcu_key_info($k); return $i ? $i['ttl'] : null; }
  function apcu_reset() { apcu_clear_cache(); }
} else {
  echo "\nAPCu is loaded but disabled for the CLI (apc.enable_cli=0): its cases are skipped\n";
  done();
}

echo "with APCu\n";
apcu_reset();
$db = fakeDb();
useDb($db);
$key = Schema::key('Frames');
check('the key names the versions, the database and the table', $key, 'zm-php-api:columns:1.39.0:1.39.0:db:zm:Frames');
Schema::columns('Frames');
check('a miss looks the columns up', lookups($db), 1);
check('and stores them', apcu_fetch($key), $frames);
check('for Schema::TTL seconds', apcu_ttl($key), Schema::TTL);
newRequest();
check('a hit answers from APCu', Schema::columns('Frames'), $frames);
check('without a lookup', lookups($db), 1);

echo "a stored entry of another version is not used\n";
$db = fakeDb();
useDb($db);
$stale = array('Id' => array('type' => 'bigint(20) unsigned', 'null' => false, 'default' => null)) + $frames;
foreach (array(
  'another release' => 'zm-php-api:columns:1.38.0:1.39.0:db:zm:Frames',
  'another schema version' => 'zm-php-api:columns:1.39.0:1.38.5:db:zm:Frames',
  'another database' => 'zm-php-api:columns:1.39.0:1.39.0:db:zm2:Frames',
) as $label => $other) {
  apcu_reset();
  newRequest();
  apcu_store($other, $stale, Schema::TTL);
  check($label, Schema::columns('Frames'), $frames);
}
check('each looked up from the database', lookups($db), 3);
check('Compat::hasColumn() is not fooled', Compat::hasColumn('Frames', 'Id'), false);

echo "prepares\n";
$db = fakeDb();
useDb($db);
Db::value('SELECT 1');
check('the API\'s statement is prepared client-side', $db->emulated, array(PHP_VERSION_ID >= 80100));
check('the connection is left as ZoneMinder set it', $db->emulate, false);
$db->emulated = array();
$db->on('/^SELECT 2/', function () { throw new \RuntimeException('no such table'); });
thrown(function () { Db::value('SELECT 2'); });
check('also after a statement that failed', $db->emulate, false);
$db->emulate = true;
$db->emulated = array();
Db::value('SELECT 1');
check('a connection that emulates already is left on', array($db->emulated, $db->emulate), array(array(true), true));

done();
