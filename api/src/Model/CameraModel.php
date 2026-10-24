<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A camera model (Models). */
class CameraModel extends Model {
  const TABLE = 'Models';
  const ALIAS = 'CameraModel';

  // Joined on CameraModel.Id = Manufacturer.Id (not ManufacturerId), as answers have
  // always been; the Manufacturer key shows the manufacturer with the model's Id.
  protected $hasOne = array(
    'Manufacturer' => array('model' => Manufacturer::class, 'foreignKey' => 'Id'),
  );
  protected $validate = array(
    'Name' => array(array('rule' => 'notBlank')),
    'Id' => array(array('rule' => 'numeric')),
  );
}
