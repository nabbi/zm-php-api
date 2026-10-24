<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A monitor's live state, written by its zmc (Monitor_Status). */
class MonitorStatus extends Model {
  const TABLE = 'Monitor_Status';
  const ALIAS = 'Monitor_Status';
  const PRIMARY_KEY = 'MonitorId';
}
