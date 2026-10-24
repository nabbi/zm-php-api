<?php
namespace ZM\Api\Http;

/** HTTP 401. */
class UnauthorizedException extends HttpException {
  const CLIENT_NAME = 'UnauthorizedException';
  const STATUS = 401;
}
