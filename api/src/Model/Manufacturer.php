<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A camera manufacturer (Manufacturers). */
class Manufacturer extends Model {
  const TABLE = 'Manufacturers';
  const ALIAS = 'Manufacturer';

  protected $hasMany = array(
    'Model' => array('model' => CameraModel::class, 'foreignKey' => 'ManufacturerId'),
  );
  protected $validate = array(
    'Id' => array(array('rule' => 'numeric')),
    'Name' => array(array('rule' => 'notBlank')),
  );
}
