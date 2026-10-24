<?php
//
// ZoneMinder API front controller.
//
// The web server sends every request under /zm/api/ here; see src/README.md for
// the layout, how a request is handled and how to add an action.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//

require_once __DIR__.'/src/bootstrap.php';

$request = ZM\Api\Http\Request::fromGlobals();

// ZoneMinder's configuration, database connection and Servers list. Loaded here,
// at file scope, because it defines globals the rest of ZoneMinder's PHP reads.
require_once __DIR__.'/../includes/config.php';

ZM\Api\App::handle($request)->send();
