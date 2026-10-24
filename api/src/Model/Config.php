<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/**
 * An option of Options (Config), addressed by its Name (ZM_WEB_EVENTS_PER_PAGE).
 * The value in effect can differ from the row's: zm.conf and conf.d override it,
 * see ConfigsController.
 */
class Config extends Model {
  const TABLE = 'Config';
  const ALIAS = 'Config';
  const PRIMARY_KEY = 'Name';
  const DISPLAY_FIELD = 'Value';

  public $recursive = 1;
}
