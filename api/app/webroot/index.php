<?php
//
// Backwards compatibility: web server configurations written for the CakePHP
// API send /zm/api requests here (Apache's api/app/webroot rewrites, nginx's
// rewrite to api/app/webroot/index.php). They are answered by the API's front
// controller, so an upgrade works before those configurations are updated to
// point at web/api/dispatch.php. Remove once installs have had a release to update.
//
require __DIR__.'/../../dispatch.php';
