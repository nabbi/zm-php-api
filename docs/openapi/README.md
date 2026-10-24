# API reference

`openapi.json` describes the API in OpenAPI 3.1: every action, the URLs that
reach it, its parameters and request body, the permissions it needs, and the
statuses and bodies it answers with. `index.html` renders it with Redoc.

## Reading it

```sh
make docs        # then open http://127.0.0.1:8090/
```

Any static web server works (`python3 -m http.server -d docs/openapi`).
Browsers don't let the page fetch `openapi.json` from a `file://` URL, so
opening `index.html` directly shows an error. The JSON loads into other
OpenAPI tools as it is: Swagger UI, Postman, Insomnia, client generators.

The page is read-only on purpose: it sends no requests. Trying calls from a
browser needs the page on the ZoneMinder server's own origin (the API answers
cross-origin requests only for configured origins) and a way to log in.
Installing it with ZoneMinder would add an endpoint to every install, so it
isn't installed.

## What's in it

- **Paths.** The REST form where a resource has one (`monitors/{id}.json`),
  otherwise the action form (`monitors/alarm/id:{id}/command:{command}.json`).
  Every action is also reachable as `<controller>/<action>/...`; the
  description at the top says how URLs are read.
- **`x-zm-action`.** The controller action that answers (`Monitors::alarm`).
- **`x-zm-permissions`.** The permissions the code checks before anything
  else (also in each description, after "Needs:"). Per-monitor rules apply on
  top.
- **Responses.** The statuses and body shapes recorded by the contract suite,
  which exercises every action as admin, viewer, a user with no permissions, a
  monitor-restricted user and anonymous. Error descriptions list the messages
  seen. Schemas of rows (`Monitor`, `Event`, ...) are shared components; which
  fields a row has depends on the action and the ZoneMinder version, so none is
  marked required there.

## Keeping it right

- `tests/run.sh test_api_openapi.php` (part of `tests/run.sh`): every action is
  documented, every operation names a real action, and every documented URL
  routes to it.
- The end-to-end contract suite checks the other half: each recorded answer's
  status is declared for its operation and its body fits the schema.
- Edit the summaries and descriptions here freely. The document was generated
  from the code's docblocks and the recorded answers, and a regeneration keeps
  text written here (summaries, descriptions, examples), replacing only the
  structure: paths, parameters, schemas and the "Needs:" lines.

When you add an action: add its operation (copy a similar one), with its
`x-zm-action`, then run the unit test.
