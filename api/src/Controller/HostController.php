<?php
//
// ZoneMinder API: /host - version, login, server facts and daemon status.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

namespace ZM\Api\Controller;

use ZM\Api\Compat;
use ZM\Api\Http\ForbiddenException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\UnauthorizedException;

class HostController extends Controller {
  const API_VERSION = '2.0';

  /** host/getVersion: {"version": ZM_VERSION, "apiversion": "2.0"} */
  public function getVersion() {
    return array('version' => ZM_VERSION, 'apiversion' => self::API_VERSION);
  }

  /** host/getTimeZone: the server's PHP time zone. */
  public function getTimeZone() {
    return array('tz' => date_default_timezone_get());
  }

  /** host/getLoad: the load averages; needs System=View, as the web UI's figure does. */
  public function getLoad() {
    $this->requirePermission('System', 'View');
    return array('load' => sys_getloadavg());
  }

  /**
   * host/login: tokens for the caller. With user/pass (or username/password),
   * an access and a refresh token; with token=<refresh token>, a new access token.
   * With legacy auth on, also auth= credentials for stream URLs.
   */
  public function login() {
    global $user;
    $answer = array('version' => ZM_VERSION, 'apiversion' => self::API_VERSION);
    if (!ZM_OPT_USE_AUTH) return $answer;

    $username = $this->request->input('user');
    if (!$username) $username = $this->request->input('username');
    $password = $this->request->input('pass');
    if (!$password) $password = $this->request->input('password');
    $token = \ZM\Api\Auth::token($this->request);
    if (!($username and $password) and !$token) {
      throw new UnauthorizedException('No identity provided');
    }
    if ($username and $password and $token) {
      // A password and someone's refresh token: whose tokens would the login mint?
      // Both spellings (user/pass, username/password); beforeFilter() refuses the
      // first on its own, this covers the second.
      throw new UnauthorizedException('Log in with a user name and password or with a refresh token, not both');
    }

    if ($username and $password) {
      // The tokens are for the account that authenticated (beforeFilter), never
      // for a name taken from the request.
      if (!$user) throw new UnauthorizedException('Not authenticated');
      \ZM\Debug('Username and password provided, generating access and refresh tokens');
      $cred = $this->credentials(true, '', $user->Username());
    } else {
      \ZM\Debug('Only generating access token');
      $cred = $this->credentials(false, $token);
    }
    if ($cred) {
      $answer['access_token'] = $cred[0];
      $answer['access_token_expires'] = $cred[1];
      if ($username and $password) {
        $answer['refresh_token'] = $cred[2];
        $answer['refresh_token_expires'] = $cred[3];
      }
    }

    if (ZM_OPT_USE_LEGACY_API_AUTH) {
      if (ZM_AUTH_RELAY == 'hashed') {
        $answer['credentials'] = 'auth='.generateAuthHash(ZM_AUTH_HASH_IPS, true);
        $answer['append_password'] = 0;
      } else {
        $answer['credentials'] = 'user='.urlencode($user->Username()).'&pass=';
        $answer['append_password'] = 1;
      }
    } else {
      \ZM\Debug('Legacy Auth is disabled, not generating auth= credentials');
    }
    return $answer;
  }

  /** host/logout: ends the session. */
  public function logout() {
    userLogout();
    return array('result' => 'ok');
  }

  /**
   * host/daemonCheck[/<daemon>[/<args>]]: whether zmdc.pl reports the daemon (or
   * ZoneMinder as a whole) running. Whether ZoneMinder is running at all is open
   * to every logged in user, as in CakePHP: the web UI and clients poll it as a
   * health check. Asking about one daemon (zmc -m 3: does monitor 3 exist and
   * capture?) needs System=View, as daemonControl's check and status do.
   */
  public function daemonCheck($daemon = false, $args = false) {
    if ($daemon !== false and $daemon !== '') $this->requirePermission('System', 'View');
    // Only letters, digits, '-', '.' and spaces reach the command line.
    $count = 0;
    $safeDaemon = $daemon ? preg_replace('/[^A-Za-z0-9\- \.]/', '', $daemon, -1, $count) : false;
    if ($count) \ZM\Error("Invalid characters found in daemon string ($daemon). Potential attack?");
    $safeArgs = $args ? preg_replace('/[^A-Za-z0-9\- \.]/', '', $args, -1, $count) : false;
    if ($count) \ZM\Error("Invalid characters found in args string ($args). Potential attack?");

    $command = ZM_PATH_BIN.'/zmdc.pl check';
    if ($safeDaemon) {
      $command .= ' '.$safeDaemon;
      if ($safeArgs) $command .= ' '.$safeArgs;
    }
    return array('result' => preg_match('/running/', exec($command)));
  }

  /**
   * host/daemonControl/<daemon>/<command>: run zmdc.pl <command> <daemon>, e.g.
   * zmfilter.pl/status. check and status need System=View, the rest System=Edit.
   */
  public function daemonControl($daemon, $command) {
    $reads = ($command == 'check' || $command == 'status');
    $this->requirePermission('System', $reads ? 'View' : 'Edit', 'Insufficient privileges');
    if (!$reads) \ZM\Api\Auth::requireExplicitForGet($this->request);
    $safeDaemon = preg_replace('/[^A-Za-z0-9\-\.]/', '', $daemon, -1, $count);
    if ($count) \ZM\Error("Invalid characters found in daemon string ($daemon). Potential attack?");
    $safeCommand = preg_replace('/[^a-z]/', '', $command, -1, $count);
    if ($count) \ZM\Error("Invalid characters found in command string ($command). Potential attack?");
    return array('result' => exec(ZM_PATH_BIN."/zmdc.pl $safeCommand $safeDaemon"));
  }

  /**
   * host/getDiskPercent[/<monitor id>]: disk used by events, per monitor the user
   * may view (in MB, keyed by monitor name) and in total. Deprecated: use the
   * Storage and Monitor records instead.
   */
  public function getDiskPercent($mid = null) {
    require_once __DIR__.'/../../../includes/Monitor.php';
    if ($mid) {
      // Digits only: MySQL would find monitor 1 for '1/../../root', and the value
      // is part of the path du measures.
      if (!ctype_digit((string)$mid)) throw new NotFoundException('Invalid monitor');
      $monitor = \ZM\Monitor::find_one(array('Id' => $mid));
      if (!$monitor) throw new NotFoundException('Invalid monitor');
      if (!$monitor->canView()) throw new UnauthorizedException('Insufficient Privileges');
    }

    $dir = ZM_DIR_EVENTS;
    if (substr($dir, 0, 1) != '/') $dir = ZM_PATH_WEB.'/'.$dir;

    if ($mid) {
      $usage = shell_exec('du -s0 '.escapeshellarg($dir.'/'.(int)$monitor->Id())." | awk '{print \$1}'");
    } else {
      $usage = array();
      foreach (dbFetchAll('SELECT `Id`, `Name`, `WebColour` FROM `Monitors`') as $row) {
        $monitor = new \ZM\Monitor($row['Id']);
        if (!$monitor->canView()) continue;
        $space = shell_exec('du -s0 '.escapeshellarg($dir.'/'.$row['Id'])." | awk '{print \$1}'");
        $space = ($space == null ? 0 : $space) / 1024 / 1024;
        $usage[$row['Name']] = array('space' => rtrim($space), 'color' => $row['WebColour']);
      }
      $space = shell_exec('df '.escapeshellarg($dir)." | tail -n1 | awk '{print \$3}'") / 1024 / 1024;
      $usage['Total'] = array('space' => rtrim($space), 'color' => '#F7464A');
    }
    return array('usage' => $usage);
  }

  // ------------------------------------------------------------------- helpers


  /**
   * [access token, its lifetime, refresh token, its lifetime] for $username, or
   * for the user of the refresh token $token. Access tokens last ZM_AUTH_HASH_TTL
   * hours, refresh tokens a day. AUTH_HASH_IPS is not applied to tokens: mobile
   * clients change address, and zmu checks tokens without one.
   */
  private function credentials($withRefresh, $token = '', $username = '') {
    if (!ZM_OPT_USE_AUTH) return null;
    Compat::requireAuth();  // 1.38: a bare include of auth.php would authenticate
    if (!authHashSecretIsSet()) {
      throw new ForbiddenException('Please create a valid AUTH_HASH_SECRET in ZoneMinder');
    }
    if ($token) {
      list($tokenUser) = validateToken($token, 'refresh', true);
      $username = $tokenUser->Username();
    }
    \ZM\Debug("Creating token for \"$username\"");

    $accessTtl = max(ZM_AUTH_HASH_TTL, 1) * 3600;
    $access = \ZM\Api\Auth::jwt($username, 'access', $accessTtl);

    $refresh = '';
    $refreshTtl = 0;
    if ($withRefresh) {
      $refreshTtl = 24 * 3600;
      $refresh = \ZM\Api\Auth::jwt($username, 'refresh', $refreshTtl);
    }
    return array($access, $accessTtl, $refresh, $refreshTtl);
  }
}
