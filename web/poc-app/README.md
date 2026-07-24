# JSON:API POC — Drupal 11

Exposes **article nodes** and **users** via core JSON:API, consumed by a zero-build frontend app served from the Drupal docroot (same origin, so no CORS setup needed).

## Setup (one command)

```bash
ddev exec bash scripts/jsonapi-poc-setup.sh
```

This enables `jsonapi` + `basic_auth` (both core), turns off JSON:API read-only mode, grants read permissions to anonymous users, creates an `api_client` role and an `apiuser` account (password `api123`) with article CRUD permissions, and seeds 5 sample articles.

## Try it

- Frontend app: <https://d-11.ddev.site/poc-app/index.html>
- Entry point: `GET https://d-11.ddev.site/jsonapi`
- Articles: `GET /jsonapi/node/article?include=uid&sort=-created&page[limit]=5`
- Single article: `GET /jsonapi/node/article/{uuid}`
- Users: `GET /jsonapi/user/user`

Useful query features (all standard JSON:API):

```
?filter[title][operator]=CONTAINS&filter[title][value]=POC   # filtering
?fields[node--article]=title,created                          # sparse fieldsets
?include=uid,field_tags                                       # embed related entities
?sort=-created&page[limit]=5&page[offset]=5                   # sort + pagination
```

## Writes (Basic Auth)

Reads are anonymous. POST/PATCH/DELETE send `Authorization: Basic` with `apiuser / api123` and `Content-Type: application/vnd.api+json`. Example:

```bash
curl -k -X POST https://d-11.ddev.site/jsonapi/node/article \
  -u apiuser:api123 \
  -H 'Content-Type: application/vnd.api+json' \
  -d '{"data":{"type":"node--article","attributes":{"title":"From curl","body":{"value":"<p>Hello</p>","format":"basic_html"}}}}'
```

## Notes

- **CORS**: not needed here since the app lives in `web/poc-app/`. If you later host the frontend on another origin (e.g. a React dev server), copy `web/sites/default/default.services.yml` to `services.yml` and configure `cors.config` (allowedOrigins, allowedHeaders: `['*']`, allowedMethods).
- **Security**: this is POC-grade. For production, don't expose `/jsonapi/user/user` anonymously (email of the requesting user is visible to itself; names to anyone with `access user profiles`), use OAuth2 (`simple_oauth`) instead of Basic Auth, and consider `jsonapi_extras` to alias/disable resources.
- **Revert**: `ddev drush config:set jsonapi.settings read_only 1 -y` puts JSON:API back in read-only mode.
