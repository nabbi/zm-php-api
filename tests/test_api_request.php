<?php
// Tests how the API reads a request: web/api/src/Http/Request.php and
// Router.php in the native API.
//
// These rules are a published interface. Clients (zmNinja, pyzm, zm-py,
// Home Assistant, zmeventnotification) were written against CakePHP 2's
// parsing, and the native API has to read their requests the same way:
//
//   - the extension picks the format, and the path is cut at its first
//     occurrence, so host/daemonCheck/zmfilter.pl.json is zmfilter.pl as json
//   - Name:value path segments are named parameters, Name[key]:value nests,
//     both URL-decoded; the rest are positional
//   - <resource>, <resource>/<id> map to index/add/view/edit/delete by method,
//     anything else is <controller>/<action>/<args>
//   - form fields may come as Model[Field] or data[Model][Field], and
//     _method / X-HTTP-Method-Override change the method
//
// None of this needs a database, so it runs on its own.
//
// Run: php tests/php/test_api_request.php   (from the repo root)

require_once(__DIR__.'/../../web/api/src/bootstrap.php');

use ZM\Api\Auth;
use ZM\Api\Http\Request;
use ZM\Api\Http\Router;
use ZM\Api\Http\RoutingException;

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

// A request as the web server hands it over: $_SERVER, $_GET and $_POST.
function request($method, $uri, array $post = array(), array $server = array()) {
  $_SERVER = array_merge(array('REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri), $server);
  $query = parse_url($uri, PHP_URL_QUERY);
  $_GET = array();
  if ($query) parse_str($query, $_GET);
  $_POST = $post;
  return Request::fromGlobals();
}

// [controller, action, positional args, named args] for a request.
function route($method, $uri, array $post = array()) {
  try {
    $r = Router::resolve(request($method, $uri, $post));
    return array($r->name, $r->action, $r->pass, $r->named);
  } catch (RoutingException $e) {
    return $e->clientName();
  }
}

echo "path, base and format\n";
$r = request('GET', '/zm/api/monitors.json');
check('base path is everything up to /api', $r->base, '/zm/api');
check('.json is taken off the path', $r->path, 'monitors');
check('.json answers json', $r->format, 'json');
$r = request('GET', '/zoneminder/api/events/index/MonitorId:1.xml');
check('another install prefix', $r->base, '/zoneminder/api');
check('.xml answers xml', $r->format, 'xml');
check('segments', $r->segments, array('events', 'index', 'MonitorId:1'));
$r = request('GET', '/zm/api/host/daemonCheck/zmfilter.pl.json');
check('cut at the first occurrence of the extension', $r->segments, array('host', 'daemonCheck', 'zmfilter.pl'));
$r = request('GET', '/zm/api/monitors', array(), array('HTTP_ACCEPT' => 'application/xml'));
check('no extension, Accept xml: xml', $r->format, 'xml');
$r = request('GET', '/zm/api/monitors', array(), array('HTTP_ACCEPT' => 'application/json, application/xml'));
check('no extension, Accept json and xml: json', $r->format, 'json');
$r = request('GET', '/zm/api/monitors.json?token=abc&x[]=1');
check('query string read from $_GET', $r->query('token'), 'abc');
check('here() keeps the extension, re-encodes the query, hides the token', $r->here(), '/zm/api/monitors.json?token=[redacted]&x%5B0%5D=1');
$r = request('GET', '/zm/api/host/login.json?user=admin&pass=s3cret&PASSWORD=x&auth=abc123&token[]=t&tokens=keep&pass2=keep&passed=');
check('here() hides credential values, any case, arrays too, and nothing else',
  $r->here(), '/zm/api/host/login.json?user=admin&pass=[redacted]&PASSWORD=[redacted]&auth=[redacted]&token%5B0%5D=[redacted]&tokens=keep&pass2=keep&passed=');
$r = request('GET', '/zm/api/monitors.json?token=&x=1');
check('here() leaves an empty value empty', $r->here(), '/zm/api/monitors.json?token=&x=1');

echo "resources\n";
check('GET resource: index', route('GET', '/zm/api/monitors.json'), array('monitors', 'index', array(), array()));
check('POST resource: add', route('POST', '/zm/api/monitors.json'), array('monitors', 'add', array(), array()));
check('GET resource/id: view', route('GET', '/zm/api/monitors/7.json'), array('monitors', 'view', array('7'), array()));
check('PUT resource/id: edit', route('PUT', '/zm/api/monitors/7.json'), array('monitors', 'edit', array('7'), array()));
check('POST resource/id: edit', route('POST', '/zm/api/monitors/7.json'), array('monitors', 'edit', array('7'), array()));
check('DELETE resource/id: delete', route('DELETE', '/zm/api/monitors/7.json'), array('monitors', 'delete', array('7'), array()));
check('UUID ids', route('GET', '/zm/api/events/0a1b2c3d-0000-4000-8000-00000000abcd.json')[1], 'view');
check('configs/<Name>', route('GET', '/zm/api/configs/ZM_LANG_DEFAULT.json'), array('configs', 'view', array('ZM_LANG_DEFAULT'), array()));
check('storage/<id> routes (was a 404 on CakePHP)', route('GET', '/zm/api/storage/1.json')[1], 'view');
check('PUT resource falls through to index', route('PUT', '/zm/api/monitors.json')[1], 'index');
check('alias models -> camera_models', route('GET', '/zm/api/models.json')[0], 'camera_models');
check('alias zonepresets -> zone_presets', route('GET', '/zm/api/zonepresets.json')[0], 'zone_presets');

echo "controller/action/args\n";
check('non-CRUD action with args', route('GET', '/zm/api/monitors/alarm/id:1/command:on.json'),
  array('monitors', 'alarm', array(), array('id' => '1', 'command' => 'on')));
check('named and positional mixed', route('GET', '/zm/api/events/consoleEvents/1%20hour.json'),
  array('events', 'consoleEvents', array('1 hour'), array()));
check('host/daemonCheck keeps the dotted daemon name', route('GET', '/zm/api/host/daemonCheck/zmfilter.pl.json')[2], array('zmfilter.pl'));
check('user_preference camelizes', Router::controllerClass('user_preference'), 'ZM\\Api\\Controller\\UserPreferenceController');
check('unknown controller', route('GET', '/zm/api/nosuch.json'), 'MissingControllerException');
check('Monitors camelizes to MonitorsController too', route('GET', '/zm/api/Monitors.json')[0], 'Monitors');
check('other capitals do not', route('GET', '/zm/api/MONITORS.json'), 'MissingControllerException');
check('no path: pages/display/home', route('GET', '/zm/api/'), array('pages', 'display', array('home'), array()));

echo "named parameters\n";
check('URL-decoded key and value', route('GET', '/zm/api/events/index/StartDateTime%20%3E%3D:2026-01-01%2000%3A00.json')[3],
  array('StartDateTime >=' => '2026-01-01 00:00'));
check('value may contain colons', Router::splitArgs(array('StartTime:12:30:00')),
  array(array(), array('StartTime' => '12:30:00')));
check('Name[key]:value nests', Router::splitArgs(array('Monitor[Id]:3')),
  array(array(), array('Monitor' => array('Id' => '3'))));
check('Name[]:value lists', Router::splitArgs(array('Id[]:1', 'Id[]:2')),
  array(array(), array('Id' => array('1', '2'))));
check('repeated names collect', Router::splitArgs(array('MonitorId:1', 'MonitorId:2')),
  array(array(), array('MonitorId' => array('1', '2'))));
check('empty segments skipped', Router::splitArgs(array('', 'a', '')), array(array('a'), array()));
check('an encoded separator (zmNinjaNg: encodeURIComponent of the segment)', route('GET', '/zm/api/events/index/MonitorId%3A1.json')[3],
  array('MonitorId' => '1'));
check('a whole encoded comparison', route('GET', '/zm/api/events/index/StartDateTime%20%3E%3D%3A2026-10-13%2008%3A35%3A05.json')[3],
  array('StartDateTime >=' => '2026-10-13 08:35:05'));
check('+ is a space, as CakePHP decodes', route('GET', '/zm/api/events/index/StartDateTime+>=:2026-01-01.json')[3],
  array('StartDateTime >=' => '2026-01-01'));
check('an encoded / separates segments, as in CakePHP', route('GET', '/zm/api/events/index/MonitorId:5%2FMonitorId:1.json')[3],
  array('MonitorId' => array('5', '1')));
check('an encoded controller, action and id are those names', array(route('GET', '/zm/api/mon%69tors%2F1.json'), route('GET', '/zm/api/events/ind%65x/MonitorId:3.json')),
  array(array('monitors', 'view', array('1'), array()), array('events', 'index', array(), array('MonitorId' => '3'))));
check('decoded once: %253A is a literal %3A, not a separator', route('GET', '/zm/api/events/consoleEvents/a%253Ab.json')[2], array('a%3Ab'));
check('a positional argument is decoded too', route('GET', '/zm/api/events/consoleEvents/1%20hour.json')[2], array('1 hour'));

echo "request body\n";
$r = request('POST', '/zm/api/monitors.json', array('Monitor' => array('Name' => 'x')));
check('Model[Field] form fields', $r->data('Monitor.Name'), 'x');
check('missing field is null', $r->data('Monitor.Function'), null);
$r = request('POST', '/zm/api/monitors.json', array('data' => array('Monitor' => array('Name' => 'x'))));
check('data[Model][Field] unwrapped', $r->data('Monitor.Name'), 'x');
$r = request('POST', '/zm/api/monitors.json', array('Monitor' => array('Name' => 'a', 'Type' => 'Ffmpeg'), 'data' => array('Monitor' => array('Name' => 'b'))));
check('data[...] wins over bare fields', $r->data, array('Monitor' => array('Name' => 'b', 'Type' => 'Ffmpeg')));
$r = request('POST', '/zm/api/monitors/1.json', array('_method' => 'PUT', 'Monitor' => array('Name' => 'x')));
check('_method overrides the method', $r->method, 'PUT');
check('_method is not left in the data', $r->data, array('Monitor' => array('Name' => 'x')));
check('_method PUT on resource/id routes to edit', Router::resolve($r)->action, 'edit');
$r = request('POST', '/zm/api/monitors/1.json', array('Monitor' => array('Name' => 'x')), array('HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE'));
check('X-HTTP-Method-Override', $r->method, 'DELETE');
$r = request('POST', '/zm/api/monitors.json', array('_method' => 'GET', 'Monitor' => array('Name' => 'x')));
check('override to GET drops the body', array($r->method, $r->data), array('GET', array()));
check('merge: later keys win, lists append', Request::merge(array('a' => 1, 'l' => array(1), 'n' => array('x' => 1)), array('a' => 2, 'l' => array(2), 'n' => array('y' => 2))),
  array('a' => 2, 'l' => array(1, 2), 'n' => array('x' => 1, 'y' => 2)));

echo "credentials\n";
$r = request('GET', '/zm/api/monitors.json?token=q');
check('token= in the query', Auth::token($r), 'q');
$r = request('GET', '/zm/api/monitors.json', array(), array('HTTP_AUTHORIZATION' => 'Bearer abc.def.ghi'));
check('Authorization: Bearer', Auth::token($r), 'abc.def.ghi');
$r = request('GET', '/zm/api/monitors.json', array(), array('REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc'));
check('Bearer after an Apache rewrite, any case', Auth::token($r), 'abc');
$r = request('GET', '/zm/api/monitors.json?token=q', array(), array('HTTP_AUTHORIZATION' => 'Bearer h'));
check('token= wins over the header', Auth::token($r), 'q');
$r = request('GET', '/zm/api/monitors.json', array(), array('HTTP_AUTHORIZATION' => 'Basic YWRtaW46eA=='));
check('basic auth is not a token', Auth::token($r), null);
// Apache with mod_php: the header only in getallheaders() (absent under the CLI).
$headers = array();
if (!function_exists('getallheaders')) { function getallheaders() { return $GLOBALS['headers']; } }
$headers = array('authorization' => 'Bearer apache');
$r = request('GET', '/zm/api/monitors.json');
check('Bearer only in getallheaders() (Apache, mod_php)', Auth::token($r), 'apache');
$r = request('GET', '/zm/api/monitors.json', array(), array('HTTP_AUTHORIZATION' => 'Bearer server'));
check('$_SERVER wins over getallheaders()', Auth::token($r), 'server');
$headers = array('Authorization' => 'Basic YWRtaW46eA==');
$r = request('GET', '/zm/api/monitors.json');
check('basic auth from getallheaders() is not a token', Auth::token($r), null);
$headers = array();
$r = request('POST', '/zm/api/host/login.json', array('user' => 'admin'));
check('input() falls back to the body', $r->input('user'), 'admin');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
