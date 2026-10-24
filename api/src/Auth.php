<?php
//
// ZoneMinder API: who is calling.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api;

use ZM\Api\Http\ForbiddenException;
use ZM\Api\Http\Request;
use ZM\Api\Http\Response;
use ZM\Api\Http\TooManyRequestsException;
use ZM\Api\Http\UnauthorizedException;

/**
 * Authenticates the request and sets the global $user, which the rest of
 * ZoneMinder's PHP (canView(), canEdit(), ...) reads.
 *
 * Credentials are tried in this order; the last one that applies wins:
 *
 *  1. Whatever includes/auth.php accepts for the web UI (username=/password=,
 *     auth= hashes, the session): zm_authenticate_request().
 *  2. user=/pass= (query or form), for `host/login` always and for every call
 *     when ZM_OPT_USE_LEGACY_API_AUTH is on.
 *  3. With legacy auth on: stateful=1 starts a session (cookie ZMSESSID); a
 *     request with that cookie and no other credentials is that session's user.
 *  4. A token: token= (query or form) or an `Authorization: Bearer` header,
 *     which keeps it out of URLs and logs. An access token, or for `host/login`
 *     a refresh token.
 *
 * Then: an account with APIEnabled=0 is refused, and with ZM_OPT_USE_AUTH on,
 * every action but `host/logout` needs an enabled user.
 *
 * Failed logins (user/pass or username/password on any action, logout too) are
 * counted per client address and user name, and per address. After
 * LOGIN_FAILURES for one name, or ADDRESS_FAILURES in all, within LOGIN_WINDOW
 * seconds, further login attempts answer 429 until the window has passed. A
 * successful login clears only its own name's count.
 */
class Auth {
  /** Failed logins for one user name from one address before 429s. */
  const LOGIN_FAILURES = 10;
  /** Failed logins from one address, whatever the names, before 429s. */
  const ADDRESS_FAILURES = 50;
  const LOGIN_WINDOW = 600;

  /**
   * @var bool whether the user was authenticated only by what a browser sends on
   *   its own (the session cookie, HTTP basic auth), not by anything in the request
   */
  public static $ambient = false;

  /**
   * @param string $action the requested action (login and logout are special)
   * @throws UnauthorizedException
   */
  public static function authenticate(Request $request, $action) {
    if (!ZM_OPT_USE_API) {
      throw new UnauthorizedException('API Disabled');
    }
    global $user;
    // 1.38: including auth.php also authenticates; Compat::requireAuth() loads
    // only its functions, so that happens below, after the checks that follow.
    Compat::requireAuth();
    // Credentials are strings. ZoneMinder's includes and the JWT library fail
    // with a TypeError (a 500) on token[]=, pass[]=, ...
    foreach (array('token', 'auth', 'user', 'pass', 'username', 'password') as $name) {
      foreach (array($_REQUEST, $request->query, $request->data) as $source) {
        if (isset($source[$name]) and !is_string($source[$name]) and !is_int($source[$name])) {
          throw new UnauthorizedException('Invalid '.$name);
        }
      }
    }
    // A user name and password in the request, on any action (logout too), is a
    // guess unless it authenticates that user. A refusal of a user who did
    // authenticate (API disabled, account disabled) is not a guess.
    $name = self::loginName($request);
    if ($name !== null) self::checkThrottle($name);
    try {
      self::authenticateUser($request, $action);
    } catch (UnauthorizedException $e) {
      if ($name !== null and !self::authenticatedAs($name)) self::recordFailure($name);
      throw $e;
    }
    if ($name !== null) {
      if (self::authenticatedAs($name)) {
        self::clearFailures($name);
      } else {
        self::recordFailure($name);
      }
    }
    if (strcasecmp($action, 'login')) self::requireSameOriginForWrites($request);
  }

  /**
   * @throws ForbiddenException when a POST, PUT, PATCH or DELETE authenticated
   *   only by the browser's own credentials comes from a page of another origin
   *   (cross-site request forgery: a form on another site posting to the API
   *   with the user's cookie or HTTP basic credentials). The browser says where
   *   a request comes from (foreignBrowserRequest()). host/login is not checked:
   *   a login that authenticates carries credentials and isn't ambient.
   */
  public static function requireSameOriginForWrites(Request $request) {
    // The method the browser sent, and the one a _method override makes of it:
    // a cross-site form POST overridden to HEAD or OPTIONS is still checked.
    $sent = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
    $safe = array('GET', 'HEAD', 'OPTIONS');
    if (!self::$ambient or (in_array($sent, $safe, true) and in_array($request->method, $safe, true))) return;
    if (!self::foreignBrowserRequest()) return;
    \ZM\Warning('Refusing a '.$request->method.' from another origin authenticated only by browser credentials');
    throw new ForbiddenException('Cross-origin request: authenticate it with a token');
  }

  /**
   * Whether a browser says this request comes from a page of another origin:
   * an Origin that is neither this host nor one the CORS rules allow (another
   * server of this install), else a Sec-Fetch-Site other than same-origin or
   * none. Browsers send them on cross-site forms, images and links; a request
   * with neither header doesn't come from a browser page (a script, a native
   * app) and is not foreign.
   */
  private static function foreignBrowserRequest() {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : null;
    if ($origin !== null and $origin !== '') return !(self::sameOrigin($origin) or self::corsAllowed($origin));
    $site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower($_SERVER['HTTP_SEC_FETCH_SITE']) : null;
    return !($site === null or $site === 'same-origin' or $site === 'none');
  }

  /**
   * Whether $origin (scheme://host[:port]) names this request's own host (Host
   * header) and port. A Host without a port is a default port; the scheme is not
   * compared, as behind a TLS-terminating proxy PHP may not know it.
   */
  private static function sameOrigin($origin) {
    if (!isset($_SERVER['HTTP_HOST']) or !self::originShape($origin)) return false;
    $o = parse_url($origin);
    $h = parse_url('http://'.$_SERVER['HTTP_HOST']);
    if (!$o or !$h or !isset($o['host'], $h['host']) or strcasecmp($o['host'], $h['host'])) return false;
    if (isset($h['port'])) {
      $default = (strtolower($o['scheme']) == 'https') ? 443 : 80;
      return (isset($o['port']) ? $o['port'] : $default) == $h['port'];
    }
    return !isset($o['port']) or $o['port'] == 80 or $o['port'] == 443;
  }

  /** An Origin is scheme://host[:port], nothing more (no path, userinfo, backslash). */
  private static function originShape($origin) {
    return is_string($origin) and preg_match('#^https?://[^/?\#@\\\\\s]+$#i', $origin);
  }

  /**
   * A top-level request parameter as zm_authenticate_request() tests it, with
   * empty(): '0' counts as absent there, so it must here too (pass=0 would
   * otherwise mark a request authenticated by the browser as explicit).
   */
  private static function requestValue($name) {
    return (isset($_REQUEST[$name]) and is_string($_REQUEST[$name]) and !empty($_REQUEST[$name])) ? $_REQUEST[$name] : null;
  }

  /**
   * Whether the request carries no credential but its token: no user name,
   * password or auth hash, and no stateful session login.
   */
  private static function tokenOnly(Request $request) {
    foreach (array('auth', 'user', 'pass', 'username', 'password', 'stateful') as $name) {
      if (isset($_REQUEST[$name]) or $request->input($name) !== null) return false;
    }
    return true;
  }

  /** Whether the request's user is $name. */
  private static function authenticatedAs($name) {
    global $user;
    return $user and !strcasecmp((string)$user->Username(), $name);
  }

  private static function authenticateUser(Request $request, $action) {
    global $user;
    $isLogin = !strcasecmp($action, 'login');
    // An access token and no other credential, on any action but a login: its
    // user may be one the same token authenticated a few seconds ago
    // (TokenCache, which says what that window means).
    $token = self::token($request);
    $cacheable = ($token and ZM_OPT_USE_AUTH and !$isLogin and self::tokenOnly($request));
    $cached = $cacheable ? TokenCache::fetch($token) : null;
    if ($cached and isset($_REQUEST['token']) and $_REQUEST['token'] === $token) {
      // zm_authenticate_request() would check this token and nothing else.
      TokenCache::apply($cached);
    } else {
      // 1.38: no zm_authenticate_request(); Compat runs master's copy of it.
      Compat::authenticateRequest();
    }
    // What zm_authenticate_request() used, read as it reads them: top-level
    // $_REQUEST only (not data[...], which Request::input() also sees). With
    // ZM_AUTH_HASH_LOGINS on, an auth= hash is all it uses; else user=/pass=,
    // then username=/password=, then REMOTE_USER or the session.
    $byAuthHash = false;
    $byPassword = false;
    if ($user) {
      if (ZM_AUTH_HASH_LOGINS and self::requestValue('auth') !== null) {
        $byAuthHash = true;
      } else {
        foreach (array(array('user', 'pass'), array('username', 'password')) as $pair) {
          $name = self::requestValue($pair[0]);
          if ($name !== null and self::requestValue($pair[1]) !== null) {
            $byPassword = self::authenticatedAs($name);
            break;
          }
        }
      }
    }

    if (!ZM_OPT_USE_AUTH) {
      // Every request is ZoneMinder's default user and carries no credential,
      // so all are ambient: a page on another site must not drive the API of a
      // LAN install without authentication. Native apps (zmNinjaNg's mobile and
      // desktop builds send through CapacitorHttp / Electron's net, no Origin)
      // and scripts are not browser pages and pass.
      self::$ambient = (bool)$user;
      return;
    }

    if (ZM_OPT_USE_LEGACY_API_AUTH or $isLogin) {
      $username = $request->input('user');
      $password = $request->input('pass');
      if ($username and $password) {
        list($user, $status) = validateUser($username, $password);
        if (!$user) {
          throw new UnauthorizedException($status);
        }
        $byPassword = true;
        \ZM\Debug("Login successful for user \"$username\"");
        migrateHash($username, $password);
      }
    }

    if (ZM_OPT_USE_LEGACY_API_AUTH) self::userFromSessionCookie();

    if ($token and $isLogin and $byPassword) {
      // A password and someone's refresh token: whose tokens would the login mint?
      throw new UnauthorizedException('Log in with a user name and password or with a refresh token, not both');
    }
    if ($token and $cached) {
      $user = TokenCache::apply($cached);
    } else if ($token) {
      // host/login trades a refresh token for new tokens; everything else takes
      // only access tokens, so a refresh token can't be used as one.
      list($user, $status) = validateToken($token, $isLogin ? 'refresh' : 'access', true);
      if (!$user) {
        throw new UnauthorizedException($status);
      }
      if ($cacheable) TokenCache::store($token, $user);
    }

    if ($user and $user->APIEnabled() != 1) {
      \ZM\Error('API disabled for: '.$user->Username());
      throw new UnauthorizedException('API disabled for: '.$user->Username());
    }

    if (strcasecmp($action, 'logout')) {
      if (!($user and $user->Username())) {
        throw new UnauthorizedException('Not Authenticated');
      }
      if (!$user->Enabled()) {
        throw new UnauthorizedException('User is not enabled');
      }
    }
    // Only now, after every check passed, may a stateful request make the session
    // this user's: a request refused above (an access token on host/login, a
    // disabled account) must not log a planted session in.
    if (ZM_OPT_USE_LEGACY_API_AUTH) self::session($request, isset($password) ? $password : null);
    // Ambient: authenticated by what a browser sends on its own (the session
    // cookie, HTTP basic auth or REMOTE_USER), not by a token, a password or an
    // auth hash in this request that was checked.
    self::$ambient = ($user and !$token and !$byPassword and !$byAuthHash);
  }

  /** A signed token of $type ('access' or 'refresh') for $username, valid for $ttl seconds. */
  public static function jwt($username, $type, $ttl) {
    require_once __DIR__.'/../../vendor/autoload.php';
    $now = time();
    return \Firebase\JWT\JWT::encode(array(
      'iss' => 'ZoneMinder', 'iat' => $now, 'exp' => $now + $ttl, 'user' => $username, 'type' => $type,
    ), ZM_AUTH_HASH_SECRET, 'HS256');
  }

  /**
   * zmu's credentials for the current user, as arguments to append to its
   * command line: an access token minted for this call that lives a minute.
   * Never the password, nor the client's own longer-lived token: a command line
   * is visible to every local user in the process list.
   *
   * Without a usable ZM_AUTH_HASH_SECRET zmu refuses tokens, so then what it
   * took before: an auth hash, or with ZM_AUTH_RELAY=none the user name. With
   * ZM_AUTH_RELAY=plain and no secret zmu accepts only the password: $password
   * when the caller has it (the request's own pass=), else the web UI session's.
   * The API's stateful login doesn't keep it, so without either zmu refuses the call.
   */
  public static function zmuCredentials($password = null) {
    if (!ZM_OPT_USE_AUTH) return '';
    global $user;
    Compat::requireAuth();  // 1.38: a bare include of auth.php would authenticate
    if (authHashSecretIsSet()) return ' -T '.escapeshellarg(self::jwt($user->Username(), 'access', 60));
    // As ZoneMinder's getZmuCommand(): the user name too, so zmu looks up one
    // user instead of trying the hash against every account. The hash is bound
    // to no address: zmu has no client address to check it against.
    if (ZM_AUTH_RELAY == 'hashed') {
      return ' -U '.escapeshellarg($user->Username()).' -A '.escapeshellarg(calculateAuthHash(''));
    }
    if (ZM_AUTH_RELAY == 'none') return ' -U '.escapeshellarg($user->Username());
    // plain: the request's own password, else the web UI session's (its login keeps it with this relay).
    if ($password === null or $password === '') $password = isset($_SESSION['password']) ? $_SESSION['password'] : '';
    if ($password === '') {
      \ZM\Warning('zmu needs ZM_AUTH_HASH_SECRET set (Options->System) to authenticate this API call with ZM_AUTH_RELAY=plain');
      return '';
    }
    return ' -U '.escapeshellarg($user->Username()).' -P '.escapeshellarg($password);
  }

  /**
   * The token from token= or an Authorization: Bearer header. Also a token= that
   * only $_REQUEST carries (a body a method override dropped): ZoneMinder's
   * includes accept it as any type, so it is checked here as this action's.
   */
  public static function token(Request $request) {
    $token = $request->input('token');
    if ($token) return (string)$token;
    if (isset($_REQUEST['token']) and is_string($_REQUEST['token']) and $_REQUEST['token'] !== '') return $_REQUEST['token'];
    return preg_match('/^Bearer\s+(\S+)$/i', trim(self::authorizationHeader()), $m) ? $m[1] : null;
  }

  /**
   * The Authorization header. Apache with mod_php doesn't put it in $_SERVER
   * (only Basic and Digest credentials, as PHP_AUTH_*) unless the configuration
   * passes it (CGIPassAuth On, or a rewrite to REDIRECT_HTTP_AUTHORIZATION);
   * getallheaders() has it there, so stock Apache configurations work too.
   */
  private static function authorizationHeader() {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) return (string)$_SERVER['HTTP_AUTHORIZATION'];
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (function_exists('getallheaders')) {
      foreach ((array)getallheaders() as $name => $value) {
        if (!strcasecmp($name, 'Authorization')) return (string)$value;
      }
    }
    return '';
  }

  /**
   * @throws ForbiddenException when a GET that changes something was authenticated
   *   only by the browser's own credentials (a link or image on another site could
   *   send it). Such requests must be a POST or carry a token or credentials.
   */
  public static function requireExplicitForGet(Request $request) {
    if (!self::$ambient or !in_array($request->method, array('GET', 'HEAD', 'OPTIONS'), true)) return;
    // Without authentication no request can carry a credential (scripts, native
    // apps and ZoneMinder's own server-to-server calls send these as GETs), so
    // only a GET a browser marks as foreign (an <img> on another site) is refused.
    if (!ZM_OPT_USE_AUTH and !self::foreignBrowserRequest()) return;
    throw new ForbiddenException('This action changes state: send it as POST, or authenticate it with a token');
  }

  /** The user name of a password login in the request, or null when it carries none. */
  private static function loginName(Request $request) {
    foreach (array(array('user', 'pass'), array('username', 'password')) as $pair) {
      $name = $request->input($pair[0]);
      $password = $request->input($pair[1]);
      // As ZoneMinder tests them, with empty(): a '0' is no password.
      if (is_string($name) and !empty($name) and is_scalar($password) and !empty($password)) {
        return strtolower($name);
      }
    }
    return null;
  }

  /**
   * APCu keys counting failed logins: [this address and user name, this address].
   * Success clears only the first, so logging in to one's own account doesn't
   * reset the count of guesses at another's.
   */
  private static function throttleKeys($name) {
    require_once __DIR__.'/../../includes/Network.php';
    $address = getRemoteAddr();
    return array('zm-api-login-failures:'.$address.':'.sha1($name), 'zm-api-login-failures:'.$address);
  }

  /** @throws TooManyRequestsException after too many recent failures from this address, or for this name */
  private static function checkThrottle($name) {
    if (!function_exists('apcu_fetch')) return;
    list($byName, $byAddress) = self::throttleKeys($name);
    $named = apcu_fetch($byName);
    $all = apcu_fetch($byAddress);
    if (($named !== false and $named >= self::LOGIN_FAILURES) or ($all !== false and $all >= self::ADDRESS_FAILURES)) {
      \ZM\Warning('Refusing a login from '.getRemoteAddr().' after too many failed logins');
      throw new TooManyRequestsException('Too many failed logins; try again later');
    }
  }

  private static function recordFailure($name) {
    if (!function_exists('apcu_add')) return;
    foreach (self::throttleKeys($name) as $key) {
      apcu_add($key, 0, self::LOGIN_WINDOW);
      apcu_inc($key);
    }
  }

  private static function clearFailures($name) {
    if (function_exists('apcu_delete')) apcu_delete(self::throttleKeys($name)[0]);
  }

  /** Legacy auth: on stateful=1, make the session the authenticated user's. Runs after every check passed. */
  private static function session(Request $request, $password) {
    global $user;
    require_once __DIR__.'/../../includes/session.php';
    if ($request->input('stateful')) {
      // zm_session_start() records the client address (X-Forwarded-For only from
      // trusted proxies), which the auth hash is checked against.
      zm_session_start();
      if ($user) {
        if ($password or empty($_SESSION['username']) or $_SESSION['username'] !== $user->Username()) {
          // The session takes on a user it did not hold: a password login, or a
          // stateful request with a token or an auth hash. Issue a new session id
          // and drop what the old one held, so an id planted in the client
          // beforehand (any anonymous request stores one) doesn't become this
          // user's session (CWE-384). It also stores the session, though this
          // request may have arrived without a cookie.
          // 1.38: no zm_session_regenerate_id_login(); Compat has its body.
          Compat::regenerateSessionIdOnLogin();
        } else {
          // The session already is this user's: keep it, and store it even though
          // this request may have arrived without a cookie.
          zm_session_persist();
        }
        $_SESSION['username'] = $user->Username();
        // The password is not kept in the session, even with ZM_AUTH_RELAY=plain:
        // nothing in the API builds stream URLs, and zmu gets a token
        // (zmuCredentials()).
        generateAuthHash(ZM_AUTH_HASH_IPS);
      }
      session_write_close();
    }
  }

  /** Legacy auth: a request with our session cookie and no other credential is the session's user. */
  private static function userFromSessionCookie() {
    global $user;
    require_once __DIR__.'/../../includes/session.php';
    if (isset($_COOKIE['ZMSESSID']) and !$user) {
      if (!is_session_started()) zm_session_start();
      $user = userFromSession();
      session_write_close();
    }
  }

  /**
   * Take back the session cookie of a session that won't be stored.
   *
   * Authentication starts a session for every request without a token, and PHP
   * queues its cookie. But a session is only stored for a client that brought our
   * cookie or logged in statefully (zm_session_is_persistable()), so for anyone
   * else (an anonymous call, a token login) the cookie would name a session that
   * doesn't exist. Other cookies are left alone. Call before the answer is sent.
   */
  public static function dropUnstoredSessionCookie() {
    if (!function_exists('zm_session_is_persistable') or zm_session_is_persistable() or headers_sent()) return;
    $cookies = array();
    foreach (headers_list() as $header) {
      if (stripos($header, 'Set-Cookie:') === 0 and stripos(ltrim(substr($header, 11)), 'ZMSESSID=') !== 0) {
        $cookies[] = $header;
      }
    }
    header_remove('Set-Cookie');
    foreach ($cookies as $header) header($header, false);
  }

  /**
   * CORS headers, with credentials, for a browser page served by this install
   * from another origin:
   *
   *  - multi-server: a page on one of the servers in the Servers table, matched
   *    by its Hostname or Name;
   *  - single server with streaming ports (ZM_MIN_STREAMING_PORT): a page on this
   *    same host, on another port.
   *
   * The Origin's host must equal the server's exactly. Any other Origin gets no
   * CORS headers.
   */
  public static function cors(Response $response) {
    if (!isset($_SERVER['HTTP_ORIGIN'])) return;
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (self::corsAllowed($origin)) {
      $response->headers['Access-Control-Allow-Origin'] = $origin;
      $response->headers['Access-Control-Allow-Credentials'] = 'true';
      $response->headers['Access-Control-Allow-Headers'] = 'x-requested-with,x-request';
    }
  }

  /** Whether cors() answers $origin (see there). */
  private static function corsAllowed($origin) {
    global $Servers;
    // An Origin is scheme://host[:port], nothing more. Anything else (a path,
    // userinfo, a backslash) is not a browser's Origin and is never echoed;
    // it would also let parse_url() find a host the browser didn't send.
    if (!self::originShape($origin)) return false;
    $host = parse_url($origin, PHP_URL_HOST);
    if (!$host) return false;
    $allowed = array();
    if (count((array)$Servers)) {
      foreach ($Servers as $Server) {
        $allowed[] = $Server->Hostname();
        $allowed[] = $Server->Name();
      }
    } else if (ZM_MIN_STREAMING_PORT and isset($_SERVER['HTTP_HOST'])) {
      $allowed[] = parse_url('http://'.$_SERVER['HTTP_HOST'], PHP_URL_HOST);
    }
    foreach ($allowed as $name) {
      if ($name and !strcasecmp($host, $name)) return true;
    }
    return false;
  }
}
