<?php
// Tests the credentials the API hands zmu when it can't mint a token (no usable
// ZM_AUTH_HASH_SECRET) and ZM_AUTH_RELAY=hashed: the user name and an auth hash
// bound to no address, as ZoneMinder's getZmuCommand() passes them.
//
// Run: tests/run.sh test_api_zmu_hashed_no_secret.php

define('ZM_AUTH_HASH_SECRET', '...Change me to something unique...');
define('ZM_AUTH_RELAY', 'hashed');

require_once __DIR__.'/support/api.php';

apiBoot(new FakeDb());
require_once __DIR__.'/../../web/includes/auth.php';
$GLOBALS['user'] = new \ZM\User(array_merge(userRow("o'brien", array('Monitors' => 'Edit')),
  array('Password' => password_hash('pw', PASSWORD_BCRYPT))));
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = array();

echo "no usable secret, hashed relay\n";
$args = \ZM\Api\Auth::zmuCredentials();
check('the user name, quoted, then an auth hash', preg_match("/^ -U 'o'\\\\''brien' -A '[0-9a-f]+'\$/", $args), 1);
check('the hash is the address-free one', $args, " -U 'o'\\''brien' -A '".calculateAuthHash('')."'");

done();
