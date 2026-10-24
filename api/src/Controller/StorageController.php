<?php
namespace ZM\Api\Controller;

/**
 * /storage: storage areas, with their disk usage. Needs System=View; changes
 * need System=Edit. Reached as storage/<action>/... (storage/<id> is not routed).
 */
class StorageController extends CrudController {
  const MODEL = \ZM\Api\Model\Storage::class;
  const PLURAL = 'storage';
  const SINGULAR = 'storage';
  const NOT_FOUND = 'Invalid storage area';
  const VIEW_PERMISSION = array('System', 'View');
  const INDEX_RECURSIVE = -1;
  const FILTERABLE = true;

  /** Each area with DiskTotalSpace and DiskUsedSpace (bytes). */
  protected function indexRows(array $rows) {
    require_once __DIR__.'/../../../includes/Storage.php';
    foreach ($rows as &$row) {
      $storage = new \ZM\Storage($row['Storage']);
      $row['Storage']['DiskTotalSpace'] = $storage->disk_total_space();
      $row['Storage']['DiskUsedSpace'] = $storage->disk_used_space();
    }
    return $rows;
  }
}
