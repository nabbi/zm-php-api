<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/**
 * A monitor (Monitors). Its answers nest the joined status and event counts
 * (Monitor_Status, Event_Summary) and, deeper, its events, zones and groups.
 */
class Monitor extends Model {
  const TABLE = 'Monitors';
  const ALIAS = 'Monitor';

  protected $belongsTo = array(
    'Manufacturer' => array('model' => Manufacturer::class, 'foreignKey' => 'ManufacturerId'),
    'CameraModel' => array('model' => CameraModel::class, 'foreignKey' => 'ModelId'),
  );
  protected $hasOne = array(
    'Monitor_Status' => array('model' => MonitorStatus::class, 'foreignKey' => 'MonitorId'),
    'Event_Summary' => array('model' => EventSummary::class, 'foreignKey' => 'MonitorId'),
  );
  protected $hasMany = array(
    'Event' => array('model' => Event::class, 'foreignKey' => 'MonitorId'),
    'Zone' => array('model' => Zone::class, 'foreignKey' => 'MonitorId', 'dependent' => true),
  );
  protected $habtm = array(
    'Group' => array('model' => Group::class, 'joinTable' => 'Groups_Monitors', 'foreignKey' => 'MonitorId',
      'associationForeignKey' => 'GroupId', 'with' => 'GroupsMonitor'),
  );
  protected $validate = array(
    'Id' => array(array('rule' => 'numeric')),
    'Name' => array('required' => array('rule' => 'notBlank', 'on' => 'create', 'required' => true,
      'message' => 'Monitor Name must be specified for creation')),
    'OutputCodec' => array('rule' => array('inList', array(0, 27, 173, 167, 226)),
      'message' => 'Invalid value. Should be one of these integer values: 0(auto), 27(h264), 173(h265/hvec), 167(vp9), 226(av1)'),
    'Device' => array('validPath' => array('rule' => 'validDevicePath', 'allowEmpty' => true,
      'message' => 'Invalid device path. Must be a valid /dev/ path (e.g. /dev/video0).')),
  );

  /**
   * Run zmdc.pl <command> for a monitor's daemon (zmc unless $daemon names another)
   * and return its output. Nothing happens, and null is returned, for a monitor
   * that doesn't capture (unless stopping) or that runs on another server.
   *
   * @param array $monitor the monitor's row (Id, Type, Device, Capturing, ServerId)
   */
  public static function daemonControl(array $monitor, $command, $daemon = null) {
    if ($monitor['Capturing'] == 'None' and $command != 'stop') {
      \ZM\Warning("Calling daemonControl with command $command when Capturing == None");
      return null;
    }
    if (defined('ZM_SERVER_ID') and ($monitor['ServerId'] != ZM_SERVER_ID)) {
      \ZM\Error('Calling daemonControl for Monitor assigned to different server. Our server id '
        .ZM_SERVER_ID.' != '.$monitor['ServerId']);
      return null;
    }
    $daemon = $daemon ? $daemon : 'zmc';
    if ($daemon == 'zmc' and $monitor['Type'] == 'Local') {
      $args = '-d '.escapeshellarg($monitor['Device']);
    } else if ($daemon == 'zmcontrol.pl') {
      $args = '--id '.escapeshellarg($monitor['Id']);
    } else {
      $args = '-m '.escapeshellarg($monitor['Id']);
    }
    $command = ZM_PATH_BIN.'/zmdc.pl '.escapeshellarg($command).' '.escapeshellarg($daemon).' '.$args;
    \ZM\Debug("Command $command");
    return exec($command).PHP_EOL;
  }

  /**
   * Device: only Local monitors pass it to a shell, so only for them must it be a
   * /dev/ path. Other types may hold legacy values (an RTSP URL) there.
   */
  public function validDevicePath($value, array $fields) {
    if ($value === null or $value === '') return true;
    if ((isset($fields['Type']) ? $fields['Type'] : null) !== 'Local') return true;
    return (bool)preg_match('#^/dev/[\w/.\-]+$#', $value);
  }
}
