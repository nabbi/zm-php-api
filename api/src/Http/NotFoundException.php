<?php
namespace ZM\Api\Http;

/** HTTP 404. */
class NotFoundException extends HttpException {
  const CLIENT_NAME = 'NotFoundException';
  const STATUS = 404;
}
