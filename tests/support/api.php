<?php
// Shared by the API unit tests (tests/php/test_api_*.php): the API's class
// loader, check(), and FakeDb, a connection that answers queries from rows the
// test declares, so models and controllers run without a database.

require_once __DIR__.'/../../../web/api/src/bootstrap.php';

// Output goes straight to STDOUT. Through PHP's output layer it would count as
// sending the response headers, after which PHP sessions refuse to start, which
// the API's authentication does.
ob_start(function ($buffer) { fwrite(STDOUT, $buffer); return ''; }, 1);

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

/** Runs $fn and returns the class and message of what it throws, or 'no exception'. */
function thrown(callable $fn) {
  try {
    $fn();
    return 'no exception';
  } catch (\Throwable $e) {
    return get_class($e).': '.$e->getMessage();
  }
}

function done() {
  global $passed, $failed;
  echo "\n$passed passed, $failed failed\n";
  exit($failed ? 1 : 0);
}

/**
 * Stands in for the PDO connection ZM\Api\Db\Db uses (Db::$connection).
 *
 *   $db = new FakeDb();
 *   $db->table('Monitors', array('Id' => 'int(10) unsigned', 'Enabled' => 'tinyint(1)'));
 *   $db->on('/FROM `Monitors` AS `Monitor`/', array(array('Monitor.Id' => 1, 'Monitor.Enabled' => true)));
 *
 * A row's keys are Alias.Field, Alias__Field (a virtual field) or a bare name
 * (a computed column). Values come back as MySQL gives them: numbers as PHP
 * numbers, booleans as 0/1 in a TINYINT(1) column, so Db::group() types them.
 * SHOW COLUMNS and Compat::hasTable()'s lookup answer from table(). Every
 * statement is kept in $queries.
 */
class FakeDb {
  public $queries = array();
  private $rules = array();
  private $tables = array();
  public $insertId = 1;

  public function table($name, array $columns) {
    $this->tables[$name] = $columns;
    return $this;
  }

  public function has($name) {
    return isset($this->tables[$name]);
  }

  /** Rows (a list, or a function of sql and params) for statements matching $pattern. First match wins. */
  public function on($pattern, $rows) {
    $this->rules[] = array($pattern, $rows);
    return $this;
  }

  public function answer($sql, array $params) {
    $this->queries[] = array($sql, $params);
    if (preg_match('/^SHOW COLUMNS FROM `(\w+)`(?: LIKE \'(\w+)\')?/', $sql, $m)) {
      if (!isset($this->tables[$m[1]])) throw new \RuntimeException("FakeDb: no table() for $m[1]");
      $rows = array();
      foreach ($this->tables[$m[1]] as $field => $type) {
        if (isset($m[2]) and $m[2] !== $field) continue;
        $rows[] = array('Field' => $field, 'Type' => $type, 'Null' => 'YES', 'Default' => null);
      }
      return $rows;
    }
    if (strpos($sql, 'FROM information_schema.TABLES') !== false) {
      // Compat::hasTable()
      return array(array('COUNT(*)' => isset($this->tables[$params[0]]) ? 1 : 0));
    }
    foreach ($this->rules as list($pattern, $rows)) {
      if (preg_match($pattern, $sql)) return is_callable($rows) ? $rows($sql, $params) : $rows;
    }
    throw new \RuntimeException("FakeDb: no rows for $sql");
  }

  /** @var bool PDO::ATTR_EMULATE_PREPARES, off as on ZoneMinder's connection */
  public $emulate = false;
  /** @var bool[] the value of $emulate when each statement was prepared */
  public $emulated = array();
  public function getAttribute($a) { return $a == \PDO::ATTR_EMULATE_PREPARES ? $this->emulate : null; }
  public function setAttribute($a, $v) { if ($a == \PDO::ATTR_EMULATE_PREPARES) $this->emulate = (bool)$v; return true; }

  public function prepare($sql, $options = array()) { $this->emulated[] = $this->emulate; return new FakeStatement($this, $sql); }
  public function query($sql) { $s = $this->prepare($sql); $s->execute(); return $s; }
  public function lastInsertId() { return (string)$this->insertId; }
}

class FakeStatement {
  private $db, $sql, $bound = array(), $meta = array(), $values = array(), $i = 0;
  public function __construct(FakeDb $db, $sql) { $this->db = $db; $this->sql = $sql; }
  public function bindValue($n, $v, $type = null) { $this->bound[$n] = $v; return true; }
  public function bindParam($n, &$v, $type = null, $length = 0, $options = null) { $this->bound[$n] = &$v; return true; }
  public function execute($params = null) {
    $rows = $this->db->answer($this->sql, array_values($params !== null ? $params : $this->bound));
    $this->meta = array();
    $this->values = array();
    foreach ($rows as $r => $row) {
      $c = 0;
      foreach ($row as $key => $v) {
        if ($r == 0) {
          $table = '';
          $name = $key;
          if (strpos($key, '.') !== false) list($table, $name) = explode('.', $key, 2);
          $this->meta[] = array('name' => $name, 'table' => $table,
            'native_type' => is_bool($v) ? 'TINY' : (is_int($v) ? 'LONG' : (is_float($v) ? 'DOUBLE' : 'VAR_STRING')),
            'len' => is_bool($v) ? 1 : 11);
        }
        $this->values[$r][$c++] = is_bool($v) ? (int)$v : $v;
      }
    }
    $this->i = 0;
    return true;
  }
  public function columnCount() { return count($this->meta); }
  public function getColumnMeta($c) { return $this->meta[$c]; }
  public function rowCount() { return count($this->values); }
  public function fetchAll($mode = null) {
    if ($mode == \PDO::FETCH_NUM) return $this->values;
    $out = array();
    while (($row = $this->fetch($mode)) !== false) $out[] = $row;
    return $out;
  }
  public function fetch($mode = null) {
    if ($this->i >= count($this->values)) return false;
    $v = $this->values[$this->i++];
    if ($mode == \PDO::FETCH_NUM) return $v;
    $row = array();
    foreach ($this->meta as $c => $m) $row[$m['name']] = $v[$c];
    return $row;
  }
}

// ------------------------------------------------------------ whole requests

/**
 * Loads what dispatch.php loads, with a stand-in config.php (support/config.php,
 * found through the include path as ZoneMinder's includes look for it) and $db as
 * the database. Call once, before api().
 */
/**
 * The Users columns userRow() fills, as useDb() declares them for a test that
 * declares no Users table itself. With APCu enabled (apc.enable_cli=1, as CI
 * runs), TokenCache::store() reads the Users schema to cache the user's row,
 * and the next request's user is built from the cached columns only: a test
 * that declares Users itself has to declare every column its users carry.
 */
const USERS_COLUMNS = array('Id' => 'int(10) unsigned', 'Username' => 'varchar(32)', 'Password' => 'varchar(64)',
  'Name' => 'varchar(64)', 'Enabled' => 'tinyint(3) unsigned', 'Stream' => "enum('None','View')",
  'Events' => "enum('None','View','Edit')", 'Control' => "enum('None','View','Edit')",
  'Monitors' => "enum('None','View','Edit','Create')", 'Groups' => "enum('None','View','Edit')",
  'Devices' => "enum('None','View','Edit')", 'Snapshots' => "enum('None','View','Edit')",
  'System' => "enum('None','View','Edit')", 'MonitorIds' => 'text', 'Language' => 'varchar(8)', 'HomeView' => 'varchar(64)',
  'TokenMinExpiry' => 'int(10) unsigned',
  'APIEnabled' => 'tinyint(3) unsigned', 'RoleId' => 'int(10) unsigned');

function apiBoot(FakeDb $db) {
  // ZoneMinder's is_session_started() calls a bare Warning() under the PHP CLI
  // (fatal: only ZM\Warning exists) and reports no session there. The API runs
  // under a web server, where neither happens; give the CLI the same answer.
  if (!function_exists('Warning')) { function Warning($message) {} }
  set_include_path(__DIR__.PATH_SEPARATOR.__DIR__.'/../../../web/includes'.PATH_SEPARATOR.get_include_path());
  ini_set('session.save_path', sys_get_temp_dir());
  require_once __DIR__.'/../../../web/vendor/autoload.php';
  // 1.38: including database.php connects (master connects on first use);
  // support/config.php points that at a throwaway SQLite file, and useDb()
  // swaps $db in.
  require_once __DIR__.'/../../../web/includes/database.php';
  useDb($db);
  // After useDb(): 1.38's session handler keeps the connection it finds when
  // auth.php loads session.php (master's looks it up on each use), so its
  // sessions go to $db too. 1.38: including auth.php also authenticates;
  // Compat::requireAuth() loads its functions only, as the API does.
  \ZM\Api\Compat::requireAuth();
}

/** Makes $db the database for the API and for ZoneMinder's includes. */
function useDb(FakeDb $db) {
  \ZM\Api\Compat::reset();  // what it probed came from the previous database
  \ZM\Api\Db\Schema::reset();
  // With APCu on (apc.enable_cli=1), the previous database's columns and cached
  // users are in it too, under the same versions; a new database starts clean.
  if (function_exists('apcu_delete') and class_exists('APCUIterator')
      and (!function_exists('apcu_enabled') or apcu_enabled())) {
    apcu_delete(new \APCUIterator('/^zm-php-api:/'));
  }
  if (!$db->has('Users')) $db->table('Users', USERS_COLUMNS);
  \ZM\Api\Db\Db::$connection = $db;
  $GLOBALS['dbConn'] = $db;
}

/** The Users row of a role, as FakeDb answers a user lookup with it. */
function userRow($name, array $permissions = array(), $apiEnabled = 1) {
  $areas = array('Stream', 'Events', 'Control', 'Monitors', 'Groups', 'Devices', 'Snapshots', 'System');
  $row = array('Id' => crc32($name) % 1000 + 10, 'Username' => $name, 'Password' => '', 'Name' => $name, 'Enabled' => 1,
    'APIEnabled' => $apiEnabled, 'TokenMinExpiry' => 0, 'RoleId' => null, 'MonitorIds' => '');
  foreach ($areas as $a) $row[$a] = isset($permissions[$a]) ? $permissions[$a] : 'None';
  return $row;
}

/**
 * Sends one request through the API in this process (App::handle()), as
 * $username with an access token, or without credentials when $username is
 * null, carrying $cookies. Returns the Response.
 */
function api($method, $uri, $username = null, array $form = array(), array $cookies = array(), array $server = array()) {
  foreach (array('user', 'monitor_permissions', 'group_permissions', 'role_monitor_permissions', 'role_group_permissions') as $g) {
    $GLOBALS[$g] = null;
  }
  $GLOBALS['object_cache'] = array();
  if (session_status() == PHP_SESSION_ACTIVE) session_write_close();
  \ZM\Api\Auth::$ambient = false;
  $_SERVER = array_merge(array('REQUEST_METHOD' => $method, 'REQUEST_URI' => '/zm/api/'.$uri, 'REMOTE_ADDR' => '127.0.0.1', 'DOCUMENT_ROOT' => ''), $server);
  if ($username !== null) {
    $now = time();
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.\Firebase\JWT\JWT::encode(array('iss' => 'ZoneMinder', 'iat' => $now,
      'exp' => $now + 3600, 'user' => $username, 'type' => 'access'), ZM_AUTH_HASH_SECRET, 'HS256');
  }
  $_GET = array();
  $q = parse_url($uri, PHP_URL_QUERY);
  if ($q) parse_str($q, $_GET);
  $_POST = $form;
  $_REQUEST = $_GET + $_POST;
  $_COOKIE = $cookies;
  $request = \ZM\Api\Http\Request::fromGlobals();
  if (isset($GLOBALS['apiJsonBody'])) {
    // php://input can't be written in the CLI: hand the request its body.
    $body = new ReflectionProperty($request, 'body');
    $body->setAccessible(true);
    $body->setValue($request, $GLOBALS['apiJsonBody']);
  }
  return \ZM\Api\App::handle($request);
}

/** api() with a JSON request body ($json, as sent) instead of form fields. */
function apiJson($method, $uri, $username, $json) {
  $GLOBALS['apiJsonBody'] = $json;
  try {
    return api($method, $uri, $username, array(), array(), array('CONTENT_TYPE' => 'application/json'));
  } finally {
    unset($GLOBALS['apiJsonBody']);
  }
}

/** status and decoded body of a Response */
function answered(\ZM\Api\Http\Response $r) {
  return array($r->status, json_decode($r->body, true));
}
