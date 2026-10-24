<?php
//
// ZoneMinder API: the columns of ZoneMinder's tables.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Db;

/**
 * The columns of a table, from SHOW COLUMNS, looked up once and kept.
 *
 * A request needs the columns of its model and of every table it joins, so
 * without a cache each request would run several SHOW COLUMNS. They are kept
 * in two places:
 *
 *  - for the rest of the request (a static);
 *  - with APCu, for TTL seconds, shared by the PHP processes of the server,
 *    under a key that names ZoneMinder's version, the database's schema
 *    version (ZM_DYN_DB_VERSION, which zmupdate.pl sets after changing the
 *    tables), the database and the table. An upgrade therefore never reads
 *    the columns of the old schema; a table altered by hand is seen at the
 *    latest TTL seconds later. Without APCu, each request looks them up.
 */
final class Schema {
  /** Seconds a table's columns are kept in APCu. */
  const TTL = 60;

  /** @var array table => its columns, for this request */
  private static $tables = array();

  /** Forget the columns kept for this request (the unit tests switch databases). */
  public static function reset() {
    self::$tables = array();
  }

  /** column => array('type' => SQL type, 'null' => bool, 'default' => value), in table order */
  public static function columns($table) {
    if (isset(self::$tables[$table])) return self::$tables[$table];
    $shared = function_exists('apcu_fetch');
    if ($shared) {
      $columns = apcu_fetch(self::key($table), $hit);
      if ($hit && is_array($columns)) return self::$tables[$table] = $columns;
    }
    $columns = array();
    foreach (Db::rows('SHOW COLUMNS FROM '.Db::quoteName($table)) as $c) {
      $columns[$c['Field']] = array('type' => strtolower($c['Type']), 'null' => $c['Null'] === 'YES', 'default' => $c['Default']);
    }
    if ($shared) apcu_store(self::key($table), $columns, self::TTL);
    return self::$tables[$table] = $columns;
  }

  /** The APCu key of a table's columns. */
  public static function key($table) {
    $parts = array();
    foreach (array('ZM_VERSION', 'ZM_DYN_DB_VERSION', 'ZM_DB_HOST', 'ZM_DB_NAME') as $c) {
      $parts[] = defined($c) ? constant($c) : '';
    }
    $parts[] = $table;
    return 'zm-php-api:columns:'.implode(':', $parts);
  }
}
