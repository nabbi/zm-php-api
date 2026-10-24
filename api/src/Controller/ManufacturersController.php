<?php
namespace ZM\Api\Controller;

/** /manufacturers: camera manufacturers. Anyone may read; changes need System=Edit. */
class ManufacturersController extends CrudController {
  const MODEL = \ZM\Api\Model\Manufacturer::class;
  const PLURAL = 'manufacturers';
  const SINGULAR = 'manufacturer';
  const NOT_FOUND = 'Invalid manufacturer';
}
