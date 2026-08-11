# Jet Lag: Hide + Seek companion

Self-hosted companion for playing Hide + Seek with friends: one phone per
player, a server you run, live positions on an OpenStreetMap map. Fan-made
and unofficial, see [Legal](#legal).

## What it does

- Create a game: name, player count, area and transit lines
- Join by game code or by scanning the host's QR code
- Live map: player positions update in real time over a server-sent events stream
- Rounds with hiding zones, street network, time traps, questions and photo targets
- Team chat with photos
- Privacy built in: a hider's location never reaches a seeker, enforced server-side
- App UI in English, French and German

## Layout

```
android/   Android app: Kotlin + Jetpack Compose, MapLibre, no push service
backend/   API server: Symfony on FrankenPHP, PostgreSQL + PostGIS, Mercure
deploy/    production stack: prebuilt image, Portainer-ready
compose.yaml, compose.override.yaml   dev stack, builds from source
.github/workflows/   CI: backend image and signed APK on version tags
```

## How it works

The app talks to the server over HTTPS (API and map tiles) and keeps a live
connection open for real-time updates. The server stores accounts, games,
positions, messages and photos in PostgreSQL, and caches map tiles on disk.
At game setup it fetches the area's map features from Overpass, and at game
creation it builds the transit overlay from OpenStreetMap data.

## Self-host

The prebuilt image is the quickest path:

```bash
cd deploy
# edit docker-compose.yaml: set OVERPASS_MIRRORS, POSTGRES_PASSWORD
# and PUBLIC_URL in the environment sections
docker compose up -d
```

Everything else is optional, or generated at first boot, including the API
key the app needs. TLS, a reverse proxy and the full guide are in
[deploy/README.md](deploy/README.md).

## Use the app

1. Download the APK from the Releases page (Android 11 or newer).
2. Connect to the server: API URL (for example `https://play.example.com`),
   the API key from your host, your name and a password. The first connect
   creates your account; use the same name and password to sign back in.
3. Create or join a game. The host picks the area and the transit lines; the
   others join with the game code or by scanning the host's QR code.
4. Play: hiders hide, seekers find them. The map shows live positions, zones
   and the street network, and the chat stays open the whole game.

Your location goes to the server you connect to and nowhere else. Only
connect to a host you trust.

## Releases

Tag a version and CI does the rest:

```bash
git tag v1.0.0
git push origin v1.0.0
```

- The backend is tested and its image pushed to `ghcr.io/gilleshz/hide-and-seek`
  with the tags `v1.0.0` and `latest`.
- The Android app is signed and attached to a GitHub Release as
  `hideandseek_v1.0.0.apk`.

First release checklist: set the four keystore secrets
(`KEYSTORE_BASE64`, `KEYSTORE_PASSWORD`, `KEY_ALIAS`, `KEY_PASSWORD`) in the
repository, then after the first build set the ghcr package visibility to
Public so any host can pull the image.

## Development

- Backend: PHP 8.4, `composer install`, `phpunit`. phpstan and phpcs run at
  level max, keep them green. The dev stack (`compose.yaml`) builds from
  source and mounts the code for quick iteration.
- Android: JDK 17+, `./gradlew assembleDebug`. CI runs the unit tests and
  detekt on every tag.

## Legal

This is an unofficial, fan-made companion tool. It is not affiliated with,
endorsed by, or associated with Jet Lag: The Game, Nebula, or Wendover
Productions LLC. All rights to the game's design, branding and content belong
to their respective owners.

The app is a self-hostable aid for playing the physical game; it does not
reproduce or replace it.

Released under the MIT License, see [LICENSE](LICENSE).
