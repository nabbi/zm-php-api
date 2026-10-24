<?php
// Tests ZM\Api\Auth::cors() (api/src/Auth.php): which Origins get CORS
// headers, with credentials.
//
// What must hold:
//   - multi-server: an Origin whose host is exactly a server's Hostname or Name
//   - single server with streaming ports: an Origin on this same host
//   - nothing for any other Origin, nor for one that isn't scheme://host[:port]
//     (a path, userinfo, a backslash, whitespace), even when parse_url() would
//     find an allowed host in it
//
// Run: tests/run.sh test_api_cors.php

define('ZM_MIN_STREAMING_PORT', 30000);
require_once __DIR__.'/support/api.php';

class StubServer {
  private $hostname, $name;
  public function __construct($hostname, $name) { $this->hostname = $hostname; $this->name = $name; }
  public function Hostname() { return $this->hostname; }
  public function Name() { return $this->name; }
}

function allowed($origin, array $servers, $httpHost = 'zm.example:8080') {
  $GLOBALS['Servers'] = $servers;
  $_SERVER = array('HTTP_ORIGIN' => $origin, 'HTTP_HOST' => $httpHost);
  $response = new \ZM\Api\Http\Response(200, '', array());
  \ZM\Api\Auth::cors($response);
  $h = $response->headers;
  if (!isset($h['Access-Control-Allow-Origin'])) return false;
  return $h['Access-Control-Allow-Origin'] === $origin and $h['Access-Control-Allow-Credentials'] === 'true';
}

$servers = array(new StubServer('zm1.example', 'node1'), new StubServer('zm2.example', 'node2'));

echo "multi-server\n";
check('a server Hostname', allowed('https://zm2.example', $servers), true);
check('with a port', allowed('http://zm2.example:8080', $servers), true);
check('a server Name', allowed('http://node1', $servers), true);
check('host case does not matter', allowed('https://ZM1.Example', $servers), true);
check('another host', allowed('https://evil.example', $servers), false);
check('a server name as a prefix', allowed('https://zm1.example.evil.example', $servers), false);
check('a server name as a suffix', allowed('https://evilzm1.example', $servers), false);

echo "not an Origin\n";
foreach (array('https://evil.example@zm1.example', 'https://zm1.example/path', 'https://zm1.example?x',
    'https://zm1.example#x', 'https://zm1.example\\@evil.example', "https://zm1.example\r\nX-Injected: 1",
    'https://zm1.example ', 'ftp://zm1.example', '//zm1.example', 'zm1.example', 'null', '') as $origin) {
  check('refused: '.json_encode($origin), allowed($origin, $servers), false);
}

echo "single server, streaming ports\n";
check('this host, another port', allowed('http://zm.example:30001', array()), true);
check('this host, no port', allowed('https://zm.example', array()), true);
check('another host', allowed('http://evil.example:30001', array()), false);
check('userinfo naming this host', allowed('http://zm.example@evil.example', array()), false);

done();
