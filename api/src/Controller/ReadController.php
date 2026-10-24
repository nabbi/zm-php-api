<?php
//
// ZoneMinder API: the list and view actions most resources share.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Controller;

use ZM\Api\Http\BadRequestException;
use ZM\Api\Http\NotFoundException;

/**
 * The read actions of a resource backed by one model:
 *
 *   GET    <resource>                 index   {"<plural>": [row, ...]}
 *   GET    <resource>/<id>            view    {"<singular>": row}
 *
 * CrudController adds add, edit and delete.
 *
 * A subclass names its model and keys in the constants, and sets who may read
 * (VIEW_PERMISSION) and write (EDIT_PERMISSION), as array(area, level), e.g.
 * array('System', 'Edit'); null means any authenticated user.
 * Override the actions or the hooks (indexRows(), viewRow()) for the rest.
 */
abstract class ReadController extends Controller {
  /** @var string the model class */
  const MODEL = '';
  /** @var string key of index's list, e.g. servers */
  const PLURAL = '';
  /** @var string key of view's row, e.g. server */
  const SINGULAR = '';
  /** @var string NotFoundException message, e.g. Invalid server */
  const NOT_FOUND = '';
  /** @var string|null the message for edit and delete, when it differs */
  const NOT_FOUND_WRITE = null;
  /** @var array|null permission needed for every action */
  const VIEW_PERMISSION = null;
  const VIEW_MESSAGE = 'Insufficient Privileges';
  /** @var array|null permission needed for add, edit and delete */
  const EDIT_PERMISSION = array('System', 'Edit');
  const EDIT_MESSAGE = 'Insufficient privileges';
  /** @var int find() depth for index and view */
  const INDEX_RECURSIVE = 0;
  const VIEW_RECURSIVE = 0;
  /** @var bool whether index takes Name:value filters */
  const FILTERABLE = false;
  /** @var mixed index's ORDER BY */
  const INDEX_ORDER = null;

  /** @var \ZM\Api\Db\Model */
  protected $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $class = static::MODEL;
    $this->model = new $class();
  }

  public function beforeFilter() {
    parent::beforeFilter();
    if (static::VIEW_PERMISSION) {
      $this->requirePermission(static::VIEW_PERMISSION[0], static::VIEW_PERMISSION[1], static::VIEW_MESSAGE);
    }
  }

  public function index() {
    $options = array('recursive' => static::INDEX_RECURSIVE);
    if (static::FILTERABLE) {
      $options['conditions'] = \ZM\Api\Filter::conditions($this->route->named, $this->model);
    }
    if (static::INDEX_ORDER !== null) $options['order'] = static::INDEX_ORDER;
    return array(static::PLURAL => $this->indexRows($this->model->find('all', $options)));
  }

  public function view($id = null) {
    $this->requireExists($id);
    $model = $this->model;
    $row = $model->find('first', array(
      'conditions' => array($model::ALIAS.'.'.$model::PRIMARY_KEY => $id),
      'recursive' => static::VIEW_RECURSIVE,
    ));
    return array(static::SINGULAR => $this->viewRow($row));
  }

  // ------------------------------------------------------------------- hooks

  /** index's rows, before they are answered. */
  protected function indexRows(array $rows) {
    return $rows;
  }

  /** view's row, before it is answered. */
  protected function viewRow(array $row) {
    return $row;
  }

  // ----------------------------------------------------------------- helpers

  protected function requireEdit() {
    if (static::EDIT_PERMISSION) {
      $this->requirePermission(static::EDIT_PERMISSION[0], static::EDIT_PERMISSION[1], static::EDIT_MESSAGE);
    }
  }


  /** @throws NotFoundException unless row $id exists */
  protected function requireExists($id, $message = null) {
    if (!$this->model->exists($id)) throw new NotFoundException($message === null ? static::NOT_FOUND : $message);
  }

  /**
   * Save, or answer 400 naming the fields that failed validation.
   * @return array the saved fields
   */
  protected function saveOrFail(array $data, $id = null) {
    $saved = $this->model->save($data, $id);
    if ($saved === false) {
      throw new BadRequestException($this->model->validationErrors
        ? 'Invalid '.implode(', ', array_keys($this->model->validationErrors))
        : 'Nothing to save');
    }
    return $saved;
  }

  /**
   * The request data without the primary key: an edit is authorized for the id
   * in the URL, so the body must not be able to name another row.
   */
  protected function withoutPrimaryKey(array $data) {
    $model = $this->model;
    if (isset($data[$model::ALIAS]) and is_array($data[$model::ALIAS])) unset($data[$model::ALIAS][$model::PRIMARY_KEY]);
    unset($data[$model::PRIMARY_KEY]);
    return $data;
  }
}
