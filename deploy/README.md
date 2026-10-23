# Self-hosting guide

This directory runs the prebuilt image, no source checkout needed. It is the
stack the release pipeline publishes to and the one Portainer expects.

## Requirements

- Docker with the compose plugin (Docker Desktop, docker-ce, or Portainer)
- A host with a couple of CPU cores and 2+ GB RAM. The stack caps the app at
  4 GB / 4 CPU by default; adjust in `docker-compose.yaml` if the transit
  overlay is not used, it is the heavy step at game creation.

## Deploy

The compose file ships with `${VAR}` placeholders. Replace them with real
values directly in the file, or keep them and add a `.env` file next to the
compose file, compose fills the placeholders from it. The examples below
edit the YAML directly.

1. `cd deploy`
2. Edit `docker-compose.yaml`. In the `app` service, replace these lines:

   ```yaml
   OVERPASS_MIRRORS: https://overpass-api.de/api/interpreter,https://overpass.kumi.systems/api/interpreter
   PUBLIC_URL: https://play.example.com
   DATABASE_URL: postgresql://jetlag:CHANGE_ME@db:5432/jetlag?serverVersion=16&charset=utf8
   ```

   In the `db` service, set `POSTGRES_PASSWORD` to the same `CHANGE_ME`.
   The `:?` syntax is there so compose stops with a clear error when a
   value is still missing.

3. Start it:

   ```bash
   docker compose up -d
   docker compose ps
   ```

First boot runs the database migrations and generates the secrets. It can
take a minute: the image has to pull and the health check waits 30 seconds.

## Environment

All values go in the `environment:` sections of `docker-compose.yaml` (or in
a `.env` file with the `${VAR}` placeholders left in place).

| Variable | Required | Default | What it does |
| --- | --- | --- | --- |
| `OVERPASS_MIRRORS` | yes | none | Comma-separated Overpass API mirrors used at game setup to fetch the area's features. Keep 2 or 3 working mirrors; some public mirrors return empty data silently. |
| `POSTGRES_PASSWORD` | yes | none | Database password. Use letters and digits only, special characters would need URL-encoding in the connection string. |
| `PUBLIC_URL` | yes | none | The public `https://` URL of the instance. Prod refuses to boot without it, see TLS below. |
| `APP_API_KEY` | no | generated at first boot | The key the Android app must present. Leave blank to generate and persist it in the secrets volume. |
| `IDENTITY_LOOKUP_KEY` | no | generated at first boot | HMAC key for the device identity lookup. Generated and persisted in the secrets volume. Back it up with that volume; losing or changing it makes every identity unreachable. |
| `OVERPASS_MIRRORS_RANDOMIZE` | no | `true` | Pick mirrors at random instead of in order. |
| `MAX_BOUNDARY_SPAN_DEG` | no | `4.0` | Largest allowed game area, in degrees. |
| `STADIA_API_KEY` | no | blank | Map style key; leave blank to disable the style. |
| `THUNDERFOREST_API_KEY` | no | blank | Map style key; leave blank to disable the style. |
| `MAPTILER_API_KEY` | no | blank | Map style key; leave blank to disable the style. |
| `TRUSTED_PROXIES` | no | none | Comma-separated CIDRs of reverse proxies, so the server sees real client IPs. |
| `POSTGRES_USER`, `POSTGRES_DB` | no | `jetlag` | Database role and name. |
| `HTTP_BIND`, `HTTP_PORT` | no | `0.0.0.0`, `8080` | Host bind for the container's port 80. |

## Connect the Android app

1. Get the API key:

   ```bash
   docker compose exec app cat /app/config/secrets/app_api_key
   ```

2. In the app, connect with:
   - API URL: your `PUBLIC_URL`, for example `https://play.example.com`
   - API key: the value above
   - Display name: how other players see you. It is not a login handle and can
     be changed in Settings. The identity is bound to this device; a new phone
     creates a new identity, so leave the old seat from the old phone or ask
     the host to remove it.

## TLS and reverse proxy

The container serves plain HTTP on port 80. Terminate TLS at a reverse proxy
of your choice and tell the server about it:

- `PUBLIC_URL` must start with `https://` in production, the entrypoint
  refuses to boot otherwise.
- `TRUSTED_PROXIES` should contain the proxy's subnet or IP so the server
  sees real client IPs (logs and rate limits). Leave it blank and every
  request shows up as the proxy's IP.

### Caddy

Easiest: run Caddy on the host, in `/etc/caddy/Caddyfile`:

```
play.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy fetches the certificate automatically once ports 80 and 443 are
reachable.

Or as a sidecar in the same stack. The app container itself runs FrankenPHP
(Caddy): it serves plain HTTP on port 80, so the sidecar is simply a second
Caddy in front of it, which is the pattern the stack expects. Remove the
app's `ports:` mapping and nothing is exposed on the host. Add the service,
the volumes, and pin the network so `TRUSTED_PROXIES` is deterministic:

```yaml
  caddy:
    image: caddy:2
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - app
```

```yaml
volumes:
  caddy_data:
  caddy_config:

networks:
  default:
    ipam:
      config:
        - subnet: 172.20.0.0/16
```

A `Caddyfile` next to `docker-compose.yaml`, with
`TRUSTED_PROXIES: 172.20.0.0/16` in the app environment:

```
play.example.com {
    reverse_proxy app:80
}
```

### Traefik / Pangolin

Point your existing proxy at the published port. The default bind is
`0.0.0.0:8080`; set `HTTP_BIND: 127.0.0.1` when the proxy runs on the same
host.

- Pangolin: create a resource for your domain with origin
  `http://<host>:8080`
- Traefik: a router with rule `` Host(`play.example.com`) `` and a service
  pointing at `http://<host>:8080`

Then set `PUBLIC_URL` to your `https://` domain and `TRUSTED_PROXIES` to the
proxy's subnet or IP.

### Other proxies (nginx and friends)

A plain `proxy_pass http://127.0.0.1:8080;` works, but real-time updates are
server-sent events over HTTP, so nginx needs `proxy_buffering off` and a
long `proxy_read_timeout` or the stream buffers and the app looks dead.
Caddy and Traefik stream by default.

## Updates

Releases are built from version tags: the image is pushed as `:latest` plus
the tagged version. Update with:

```bash
cd deploy
docker compose pull
docker compose up -d
```

For production, pin a version in `docker-compose.yaml`
(`image: ghcr.io/gilleshz/hide-and-seek:1.2.3`) instead of `:latest`.

### PostGIS extension on an existing volume

Swapping the image does not upgrade an existing database volume. Back up
first, then upgrade the extension once per database:

```bash
docker compose exec db psql -U jetlag jetlag -c 'ALTER EXTENSION postgis UPDATE;'
```

If PostgreSQL reports a collation version mismatch, run `REINDEX DATABASE`
and `ALTER DATABASE ... REFRESH COLLATION VERSION` in the same maintenance
window.

### Mercure 1.0

The hub now speaks Mercure 1.0. Deploy the new image first, then install the
updated APK (or restart the app) so it fetches a 1.0 access token. An old APK
cannot receive live events from the new hub; REST calls keep working.

### Identity refactor (v1.5.0, destructive)

Read this before deploying this image over an older stack.

- **Every existing identity is destroyed.** Migration `Version20261023120000`
  truncates `identities` `CASCADE`. That deletes every player seat and its
  dependent rows (round memberships, locations, time traps); games, rounds and
  chat text may remain with orphaned attribution.
- **Back up `db_data` first** (see Backups) and run the upgrade in a maintenance
  window. There is no in-place conversion: pre-refactor rows have no device
  binding, so every player creates a new identity on their next join.
- **The new secret is `IDENTITY_LOOKUP_KEY`.** It is generated and persisted in
  the secrets volume when omitted. Back it up with that volume: losing or
  changing it makes every identity unreachable.
- **API compatibility.** Old APKs keep sending the `name` wire field on join; the
  server accepts it and ignores it for an existing device identity. Profile calls
  that still send `?name=` have the query ignored and act on the `X-Device-Key`
  identity. Real-time behavior is unchanged, but ship the new APK so Settings
  shows and edits the display name.

### Worker mode (optional)

Worker mode is off by default. Set `FRANKENPHP_WORKER_MODE=1` in the stack env
(optionally `FRANKENPHP_WORKER_COUNT`, default 4, `FRANKENPHP_WORKER_THREADS`,
default 2, `FRANKENPHP_LOOP_MAX`, default 500) and redeploy the app service.
It reuses the PHP process, Symfony container and Doctrine connection. Roll
back with `FRANKENPHP_WORKER_MODE=0` and a restart. A database restart can
leave stale connections until the worker recycles; session advisory locks
live with the connection; keep 4 to 8 workers and watch memory. Do not enable
it in dev, where bind mounts need hot reload.

## Backups

- Database (essential):

  ```bash
  docker compose exec db pg_dump -U jetlag jetlag > backup.sql
  docker compose exec -T db psql -U jetlag jetlag < backup.sql
  ```

  Adjust the role and database name if you changed `POSTGRES_USER` or
  `POSTGRES_DB`.

- `secrets` volume: keep a copy. Losing it rotates the app API key and every
  phone has to reconnect with the new key. It also holds `IDENTITY_LOOKUP_KEY`;
  losing or changing that value makes every identity unreachable:

  ```bash
  docker compose exec app tar cf - /app/config/secrets > secrets.tar
  ```

- `uploads` (chat photos) and `tiles` (map tile cache) volumes: back them up
  or accept the loss, the tile cache regenerates on demand.

## Portainer

Use the stack editor: paste `docker-compose.yaml` and set the same values in
the stack's environment section (this fills the `${VAR}` placeholders), or
edit the file inline. Everything else is identical.

## Troubleshooting

- `docker compose ps` shows the app not healthy: read the logs, migrations
  and secret generation happen at boot.

  ```bash
  docker compose logs -f app
  ```

- The health check probes `http://127.0.0.1/healthz` inside the container;
  the same endpoint answers from the host on the published port.
- The app says the API key is wrong: the secrets volume was recreated, or
  the key was typed wrong. Read it again with the exec command above.
- The port is taken: change `HTTP_PORT` and the proxy target.
