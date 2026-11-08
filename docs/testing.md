# Testing

Two layers, from fast to complete.

## 1. Unit tests (this repository, and CI)

```sh
ZM_SRC=/path/to/zoneminder tests/run.sh
```

DB-free: `tests/support/api.php` provides `FakeDb` (answers SQL from rows a test
declares and records every statement), `api()`/`apiJson()` (one request through
`App::handle()` in-process, with a Bearer token, cookies or extra `$_SERVER`
headers) and a stand-in `config.php`. `run.sh` lays out a scratch copy as
ZoneMinder's tree (`web/` from `ZM_SRC`, this `api/` as `web/api`, `tests/` as
`tests/php`), so the API's relative includes resolve. Needs only the PHP CLI.

CI (`.github/workflows/ci.yml`) runs `make lint` and these tests on every push
and pull request, on PHP 8.1, 8.3 and 8.4 against a fresh clone of ZoneMinder
master and of release-1.38, then applies `tools/zm-overlay.sh` to the tree and
builds the release tarball. A `v*` tag runs the tests again and publishes the
release (`release.yml`).

`test_api_openapi.php` holds the API reference (`docs/openapi/openapi.json`)
to the code: every action documented, every documented URL routed to its action.

Every security fix has a test that fails without it (checked by running each
new test against the previous commit). Under the CLI, ZoneMinder's
`is_session_started()` logs notices about `session_start()`; they are harmless.

## 2. End to end

A ZoneMinder built with this API overlaid (`tools/zm-overlay.sh`) runs next to
a stock build of the same commit with CakePHP, in the maintainer's test
harness (not public yet). The web server routes `/zm/api` the stock way,
through `api/app/webroot/index.php`, so the CakePHP-era forwarder is what is
exercised. The checks:

- an API contract suite that records every action's answers (status, content
  type, body structure, error messages) for each role, and compares the two
  APIs request by request;
- the API reference checked against the contract suite's answers: each
  recorded status is declared for its operation, and each body fits the
  documented schema;
- the web UI and daemon suites (smoke, PTZ, events, logging, signals);
- the clients' own test suites against the live stack: pyzmNg, zm-py (Home
  Assistant), zmNinjaNg, zmeventnotificationNg with its detection hook, and
  openHAB's ZoneMinder binding, including their write and run-state tiers;
- a two-node multi-server install, with this API and CakePHP side by side.

### Results

| | ZoneMinder master `233a15828` (2026-10-13) | release-1.38 `4ec92a732` (2026-10-14) |
|---|---|---|
| Unit tests | 25 files pass | 26 files pass |
| Contract suite | 525 passed, as stock CakePHP | as stock 1.38, except where it answers as master (`compatibility.md`, "ZoneMinder 1.38") |
| pyzmNg | 61 passed | 61 passed, 4 skipped |
| zm-py (Home Assistant) | 20 passed | 21 passed (write and run-state tiers) |
| zmNinjaNg | 186 passed; the rest fail intermittently on stock CakePHP too | 185 of 194 passed; the 2 failures are in the app, not the API |
| zmeventnotificationNg | 16 PASS, as on CakePHP | 16 PASS, as on CakePHP |
| openHAB binding | 21 PASS, as on CakePHP | 23 PASS (write and run-state tiers) |
| Multi-server | smoke, contract and clients as on CakePHP; editing node 2's monitor through node 1 restarts node 2's daemons only | — |

Security: every hardening change has a unit test (`SECURITY.md`).

Unit tests re-run 2026-11-08 on master `855683051` and release-1.38 `0d3686b03`:
32 files, 661 checks, 0 failed on both (`TMPDIR` must allow executables:
`test_api_alarm_cause.php` runs a stand-in `zmu`; a `noexec` `/tmp` fails it).

### Bugs this testing found

- zmNinjaNg encodes whole path segments, so named filters arrived as
  `MonitorId%3A1`; the API ignored them and answered every event. Fixed
  (decode the path once, as CakePHP), and the contract suite now compares
  encoded and literal filters.
- A live parsing audit against CakePHP (`docs/compatibility.md`) then moved
  decoding to the whole path and made query-string filters on events strict
  again.
- `SHOW TABLES LIKE ?` fails under 1.38's server-side prepares (MariaDB takes no
  placeholder there); `Compat::hasTable()` queries `information_schema`
  instead. The unit tests' fake database can't catch that kind of thing, which
  is why the end-to-end run matters.
