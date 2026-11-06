# Self-hosting guide

This directory runs the prebuilt image, no source checkout needed. The same
`docker-compose.yaml` works pasted into a Portainer stack or run with
`docker compose`.

## Requirements

- Docker with the compose plugin, or Portainer
- A domain and a reverse proxy that terminates TLS (Caddy, Traefik, Pangolin,
  nginx). The server refuses to boot with a plain `http://` URL in production.
- 2 CPU cores and 2 GB RAM. The stack caps the app at 4 GB and 4 CPU; the
  transit overlay built at game creation is the heavy step.
- Disk for the database, chat photos and the map tile cache, under `/srv/jetlag`
  by default.

## Configure

All values are literal in `docker-compose.yaml`, there is no separate env file.
Change these:

| Where | What to set |
| --- | --- |
| app | `PUBLIC_URL`: your `https://` domain, for example `https://play.example.com` |
| app | `DATABASE_URL`: same user and password as the `db` service |
| db | `POSTGRES_USER` and `POSTGRES_PASSWORD`: a real password, letters and digits only, so no URL encoding is needed |
| app | `OVERPASS_MIRRORS`: two or three Overpass mirrors that answer for your area, comma separated |
| both | the `/srv/jetlag/...` host paths, or let Docker create them |

Leave the rest alone unless you have a reason:

- `APP_API_KEY` empty: generated on first boot and kept in the `secrets` volume.
- The map style keys empty: the extra styles stay off, the OpenStreetMap style
  always works.
- The SIRI Lite keys empty: the IDFM, CTS and TCL live-data profiles stay hidden.
  Set `PRIM_API_KEY` for IDFM, `CTS_API_TOKEN` for CTS, or `SYTRAL_SIRI_USER` plus
  `SYTRAL_SIRI_PASSWORD` for TCL to enable that network's live departures.
- `FRANKENPHP_WORKER_MODE: 1`: reuses the PHP process between requests. Set it
  to `0` to turn it off.
- `MAX_BOUNDARY_SPAN_DEG`: largest allowed game area, in degrees.

## Deploy in Portainer

1. Stacks, then Add stack, then Web editor. Paste `docker-compose.yaml`.
2. Set the values above in the editor.
3. Deploy the stack.

## Deploy with docker compose

```bash
cd deploy
docker compose up -d
docker compose ps
```

First boot pulls the image, runs the migrations and generates the secrets. It
takes about a minute, the app reports healthy after that.

## TLS and reverse proxy

The container serves plain HTTP on port 80, published on 8080. Terminate TLS in
front of it, then set `PUBLIC_URL` to the same `https://` domain.

Caddy on the host, in `/etc/caddy/Caddyfile`:

```
play.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Traefik or Pangolin: point the route at `http://<host>:8080`.

With nginx, live updates are server-sent events, so set `proxy_buffering off`
and a long `proxy_read_timeout`, or the stream buffers and the app looks dead.
Caddy and Traefik stream by default.

When the proxy is a container on another network, add its subnet to the app
environment so logs and rate limits see the real client IP:

```yaml
      TRUSTED_PROXIES: 172.20.0.0/16
```

## Connect the app

1. Read the API key:

   ```bash
   docker compose exec app cat /app/config/secrets/app_api_key
   ```

2. In the app, connect with the `PUBLIC_URL`, that key and a display name. The
   identity is bound to the phone: a new phone is a new identity, so leave the
   old seat from the old phone or ask the host to remove it.

## Updates

```bash
docker compose pull
docker compose up -d
```

The image is `:latest`. Pin a version (`:v1.9.0`) so an update is deliberate.
Back up the database before upgrading a stack older than v1.5.0: that migration
truncates the identities table.

To upgrade the PostGIS extension after a major database image change:

```bash
docker compose exec db psql -U CHANGE_ME jetlag -c 'ALTER EXTENSION postgis UPDATE;'
```

## Backups

- Database:

  ```bash
  docker compose exec db pg_dump -U CHANGE_ME jetlag > backup.sql
  ```

- `secrets` volume, keep a copy. It holds the API key the phones use, and the
  identity key: losing or changing that one makes every existing identity
  unreachable.

  ```bash
  docker compose exec app tar cf - /app/config/secrets > secrets.tar
  ```

- `/srv/jetlag/uploads` (chat photos): back it up with the database if you care
  about old messages.
- `/srv/jetlag/tiles` and the `catalog` volume: caches, safe to lose.

## Troubleshooting

- Not healthy: `docker compose logs -f app`. Migrations and secret generation
  happen at boot.
- API key rejected: read it again with the command above. A recreated `secrets`
  volume means a new key.
- Port taken: change the published port in `ports:` and point the proxy at it.
- Live map does not update: the proxy is buffering the event stream, see TLS
  above.
