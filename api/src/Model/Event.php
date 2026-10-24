<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/**
 * A recorded event (Events). Rows carry four computed fields: StartTimeSecs and
 * EndTimeSecs (Unix time), StartTime and EndTime (the legacy names; EndTime is
 * computed for an event that is still open).
 */
class Event extends Model {
  const TABLE = 'Events';
  const ALIAS = 'Event';

  protected $belongsTo = array(
    'Monitor' => array('model' => Monitor::class, 'foreignKey' => 'MonitorId'),
    'Storage' => array('model' => Storage::class, 'foreignKey' => 'StorageId'),
  );
  protected $hasMany = array(
    'Frame' => array('model' => Frame::class, 'foreignKey' => 'EventId', 'dependent' => true),
  );
  // An event's groups are its monitor's, so they are not an association of the
  // event's own key; EventsController adds them (Group).
  protected $habtm = array(
    'Tag' => array('model' => Tag::class, 'joinTable' => 'Events_Tags', 'foreignKey' => 'EventId',
      'associationForeignKey' => 'TagId', 'with' => 'EventsTag'),
  );
  protected $virtualFields = array(
    'StartTimeSecs' => 'UNIX_TIMESTAMP(Event.StartDateTime)',
    'EndTimeSecs' => 'CASE WHEN Event.EndDateTime IS NOT NULL THEN UNIX_TIMESTAMP(Event.EndDateTime)'
      .' WHEN Event.Length > 0 THEN UNIX_TIMESTAMP(Event.StartDateTime) + Event.Length'
      .' ELSE UNIX_TIMESTAMP(Event.StartDateTime) END',
    'StartTime' => 'Event.StartDateTime',
    'EndTime' => 'CASE WHEN Event.EndDateTime IS NOT NULL THEN Event.EndDateTime'
      .' WHEN Event.Length > 0 THEN DATE_ADD(Event.StartDateTime, INTERVAL FLOOR(Event.Length) SECOND)'
      .' ELSE NOW() END',
  );
  protected $validate = array(
    'DefaultVideo' => array('rule' => array('custom', '/^(?!\.\.?$)[^\/\\\\\x00]+$/D'), 'allowEmpty' => true,
      'message' => 'DefaultVideo must be a filename without a path'),
  );
}
