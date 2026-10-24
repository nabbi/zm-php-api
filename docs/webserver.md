# Web server configuration

ZoneMinder's own Apache and nginx configurations work unchanged: they send
`/zm/api` to `api/app/webroot/index.php`, which forwards to `dispatch.php`.
This page covers what is worth changing, and running the API next to API v3.
Every block here was checked with nginx (alpine image, PHP-FPM 8.4) and Apache
2.4 (mod_php 8.4) against a stub front controller, for routes, encoded named
arguments, query strings and form bodies.

## nginx: send every /zm/api/ path to the front controller

ZoneMinder's nginx sample routes the API with a prefix location nested in
`location /zm`, after the `\.php$` and image regexes:

```nginx
location /zm/api/ {
    alias "@ZM_WEBDIR@";
    rewrite ^/zm/api(.+)$ /zm/api/app/webroot/index.php?p=$1 last;
}
```

nginx matches the nested regexes before that prefix, so any `.php` file under
`api/` runs directly (`/zm/api/src/App.php` answers 200) and images under
`api/` are served as files. The API's class files only define classes, so
nothing runs, but no request should reach them. A regex placed first, which
skips only the front controller so it doesn't loop, sends all of them through
the API:

```nginx
location /zm {
    alias "@ZM_WEBDIR@";
    index index.php;

    location ~ ^/zm/api/(?!dispatch\.php$) {
        rewrite ^/zm/api(/.*)$ /zm/api/dispatch.php?p=$1 last;
    }

    location ~ \.php$ { ... }                      # unchanged
    location ~ \.(jpg|jpeg|gif|png|ico)$ { ... }   # unchanged
}
```

and drop the `location /zm/api/` block. To keep the CakePHP-era entry point,
use `app/webroot/index\.php` in both places instead of `dispatch\.php`. The
same block is in `contrib/webserver/nginx.conf`.

## Apache

The rewrite rules live in the server configuration. This API ships no
`.htaccess` files, so `AllowOverride None` is fine. ZoneMinder's three
`<Directory>` blocks (`api`, `api/app`, `api/app/webroot`) rewrite into
`app/webroot`, where anything that isn't a file goes to `index.php`. Since
`api/app/webroot` holds only that file, every API path reaches the front
controller, `api/src/` included. `contrib/webserver/apache.conf` replaces the
three blocks with one that names `dispatch.php` directly.

## Next to API v3 (zm-api)

[zm-api](https://github.com/SteveGilvarry/zm-api) can be published under the
same `/zm/api` prefix (as `/zm/api/v3/`) by a reverse proxy, provided its
location wins over this API's rewrite:

- **nginx:** a `^~` prefix location at server level. A `^~` prefix match that
  is the longest prefix stops the regex search, so the nested API regex above
  never sees `/zm/api/v3/` requests:

  ```nginx
  location ^~ /zm/api/v3/ {
      proxy_pass http://127.0.0.1:8080/api/v3/;
      proxy_http_version 1.1;
      proxy_set_header Host $http_host;
      proxy_set_header X-Forwarded-For $remote_addr;
      proxy_set_header X-Forwarded-Proto $scheme;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection $http_connection;
      proxy_buffering off;
      proxy_read_timeout 1h;
  }
  ```

- **Apache:** `ProxyPass` maps the URL before the `<Directory>` rewrites run,
  so it needs no ordering against them (mod_proxy, mod_proxy_http, and
  mod_proxy_wstunnel for `upgrade=websocket`, mod_headers):

  ```apache
  ProxyPreserveHost On
  ProxyPass        /zm/api/v3/ http://127.0.0.1:8080/api/v3/ upgrade=websocket
  ProxyPassReverse /zm/api/v3/ http://127.0.0.1:8080/api/v3/
  <Location "/zm/api/v3/">
      RequestHeader unset X-Forwarded-For
  </Location>
  ```

- **X-Forwarded-For:** set it to the peer address (nginx), or drop the
  client's before mod_proxy appends the peer (Apache). When zm-api trusts proxy
  headers it keys its login rate limit on the first address in that header, so
  one a client sends would choose its own bucket.
- **Links in responses:** zm-api writes URLs that start at the site root
  (`/api/v3/...`: HLS playlists, media links, Swagger UI). Under a prefix those
  links miss the proxy until zm-api can be told its public prefix (proposed in
  [zm-api#222](https://github.com/SteveGilvarry/zm-api/issues/222)). Until
  then, also proxy the root paths it writes, or keep it at `/api/v3`.

## Serving the API reference

`docs/openapi/` is static (`index.html`, `openapi.json`) and sends no requests,
so a package can install it and serve it read-only, e.g. at `/zm/api-docs/v2/`:

```nginx
location ^~ /zm/api-docs/ {
    alias "/usr/share/zoneminder/www/api-docs/";   # holds v2/index.html, v2/openapi.json
    index index.html;
}
```

On Apache, files under ZoneMinder's web root are served by its `/zm` alias
already. It isn't installed by default (`docs/openapi/README.md`).

## Optional: refusing multipart bodies

Some installs refuse `multipart/` request bodies at the web server, so PHP
never parses them. Nothing in ZoneMinder needs them except the monitor CSV
import. It is not on by default, because this API reads multipart form fields
like URL-encoded ones (as CakePHP did), so a client that posts its forms as
multipart gets a 403 instead. Commented out, for those who want it:

```nginx
# Inside the server block. Breaks the monitor CSV import and multipart API clients.
#if ($content_type ~* "^multipart/") {
#    return 403;
#}
```

```apache
# Inside <Directory "@ZM_WEBDIR@">. Breaks the monitor CSV import and multipart API clients.
#<If "%{HTTP:Content-Type} =~ m#^multipart/#i">
#    Require all denied
#</If>
```
