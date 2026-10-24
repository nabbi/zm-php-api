<?php
//
// ZoneMinder API: paged lists (events, logs, users, event data).
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api;

use ZM\Api\Db\Model;
use ZM\Api\Http\NotFoundException;

/**
 * One page of a model's rows, and the "pagination" block describing it:
 *
 *   {"page": 1, "current": 20, "count": 57, "prevPage": false, "nextPage": true,
 *    "pageCount": 3, "order": {"Log.TimeKey": "asc"}, "limit": 20,
 *    "options": {...}, "paramType": "querystring", "queryScope": null}
 *
 * The request picks the page with page, limit, sort and direction, as named
 * arguments (paramType named: users/index/page:2) or as query parameters
 * (paramType querystring: logs?page=2). "options" lists the settings that differ
 * from the defaults. This block's layout is what paged clients parse; keep it.
 */
class Paginator {
  /** The request parameters that page; anything else is ignored. */
  const WHITELIST = array('limit', 'sort', 'page', 'direction');
  /** The highest page asked for; any number above it is this one. */
  const MAX_PAGE = 1000000000;

  /** @var array page, limit, maxLimit, paramType, order, conditions, recursive, ... */
  private $settings;

  public function __construct(array $settings = array()) {
    $this->settings = $settings + array('page' => 1, 'limit' => 20, 'maxLimit' => 100, 'paramType' => 'named');
  }

  /**
   * @param array $named the request's named arguments
   * @param array $query the query string
   * @return array (rows, pagination block)
   * @throws NotFoundException for a page past the last one
   */
  public function paginate(Model $model, array $named, array $query, array $extraConditions = array()) {
    $defaults = $this->settings;
    $request = ($defaults['paramType'] == 'querystring') ? $query : $named;
    $options = array_merge($defaults, array_intersect_key($request, array_flip(self::WHITELIST)));
    $options = $this->validateSort($model, $options);
    $options['limit'] = is_scalar($options['limit']) ? (int)$options['limit'] : $this->settings['limit'];
    if ($options['limit'] < 1) $options['limit'] = 1;
    $options['limit'] = min($options['limit'], $options['maxLimit']);
    if (!isset($options['conditions'])) $options['conditions'] = array();

    $conditions = $options['conditions'];
    if (count($extraConditions)) $conditions = array_merge((array)$conditions, $extraConditions);
    $order = isset($options['order']) ? $options['order'] : null;
    $limit = $options['limit'];
    $page = is_scalar($options['page']) ? (int)$options['page'] : 1;
    if ($page < 1) $page = 1;
    // Past any real page (a 404 below), and small enough that the offset
    // (page - 1) * limit stays an integer.
    $page = min($page, self::MAX_PAGE);
    $options['page'] = $page;

    $find = array('conditions' => $conditions, 'order' => $order, 'limit' => $limit, 'page' => $page);
    foreach (array('recursive', 'fields', 'joins', 'group') as $k) {
      if (isset($options[$k])) $find[$k] = $options[$k];
    }
    $rows = $model->find('all', $find);

    $count = 0;
    if (count($rows)) {
      $countOptions = array('conditions' => $conditions);
      foreach (array('recursive', 'joins') as $k) {
        if (isset($options[$k])) $countOptions[$k] = $options[$k];
      }
      if (isset($options['group'])) $countOptions['distinct'] = $options['group'];
      $count = $model->find('count', $countOptions);
    }
    $pageCount = (int)ceil($count / $limit);
    $requestedPage = $page;
    $page = max(min($page, $pageCount), 1);
    unset($defaults[0]);
    $paging = array(
      'page' => $page,
      'current' => count($rows),
      'count' => $count,
      'prevPage' => ($page > 1),
      'nextPage' => ($count > ($page * $limit)),
      'pageCount' => $pageCount,
      'order' => $order,
      'limit' => $limit,
      'options' => self::diff($options, $defaults),
      'paramType' => $options['paramType'],
      'queryScope' => null,
    );
    if ($requestedPage > $page) throw new NotFoundException('Not Found');
    return array($rows, $paging);
  }

  /** sort/direction from the request become the order, if sort names a column. */
  private function validateSort(Model $model, array $options) {
    if (isset($options['sort']) and is_string($options['sort'])) {
      $direction = (isset($options['direction']) and is_string($options['direction'])) ? strtolower($options['direction']) : null;
      if (!in_array($direction, array('asc', 'desc'), true)) $direction = 'asc';
      $options['order'] = array($options['sort'] => $direction);
    }
    if (empty($options['order']) or !is_array($options['order'])) return $options;
    $order = array();
    foreach ($options['order'] as $key => $value) {
      if (is_int($key)) {
        $parts = explode(' ', $value);
        $key = $parts[0];
        $value = (count($parts) === 2) ? trim($parts[1]) : 'asc';
      }
      $alias = $model::ALIAS;
      $field = $key;
      if (strpos($key, '.') !== false) list($alias, $field) = explode('.', $key, 2);
      if ($alias === $model::ALIAS and $model->hasField($field)) {
        $order[$model::ALIAS.'.'.$field] = $value;
      } else if ($alias === $model::ALIAS and $model->hasVirtualField($key)) {
        $order[$field] = $value;
      }
    }
    $options['order'] = $order;
    return $options;
  }

  /**
   * Keys of $data that $compare doesn't have with an equal (==) value, plus the
   * keys only $compare has.
   */
  private static function diff(array $data, array $compare) {
    if (!count($data)) return $compare;
    if (!count($compare)) return $data;
    foreach (array_intersect_key($data, $compare) as $key => $value) {
      if ($data[$key] == $compare[$key]) {
        unset($data[$key], $compare[$key]);
      }
    }
    return $data + $compare;
  }
}
