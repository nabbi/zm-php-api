<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/**
 * A frame of an event (Frames), addressed by (EventId, FrameId). The table's key
 * is that pair, so FramesController writes rows itself; save() and delete() of
 * this model, which take one key column, are not used for frames.
 */
class Frame extends Model {
  const TABLE = 'Frames';
  const ALIAS = 'Frame';
  const PRIMARY_KEY = 'EventId';

  protected $belongsTo = array(
    'Event' => array('model' => Event::class, 'foreignKey' => 'EventId'),
  );
  protected $virtualFields = array(
    'TimeStampSecs' => 'UNIX_TIMESTAMP(Frame.TimeStamp)',
  );
  protected $validate = array(
    'EventId' => array(array('rule' => 'numeric'), array('rule' => 'notEmpty')),
    'FrameId' => array(array('rule' => 'numeric'), array('rule' => 'notEmpty')),
    'Type' => array(array('rule' => 'multiple')),
    'TimeStamp' => array(array('rule' => 'datetime')),
    'Delta' => array(array('rule' => 'numeric')),
    'Score' => array(array('rule' => 'numeric')),
  );
}
