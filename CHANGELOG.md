# Changelog

## 2.0.0 (unreleased)

Numbered after the API it serves (API v2, `apiversion` 2.0); was 0.1.0 before.

First version as its own repository. It began as a ZoneMinder branch that
replaced CakePHP in the tree, then:

- Packaging: `tools/zm-overlay.sh` layers it onto a ZoneMinder source tree,
  for distribution packages and test builds.
- Ported from ZoneMinder master since the import: restart zmc and zmcontrol.pl
  after an API monitor edit through `ZM\Monitor` (`25dba3267`).
- Security: 18 hardening changes over CakePHP's behaviour, each with a unit
  test (`SECURITY.md`).
- zmu gets `-U` with `-A` when it has to use an auth hash.
- Runs on ZoneMinder 1.38 as well as master: `api/src/Compat.php` probes
  for what 1.38 lacks (`zm_authenticate_request()`, `zmDbConn()`,
  `zm_session_regenerate_id_login()`, the EncoderTemplates and Notifications
  tables, frames keyed by event and frame number) and answers as on master.
  `tests/run.sh` passes against both trees.
- Fewer database round trips: table columns are kept in APCu (60 s, keyed
  by ZoneMinder's version and database version) instead of a `SHOW COLUMNS`
  per model and joined table on every request, and the API's statements are
  prepared client-side (one round trip instead of three; PHP 8.1 and later,
  where the value types are the same).
- Fewer statements per call: an access token's user and permissions are kept
  5 s in APCu (`TokenCache`; changes made outside the API apply within those
  5 s, user edits through the API at once); event lists and views skip reading
  every monitor when the user may view them all; monitor and event view drop
  their existence check, and event view reads its neighbours and frame ids in
  one statement (monitor view 8 statements to 3 with a cached token, event view
  18 to 7, monitor list 7 to 3).
- Measured on ZoneMinder master (`1fc6630d7`, 9 common API calls, 64
  concurrent clients): 2,624 requests/s, p95 22.4 ms, against CakePHP's 1,581
  requests/s, p95 58.7 ms, on the same build and data (~1.7x); responses
  byte-identical to CakePHP's.

- API reference: `docs/openapi/openapi.json` (OpenAPI 3.1) documents every
  action, its URLs, parameters, the permissions it needs, and its answers'
  statuses and bodies, taken from the contract suite's recorded answers.
  `make docs` serves it rendered (Redoc). `test_api_openapi.php` fails when an
  action is added or removed without the document following, or when a
  documented URL routes to another action. A row field whose column may be
  NULL is documented as nullable, inside join rows (`EventsTag`) too.

- Web server: `docs/webserver.md` (nginx location order, Apache, next to API
  v3, the API reference); `contrib/webserver/nginx.conf` sends every `/zm/api/`
  path to the front controller, as Apache's rules already do.

See `SECURITY.md` for every intended difference from CakePHP's API.
