<?php
// Tests Controller::requirePermission() (api/src/Controller/Controller.php) with
// user roles, through the whole API in this process.
//
// What must hold:
//   - an area permission is granted by the user's own field or by their role's
//     (User_Roles), as ZM\User::canView()/canEdit() and the web UI grant it
//     (ZoneMinder master and release-1.38, GHSA-ff93, GHSA-rmf7); a role with
//     None adds nothing, and a RoleId with no role row counts as no role
//   - View needs a level other than None; Edit needs Edit (or Create)
//   - a check on an area that is not a permission area fails closed
//
// Run: tests/run.sh test_api_role_permissions.php

require_once __DIR__.'/support/api.php';

$roles = array(
  1 => array('Id' => 1, 'Name' => 'viewers', 'System' => 'View', 'Events' => 'View'),
  2 => array('Id' => 2, 'Name' => 'editors', 'System' => 'Edit', 'Events' => 'Edit'),
  3 => array('Id' => 3, 'Name' => 'nothing', 'System' => 'None', 'Events' => 'None'),
);
foreach ($roles as &$r) {
  foreach (array('Stream', 'Control', 'Monitors', 'Groups', 'Devices', 'Snapshots') as $a) $r[$a] = 'None';
  $r['Description'] = '';
}
unset($r);
$users = array(
  'nobody' => userRow('nobody'),
  'ownview' => userRow('ownview', array('System' => 'View')),
  'roleview' => array_merge(userRow('roleview'), array('RoleId' => 1)),
  'roleedit' => array_merge(userRow('roleedit'), array('RoleId' => 2)),
  'rolenone' => array_merge(userRow('rolenone'), array('RoleId' => 3)),
  'rolegone' => array_merge(userRow('rolegone'), array('RoleId' => 99)),
  'bothview' => array_merge(userRow('bothview', array('System' => 'View')), array('RoleId' => 3)),
);

$db = new FakeDb();
$db->table('Users', array('Id' => 'int(10) unsigned', 'Username' => 'varchar(32)', 'Password' => 'varchar(64)',
    'System' => "enum('None','View','Edit')", 'Events' => "enum('None','View','Edit')", 'RoleId' => 'int(10) unsigned'))
  ->table('States', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'Definition' => 'text', 'IsActive' => 'tinyint(3) unsigned'))
  ->table('Tags', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'CreateDate' => 'datetime',
    'CreatedBy' => 'int(10) unsigned', 'LastAssignedDate' => 'datetime'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `?User_Roles`? WHERE/', function ($sql, $p) use ($roles) {
    return isset($roles[$p[0]]) ? array($roles[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/FROM `?Role_(Monitors|Groups)_Permissions`?/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\)/', array(array('COUNT(*)' => 0)))
  ->on('/FROM `(States|Tags)` AS/', array());
apiBoot($db);

function status($method, $uri, $user) {
  return answered(api($method, $uri, $user))[0];
}

echo "View: System (states)\n";
check('no permission, no role: 401', status('GET', 'states.json', 'nobody'), 401);
check('own View: 200', status('GET', 'states.json', 'ownview'), 200);
check('own None, role View: 200', status('GET', 'states.json', 'roleview'), 200);
check('own None, role Edit: 200', status('GET', 'states.json', 'roleedit'), 200);
check('own None, role None: 401', status('GET', 'states.json', 'rolenone'), 401);
check('own None, RoleId without a role: 401', status('GET', 'states.json', 'rolegone'), 401);
check('own View, role None: 200 (the own field still counts)', status('GET', 'states.json', 'bothview'), 200);

echo "Edit: System (delete a state; 404 means the permission check passed)\n";
check('own View: 401', status('DELETE', 'states/7.json', 'ownview'), 401);
check('own None, role View: 401', status('DELETE', 'states/7.json', 'roleview'), 401);
check('own None, role Edit: past the check', status('DELETE', 'states/7.json', 'roleedit'), 404);
check('own None, role None: 401', status('DELETE', 'states/7.json', 'rolenone'), 401);

echo "View: Events (tags), as CakePHP's canView('Events')\n";
check('no permission, no role: 401', status('GET', 'tags.json', 'nobody'), 401);
check('own None, role View: 200', status('GET', 'tags.json', 'roleview'), 200);
check('own None, role None: 401', status('GET', 'tags.json', 'rolenone'), 401);

echo "an unknown area fails closed\n";
$GLOBALS['user'] = new \ZM\User(array_merge(userRow('root', array('System' => 'Edit', 'Events' => 'Edit')), array('RoleId' => 2)));
$controller = (new ReflectionClass(\ZM\Api\Controller\StatesController::class))->newInstanceWithoutConstructor();
$require = new ReflectionMethod($controller, 'requirePermission');
$require->setAccessible(true);
check('System Edit holds', thrown(function () use ($require, $controller) { $require->invoke($controller, 'System', 'Edit'); }), 'no exception');
check('Bogus View: refused', thrown(function () use ($require, $controller) { $require->invoke($controller, 'Bogus', 'View'); }),
  'ZM\Api\Http\UnauthorizedException: Insufficient Privileges');
check('Id View (a column, not a permission): refused', thrown(function () use ($require, $controller) { $require->invoke($controller, 'Id', 'View'); }),
  'ZM\Api\Http\UnauthorizedException: Insufficient Privileges');
$GLOBALS['user'] = null;

done();
