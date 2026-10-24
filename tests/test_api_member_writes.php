<?php
// Tests writes that must stay within what the caller may change: a group's
// member list (api/src/Controller/GroupsController.php) and user preferences
// (api/src/Controller/UserPreferenceController.php).
//
// What must hold:
//   - a group's members change only through the list the membership check saw:
//     {"Monitor": null} changes nothing, and removing a monitor the user may
//     not edit is a 401 with nothing written
//   - a preference is always written as the caller's (UserId) and never by Id,
//     whichever nesting of UserPreference[...] the request uses
//
// Run: tests/run.sh test_api_member_writes.php

require_once __DIR__.'/support/api.php';

$users = array(
  'grp' => userRow('grp', array('Groups' => 'Edit', 'Monitors' => 'View')),
  'plain' => userRow('plain', array('Events' => 'View')),
);
$db = new FakeDb();
$db->table('Groups', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'ParentId' => 'int(10) unsigned'))
  ->table('User_Preferences', array('Id' => 'int(10) unsigned', 'UserId' => 'int(10) unsigned', 'Name' => 'varchar(64)', 'Value' => 'text'))
  ->table('Monitors', array('Id' => 'int(10) unsigned', 'Name' => 'varchar(64)'))
  ->on('/FROM Users WHERE Enabled=1 AND Username=\?/', function ($sql, $p) use ($users) {
    return isset($users[$p[0]]) ? array($users[$p[0]]) : array();
  })
  ->on('/FROM `(Monitors|Groups)_Permissions`/', array())
  ->on('/Sessions/', array())
  ->on('/^SELECT COUNT\(\*\) FROM `(Groups|User_Preferences)` WHERE `Id` = \?/', array(array('COUNT(*)' => 1)))
  ->on('/FROM `Groups_Monitors` WHERE `GroupId`=\?/', array(array('MonitorId' => 7)))
  ->on('/SELECT \* FROM `Groups` WHERE `Id`=\?/', array(array('Id' => 5, 'Name' => 'g', 'ParentId' => null)))
  ->on('/FROM `Groups` AS `Group`/', array(array('Group.Id' => 5, 'Group.Name' => 'g', 'Group.ParentId' => null)))
  // Monitor 7, a member of group 5; grp may only view it.
  ->on('/FROM `Monitors` WHERE/', array(array('Id' => 7, 'Name' => 'm7', 'Deleted' => 0, 'ServerId' => null)))
  ->on('/FROM `User_Preferences` AS `UserPreference`/', function ($sql, $p) use ($users) {
    // Preference 99 belongs to user 1 (requireOwned() reads it by Id); the
    // caller has none yet (add() looks theirs up by UserId and Name).
    if (strpos($sql, '`UserId`') !== false) return array();
    return array(array('UserPreference.Id' => 99, 'UserPreference.UserId' => 1, 'UserPreference.Name' => 'x', 'UserPreference.Value' => 'v'));
  })
  ->on('/^(DELETE|INSERT|UPDATE)/', array())
  ->on('/./', array());
apiBoot($db);

function writes(FakeDb $db) {
  return array_values(array_filter($db->queries, function ($q) { return preg_match('/^(DELETE|INSERT|UPDATE)/', $q[0]); }));
}

echo "group members\n";
$db->queries = array();
$a = answered(apiJson('PUT', 'groups/5.json', 'grp', '{"Monitor": null}'));
check('{"Monitor": null}: members untouched', array_filter(writes($db), function ($q) { return strpos($q[0], 'Groups_Monitors') !== false; }), array());
$db->queries = array();
$a = answered(apiJson('PUT', 'groups/5.json', 'grp', '{"Monitor": []}'));
check('emptying a group with a monitor the user may not edit: 401', $a[0], 401);
check('and nothing written', writes($db), array());
$db->queries = array();
$a = answered(apiJson('PUT', 'groups/5.json', 'grp', '{"Group": {"MonitorIds": ""}, "Monitor": [1]}'));
check('MonitorIds "" with a Monitor list: still checked, 401', $a[0], 401);

echo "user preferences\n";
$own = $users['plain']['Id'];
$db->queries = array();
$a = answered(api('POST', 'user_preference.json', 'plain', array('UserPreference' => array('Id' => 99, 'UserId' => 1, 'Name' => 'x', 'Value' => 'evil'))));
$w = writes($db);
check('add: one write', count($w), 1);
check('add: an INSERT, not an UPDATE of preference 99', strpos($w[0][0], 'INSERT') === 0, true);
check('add: written as the caller', in_array($own, $w[0][1], true) and !in_array(1, $w[0][1], true), true);
check('add: no Id column', strpos($w[0][0], '`Id`'), false);

$db->queries = array();
$a = answered(api('POST', 'user_preference.json', 'plain', array('Id' => 99, 'UserId' => 1, 'Name' => 'x', 'Value' => 'evil')));
$w = writes($db);
check('add, bare fields: written as the caller, no Id', array(strpos($w[0][0], '`Id`'), in_array($own, $w[0][1], true)), array(false, true));

$db->queries = array();
$a = answered(api('PUT', 'user_preference/99.json', 'plain', array('UserPreference' => array('UserPreference' => array('UserId' => 1, 'Value' => 'evil')))));
check("edit of another user's preference: 401", $a[0], 401);
check('nothing written', writes($db), array());

$a = answered(api('POST', 'user_preference.json', 'plain', array('Name' => array('x'), 'Value' => 'v')));
check('add with an array value: 400', $a[0], 400);

done();
