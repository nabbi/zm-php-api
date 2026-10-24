<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A snapshot: a named set of events (Snapshots), linked through Snapshots_Events. */
class Snapshot extends Model {
  const TABLE = 'Snapshots';
  const ALIAS = 'Snapshot';

  protected $habtm = array(
    'Event' => array('model' => Event::class, 'joinTable' => 'Snapshots_Events', 'foreignKey' => 'SnapshotId',
      'associationForeignKey' => 'EventId', 'with' => 'SnapshotsEvent'),
  );
  protected $validate = array(
    'Id' => array(array('rule' => 'numeric')),
  );
}
