<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\Response;
use ZM\Api\Paginator;

/**
 * /logs: ZoneMinder's log. Needs System=View; writing needs System=Edit (adding
 * also works for anyone when ZM_LOG_INJECT is on). Entries are addressed by
 * TimeKey: logs/view/<TimeKey>.
 *
 * The index is paged with ?page=&limit= (100 by default), oldest first unless
 * ?sort=&direction= say otherwise: {"logs": [...], "pagination": {...}}.
 */
class LogsController extends CrudController {
  const MODEL = \ZM\Api\Model\Log::class;
  const PLURAL = 'logs';
  const SINGULAR = 'log';
  const NOT_FOUND = 'Invalid log';
  const VIEW_PERMISSION = array('System', 'View');
  const VIEW_RECURSIVE = 1;

  public function index() {
    $paginator = new Paginator(array('limit' => 100, 'order' => array('Log.TimeKey' => 'asc'),
      'paramType' => 'querystring', 'recursive' => -1));
    list($rows, $paging) = $paginator->paginate($this->model, $this->route->named, $this->request->query);
    return array('logs' => $rows, 'pagination' => $paging);
  }

  /** Without a TimeKey, the entry is stamped now. */
  public function add() {
    global $user;
    if ($user and $user->System() != 'Edit' and !ZM_LOG_INJECT) {
      throw new \ZM\Api\Http\UnauthorizedException('Insufficient privileges');
    }
    $this->allowMethod('POST');
    $data = $this->request->data;
    $record = (isset($data['Log']) and is_array($data['Log'])) ? $data['Log'] : $data;
    unset($record['Id']);
    if (!isset($record['TimeKey']) or $record['TimeKey'] === '') $record['TimeKey'] = sprintf('%.6f', microtime(true));
    $this->model->id = null;
    $this->saveOrFail(array('Log' => $record));
    return Response::nothing($this->request->format);
  }

  public function edit($id = null) {
    $this->requireEdit();
    $this->requireExists($id);
    $this->allowMethod('POST', 'PUT');
    $this->saveOrFail($this->withoutPrimaryKey($this->request->data), $id);
    return Response::nothing($this->request->format);
  }
}
