<?php
//
// ZoneMinder API: the answer, in JSON or XML.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Http;

/**
 * Status, headers and body of an answer.
 *
 * Bodies are encoded the way clients have always received them: JSON with
 * json_encode's default flags (so "/" is sent as "\/" and non-ASCII as \uXXXX),
 * XML with a <response> root element. Both carry "; charset=UTF-8".
 */
class Response {
  public $status = 200;
  /** @var array header name => value */
  public $headers = array();
  public $body = '';

  /** An answer made of $data, a map of top-level keys, in the request's format. */
  public static function data($data, $format, $status = 200) {
    $r = new Response();
    $r->status = $status;
    if ($format == 'xml') {
      $r->headers['Content-Type'] = 'application/xml; charset=UTF-8';
      $r->body = Xml::fromArray(array('response' => $data));
    } else {
      $r->headers['Content-Type'] = 'application/json; charset=UTF-8';
      $json = json_encode($data);
      if ($json === false) {
        throw new \RuntimeException('Unable to encode the answer as JSON: '.json_last_error_msg());
      }
      $r->body = $json;
    }
    return $r;
  }

  /** A 200 with an empty body: what add and delete have always answered on success. */
  public static function nothing($format) {
    $r = new Response();
    $r->headers['Content-Type'] = ($format == 'xml' ? 'application/xml' : 'application/json').'; charset=UTF-8';
    return $r;
  }

  /**
   * The answer for an exception. Its body:
   *
   *   {"success": false, "data": {"name", "message", "url",
   *                               "exception": {"class", "code", "message"}}}
   *
   * - An HttpException (4xx) shows its message as name and message.
   * - Anything else (a database error, a PHP error, a 5xx) is a 500 that says only
   *   "An Internal Error Has Occurred.". exception.class and .code are kept, but
   *   not the message: a database error's text quotes SQL. The caller logs it.
   * - $wrapped = false gives the bare {"name", "message", "url"} that an unknown
   *   controller has always answered.
   */
  public static function error(\Throwable $e, Request $request, $wrapped = true) {
    if ($e instanceof HttpException and $e->getCode() < 500) {
      $status = $e->getCode();
      $message = $e->publicMessage();
      $class = $e->clientName();
    } else {
      $code = $e->getCode();
      $status = (is_int($code) and $code > 500 and $code < 506) ? $code : 500;
      $message = 'An Internal Error Has Occurred.';
      $class = ($e instanceof HttpException) ? $e->clientName() : get_class($e);
    }
    $h = function ($s) { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $data = array('name' => $h($message), 'message' => $h($message), 'url' => $h($request->here()));
    if (!$wrapped) {
      return self::data($data, $request->format, $status);
    }
    $detail = ($e instanceof HttpException and $e->getCode() < 500) ? $e->getMessage() : $message;
    $data['exception'] = array('class' => $class, 'code' => $e->getCode(), 'message' => $detail);
    return self::data(array('success' => false, 'data' => $data), $request->format, $status);
  }

  public function send() {
    http_response_code($this->status);
    foreach ($this->headers as $name => $value) {
      header($name.': '.$value);
    }
    echo $this->body;
  }
}
