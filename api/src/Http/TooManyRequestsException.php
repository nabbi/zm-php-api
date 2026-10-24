<?php
namespace ZM\Api\Http;

/** HTTP 429. */
class TooManyRequestsException extends HttpException {
  const CLIENT_NAME = 'TooManyRequestsException';
  const STATUS = 429;
}
