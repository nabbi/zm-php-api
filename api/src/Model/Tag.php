<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** An event tag (Tags), linked to events through Events_Tags. */
class Tag extends Model {
  const TABLE = 'Tags';
  const ALIAS = 'Tag';

  protected $habtm = array(
    'Event' => array('model' => Event::class, 'joinTable' => 'Events_Tags', 'foreignKey' => 'TagId',
      'associationForeignKey' => 'EventId', 'with' => 'EventsTag'),
  );
  protected $validate = array(
    'Name' => array(
      array('rule' => 'notBlank'),
      array('rule' => 'isUnique', 'message' => 'A tag with this name already exists'),
    ),
    'Id' => array(array('rule' => 'numeric')),
  );
}
