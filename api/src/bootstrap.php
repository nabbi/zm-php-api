<?php
//
// ZoneMinder API: class loading. Classes in namespace ZM\Api live under src/,
// one per file, the path following the namespace (ZM\Api\Http\Router is
// src/Http/Router.php).
//

spl_autoload_register(function ($class) {
  $prefix = 'ZM\\Api\\';
  if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
  $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
  if (is_file($file)) require $file;
});
