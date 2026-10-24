<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/**
 * A log entry (Logs). Addressed by TimeKey, its timestamp in seconds with a
 * fraction, which is what clients have always used to find an entry.
 */
class Log extends Model {
  const TABLE = 'Logs';
  const ALIAS = 'Log';
  const PRIMARY_KEY = 'TimeKey';

  public $recursive = 1;
}
