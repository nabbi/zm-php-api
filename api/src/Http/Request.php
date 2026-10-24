<?php
//
// ZoneMinder API: the incoming request.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Http;

/**
 * One API request: method, the path split into controller/action/arguments, the
 * response format, query string and request body.
 *
 * The parsing rules are the ones clients were written against (CakePHP 2's), so
 * existing clients keep working:
 *
 *  - The path is taken from REQUEST_URI below the API's base path (/zm/api).
 *  - A trailing extension picks the format: `.json` or `.xml`. Without one, an
 *    `Accept` header asking for XML gives XML, anything else JSON.
 *  - Path arguments written `Name:value` are named parameters (filters, paging);
 *    the rest are positional. The path is URL-decoded once before it is split,
 *    as CakePHP does, so %3A and %2F act as : and /.
 *  - The body: form fields (`Model[Field]=value`) for POST, and for PUT/DELETE
 *    when form-encoded. A `_method` field or an X-HTTP-Method-Override header
 *    overrides the method (upper-cased; CakePHP keeps a lowercase override as
 *    is and then matches no route for it, which no client relies on). A JSON body (Content-Type application/json) is merged
 *    in later, see mergeJsonBody().
 */
class Request {
  /** @var string GET, POST, PUT, DELETE, ... after any override */
  public $method;
  /** @var string the path below the API base, without extension: monitors/index/Name:x */
  public $path;
  /** @var string the raw path below the API base, as sent (still URL-encoded) */
  public $rawPath;
  /** @var string json or xml */
  public $format = 'json';
  /** @var string|null the extension as written, if any */
  public $extension;
  /** @var string[] path segments, URL-decoded */
  public $segments = array();
  /** @var array $_GET */
  public $query = array();
  /** @var array the parsed body (form fields; JSON merged by mergeJsonBody()) */
  public $data = array();
  /** @var string the API's base path, e.g. /zm/api */
  public $base;

  public static function fromGlobals() {
    $r = new Request();
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    list($path) = explode('?', $uri, 2);
    $r->base = self::basePath($path);
    $path = substr($path, strlen($r->base));
    $r->rawPath = ltrim($path, '/');
    $r->query = $_GET;
    $r->parsePath($r->rawPath);
    $r->parseBody();
    if ($r->extension === null) {
      $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
      $r->format = (stripos($accept, 'xml') !== false and stripos($accept, 'json') === false) ? 'xml' : 'json';
    }
    return $r;
  }

  /** The API's base path: everything in the request path up to and including /api. */
  private static function basePath($path) {
    $pos = strpos($path, '/api/');
    if ($pos === false) {
      return (substr($path, -4) === '/api') ? $path : '';
    }
    return substr($path, 0, $pos + 4);
  }

  /**
   * Split the path into segments, after taking off the extension. As before, the
   * extension is the last `.word` of the path, and the path is cut at its first
   * occurrence (so `host/daemonCheck/zmfilter.pl.json` is `zmfilter.pl` as json).
   */
  public function parsePath($path) {
    if (preg_match('/\.([0-9a-zA-Z]*)$/', $path, $m) === 1) {
      $this->extension = $m[1];
      $path = substr($path, 0, strpos($path, '.'.$m[1]));
      $this->format = (strtolower($m[1]) === 'xml') ? 'xml' : 'json';
    }
    // Decoded once, then split, as CakeRoute::parse() does (urldecode: + is a
    // space): an encoded %3A separator is a named argument's, an encoded %2F
    // separates segments, an encoded controller or action name is that name.
    $path = urldecode($path);
    $this->path = $path;
    $this->segments = array();
    foreach (explode('/', $path) as $segment) {
      if ($segment !== '') $this->segments[] = $segment;
    }
  }

  private function parseBody() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    $data = array();
    if ($_POST) {
      $data = $_POST;
    } else if (($method == 'PUT' or $method == 'DELETE') and strpos($this->contentType(), 'application/x-www-form-urlencoded') === 0) {
      parse_str($this->body(), $data);
    }
    $override = null;
    if (!empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
      $data['_method'] = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];
    }
    if (isset($data['_method'])) {
      if (is_string($data['_method']) and $data['_method'] !== '') {
        $override = strtoupper($data['_method']);
        $method = $override;
      }
      unset($data['_method']);
    }
    // An override to a method without a body (GET, ...) drops the body, as
    // CakePHP does; compared in upper case, like the method it sets.
    if ($override and !in_array($override, array('POST', 'PUT', 'PATCH', 'DELETE'), true)) {
      $data = array();
    }
    // Fields may also be sent as data[Model][Field]; those win over the bare ones.
    if (isset($data['data'])) {
      // data=x (not data[...]) is no field list: dropped, not a string body.
      $inner = is_array($data['data']) ? $data['data'] : array();
      if (count($data) <= 1) {
        $data = $inner;
      } else {
        unset($data['data']);
        $data = self::merge($data, (array)$inner);
      }
    }
    $this->method = $method;
    $this->data = $data;
  }

  /**
   * Merge a JSON body into $data. Done after authentication, as before: the
   * credentials checks only look at the query string and form fields.
   */
  public function mergeJsonBody() {
    if (!in_array($this->method, array('POST', 'PUT', 'DELETE'))) return;
    // As CakePHP's RequestHandler: the media type exactly, parameters (charset) aside.
    if (strtolower(trim(explode(';', $this->contentType())[0])) !== 'application/json') return;
    $this->data = (array)json_decode($this->body(), true);
  }

  public function contentType() {
    if (!empty($_SERVER['CONTENT_TYPE'])) return $_SERVER['CONTENT_TYPE'];
    return isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '';
  }

  private $body;
  public function body() {
    if ($this->body === null) $this->body = (string)file_get_contents('php://input');
    return $this->body;
  }

  /** A query string parameter, or null. */
  public function query($name) {
    return isset($this->query[$name]) ? $this->query[$name] : null;
  }

  /** A body field by dotted path (`Monitor.Name`), or null. */
  public function data($path) {
    $value = $this->data;
    foreach (explode('.', $path) as $key) {
      if (!is_array($value) or !array_key_exists($key, $value)) return null;
      $value = $value[$key];
    }
    return $value;
  }

  /** The query parameter, else the body field: how credentials are looked up. */
  public function input($name) {
    $v = $this->query($name);
    return $v ? $v : $this->data($name);
  }

  public function is($method) {
    return $this->method === strtoupper($method);
  }

  /** Query parameters that carry credentials: here() shows no value of theirs. */
  const SECRET_PARAMS = array('token', 'auth', 'pass', 'password');

  /**
   * The request URL as error bodies show it: path plus the query string,
   * re-encoded, with the values of credential parameters (SECRET_PARAMS, also
   * when sent as arrays) replaced by [redacted]. An empty value stays empty.
   */
  public function here() {
    $url = $this->base.'/'.$this->rawPath;
    if (!empty($this->query)) $url .= '?'.http_build_query($this->query, '', '&');
    $names = implode('|', array_map('preg_quote', self::SECRET_PARAMS));
    return preg_replace('/([?&](?:'.$names.')(?:%5B[^=&]*?%5D)*=)[^&#]+/i', '$1[redacted]', $url);
  }

  /** Recursive merge where later string keys win and lists are appended (Hash::merge). */
  public static function merge(array $a, array $b) {
    foreach ($b as $k => $v) {
      if (is_int($k)) {
        $a[] = $v;
      } else if (isset($a[$k]) and is_array($a[$k]) and is_array($v)) {
        $a[$k] = self::merge($a[$k], $v);
      } else {
        $a[$k] = $v;
      }
    }
    return $a;
  }
}
