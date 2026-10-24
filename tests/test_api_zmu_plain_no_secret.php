<?php
// Tests the credentials the API hands zmu when it can't mint a token: no usable
// ZM_AUTH_HASH_SECRET (here the shipped default) and ZM_AUTH_RELAY=plain, where
// zmu accepts only the password. It used to get the request's own pass=; the
// session (the web UI login keeps it with this relay) is the second choice.
//
// Run: php tests/php/test_api_zmu_plain_no_secret.php   (from the repo root)

define('ZM_AUTH_HASH_SECRET', '...Change me to something unique...');
define('ZM_AUTH_RELAY', 'plain');

require_once __DIR__.'/support/api.php';

apiBoot(new FakeDb());
require_once __DIR__.'/../../web/includes/auth.php';
$GLOBALS['user'] = new \ZM\User(userRow('admin', array('Monitors' => 'Edit')));
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = array();

echo "no usable secret, plain relay\n";
check('the secret counts as unset', authHashSecretIsSet(), false);
check("the request's password", \ZM\Api\Auth::zmuCredentials('from request'), " -U 'admin' -P 'from request'");
$_SESSION['password'] = 'from session';
check("the request's password over the session's", \ZM\Api\Auth::zmuCredentials('from request'), " -U 'admin' -P 'from request'");
check("else the session's", \ZM\Api\Auth::zmuCredentials(), " -U 'admin' -P 'from session'");
check("an empty request password falls back too", \ZM\Api\Auth::zmuCredentials(''), " -U 'admin' -P 'from session'");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = array();
check('neither: no credentials, zmu refuses', \ZM\Api\Auth::zmuCredentials(), '');
check('quoted for the shell', \ZM\Api\Auth::zmuCredentials("it's; rm"), " -U 'admin' -P 'it'\\''s; rm'");

done();
