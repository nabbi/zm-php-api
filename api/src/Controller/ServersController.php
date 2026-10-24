<?php
namespace ZM\Api\Controller;

/** /servers: the servers of a multi-server install. Anyone may read; changes need System=Edit. */
class ServersController extends CrudController {
  const MODEL = \ZM\Api\Model\Server::class;
  const PLURAL = 'servers';
  const SINGULAR = 'server';
  const NOT_FOUND = 'Invalid server';
}
