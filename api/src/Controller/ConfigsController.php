<?php
namespace ZM\Api\Controller;

use ZM\Api\Db\Db;
use ZM\Api\Http\ForbiddenException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;
use ZM\Api\Model\Config;

/**
 * /configs: the options of Options. Reading needs System=View, changing System=Edit.
 *
 * An option is addressed by its Name (configs/ZM_WEB_EVENTS_PER_PAGE) or its Id.
 * The value answered is the one in effect, which zm.conf and conf.d may override.
 * Secrets (private options, the database credentials) are answered with an
 * empty Value.
 */
class ConfigsController extends Controller {
  /**
   * Options whose value is never answered, besides those marked Private: the
   * database credentials, and passwords ZoneMinder doesn't mark Private (the
   * upload and MQTT passwords, the Janus API secret).
   */
  const SECRETS = array('ZM_DB_HOST', 'ZM_DB_NAME', 'ZM_DB_USER', 'ZM_DB_PASS', 'ZM_DB_TYPE',
    'ZM_AUTH_HASH_SECRET', 'ZM_UPLOAD_PASS', 'ZM_MQTT_PASSWORD', 'ZM_JANUS_SECRET');

  /** @var Config */
  private $model;

  public function __construct($request, $route) {
    parent::__construct($request, $route);
    $this->model = new Config();
  }

  /**
   * Every option (the database's, then those only in the config files), or with
   * Name:value filters, the matching database rows.
   */
  public function index() {
    $this->requirePermission('System', 'View', 'Insufficient privileges');
    $conditions = \ZM\Api\Filter::conditions($this->route->named, $this->model);
    // A secret's Value is answered empty; it must not be matched either, or
    // Value LIKE:a% one character at a time reads it (the auth hash secret, so
    // tokens could be forged). Filters on Value only ever match other options.
    if (self::namesValue($conditions)) {
      $conditions[] = array('Config.Private' => 0);
      $conditions[] = array('Config.Name NOT IN' => self::SECRETS);
    }
    $configs = $this->model->find('all', array('conditions' => $conditions, 'recursive' => -1));
    global $zm_config;
    $byName = array();
    foreach ($configs as $i => $c) $byName[$c['Config']['Name']] = $i;
    foreach ($zm_config as $name => $c) {
      if (isset($byName[$name])) {
        $configs[$byName[$name]]['Config']['Value'] = $c['Value'];
      } else if (!count($conditions)) {
        $configs[] = array('Config' => $c);
      }
    }
    foreach ($configs as &$config) $config['Config'] = $this->redact($config['Config']);
    return array('configs' => $configs);
  }

  public function view($id = null) {
    $this->requirePermission('System', 'View', 'Insufficient privileges');
    $name = $this->resolve($id);
    $config = $this->model->find('first', array('conditions' => array('Config.Name' => $name), 'recursive' => -1));
    global $zm_config;
    if (isset($zm_config[$name])) $config['Config']['Value'] = $zm_config[$name]['Value'];
    $config['Config'] = $this->redact($config['Config']);
    return array('config' => $config);
  }

  /**
   * configs/viewByName/<Name>: {"config": {"Value": ...}}, also for options that
   * exist only in the config files (those answer their Name too).
   */
  public function viewByName($name = null) {
    $this->requirePermission('System', 'View', 'Insufficient privileges');
    global $zm_config;
    $value = Db::value('SELECT `Value` FROM `Config` WHERE `Name` = ?', array((string)$name));
    if ($value === null and !$this->model->exists($name)) {
      if (!isset($zm_config[$name])) {
        \ZM\Debug('No such config '.$name);
        throw new NotFoundException('Invalid config');
      }
      $config = $zm_config[$name];
    } else {
      $config = array('Value' => isset($zm_config[$name]) ? $zm_config[$name]['Value'] : $value);
    }
    return array('config' => $this->redact($config, $name));
  }

  /** configs/categories: {"categories": [{"Config": {"Category": ...}}, ...]} */
  public function categories($category = null) {
    $this->requirePermission('System', 'View', 'Insufficient privileges');
    $rows = Db::select("SELECT DISTINCT `Config`.`Category` FROM `Config` AS `Config` WHERE `Config`.`Category` != ?",
      array('hidden'));
    return array('categories' => $rows);
  }

  /** Change an option's Value. System options (zm.conf) and read-only ones refuse with 403. */
  public function edit($id = null) {
    $this->requirePermission('System', 'Edit', 'Insufficient privileges');
    $name = $this->resolve($id);
    $this->allowMethod('POST', 'PUT');
    $row = $this->model->find('first', array('conditions' => array('Config.Name' => $name), 'recursive' => -1));
    if (!empty($row['Config']['System'])) {
      throw new ForbiddenException('Cannot edit a system Config entry. Must be changed in /etc/zm/zm.conf');
    }
    if (!empty($row['Config']['Readonly'])) {
      throw new ForbiddenException('Cannot edit a readonly Config entry');
    }
    $data = $this->request->data;
    $record = (isset($data['Config']) and is_array($data['Config'])) ? $data['Config'] : $data;
    unset($record['Name'], $record['Id']);
    $this->model->save(array('Config' => $record), $name);
    return Response::nothing($this->request->format);
  }

  public function delete($id = null) {
    $this->requirePermission('System', 'Edit', 'Insufficient privileges');
    $name = $this->resolve($id);
    $this->allowMethod('POST', 'DELETE');
    $this->model->delete($name);
    return Response::nothing($this->request->format);
  }

  /**
   * The Name of the option addressed as $id: its Name, or its numeric Id.
   * @throws NotFoundException when there is no such row
   */
  private function resolve($id) {
    if ($id !== null and ctype_digit((string)$id)) {
      $name = Db::value('SELECT `Name` FROM `Config` WHERE `Id` = ?', array((int)$id));
      if ($name !== null) return $name;
    }
    if (!$this->model->exists($id)) throw new NotFoundException('Invalid config');
    return $id;
  }

  /** Whether find() conditions (as Filter::conditions() writes them) test Config.Value. */
  private static function namesValue(array $conditions) {
    foreach ($conditions as $key => $value) {
      if (is_string($key) and preg_match('/^Config\.Value(\s|$)/', $key)) return true;
      if (is_array($value) and self::namesValue($value)) return true;
    }
    return false;
  }

  /** $config with an empty Value if the option is a secret. */
  private function redact(array $config, $name = null) {
    if ($name === null) $name = isset($config['Name']) ? $config['Name'] : '';
    global $zm_config;
    $private = (!empty($config['Private']) || (isset($zm_config[$name]) && !empty($zm_config[$name]['Private'])));
    if ($private or in_array($name, self::SECRETS, true)) $config['Value'] = '';
    return $config;
  }


}
