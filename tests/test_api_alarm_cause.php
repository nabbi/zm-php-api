<?php
// Tests the cause of monitors/alarm/command:on (api/src/Controller/MonitorsController.php)
// through the whole API in this process, with a stand-in zmu that records its arguments.
//
// What must hold:
//   - the cause (query or body) reaches zmu as one argument after --cause
//   - only letters, digits, spaces and plain punctuation are kept, at most 31 bytes cut on
//     a character boundary; nothing usable left means no --cause ("Forced Web")
//   - a zmu without --cause (1.38, master before --cause) gets no --cause: it would print
//     its usage and exit 0 without forcing the alarm
//   - off, disable and status never pass a cause; a user without edit rights runs no zmu
//
// The stand-in zmu is a shell script, so the scratch copy must be on a file system that
// allows exec (TMPDIR=<dir> tests/run.sh where /tmp is noexec).
//
// Run: tests/run.sh test_api_alarm_cause.php

define('ZM_PATH_BIN', __DIR__.'/alarm-cause-bin');
require_once __DIR__.'/support/api.php';

@mkdir(ZM_PATH_BIN);
$argsFile = ZM_PATH_BIN.'/args';
$usageFile = ZM_PATH_BIN.'/usage';
// -h prints the usage file; any other call writes one argument per line to the args file.
file_put_contents(ZM_PATH_BIN.'/zmu', "#!/bin/sh\n"
  ."if [ \"\$1\" = -h ]; then cat ".escapeshellarg($usageFile)."; exit 0; fi\n"
  .": > ".escapeshellarg($argsFile)."\n"
  ."for a in \"\$@\"; do printf '%s\\n' \"\$a\" >> ".escapeshellarg($argsFile)."; done\n"
  ."echo 1\n");
chmod(ZM_PATH_BIN.'/zmu', 0755);

function zmuUsage($withCause) {
  global $usageFile;
  file_put_contents($usageFile, "  -a, --alarm  : Force alarm\n".($withCause ? "      --cause <text> : the cause\n" : ''));
  touch(ZM_PATH_BIN.'/zmu', time() + ($withCause ? 1 : 2));  // a new mtime: a new APCu key
  clearstatcache();
  \ZM\Api\Compat::reset();
}

/** The arguments zmu got for one request, or null when it didn't run. */
function zmuArgs($method, $uri, $username, array $form = array()) {
  global $argsFile;
  @unlink($argsFile);
  $status = answered(api($method, $uri, $username, $form))[0];
  return array($status, is_file($argsFile) ? file($argsFile, FILE_IGNORE_NEW_LINES) : null);
}

/** The value after --cause, or null. */
function causeArg($args) {
  if (!is_array($args)) return null;
  $i = array_search('--cause', $args, true);
  return $i === false ? null : $args[$i + 1];
}

$users = array(
  'editor' => userRow('editor', array('Monitors' => 'Edit', 'Events' => 'View', 'Stream' => 'View')),
  'viewer' => userRow('viewer', array('Monitors' => 'View', 'Events' => 'View', 'Stream' => 'View')),
);
$db = new FakeDb();
$db->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `Monitors` WHERE `Id` = \?/', function ($sql, $p) { return array(array('COUNT(*)' => $p[0] == '1' ? 1 : 0)); })
  ->on('/FROM `Monitors`/', array(array('Id' => 1, 'Name' => 'front')));
apiBoot($db);

$on = 'monitors/alarm/id:1/command:on.json';

echo "zmu with --cause\n";
zmuUsage(true);
list($s, $a) = zmuArgs('POST', $on.'?cause=HA-test', 'editor');
check('200, -a and the cause as one argument', array($s, in_array('-a', $a ?: array(), true), causeArg($a)), array(200, true, 'HA-test'));
list($s, $a) = zmuArgs('POST', $on, 'editor', array('cause' => 'Doorbell (front)'));
check('cause from the body', causeArg($a), 'Doorbell (front)');
list($s, $a) = zmuArgs('POST', $on, 'editor');
check('no cause: no --cause', array($s, causeArg($a), is_array($a)), array(200, null, true));
list($s, $a) = zmuArgs('POST', $on.'?cause='.rawurlencode("x'; touch /tmp/p \$(id) `id` | y"), 'editor');
check('shell metacharacters: one argument, $ | backtick and quote dropped', causeArg($a), 'x; touch /tmp/p (id) id y');
list($s, $a) = zmuArgs('POST', $on.'?cause='.rawurlencode('<svg onload=alert(1)>'), 'editor');
check('markup dropped', causeArg($a), 'svg onload=alert(1)');
list($s, $a) = zmuArgs('POST', $on.'?cause=a%00b%0Ac', 'editor');
check('NUL and newline dropped, no 500', array($s, causeArg($a)), array(200, 'abc'));
list($s, $a) = zmuArgs('POST', $on.'?cause=%3C%3E%22%27%26', 'editor');
check('nothing usable left: no --cause', causeArg($a), null);
list($s, $a) = zmuArgs('POST', $on.'?cause=ok%C3%28', 'editor');
check('invalid UTF-8: no --cause', causeArg($a), null);
list($s, $a) = zmuArgs('POST', $on.'?cause[]=x', 'editor');
check('array cause: no --cause', array($s, causeArg($a)), array(200, null));
list($s, $a) = zmuArgs('POST', $on.'?cause=--help', 'editor');
check('a leading dash stays the value of --cause', causeArg($a), '--help');
list($s, $a) = zmuArgs('POST', $on.'?cause='.str_repeat('a', 30).'%C3%A9', 'editor');
check('cut at 31 bytes on a character boundary', causeArg($a), str_repeat('a', 30));
list($s, $a) = zmuArgs('POST', $on.'?cause='.rawurlencode('Türklingel'), 'editor');
check('non-ASCII letters kept', causeArg($a), 'Türklingel');
foreach (array('off', 'disable', 'status') as $cmd) {
  list($s, $a) = zmuArgs('POST', "monitors/alarm/id:1/command:$cmd.json?cause=x", 'editor');
  check("$cmd: no --cause", array($s, causeArg($a), is_array($a)), array(200, null, true));
}
list($s, $a) = zmuArgs('POST', $on.'?cause=x', 'viewer');
check('viewer: 401 and zmu not run', array($s, $a), array(401, null));

echo "zmu without --cause (1.38, master before --cause)\n";
zmuUsage(false);
list($s, $a) = zmuArgs('POST', $on.'?cause=HA-test', 'editor');
check('200, -a, no --cause', array($s, in_array('-a', $a ?: array(), true), causeArg($a)), array(200, true, null));

done();
