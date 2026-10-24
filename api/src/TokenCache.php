<?php
//
// ZoneMinder API: the user an access token stands for, kept a few seconds.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api;

use ZM\Api\Db\Db;
use ZM\Api\Db\Schema;

/**
 * The user an access token authenticated, and that user's monitor and group
 * permissions, kept in APCu for TTL seconds, so a client sending the same token
 * on every call doesn't have the token's user and permissions looked up on each.
 *
 * Without the cache, every call with a token checks its signature and expiry
 * and reads the user's row twice (ZoneMinder's zm_authenticate_request() and
 * the API's own check), then the permission rows once a monitor is checked.
 * With it, a call within TTL seconds of the last full check of the same token
 * reads none of them. What a cached entry keeps:
 *
 *  - the Users row the check read (so APIEnabled, Enabled and the permission
 *    levels are as of that check), and the user's Monitors_Permissions and
 *    Groups_Permissions rows;
 *  - the token's expiry: an entry is never used after the token expires.
 *
 * So a change made directly in the database or through the web UI (a token
 * revoked with TokenMinExpiry, the API or the account disabled, permissions
 * changed, the user deleted) applies to a token already in use at the latest
 * TTL seconds later. A user saved or deleted through this API empties the
 * cache at once (this server's; another server of a multi-server install
 * keeps its entries for up to TTL seconds). Only access tokens on
 * non-login actions are cached; password logins, auth hashes, sessions and
 * refresh tokens are checked on every call as before.
 *
 * The key is an HMAC of the token with ZM_AUTH_HASH_SECRET, never the token: a
 * new secret leaves every entry unreachable. Without APCu nothing is cached.
 */
final class TokenCache {
  /** Seconds an entry is used: the window in which a change to the user may not apply yet. */
  const TTL = 5;
  const PREFIX = 'zm-php-api:token-user:';
  /** When the cache was last emptied (microtime); entries made before are not used. */
  const EMPTIED = 'zm-php-api:token-user-emptied';

  /** @var float when this request started its token check */
  private static $started = 0.0;

  /** Whether APCu is there to keep entries in. */
  public static function enabled() {
    return function_exists('apcu_enabled') ? apcu_enabled() : function_exists('apcu_fetch');
  }

  /**
   * The cached user for $token as array('user' => ZM\User, 'monitor_permissions'
   * => rows, 'group_permissions' => rows), or null.
   */
  public static function fetch($token) {
    self::$started = microtime(true);
    if (!self::enabled()) return null;
    $entry = apcu_fetch(self::key($token), $hit);
    if (!$hit or !is_array($entry) or !isset($entry['row'], $entry['exp'], $entry['at'])) return null;
    if ($entry['exp'] <= time()) return null;
    $emptied = apcu_fetch(self::EMPTIED);
    if ($emptied !== false and $entry['at'] <= $emptied) return null;
    require_once __DIR__.'/../../includes/User.php';
    return $entry;
  }

  /**
   * Keep $user (just authenticated by $token, an access token) and the user's
   * permission rows. Nothing is kept for a token without an expiry, nor when
   * the cache was emptied while this request was checking the token.
   */
  public static function store($token, \ZM\User $user) {
    if (!self::enabled()) return;
    $exp = self::expiry($token);
    if ($exp === null) return;
    $ttl = min(self::TTL, $exp - time());
    if ($ttl < 1) return;
    $emptied = apcu_fetch(self::EMPTIED);
    if ($emptied !== false and self::$started <= $emptied) return;
    $row = self::row($user);
    if (!isset($row['Id'])) return;
    $entry = array(
      'row' => $row,
      'monitor_permissions' => Db::rows('SELECT * FROM `Monitors_Permissions` WHERE `UserId` = ?', array($row['Id'])),
      'group_permissions' => Db::rows('SELECT * FROM `Groups_Permissions` WHERE `UserId` = ?', array($row['Id'])),
      'exp' => $exp,
      'at' => microtime(true),
    );
    apcu_store(self::key($token), $entry, $ttl);
    // This request uses the permission rows just read, as later ones will.
    self::applyPermissions($entry);
  }

  /** Forget every entry (a user was saved or deleted). */
  public static function clear() {
    if (!self::enabled()) return;
    apcu_store(self::EMPTIED, microtime(true), 2 * self::TTL);
    if (class_exists('APCUIterator')) apcu_delete(new \APCUIterator('/^'.preg_quote(self::PREFIX, '/').'/'));
  }

  /**
   * Make the cached user the request's user, with its permissions where
   * ZoneMinder's visibleMonitor() and editableMonitor() look for them (they
   * read them on first use otherwise).
   */
  public static function apply(array $entry) {
    global $user;
    $user = new \ZM\User($entry['row']);
    self::applyPermissions($entry);
    return $user;
  }

  /** The entry's permission rows where visibleMonitor() and editableMonitor() look for them. */
  private static function applyPermissions(array $entry) {
    global $monitor_permissions, $group_permissions;
    $monitor_permissions = array();
    foreach ($entry['monitor_permissions'] as $r) {
      $monitor_permissions[$r['MonitorId']] = new \ZM\Monitor_Permission($r);
    }
    $group_permissions = array();
    foreach ($entry['group_permissions'] as $r) $group_permissions[] = new \ZM\Group_Permission($r);
  }

  /** The APCu key of a token: an HMAC, so the token itself is never stored. */
  private static function key($token) {
    $db = (defined('ZM_DB_HOST') ? ZM_DB_HOST : '').':'.(defined('ZM_DB_NAME') ? ZM_DB_NAME : '');
    return self::PREFIX.hash_hmac('sha256', $db."\0".$token, ZM_AUTH_HASH_SECRET);
  }

  /** The `exp` of a token whose signature was just checked, or null. */
  private static function expiry($token) {
    $parts = explode('.', $token);
    if (count($parts) != 3) return null;
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return (is_array($payload) and isset($payload['exp']) and is_int($payload['exp'])) ? $payload['exp'] : null;
  }

  /** The Users columns of $user, as the row it was made from. */
  private static function row(\ZM\User $user) {
    $read = \Closure::bind(function () { return get_object_vars($this); }, $user, \ZM\User::class);
    return array_intersect_key($read(), Schema::columns('Users'));
  }
}
