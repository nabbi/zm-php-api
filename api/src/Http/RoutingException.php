<?php
namespace ZM\Api\Http;

/**
 * No such controller or action (404). Its body says "Not Found"; the reason
 * is in exception.message.
 */
class RoutingException extends HttpException {
  const STATUS = 404;
  private $clientName;

  /** $clientName: MissingControllerException, MissingActionException or PrivateActionException */
  public function __construct($clientName, $message) {
    parent::__construct($message, 404);
    $this->clientName = $clientName;
  }

  public function clientName() {
    return $this->clientName;
  }

  public function publicMessage() {
    return 'Not Found';
  }
}
