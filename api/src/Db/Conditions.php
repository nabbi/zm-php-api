<?php
//
// ZoneMinder API: WHERE clauses from condition arrays.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Db;

use ZM\Api\Http\BadRequestException;

/**
 * Turns a condition array into SQL with ? placeholders. The syntax is the one
 * the API's filters have always used:
 *
 *   array('Monitor.Id' => 5)                    `Monitor`.`Id` = ?
 *   array('Id' => array(1, 2))                  `Id` IN (?, ?)
 *   array('Name LIKE' => '%hik%')               `Name` LIKE ?
 *   array('EndDateTime IS NOT' => 'NULL')       `EndDateTime` IS NOT NULL
 *   array('OR' => array(...), 'NOT' => ...)     grouped with OR / negated
 *   array(array(...), array(...))               each group ANDed
 *   'StartDateTime >= NOW()'                    a string is taken as SQL, so it
 *                                               must never contain request input
 *
 * Values are always bound, never spliced into the SQL. A field written without
 * an alias is qualified with $alias when it is one of $columns, so it can't be
 * ambiguous in a query that joins other tables.
 */
class Conditions {
  /** Operators a condition key may carry after its field name. */
  const OPERATORS = array(
    '=', '!=', '<>', '<=>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
    'IS', 'IS NOT', 'REGEXP', 'NOT REGEXP', 'RLIKE', 'SOUNDS LIKE',
    'AND', '&&', '&', '~', '^', 'DIV', '/', '<<', '>>', '-', '+', '%', 'MOD',
  );
  /** Values IS / IS NOT take as SQL keywords. */
  const IS_KEYWORDS = array('NULL', 'TRUE', 'FALSE', 'UNKNOWN');

  private $alias;
  private $columns;
  /** @var array values for the placeholders, in order */
  public $params = array();

  public function __construct($alias, array $columns) {
    $this->alias = $alias;
    $this->columns = $columns;
  }

  /** @return string SQL (without WHERE), or '' for no condition */
  public function sql($conditions) {
    return $this->group($conditions, 'AND');
  }

  private function group($conditions, $glue) {
    if (is_string($conditions)) return $conditions;
    if (!is_array($conditions)) return '';
    $parts = array();
    foreach ($conditions as $key => $value) {
      $upper = is_string($key) ? strtoupper(trim($key)) : null;
      if (is_int($key)) {
        $sql = is_array($value) ? $this->group($value, 'AND') : (string)$value;
      } else if ($upper === 'OR' or $upper === 'AND') {
        $sql = $this->group($value, $upper);
      } else if ($upper === 'NOT') {
        $inner = $this->group($value, 'AND');
        $sql = ($inner === '') ? '' : "NOT ($inner)";
      } else {
        $sql = $this->comparison($key, $value);
      }
      if ($sql !== '') $parts[] = (count($conditions) > 1) ? "($sql)" : $sql;
    }
    if (!count($parts)) return '';
    return implode(" $glue ", $parts);
  }

  /** One `Field [operator]` => value term. */
  private function comparison($key, $value) {
    $key = trim($key);
    $field = $key;
    $op = '';
    if (preg_match('/^([^\s]+)\s+(.+)$/', $key, $m)) {
      $field = $m[1];
      $op = strtoupper(trim($m[2]));
    }
    if ($op !== '' and !in_array($op, self::OPERATORS, true)) {
      throw new BadRequestException('Invalid operator: '.$op);
    }
    $name = $this->qualify($field);

    if (is_array($value)) {
      $op = ($op === '' or $op === '=' or $op === 'IN') ? 'IN' : (($op === '!=' or $op === '<>' or $op === 'NOT IN') ? 'NOT IN' : $op);
      if (!count($value)) return ($op === 'NOT IN') ? '1 = 1' : '1 = 0';
      if ($op !== 'IN' and $op !== 'NOT IN') {
        throw new BadRequestException('Invalid operator for a list: '.$op);
      }
      foreach ($value as $v) $this->params[] = $v;
      return "$name $op (".implode(', ', array_fill(0, count($value), '?')).')';
    }
    if ($value === null) {
      return ($op === '!=' or $op === '<>' or $op === 'IS NOT') ? "$name IS NOT NULL" : "$name IS NULL";
    }
    if ($op === 'IS' or $op === 'IS NOT') {
      if (in_array(strtoupper(trim($value)), self::IS_KEYWORDS, true)) {
        return "$name $op ".strtoupper(trim($value));
      }
    }
    if ($op === '') $op = '=';
    $this->params[] = $value;
    return "$name $op ?";
  }

  /** `Alias`.`Field`: unaliased fields that are $columns get the main alias. */
  public function qualify($field) {
    if (strpos($field, '.') === false and in_array($field, $this->columns, true)) {
      $field = $this->alias.'.'.$field;
    }
    if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $field)) {
      throw new BadRequestException('Invalid field: '.$field);
    }
    return Db::quoteName($field);
  }
}
