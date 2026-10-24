<?php
//
// ZoneMinder API: request in, answer out.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api;

use ZM\Api\Http\HttpException;
use ZM\Api\Http\Request;
use ZM\Api\Http\Response;
use ZM\Api\Http\Router;
use ZM\Api\Http\RoutingException;

/**
 * Handles one request:
 *
 *   route -> controller -> beforeFilter() (authentication) -> action -> answer
 *
 * The order matters to clients: an unknown controller is a 404 for anyone, but
 * an unknown action is only reported to an authenticated caller (401 first).
 */
class App {
  public static function handle(Request $request) {
    $response = self::answer($request);
    Auth::dropUnstoredSessionCookie();
    return $response;
  }

  private static function answer(Request $request) {
    try {
      $route = Router::resolve($request);
    } catch (RoutingException $e) {
      return Response::error($e, $request, false);
    }
    $class = $route->class;
    $controller = null;
    try {
      $controller = new $class($request, $route);
      $controller->beforeFilter();
      $method = $controller->actionMethod($route->action);
      $request->mergeJsonBody();
      // An action called without the arguments it needs (monitors/daemonControl.json)
      // is the client's mistake, not an ArgumentCountError (a 500).
      if ((new \ReflectionMethod($controller, $method))->getNumberOfRequiredParameters() > count($route->pass)) {
        throw new Http\BadRequestException('Missing arguments for '.$route->action);
      }
      $result = call_user_func_array(array($controller, $method), $route->pass);
      $response = ($result instanceof Response) ? $result
        : Response::data($result === null ? array() : $result, $request->format);
      $response->headers = $controller->response->headers + $response->headers;
      return $response;
    } catch (\Throwable $e) {
      if (!($e instanceof HttpException and $e->getCode() < 500)) {
        // One line: the decoded path or the exception's text (SQL quoting a
        // value) could carry a %0A into the log.
        \ZM\Error(str_replace(array("\r", "\n"), ' ', 'API '.$request->method.' '.$request->rawPath.': '
          .get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine()));
      }
      return Response::error($e, $request);
    }
  }
}
