# zm-php-api

[![ci](https://github.com/nabbi/zm-php-api/actions/workflows/ci.yml/badge.svg)](https://github.com/nabbi/zm-php-api/actions/workflows/ci.yml)

A drop-in replacement for ZoneMinder's CakePHP API (`/zm/api`, API v2: it reports `apiversion` 2.0), in
plain PHP, with no framework.

## Why

ZoneMinder's API runs on CakePHP 2.10, which has been end of life since 2021.
ZoneMinder ships a frozen, vendored copy of it with two forked plugins. The
long-term replacement is [zm-api](https://github.com/SteveGilvarry/zm-api), the
Rust API v3. It isn't backward compatible, though, so every client has to be
ported before the legacy API can go.

zm-php-api covers the time until then. It answers the same URLs with the same
status codes, bodies and errors, so the clients and the web UI keep working
unchanged, and it takes CakePHP out of the install now. Once API v3 replaces
the legacy API, this project has done its job.

## At a glance

| | |
|---|---|
| Code | About 6,000 lines of PHP: a router, 28 controllers and a query builder that binds every value. It replaces CakePHP's ~1,300 files (~296,000 lines) |
| Compatibility | All 525 records of an API contract suite (every action, every role) match stock CakePHP on ZoneMinder master |
| Clients | pyzmNg, zm-py (Home Assistant), zmNinjaNg, zmeventnotificationNg and openHAB's binding pass their own test suites against it, as they do on CakePHP |
| Fixes | 19 endpoints that fail on CakePHP work; 10 kinds of wrong answer are corrected; 16 security hardening changes, each with a unit test |
| Tests | 32 unit test files (661 checks), no database needed; end-to-end runs on single-server and multi-server installs |
| ZoneMinder | master and 1.38, from one codebase |

## Quick start

Download the tarball of a [release](https://github.com/nabbi/zm-php-api/releases)
(or clone this repository), then, on a ZoneMinder source tree, before CMake:

```sh
tools/zm-overlay.sh /path/to/zoneminder     # replaces web/api with this API
cd /path/to/zoneminder && cmake ... && make install
```

On Gentoo, `www-misc/zoneminder` from the
[oubliette overlay](https://github.com/nabbi/oubliette-overlay) builds either
API: `USE=apiv2-php` for this one, `USE=apiv2-cake` (the default) for CakePHP.

Nothing to change in the web server ([docs/webserver.md](docs/webserver.md) has
recommended changes). For packaging and the details, see
[docs/install.md](docs/install.md).

## Documentation

| | |
|---|---|
| [docs/install.md](docs/install.md) | Installing, packaging, web server, repository layout |
| [docs/webserver.md](docs/webserver.md) | nginx and Apache: routing every API path, next to API v3, serving the API reference |
| [docs/architecture.md](docs/architecture.md) | How a request is handled, conventions, and what changed from CakePHP |
| [docs/compatibility.md](docs/compatibility.md) | What each client sends, ZoneMinder 1.38, and the deliberate differences from CakePHP |
| [docs/testing.md](docs/testing.md) | How it is tested, and the latest results |
| [docs/openapi/](docs/openapi/README.md) | The API reference: an OpenAPI 3.1 document of every endpoint, and a page that renders it (`make docs`) |
| [SECURITY.md](SECURITY.md) | Reporting a vulnerability, and what the security changes cover |
| [CHANGELOG.md](CHANGELOG.md) | Releases |

## Status

Release candidate (2.0.0-rc.1; numbered after API v2). Tested on ZoneMinder master and release-1.38, by CI on every push ([ci](https://github.com/nabbi/zm-php-api/actions/workflows/ci.yml)); the 2.0.0 final follows the ZoneMinder release that carries the API fixes the unit tests reproduce.

## License

GPL-2.0-or-later, as ZoneMinder ([LICENSE](LICENSE)).
