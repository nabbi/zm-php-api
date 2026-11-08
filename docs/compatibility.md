# Compatibility with CakePHP's API

The goal is the same answers for every request a client sends. A contract
suite (`testing.md`) checks recorded answers (522 records, identical to stock master),
and a parsing audit compared both APIs live, request shape by request shape.
Security differences are in `../SECURITY.md`; this file lists the rest.

## Request shapes clients send

| Client | Shapes |
|---|---|
| zmNinjaNg | Named filters with each segment `encodeURIComponent`-ed (`events/index/MonitorId%3A1/StartDateTime%20%3E%3D%3A….json?page&limit&sort&direction`), `logs/index/Component%3A…`, `server_stats/index/TimeStamp%20%3E%3D%3A…`, raw `tags/index/Events.Id:1,2`, `monitors/alarm/id:N/command:…`, `configs/viewByName/X`, `zones.json?MonitorId=`, form bodies, token in the query |
| pyzmNg (and zmeventnotificationNg's hooks) | Raw named filters with spaces (`StartTime >=:…`, `Notes REGEXP:detected`), `frames/index/EventId:`, `zones/forMonitor/N`, `PUT configs/<Id>` and `events/N` with `Model[Field]` forms, token or legacy `auth=`/`user=`+`pass=` |
| zm-py (Home Assistant) | `events/consoleEvents/1%20hour/Archived=:0`, `states/change/<quoted>`, alarm/daemonStatus named arguments |

All of them parse as in CakePHP: the path is URL-decoded once and then split
(`%3A` and `%2F` act as `:` and `/`, `+` is a space), named arguments repeat into
lists and nest with `[key]`, the query string filters `events` only when there
are no named arguments.

## ZoneMinder 1.38

The same API runs on 1.38 (`api/src/Compat.php`). It answers as it does on
master, so where 1.38's CakePHP API differs from master's, it follows master:

| Request | 1.38's CakePHP API | This API on 1.38 |
|---|---|---|
| `encoder_templates`, `notifications` | 404 (no such controller) | 404, the same: 1.38 has no such tables |
| `frames/<Id>`, `frames/view/<Id>` (and edit/, delete/) | the frame with that Id | the same: 1.38's Frames has an Id. On master one number finds no frame (master keys frames by event and frame number) |
| `frames/view/<EventId>/<FrameId>` (and edit/, delete/) | 404 `Invalid frame` (it read the first number as the Id) | that frame, as on master |
| `POST frames` for a frame number the event has already | a second row with that number | 400, as on master |
| Logins (`username=`/`password=`) | 1.38's session handling | master's (`zm_authenticate_request()`) |
| Requests authenticated by password or session (`host/getVersion`, `host/login`, ...) | no cache headers | `Cache-Control: no-store, no-cache, must-revalidate`, `Expires`, `Pragma: no-cache`, as master: its login code starts the session, and PHP adds them |
| `POST monitors` with a refused `Device` | `message` is `{"Device": ["..."]}` | `message` is a string, as master |
| `monitors/alarm/.../command:on` with `cause=` | cause dropped, the event says "Forced Web" | the same: 1.38's zmu has no `--cause`, so it isn't passed (`Compat::zmuTakesCause()`). With a zmu that has it, the filtered cause is recorded |
| `tags` add, edit, delete | 500 | they work, as on master |

The contract suite on 1.38 (see `testing.md`) differs from stock 1.38 in exactly
these rows, and each answer equals master's.

## Deliberate differences

Each answers where CakePHP failed; no client depends on the failure.

| Request | CakePHP | This API |
|---|---|---|
| No, uppercase or unknown extension (`monitors/1`, `.JSON`) | 500, an HTML error page | JSON (XML with `Accept: application/xml` or `.xml`) |
| `.xml` | the view template's source | XML |
| `events/INDEX/…` (action in another case) | 500 (no template) | the action |
| `monitors//1.json` | 404 | the view |
| `events.json?sort=Id&direction=desc` without `limit`/`page` | order ignored | ordered |
| `events/index/MonitorId in:3,5` (lowercase operator) | 400 | applied |
| `configs/<Id>.json`, `PUT configs/<Id>.json` (pyzmNg `set_config`) | 404: CakePHP keys Config by Name | the option with that Id (or Name) |
| `_method=put` (lowercase override) | kept as is, routes nowhere | upper-cased |
| `?_=<n>` and credentials on `events.json` without named arguments | 400 / 500 (taken as filters) | ignored; other unknown parameters are a 400, as in CakePHP |
| XML request bodies (`Content-Type: application/xml`) | decoded | not supported (no client sends them) |
| With authentication off, writes from a browser app on another origin (zmNinjaNg's web build behind its dev proxy) | accepted | refused; reads and login work. Serve the app from ZoneMinder's origin, or turn authentication on |
| An area permission (System, Monitors, Groups, ...) the user holds through their role (User_Roles), not their own field | refused, except Events (`$user->canView('Events')`) | granted, as the web UI grants it (`ZM\User::canView()/canEdit()`): own field or role |

The contract suite keeps a record of each CakePHP bug this API fixes; each
matches its fix.
