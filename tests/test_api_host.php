<?php
// Tests the permission checks of host/daemonCheck and host/daemonControl
// (api/src/Controller/HostController.php) through the whole API in this
// process. zmdc.pl isn't run: ZM_PATH_BIN names a directory without it.
//
// What must hold:
//   - host/daemonCheck with no daemon (is ZoneMinder running?) is open to any
//     logged in user: the web UI and clients poll it
//   - asking about one daemon (daemonCheck/zmc/-m 1) needs System=View
//   - daemonControl's check and status need System=View, any other command
//     System=Edit
//
// Run: tests/run.sh test_api_host.php

define('ZM_PATH_BIN', sys_get_temp_dir().'/zm-php-api-no-such-bin');
require_once __DIR__.'/support/api.php';

$users = array(
  'none' => userRow('none'),
  'sysview' => userRow('sysview', array('System' => 'View')),
  'sysedit' => userRow('sysedit', array('System' => 'Edit')),
);
$db = new FakeDb();
$db->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array());
apiBoot($db);

function status($method, $uri, $user) {
  return answered(api($method, $uri, $user))[0];
}

echo "daemonCheck\n";
check('ZoneMinder running?, no permissions: 200', status('GET', 'host/daemonCheck.json', 'none'), 200);
check('without credentials: 401', status('GET', 'host/daemonCheck.json', null), 401);
check('one daemon, no permissions: 401', status('GET', 'host/daemonCheck/zmc/-m%201.json', 'none'), 401);
check('one daemon, System=View: 200', status('GET', 'host/daemonCheck/zmc/-m%201.json', 'sysview'), 200);

echo "daemonControl\n";
check('status, no permissions: 401', status('GET', 'host/daemonControl/zmfilter.pl/status.json', 'none'), 401);
check('status, System=View: 200', status('GET', 'host/daemonControl/zmfilter.pl/status.json', 'sysview'), 200);
check('check, System=View: 200', status('GET', 'host/daemonControl/zmfilter.pl/check.json', 'sysview'), 200);
check('restart, System=View: 401', status('POST', 'host/daemonControl/zmfilter.pl/restart.json', 'sysview'), 401);
check('restart, System=Edit: 200', status('POST', 'host/daemonControl/zmfilter.pl/restart.json', 'sysedit'), 200);

echo "getDiskPercent\n";
check('dots in the monitor id: 404', status('GET', 'host/getDiskPercent/1...json', 'sysview'), 404);
check('an encoded path in the monitor id is split into arguments, as CakePHP splits it: the id is 1abc..',
  status('GET', 'host/getDiskPercent/1abc..%2F..%2Froot.json', 'sysview'), 404);
check('a non-numeric id: 404', status('GET', 'host/getDiskPercent/1abc.json', 'sysview'), 404);

done();
