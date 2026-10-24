<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A server load sample written by zmstats (Server_Stats). */
class ServerStat extends Model {
  const TABLE = 'Server_Stats';
  const ALIAS = 'ServerStat';

  protected $validate = array(
    'ServerId' => array(array('rule' => 'numeric')),
  );
}
