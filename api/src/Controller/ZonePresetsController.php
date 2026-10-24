<?php
namespace ZM\Api\Controller;

/**
 * /zone_presets: zone templates, shared by all monitors. Anyone may read; changes
 * are system configuration and need System=Edit. Reached as
 * zone_presets/<action>/... (the zonepresets resource name has no controller).
 */
class ZonePresetsController extends CrudController {
  const MODEL = \ZM\Api\Model\ZonePreset::class;
  const PLURAL = 'zonePresets';
  const SINGULAR = 'zonePreset';
  const NOT_FOUND = 'Invalid zone preset';
  const EDIT_MESSAGE = 'Insufficient Privileges';
  const INDEX_RECURSIVE = 1;
  const VIEW_RECURSIVE = 1;

  /** Everything but index and view is a change, checked before anything else. */
  public function beforeFilter() {
    parent::beforeFilter();
    if (!in_array($this->route->action, array('index', 'view'), true)) $this->requireEdit();
  }

  public function edit($id = null) {
    $this->requireEdit();
    $this->requireExists($id);
    $this->allowMethod('POST', 'PUT');
    $this->saveOrFail($this->withoutPrimaryKey($this->request->data), $id);
    return \ZM\Api\Http\Response::nothing($this->request->format);
  }
}
