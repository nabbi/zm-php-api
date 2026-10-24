<?php
namespace ZM\Api\Http;

/** HTTP 400. */
class BadRequestException extends HttpException {
  const CLIENT_NAME = 'BadRequestException';
  const STATUS = 400;
}
