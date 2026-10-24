# Installing

The API replaces `web/api` in a ZoneMinder **source tree** before ZoneMinder is
configured, so it is built and installed with ZoneMinder:

```sh
tools/zm-overlay.sh /path/to/zoneminder     # replaces web/api, drops CakePHP's CMake hooks
cd /path/to/zoneminder && cmake ... && make install
```

`zm-overlay.sh` removes CakePHP (`web/api/app`, `web/api/lib`, the Crud and
Enum-Behavior submodules), drops `web/CMakeLists.txt`'s CakePHP config install
rules, points the top-level submodule check at `dep/RtspServer`, and writes
`web/api/VERSION`. It checks every edit applies before making any, and running
it again is harmless.

## Packaging

A distribution package runs `tools/zm-overlay.sh` on the unpacked ZoneMinder
source in its prepare step, before CMake, and can run the unit tests
(`ZM_SRC=<source> tests/run.sh`, PHP CLI only) as its test step. Offering both
APIs is a build option: without the overlay step, ZoneMinder's CakePHP API is
built unchanged.

## Web server

Nothing to change. ZoneMinder's Apache and nginx configurations send `/zm/api`
to `api/app/webroot/index.php`, which now forwards to `dispatch.php`. To route
to `dispatch.php` directly, see `contrib/webserver/`; for nginx's location
order, running next to API v3 and serving the API reference, see
[webserver.md](webserver.md).

## Repository layout

| Path | What |
|---|---|
| `api/` | Installs as ZoneMinder's `web/api`: `dispatch.php`, `src/` (guide: [architecture.md](architecture.md)), and `index.php` + `app/webroot/index.php`, which forward to `dispatch.php` so CakePHP-era web server configurations keep working |
| `tools/zm-overlay.sh` | Layers `api/` onto a ZoneMinder source tree in place of CakePHP, before CMake runs |
| `tests/` | DB-free unit tests; `tests/run.sh` runs them against a ZoneMinder source tree |
| `contrib/webserver/` | Optional: Apache and nginx rules that route `/zm/api` to `dispatch.php` directly |
| `SECURITY.md` | Reporting, and what the security changes cover |
| `docs/install.md` | Installing (this page) |
| `docs/webserver.md` | nginx and Apache: routing, next to API v3, the API reference |
| `docs/architecture.md` | How a request is handled, conventions, what changed from CakePHP |
| `docs/compatibility.md` | The request shapes each client sends, and the remaining (deliberate) differences from CakePHP |
| `docs/testing.md` | How it is tested, and the latest results |
