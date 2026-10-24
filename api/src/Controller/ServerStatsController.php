<?php
namespace ZM\Api\Controller;

/** /server_stats: load samples, oldest first. Anyone may read; changes need System=Edit. */
class ServerStatsController extends CrudController {
  const MODEL = \ZM\Api\Model\ServerStat::class;
  const PLURAL = 'serverstats';
  const SINGULAR = 'serverstat';
  const NOT_FOUND = 'Invalid server stat entry';
  const NOT_FOUND_WRITE = 'Invalid server stat';
  const FILTERABLE = true;
  const INDEX_ORDER = array('TimeStamp ASC');
}
