<?php
//
// ZoneMinder API: running SQL and shaping the rows.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Db;

use ZM\Api\Compat;

/**
 * Queries on ZoneMinder's own connection (includes/database.php), with the row
 * layout and value types API clients have always received:
 *
 *  - Each row is grouped by table alias: SELECT ... FROM Monitors AS Monitor
 *    LEFT JOIN Monitor_Status AS Monitor_Status gives
 *    array('Monitor' => array(...), 'Monitor_Status' => array(...)).
 *    A column aliased `Alias__Field` (virtual fields) lands in Alias/Field.
 *  - Integers and floats are PHP numbers, TINYINT(1) columns are booleans,
 *    everything else (DECIMAL, dates, text) is a string, NULL is null.
 *
 * The API's own statements are prepared client-side (emulated prepares): the
 * values are still bound, quoted by PDO for the connection's character set,
 * and each statement is one round trip to the server instead of three
 * (prepare, execute, close). ZoneMinder's connection has emulation off, and
 * pdo_mysql decides it per connection, not per statement, so prepare() turns
 * it on for the API's statement and back off at once; ZoneMinder's own
 * queries on the connection are prepared server-side as before. From PHP 8.1
 * on, both ways return integers and floats as PHP numbers; before 8.1 the
 * emulated ones came back as strings, so there the API keeps server-side
 * prepares.
 */
class Db {
  /** @var object|null a connection to use instead of ZoneMinder's (tests set one) */
  public static $connection = null;

  /** @return \PDO */
  public static function connection() {
    // 1.38: no zmDbConn(); Compat::db() returns the connection database.php made.
    return self::$connection ? self::$connection : Compat::db();
  }

  /**
   * Rows of a SELECT, grouped by table alias.
   * @param array $params values for the ? placeholders
   */
  public static function select($sql, array $params = array()) {
    $stmt = self::run($sql, $params);
    $meta = array();
    for ($i = 0; $i < $stmt->columnCount(); $i++) $meta[] = $stmt->getColumnMeta($i);
    return self::group($meta, $stmt->fetchAll(\PDO::FETCH_NUM));
  }

  /**
   * Rows as select() returns them, from the columns' metadata (as
   * PDOStatement::getColumnMeta() gives it) and the rows' values in column
   * order: each value under its table alias (the part before `__` for a column
   * aliased Alias__Field, 0 for a computed column), TINYINT(1) values as
   * booleans.
   */
  public static function group(array $meta, array $values) {
    $columns = array();
    foreach ($meta as $i => $m) {
      $table = isset($m['table']) ? $m['table'] : '';
      $name = $m['name'];
      if (strpos($name, '__') !== false) {
        list($table, $name) = explode('__', $name, 2);
      }
      $bool = (isset($m['native_type']) and $m['native_type'] === 'TINY' and $m['len'] === 1);
      $columns[$i] = array($table === '' ? 0 : $table, $name, $bool);
    }
    $rows = array();
    foreach ($values as $v) {
      $row = array();
      foreach ($columns as $i => $c) {
        $row[$c[0]][$c[1]] = ($c[2] and $v[$i] !== null) ? (bool)$v[$i] : $v[$i];
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /** Rows as plain column => value arrays (no grouping by alias). */
  public static function rows($sql, array $params = array()) {
    return self::run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
  }

  /** The first column of the first row, or null. */
  public static function value($sql, array $params = array()) {
    $row = self::run($sql, $params)->fetch(\PDO::FETCH_NUM);
    return $row ? $row[0] : null;
  }

  /** Run an INSERT/UPDATE/DELETE; returns the number of rows affected. */
  public static function execute($sql, array $params = array()) {
    return self::run($sql, $params)->rowCount();
  }

  public static function lastInsertId() {
    return self::connection()->lastInsertId();
  }

  /** @return \PDOStatement */
  private static function run($sql, array $params) {
    $stmt = self::prepare($sql);
    $i = 1;
    foreach ($params as $p) {
      $type = is_int($p) ? \PDO::PARAM_INT : ($p === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
      $stmt->bindValue($i++, is_bool($p) ? (int)$p : $p, is_bool($p) ? \PDO::PARAM_INT : $type);
    }
    $stmt->execute();
    return $stmt;
  }

  /** A statement prepared client-side (see the class comment), where PHP returns the same types. */
  private static function prepare($sql) {
    $db = self::connection();
    if (PHP_VERSION_ID < 80100 || $db->getAttribute(\PDO::ATTR_EMULATE_PREPARES)) return $db->prepare($sql);
    $db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
    try {
      return $db->prepare($sql);
    } finally {
      $db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }
  }

  /** `Alias`.`Field` from Alias.Field or Field. */
  public static function quoteName($name) {
    return implode('.', array_map(function ($part) {
      return '`'.str_replace('`', '``', $part).'`';
    }, explode('.', $name)));
  }
}
