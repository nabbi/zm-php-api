<?php
//
// ZoneMinder API: filters given as named arguments.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api;

use ZM\Api\Http\BadRequestException;

/**
 * Filters for index-style actions, written as named arguments:
 *
 *   monitors/index/Function:Modect/Enabled:1.json      equality
 *   events/index/StartDateTime >=:2026-01-01.json      <field> <operator>:<value>
 *   monitors/index/Name LIKE:hik.json                  LIKE without % means "contains"
 *   events/index/MonitorId IN:1,2,3.json               IN takes a comma separated list
 *   events/index/EndDateTime IS NOT:NULL.json          IS / IS NOT NULL
 *
 * Paging and auth parameters (page, limit, sort, direction, token, p) are not
 * filters.
 *
 * Every field is checked against the tables the query reads and written so
 * MySQL finds exactly one column, spelled as the schema spells it (names match
 * without regard to case, as in MySQL):
 *   - Alias.Field must name the queried model or, with 'joined', one it joins
 *     (Monitor_Status.Status), and a column of it
 *   - a bare field is the queried model's column if it has one; otherwise, with
 *     'joined', the column of the one joined model that has it (Status,
 *     CaptureFPS from Monitor_Status); two joined models having it is a 400
 *   - names in 'bare' (GroupId, ...) are left as given for the caller to handle
 * Anything else is a 400 naming the field, never SQL that MySQL refuses.
 */
class Filter {
  const NOT_FILTERS = array('p', 'sort', 'page', 'limit', 'token', 'direction');

  /**
   * @param array $named the request's named arguments
   * @param Db\Model $model the queried model
   * @param array $options 'joined' => true when the query joins the model's
   *   belongsTo/hasOne models (recursive >= 0) and filters may name their
   *   columns; 'bare' => names that are not columns, left as given
   * @return array conditions for Model::find()
   * @throws BadRequestException on an unknown or ambiguous field, or an unknown operator
   */
  public static function conditions(array $named, Db\Model $model, array $options = array()) {
    $bare = isset($options['bare']) ? $options['bare'] : array();
    $joined = !empty($options['joined']);
    $conditions = array();
    foreach ($named as $attribute => $value) {
      $lhs = trim($attribute);
      if ($lhs !== '' and in_array($lhs, self::NOT_FILTERS)) continue;
      if (preg_match('/^(?P<field>[a-z0-9_\.]+)(?P<operator>.+)?$/i', $lhs, $m) !== 1) {
        throw new BadRequestException('Invalid argument before `:`: '.$lhs);
      }
      $operator = isset($m['operator']) ? strtoupper(trim($m['operator'])) : '';
      if ($operator !== '' and !in_array($operator, Db\Conditions::OPERATORS, true)) {
        throw new BadRequestException('Invalid operator: '.$operator);
      }
      $field = self::column($m['field'], $model, $joined, $bare);
      if ($operator == 'LIKE' and !is_array($value) and strpos($value, '%') === false) {
        $value = '%'.$value.'%';
      } else if ($operator == 'IN' and !is_array($value)) {
        $value = explode(',', $value);
      }
      $key = $field.($operator !== '' ? ' '.$operator : '');
      if (is_array($value)) {
        $conditions[] = array($key => array_values($value));
      } else {
        $conditions[$key] = $value;
      }
    }
    return $conditions;
  }

  /** Alias.Column for a filter field (see the class comment), or a name from $bare. */
  private static function column($field, Db\Model $model, $joined, array $bare) {
    if (in_array($field, $bare, true)) return $field;
    $dot = strrpos($field, '.');
    $alias = ($dot === false) ? null : substr($field, 0, $dot);
    $name = ($dot === false) ? $field : substr($field, $dot + 1);
    $models = array($model::ALIAS => $model);
    if ($joined) $models += $model->joinedModels();
    $found = array();
    foreach ($models as $a => $m) {
      if ($alias !== null and strcasecmp($a, $alias)) continue;
      if (!is_object($m)) $m = new $m();
      foreach ($m->columns() as $c) {
        if (!strcasecmp($c, $name)) {
          $found[] = "$a.$c";
          break;
        }
      }
      // The queried model's own column wins over a joined model's.
      if ($alias === null and $a === $model::ALIAS and count($found)) break;
    }
    if (!count($found)) throw new BadRequestException('Unknown filter field: '.$field);
    if (count($found) > 1) throw new BadRequestException('Ambiguous filter field: '.$field);
    return $found[0];
  }
}
