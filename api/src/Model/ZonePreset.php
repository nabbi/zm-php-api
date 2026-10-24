<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A zone template, not tied to a monitor (ZonePresets). */
class ZonePreset extends Model {
  const TABLE = 'ZonePresets';
  const ALIAS = 'ZonePreset';

  public $recursive = 1;
}
