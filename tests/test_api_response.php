<?php
// Tests Response (web/api/src/Http/Response.php, Xml.php): the bytes, status
// and Content-Type of every answer the API sends.
//
// Clients parse these exactly: zmNinja and pyzm read error bodies by
// data.name / data.message, some match on exception.class, and JSON is encoded
// with PHP's default flags, so "/" goes out as "\/" and non-ASCII as \uXXXX, as
// it did under CakePHP. A 500 never carries the internal message (database
// errors quote SQL).
//
// Run: php tests/php/test_api_response.php   (from the repo root)

require_once __DIR__.'/support/api.php';

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\ForbiddenException;
use ZM\Api\Http\MethodNotAllowedException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Request;
use ZM\Api\Http\Response;
use ZM\Api\Http\RoutingException;
use ZM\Api\Http\TooManyRequestsException;
use ZM\Api\Http\UnauthorizedException;

function request($uri) {
  $_SERVER = array('REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri);
  $_GET = array();
  $q = parse_url($uri, PHP_URL_QUERY);
  if ($q) parse_str($q, $_GET);
  $_POST = array();
  return Request::fromGlobals();
}

function answer(Response $r) {
  return array($r->status, $r->headers['Content-Type'], $r->body);
}

const JSON = 'application/json; charset=UTF-8';
const XML = 'application/xml; charset=UTF-8';

echo "data\n";
check('JSON with default flags', answer(Response::data(array('path' => '/a/b', 'name' => 'Café', 'n' => 1, 'f' => 1.5, 'ok' => true, 'none' => null), 'json')),
  array(200, JSON, '{"path":"\/a\/b","name":"Caf\\u00e9","n":1,"f":1.5,"ok":true,"none":null}'));
check('an empty list is []', Response::data(array('monitors' => array()), 'json')->body, '{"monitors":[]}');
check('another status', Response::data(array('x' => 1), 'json', 202)->status, 202);
check('unencodable data throws', thrown(function () { Response::data(array('bad' => "\xB1"), 'json'); }),
  'RuntimeException: Unable to encode the answer as JSON: Malformed UTF-8 characters, possibly incorrectly encoded');

echo "nothing (add and delete on success)\n";
check('empty JSON body', answer(Response::nothing('json')), array(200, JSON, ''));
check('empty XML body', answer(Response::nothing('xml')), array(200, XML, ''));

echo "XML\n";
check('response root, lists repeat the element, null is empty, booleans 1/0',
  answer(Response::data(array('monitors' => array(array('Monitor' => array('Id' => 1, 'On' => true)), array('Monitor' => array('Id' => 2, 'On' => false, 'Notes' => null)))), 'xml')),
  array(200, XML, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><monitors><Monitor><Id>1</Id><On>1</On></Monitor></monitors><monitors><Monitor><Id>2</Id><On>0</On><Notes/></Monitor></monitors></response>\n"));
check('XML: keys that are not XML names become <item name="...">',
  answer(Response::data(array('usage' => array('Front Door' => array('space' => 1), 'xmlKey' => 2, '9lives' => 3)), 'xml')),
  array(200, XML, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><usage><item name=\"Front Door\"><space>1</space></item><item name=\"xmlKey\">2</item><item name=\"9lives\">3</item></usage></response>\n"));
check('XML: a key ending in a newline is an item too, not a DOMException',
  thrown(function () { return Response::data(array('usage' => array("Cam\n" => 1)), 'xml')->body; }), 'no exception');
check('... named as sent', strpos(Response::data(array('usage' => array("Cam\n" => 1)), 'xml')->body, '<item name="Cam') !== false, true);
check('numeric keys become item', Response::data(array('ids' => array(5 => 'a')), 'xml')->body,
  "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><ids><item>a</item></ids></response>\n");

echo "errors\n";
$r = request('/zm/api/monitors/999.json?x=1');
check('404 envelope', answer(Response::error(new NotFoundException('Invalid monitor'), $r)), array(404, JSON,
  '{"success":false,"data":{"name":"Invalid monitor","message":"Invalid monitor","url":"\/zm\/api\/monitors\/999.json?x=1","exception":{"class":"NotFoundException","code":404,"message":"Invalid monitor"}}}'));
foreach (array(
  array(new BadRequestException('Bad'), 400, 'BadRequestException'),
  array(new UnauthorizedException('Insufficient Privileges'), 401, 'UnauthorizedException'),
  array(new ForbiddenException('No'), 403, 'ForbiddenException'),
  array(new MethodNotAllowedException('Method Not Allowed'), 405, 'MethodNotAllowedException'),
  array(new TooManyRequestsException('Too many'), 429, 'TooManyRequestsException'),
) as list($e, $status, $class)) {
  $body = json_decode(Response::error($e, $r)->body, true);
  check("$class: status and class name", array(Response::error($e, $r)->status, $body['data']['exception']['class']), array($status, $class));
}
check('a database error is a 500 without its text',
  answer(Response::error(new \PDOException("SQLSTATE[42S22]: Unknown column 'x' in 'where clause'"), request('/zm/api/events.json'))),
  array(500, JSON, '{"success":false,"data":{"name":"An Internal Error Has Occurred.","message":"An Internal Error Has Occurred.","url":"\/zm\/api\/events.json","exception":{"class":"PDOException","code":0,"message":"An Internal Error Has Occurred."}}}'));
check('a PHP error is a 500 without its text',
  json_decode(Response::error(new \TypeError('count(): Argument #1 must be of type Countable'), $r)->body, true)['data'],
  array('name' => 'An Internal Error Has Occurred.', 'message' => 'An Internal Error Has Occurred.', 'url' => '/zm/api/monitors/999.json?x=1',
    'exception' => array('class' => 'TypeError', 'code' => 0, 'message' => 'An Internal Error Has Occurred.')));
check('an unknown controller: the bare body',
  answer(Response::error(new RoutingException('MissingControllerException', 'Controller class NosuchController could not be found.'), request('/zm/api/nosuch.json'), false)),
  array(404, JSON, '{"name":"Not Found","message":"Not Found","url":"\/zm\/api\/nosuch.json"}'));
check('the URL hides credentials', json_decode(Response::error(new NotFoundException('x'), request('/zm/api/a.json?pass=hunter2&token=eyJ0.x'))->body, true)['data']['url'],
  '/zm/api/a.json?pass=[redacted]&amp;token=[redacted]');
check('the URL is HTML-escaped', json_decode(Response::error(new NotFoundException('x'), request('/zm/api/a.json?q=<b>'))->body, true)['data']['url'],
  '/zm/api/a.json?q=%3Cb%3E');
check('the message is HTML-escaped', json_decode(Response::error(new BadRequestException('Invalid field: <x>'), $r)->body, true)['data']['name'],
  'Invalid field: &lt;x&gt;');
check('errors in XML', answer(Response::error(new NotFoundException('Invalid monitor'), request('/zm/api/monitors/9.xml'))),
  array(404, XML, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><success>0</success><data><name>Invalid monitor</name><message>Invalid monitor</message><url>/zm/api/monitors/9.xml</url><exception><class>NotFoundException</class><code>404</code><message>Invalid monitor</message></exception></data></response>\n"));

done();
