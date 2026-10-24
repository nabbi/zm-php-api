<?php
//
// ZoneMinder API: the add/edit/delete actions most resources share.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Controller;

use ZM\Api\Http\Response;

/**
 * ReadController plus the write actions:
 *
 *   POST   <resource>                 add     empty body
 *   PUT    <resource>/<id>            edit    {"message": "Saved"} (or "Error")
 *   DELETE <resource>/<id>            delete  empty body
 *
 * Writes need EDIT_PERMISSION.
 */
abstract class CrudController extends ReadController {
  public function add() {
    $this->allowMethod('POST');
    $this->requireEdit();
    $this->model->id = null;
    $this->saveOrFail($this->request->data);
    return Response::nothing($this->request->format);
  }

  public function edit($id = null) {
    $this->requireEdit();
    $this->requireExists($id, static::NOT_FOUND_WRITE);
    $data = $this->withoutPrimaryKey($this->request->data);
    return array('message' => $this->model->save($data, $id) ? 'Saved' : 'Error');
  }

  public function delete($id = null) {
    $this->requireEdit();
    $this->requireExists($id, static::NOT_FOUND_WRITE);
    $this->allowMethod('POST', 'DELETE');
    $this->model->delete($id);
    return Response::nothing($this->request->format);
  }

}
