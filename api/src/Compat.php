<?php
//
// ZoneMinder API: the differences between the ZoneMinder releases it runs on.
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
 * Everything that differs between ZoneMinder 1.38 and master, in one place.
 *
 * The API is written against master. Where 1.38 lacks something master has,
 * the code asks this class instead of calling it directly, and each method
 * answers with master's way when it is there and 1.38's otherwise.
 *
 * Rules:
 *  - Probe for the thing itself (a function, a table, a column), never compare
 *    ZM_VERSION. A 1.38 point release that backports one of these then takes
 *    master's path for it on its own, and a master build is never treated as
 *    1.38 because of its version string.
 *  - On 1.38, answer as master does. Features only master has stay absent
 *    (their routes answer 404, as 1.38's CakePHP API did); bugs of 1.38's
 *    CakePHP API are not reproduced, as on master.
 *  - Each method names the master commit that brought the difference, so it
 *    can be checked against what 1.38 has later.
 *
 * Every caller has a "1.38:" comment. When 1.38 is no longer supported, delete
 * this class and replace each Compat:: call with its master branch; grep for
 * "Compat::" and "1.38:" to find them all.
 */
final class Compat {
  /** @var array table name => whether it exists */
  private static $tables = array();

  /** Forget what was probed (the unit tests switch databases between cases). */
  public static function reset() {
    self::$tables = array();
  }

  // ------------------------------------------------------------ database

  /**
   * ZoneMinder's database connection.
   *
   * master (9c06eb6ba, 2026-08-17): zmDbConn() connects on first use, and shows
   * the "no database connection" page and ends the request if it can't.
   * 1.38: database.php connected when config.php loaded it (and showed that
   * page and exited then if it couldn't), so its global already holds the
   * connection.
   *
   * @return \PDO
   */
  public static function db() {
    if (function_exists('zmDbConn')) return zmDbConn();
    return $GLOBALS['dbConn'];
  }

  /**
   * Whether a table exists.
   *
   * 1.38 lacks EncoderTemplates (master c4073c964, 2026-05-06) and
   * Notifications (master 2fce145a9, 2026-03-05).
   */
  public static function hasTable($table) {
    if (!isset(self::$tables[$table])) {
      // Not SHOW TABLES LIKE ?: MariaDB refuses a placeholder there when the
      // statement is prepared server-side, as on 1.38's connection.
      self::$tables[$table] = Db::value('SELECT COUNT(*) FROM information_schema.TABLES'
        .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array($table)) > 0;
    }
    return self::$tables[$table];
  }

  /**
   * Whether a table has a column.
   *
   * 1.38's Frames has its own key `Id`; master (c4ef3fdd6, 2026-10-01) keys
   * frames by (EventId, FrameId) and dropped it.
   */
  public static function hasColumn($table, $column) {
    return array_key_exists($column, Schema::columns($table));
  }

  // ------------------------------------------------------- authentication

  /**
   * Load includes/auth.php for its functions, without authenticating anything.
   *
   * master (b9f565854, 2026-08-17): including auth.php only defines functions.
   * 1.38: including it also authenticates the request, once, at file scope:
   * it reads $_REQUEST, may log a user in, open a session and rewrite their
   * stored password hash, and sets a $user that is lost when the include
   * happens in a function. The API authenticates later and on its own terms
   * (authenticateRequest(), after its own checks of the credentials), so on
   * the first include the request is hidden: no parameters, no REMOTE_USER and
   * no session, which leaves 1.38's code nothing to act on. On master the
   * include does nothing else either way.
   */
  public static function requireAuth() {
    if (function_exists('validateUser')) return;  // already loaded
    $request = $_REQUEST;
    $remoteUser = isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : null;
    $_REQUEST = array();
    unset($_SERVER['REMOTE_USER']);
    try {
      require_once __DIR__.'/../../includes/auth.php';
    } finally {
      $_REQUEST = $request;
      if ($remoteUser !== null) $_SERVER['REMOTE_USER'] = $remoteUser;
    }
  }

  /**
   * Authenticate the request from its token, auth hash, user name and
   * password, REMOTE_USER or session, and set the global $user.
   *
   * master (b9f565854): zm_authenticate_request().
   * 1.38: no such function; the same code ran when auth.php was included
   * (see requireAuth()). authenticate138() is master's function as of
   * 9b07f8cd1 (2026-10-13), so both releases authenticate alike.
   */
  public static function authenticateRequest() {
    self::requireAuth();
    if (function_exists('zm_authenticate_request')) {
      zm_authenticate_request();
    } else {
      self::authenticate138();
    }
  }

  /**
   * At a login: a new session id, with nothing carried over from the session
   * the client brought (CWE-384), stored although the client sent no cookie.
   *
   * master (5ae582b1a, 2026-06-12): zm_session_regenerate_id_login().
   * 1.38: no such function. This is its body, using what 1.38 has.
   */
  public static function regenerateSessionIdOnLogin() {
    if (function_exists('zm_session_regenerate_id_login')) {
      zm_session_regenerate_id_login();
      return;
    }
    require_once __DIR__.'/../../includes/Network.php';
    if (!is_session_started()) zm_session_start();
    zm_session_persist();
    $_SESSION = array();
    session_regenerate_id(true);
    $_SESSION['generated_at'] = time();
    unset($_SESSION['prevRemoteAddr']);
    unset($_SESSION['prevRemoteAddrAt']);
    $_SESSION['remoteAddr'] = getRemoteAddr();
  }

  /**
   * 1.38: master's zm_authenticate_request() as of 9b07f8cd1, unchanged but
   * for the session regeneration at a login, which goes through
   * regenerateSessionIdOnLogin() (1.38 lacks zm_session_regenerate_id_login()).
   * 1.38's own copy of this code, at the end of its auth.php, differs in two
   * ways that master fixed: it doesn't start the session before reading
   * $_SESSION, and at a login it keeps the pre-login session's contents.
   * When master changes zm_authenticate_request(), check whether this needs
   * the change too.
   */
  private static function authenticate138() {
    global $user;

    if (ZM_OPT_USE_AUTH) {
      if (!empty($_REQUEST['token'])) {
        $ret = validateToken($_REQUEST['token'], 'any');
        if (!$ret[0]) {
          \ZM\Warning($ret[1]);
          $user = null;
        } else {
          $user = $ret[0];
        }
      } else {
        if (!is_session_started()) {
          zm_session_start();
        }

        $requestAuth = requestString('auth');
        $requestUser = requestString('user');
        $requestPass = requestString('pass');
        $requestUsername = requestString('username');
        $requestPassword = requestString('password');
        if (ZM_AUTH_HASH_LOGINS && empty($user) && !empty($requestAuth)) {
          $user = getAuthUser($requestAuth);
          if ($user) {
            $remoteAddr = ZM_AUTH_HASH_IPS ? $_SESSION['remoteAddr'] : '';
            if (isset($_SESSION['AuthHash'.$remoteAddr]) and ($_SESSION['AuthHash'.$remoteAddr] != $requestAuth)) {
              unset($_SESSION['AuthHashGeneratedAt']);
              unset($_SESSION['AuthHash'.$remoteAddr]);
            }
            $_SESSION['username'] = $user->Username();
          }
        } else if (!(empty($requestUser) or empty($requestPass))) {
          $ret = validateUser($requestUser, $requestPass);
          if (!$ret[0]) {
            \ZM\Warning($ret[1]);
            $user = null;
            return;
          }
          $user = $ret[0];
          migrateHash($requestUser, $requestPass);
        } else if (!(empty($requestUsername) or empty($requestPassword))) {
          $ret = validateUser($requestUsername, $requestPassword);
          if (!$ret[0]) {
            \ZM\Warning($ret[1]);
            $user = null;
            return;
          }
          $user = $ret[0];

          if (
            defined('ZM_OPT_USE_GOOG_RECAPTCHA') && ZM_OPT_USE_GOOG_RECAPTCHA
            && defined('ZM_OPT_GOOG_RECAPTCHA_SECRETKEY') && ZM_OPT_GOOG_RECAPTCHA_SECRETKEY
            && defined('ZM_OPT_GOOG_RECAPTCHA_SITEKEY') && ZM_OPT_GOOG_RECAPTCHA_SITEKEY
          ) {
            if (!isset($_REQUEST['g-recaptcha-response'])) {
              \ZM\Error('reCaptcha authentication failed. No g-recpatcha-response in REQUEST: ');
              $user = null;
              return;
            }
            $url = 'https://www.google.com/recaptcha/api/siteverify';
            $fields = array(
              'secret'    => ZM_OPT_GOOG_RECAPTCHA_SECRETKEY,
              'response'  => $_REQUEST['g-recaptcha-response'],
              'remoteip'  => $_SERVER['REMOTE_ADDR']
            );
            $res = do_post_request($url, http_build_query($fields));
            $responseData = json_decode($res, true);
            if (isset($responseData['success']) && ($responseData['success'] == false)) {
              if (isset($responseData['error-codes']) && is_array($responseData['error-codes'])) {
                if (!in_array('invalid-input-secret', $responseData['error-codes'])) {
                  \ZM\Error('reCaptcha authentication failed. response was: '.print_r($responseData['error-codes'], true));
                  $user = null;
                  return;
                } else {
                  \ZM\Error('Invalid recaptcha secret detected');
                }
              }
            }
          }

          self::regenerateSessionIdOnLogin();  // master: zm_session_regenerate_id_login()

          $username = $requestUsername;
          $password = $requestPassword;

          \ZM\Info("Login successful for user \"$username\"");
          $password_type = password_type($user->Password());
          if ($password_type == 'mysql' or $password_type == 'mysql+bcrypt') {
            \ZM\Info('Migrating password, if possible for future logins');
            migrateHash($username, $password);
          }

          if (ZM_AUTH_TYPE == 'builtin') {
            $_SESSION['passwordHash'] = $user->Password();
          }

          $_SESSION['username'] = $user->Username();
          if (ZM_AUTH_RELAY == 'plain') {
            $_SESSION['password'] = $_REQUEST['password'];
          }
        } else if ((ZM_AUTH_TYPE == 'remote') and !empty($_SERVER['REMOTE_USER'])) {
          if (ZM_CASE_INSENSITIVE_USERNAMES) {
            $sql = 'SELECT * FROM Users WHERE Enabled=1 AND LOWER(Username)=LOWER(?)';
          } else {
            $sql = 'SELECT * FROM Users WHERE Enabled=1 AND Username=?';
          }
          $user = new \ZM\User(dbFetchOne($sql, NULL, array($_SERVER['REMOTE_USER'])));
        } else {
          $user = userFromSession();
        }

        if (!empty($user)) {
          generateAuthHash(ZM_AUTH_HASH_IPS);
        }
      }
    } else {
      global $defaultUser;
      $user = new \ZM\User($defaultUser);
    }
  }
}
