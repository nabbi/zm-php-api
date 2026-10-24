<?php
namespace ZM\Api\Http;

/** HTTP 403. */
class ForbiddenException extends HttpException {
  const CLIENT_NAME = 'ForbiddenException';
  const STATUS = 403;
}
