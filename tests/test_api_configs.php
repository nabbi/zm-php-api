<?php
// Tests secrets in /configs (api/src/Controller/ConfigsController.php)
// through the whole API in this process.
//
// What must hold:
//   - a secret (marked Private, or in SECRETS: database credentials, the auth
//     hash secret, upload/MQTT passwords, the Janus secret) is answered with an
//     empty Value by index, view and viewByName
//   - a filter on Value never matches a secret, so its value can't be guessed
//     a character at a time (Value LIKE:a%): the query excludes secret rows
//   - other options filter on Value as before
//
// Run: tests/run.sh test_api_configs.php

require_once __DIR__.'/support/api.php';

$users = array('sysview' => userRow('sysview', array('System' => 'View')));
$rows = array(
  array('Id' => 1, 'Name' => 'ZM_AUTH_HASH_SECRET', 'Value' => 'abc123', 'Private' => 1),
  array('Id' => 2, 'Name' => 'ZM_UPLOAD_PASS', 'Value' => 'ftp-pw', 'Private' => 0),
  array('Id' => 3, 'Name' => 'ZM_LANG_DEFAULT', 'Value' => 'en_gb', 'Private' => 0),
);
$db = new FakeDb();
$db->table('Config', array('Id' => 'smallint(5) unsigned', 'Name' => 'varchar(32)', 'Value' => 'text', 'Private' => 'tinyint(3) unsigned'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT `Value` FROM `Config` WHERE `Name` = \?/', function ($sql, $p) use ($rows) {
    foreach ($rows as $r) if ($r['Name'] === $p[0]) return array(array('Value' => $r['Value']));
    return array();
  })
  ->on('/FROM `Config` AS `Config`/', function ($sql, $p) use ($rows) {
    // As MySQL would: Name =, Value LIKE (prefix), Private = 0 and Name NOT IN.
    $where = strstr($sql, 'WHERE');
    $out = array();
    foreach ($rows as $r) {
      $params = $p;
      $ok = true;
      if (preg_match_all('/`Config`\.`(\w+)` (=|LIKE|NOT IN) (\(([?, ]+)\)|\?)/', (string)$where, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
          if ($c[2] == 'NOT IN') {
            $n = substr_count($c[4], '?');
            $list = array_splice($params, 0, $n);
            if (in_array($r[$c[1]], $list, true)) $ok = false;
          } else {
            $v = array_shift($params);
            if ($c[2] == '=' and (string)$r[$c[1]] !== (string)$v) $ok = false;
            if ($c[2] == 'LIKE' and strpos((string)$r[$c[1]], rtrim($v, '%')) !== 0) $ok = false;
          }
        }
      }
      if ($ok) {
        $row = array();
        foreach ($r as $k => $v) $row["Config.$k"] = $v;
        $out[] = $row;
      }
    }
    return $out;
  });
apiBoot($db);
$GLOBALS['zm_config'] = array();

function names($answer) {
  return array_map(function ($c) { return $c['Config']['Name']; }, $answer[1]['configs']);
}

echo "values answered\n";
$a = answered(api('GET', 'configs.json', 'sysview'));
$values = array();
foreach ($a[1]['configs'] as $c) $values[$c['Config']['Name']] = $c['Config']['Value'];
check('index: secrets empty, the rest as stored', $values,
  array('ZM_AUTH_HASH_SECRET' => '', 'ZM_UPLOAD_PASS' => '', 'ZM_LANG_DEFAULT' => 'en_gb'));
check('viewByName: the auth hash secret empty',
  answered(api('GET', 'configs/viewByName/ZM_AUTH_HASH_SECRET.json', 'sysview'))[1]['config']['Value'], '');
check('viewByName: the upload password empty',
  answered(api('GET', 'configs/viewByName/ZM_UPLOAD_PASS.json', 'sysview'))[1]['config']['Value'], '');

echo "filters on Value\n";
check('the right first letter of the secret matches nothing',
  names(answered(api('GET', 'configs/index/Name:ZM_AUTH_HASH_SECRET/Value%20LIKE:a%25.json', 'sysview'))), array());
check('nor of a non-Private secret',
  names(answered(api('GET', 'configs/index/Name:ZM_UPLOAD_PASS/Value%20LIKE:f%25.json', 'sysview'))), array());
check('nor Value equality',
  names(answered(api('GET', 'configs/index/Value:abc123.json', 'sysview'))), array());
check('an ordinary option still filters on Value',
  names(answered(api('GET', 'configs/index/Value:en_gb.json', 'sysview'))), array('ZM_LANG_DEFAULT'));
check('filters not on Value are unchanged',
  names(answered(api('GET', 'configs/index/Name:ZM_AUTH_HASH_SECRET.json', 'sysview'))), array('ZM_AUTH_HASH_SECRET'));

done();
