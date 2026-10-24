<?php
namespace ZM\Api\Controller;

/**
 * /camera_models: camera models. Reached only as camera_models/<action>/...;
 * the cameramodels resource name has never had a controller.
 */
class CameraModelsController extends CrudController {
  const MODEL = \ZM\Api\Model\CameraModel::class;
  const PLURAL = 'models';
  const SINGULAR = 'model';
  const NOT_FOUND = 'Invalid model';
}
