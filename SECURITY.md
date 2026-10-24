# Security

## Reporting

Report vulnerabilities privately, through this repository's private
vulnerability reporting, not in a public issue. Problems that also affect
ZoneMinder's own API go to ZoneMinder through its security policy.

## What the security changes cover

Where this API's answers differ from CakePHP's for security reasons, the
difference is deliberate, kept as small as clients allow, and pinned by a unit
test in `tests/`. Eighteen changes in all:

| Area | Changes |
|---|---|
| Authentication, sessions and tokens | 6 |
| Permission checks | 6 |
| Information exposure (secrets, error bodies) | 2 |
| Cross-origin requests | 2 |
| Input validation | 2 |

## Not covered

- **Authentication off.** Then nothing in a request is a credential, and the API
  can only go by what browsers say about a request's origin. Cross-origin POST,
  PUT and DELETE are refused (browsers send `Origin` on them over HTTP too), but
  a state-changing GET from another site's `<img>` over plain HTTP carries no
  `Origin` or `Sec-Fetch-Site` and is accepted, and DNS rebinding (a page whose
  host name an attacker points at the install) passes the same-origin check,
  which trusts the `Host` header. Turn authentication
  on for anything reachable from a browser.

- ZoneMinder's web UI, `zms` and the daemons: this repository replaces only the API.
- The API trusts ZoneMinder's `web/includes` for authentication (`validateUser`,
  `validateToken`, `getAuthUser`, sessions). Fixes there come from ZoneMinder.
- Without the APCu PHP extension (a ZoneMinder dependency) there is no login throttle.
- **Token cache window.** With APCu, an access token's user and permissions are
  kept for 5 s after a full check (`api/src/TokenCache.php`). A token revoked
  (`TokenMinExpiry`), an account or its API access disabled, or permissions
  changed in the web UI or the database apply to a token already in use within
  those 5 s, not on the next call as with CakePHP; a user saved or deleted
  through this API applies at once on the server that handled it. An expired
  token is never accepted. The cache holds the Users row (with its password
  hash) in APCu, which only the PHP pool serving ZoneMinder reads.
