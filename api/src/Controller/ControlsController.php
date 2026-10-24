<?php
namespace ZM\Api\Controller;

/**
 * /controls: PTZ control definitions, read-only. They are edited under Options,
 * so reading them needs System=View.
 */
class ControlsController extends ReadController {
  const MODEL = \ZM\Api\Model\Control::class;
  const PLURAL = 'controls';
  const SINGULAR = 'control';
  const NOT_FOUND = 'Invalid control';
  const VIEW_PERMISSION = array('System', 'View');
  const VIEW_RECURSIVE = -1;
}
