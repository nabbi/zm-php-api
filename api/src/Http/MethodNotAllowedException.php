<?php
namespace ZM\Api\Http;

/** HTTP 405. */
class MethodNotAllowedException extends HttpException {
  const CLIENT_NAME = 'MethodNotAllowedException';
  const STATUS = 405;
}
