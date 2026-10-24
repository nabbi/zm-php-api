<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** Data attached to an event, e.g. detected objects (Event_Data). */
class EventData extends Model {
  const TABLE = 'Event_Data';
  const ALIAS = 'EventData';

  protected $belongsTo = array(
    'Event' => array('model' => Event::class, 'foreignKey' => 'EventId'),
    'Monitor' => array('model' => Monitor::class, 'foreignKey' => 'MonitorId'),
  );
  protected $validate = array(
    'EventId' => array(array('rule' => 'numeric')),
    'FrameId' => array(array('rule' => 'numeric')),
    'MonitorId' => array(array('rule' => 'numeric')),
    'TimeStamp' => array(array('rule' => 'datetime')),
  );
}
