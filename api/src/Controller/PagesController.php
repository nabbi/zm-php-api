<?php
namespace ZM\Api\Controller;

use ZM\Api\Http\ForbiddenException;
use ZM\Api\Http\NotFoundException;
use ZM\Api\Http\Response;

/**
 * /pages: static pages. The API has none, so this answers 404 (403 for a path
 * with . or ..), as the CakePHP scaffold it replaces did. /api/ itself redirects
 * to /api/pages/home.
 */
class PagesController extends Controller {
  public function display(...$path) {
    if (!count($path)) {
      $response = new Response();
      $response->status = 302;
      $response->headers['Location'] = $this->request->base.'/';
      return $response;
    }
    if (in_array('..', $path, true) or in_array('.', $path, true)) throw new ForbiddenException('Forbidden');
    throw new NotFoundException('Not Found');
  }
}
