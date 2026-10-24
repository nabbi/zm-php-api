<?php
namespace ZM\Api\Model;

use ZM\Api\Db\Model;

/** A monitor's event counts and disk use, kept up to date by ZoneMinder (Event_Summaries). */
class EventSummary extends Model {
  const TABLE = 'Event_Summaries';
  const ALIAS = 'Event_Summary';
  const PRIMARY_KEY = 'MonitorId';
}
