<?php
//
// ZoneMinder API: errors that become HTTP answers.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Http;

/**
 * An error the API answers with its own status code and message, e.g.
 * `throw new NotFoundException('Invalid monitor')` gives a 404 whose body names
 * "Invalid monitor".
 *
 * Error bodies include the exception's class name (`data.exception.class`).
 * Clients have seen CakePHP's names there, so each subclass keeps that name in
 * CLIENT_NAME rather than exposing its PHP class.
 */
class HttpException extends \RuntimeException {
  const CLIENT_NAME = 'HttpException';
  const STATUS = 500;

  public function __construct($message = '', $code = null) {
    parent::__construct($message, $code === null ? static::STATUS : $code);
  }

  /** The name shown in the body, and whether the message is shown as is. */
  public function clientName() {
    return static::CLIENT_NAME;
  }

  /**
   * The `name`/`message` of the body. Routing errors (missing controller or action)
   * say only "Not Found", as before; the details stay in `exception.message`.
   */
  public function publicMessage() {
    return $this->getMessage();
  }
}
