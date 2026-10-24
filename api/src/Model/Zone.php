<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Db;
use ZM\Api\Db\Model;

/** A motion detection zone of a monitor (Zones). */
class Zone extends Model {
  const TABLE = 'Zones';
  const ALIAS = 'Zone';

  protected $belongsTo = array(
    'Monitor' => array('model' => Monitor::class, 'foreignKey' => 'MonitorId'),
  );
  protected $validate = array(
    'MonitorId' => array('rule' => 'checkMonitorId', 'message' => 'Zones must have a valid MonitorId'),
    'Name' => array('required' => array('rule' => 'notBlank', 'message' => 'Zone Name must be specified for creation')),
  );

  /** The MonitorId names an existing monitor. */
  public function checkMonitorId($monitorId) {
    return Db::value('SELECT COUNT(*) FROM `Monitors` WHERE `Id` = ?', array($monitorId)) > 0;
  }
}
