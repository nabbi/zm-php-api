# Changelog

## 2.0.0-rc.1 (2026-11-08)

First public release, as a release candidate: the ZoneMinder release that
carries the API fixes its unit tests reproduce is still in the works, so the
2.0.0 final follows that release. Packaged as a tarball on the GitHub release
(`tools/package.sh`), built and tested by CI (`.github/workflows/`) against
ZoneMinder master and release-1.38 on PHP 8.1, 8.3 and 8.4. Gentoo: the
`www-misc/zoneminder` ebuild in the
[oubliette overlay](https://github.com/nabbi/oubliette-overlay) builds
ZoneMinder with this API (`USE=apiv2-php`) or with CakePHP (`USE=apiv2-cake`).

Numbered after the API it serves (API v2, `apiversion` 2.0); was 0.1.0 before.

First version as its own repository. It began as a ZoneMinder branch that
replaced CakePHP in the tree, then:

- Packaging: `tools/zm-overlay.sh` layers it onto a ZoneMinder source tree,
  for distribution packages and test builds.
- Ported from ZoneMinder master since the import: restart zmc and zmcontrol.pl
  after an API monitor edit through `ZM\Monitor` (`25dba3267`); a password
  change sets `TokenMinExpiry`, so the user's tokens issued before it are
  refused (`#5455`, GHSA-5w6w); area permission checks
  (`Controller::requirePermission()`) count the user's role (User_Roles) as
  well as their own field, as `ZM\User::canView()/canEdit()` and the web UI
  do, in every area (CakePHP's API does so for Events only, GHSA-rmf7), and an
  area that is not a permission fails closed. All-digit filter field names
  (`0:1=1`, GHSA-xqfr) were already refused as unknown columns; a test pins it.
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

- `monitors/alarm/.../command:on` takes an optional `cause` (query or body),
  passed to `zmu --cause` so the event records it in place of "Forced Web"
  (the ZoneMinder change that adds it). Only letters, digits, spaces and plain
  punctuation are kept, cut to 31 bytes on a character boundary. Where zmu has no `--cause` (1.38,
  master before that change) it is not passed: such a zmu would print its
  usage and not force the alarm. `test_api_alarm_cause.php`.

See `SECURITY.md` for every intended difference from CakePHP's API.
