<?php
namespace ZM\Api\Http;

/** HTTP 500. */
class InternalErrorException extends HttpException {
  const CLIENT_NAME = 'InternalErrorException';
  const STATUS = 500;
}
