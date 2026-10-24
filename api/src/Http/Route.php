<?php
namespace ZM\Api\Http;

/** The outcome of routing: who answers, and with what arguments. */
class Route {
  /** @var string the controller's URL name: monitors, user_preference */
  public $name;
  /** @var string the controller's class */
  public $class;
  /** @var string the action as requested (matched case-insensitively) */
  public $action;
  /** @var string[] positional arguments, passed to the action method */
  public $pass;
  /** @var array named arguments (Name:value) */
  public $named;

  public function __construct($name, $class, $action, array $pass, array $named) {
    $this->name = $name;
    $this->class = $class;
    $this->action = $action;
    $this->pass = $pass;
    $this->named = $named;
  }
}
