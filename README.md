# Jet Lag: Hide + Seek companion

Self-hosted companion for playing Hide + Seek with friends: one phone per
player, a server you run, live positions on an OpenStreetMap map. Fan-made
and unofficial, see [Legal](#legal).

## Why this app

Several companion apps for Hide + Seek already exist, and some are very good.
Most of them are web apps, and not all of them are open source. This one takes a
different route on three points, and each one matters in a game where everyone
spends hours walking around with the phone in a pocket.

### Why a native app and not a web app

The game is played with the screen off: you walk to a hiding zone, then you stay
there while the seekers hunt. A web page is paused as soon as the phone is locked
or another app comes to the front, and the position updates stop with it. The
Android app runs a small background service, the one with the ongoing
notification, that keeps GPS and the live connection alive with the screen off.

It is also careful with the battery: it reads the phone's own GPS without Google
Play Services, reports only as often as the round needs, and stops tracking
completely when the round ends. A long game should not drain the phone before the
round is over.

### Why does a live game need a server at all?

The phones are not on the same network. They are spread across a city, each on its
own mobile connection, so they have no direct line to each other. Something in the
middle has to collect each position and hand it to the right people.

Think of the server as a referee and a noticeboard:

- it receives every position and decides who may see it. A seeker never receives
  the hider's real position, the server holds it back;
- it answers the game's questions. When a seeker asks "compared to me, are you
  closer to or further from the nearest museum?", the server does the distance
  work on OpenStreetMap data and draws the shrinking search area for everyone;
- it remembers where the game stands. A phone that loses signal, sleeps or dies
  comes back to a game that did not move, instead of taking the whole game down
  with it.

That is also why one phone cannot simply host the game: the host's phone would
have to stay awake and connected for the whole round, and a modified app on that
phone could read everything.

### Whose server is it?

Every app of this kind needs a server. The question is who runs it and who can see
the data. Hosted services usually keep everything on the company's machines,
sometimes on Firebase or a similar platform: everyone playing sends their live
position, their messages and their photos there, to someone they have never met,
under rules they cannot read and cannot check.

Here, the server is one machine, run by the person who organises the game: a small
VPS, a NAS, a home server. It is the same software as in this repository, so you
can read what it stores, check what it does with it, and delete it when you want.
The only people who can see your position during a game are the people you are
playing with.

The trade-off is the one self-hosting always has: the person running the server
has to be someone you trust. Only connect to a host you know.

## What it does

- Create a game: name, player count, area, and transit lines from OpenStreetMap
  or a GTFS feed
- GTFS network catalog: pick a public feed for the area, with live departures
  when the feed provides them
- Join by game code or by scanning the host's QR code
- Live map: player positions update in real time over a server-sent events stream
- Profile photos on the map pins, picked from the camera or the gallery
- Editable display names, shared across every game on your server
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
connection open for real-time updates. The server stores player names, games,
positions, messages and photos in PostgreSQL, and caches map tiles on disk.
At game setup it fetches the area's map features from Overpass, and at game
creation it builds the transit overlay from OpenStreetMap data and the selected
GTFS feeds.

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
   the API key from your host, and a display name. The identity is bound to
   this phone; the display name is how other players see you and can be
   changed in Settings. If you change phones, connect as a new identity, then
   leave the old seat or ask the host to remove it.
3. Create or join a game. The host picks the area and the transit lines, from
   OpenStreetMap or a GTFS feed; the others join with the game code or by
   scanning the host's QR code.
4. Play: hiders hide, seekers find them. The map shows live positions, zones
   and the street network, and the chat stays open the whole game.

Your location goes to the server you connect to and nowhere else. Only
connect to a host you trust.

## Development

- Backend: PHP 8.4, `composer install`, `phpunit`. phpstan and phpcs run at
  level max, keep them green. The dev stack (`compose.yaml`) builds from
  source and mounts the code for quick iteration.
- Android: JDK 17+, `./gradlew assembleDebug`. CI runs the unit tests and
  detekt on every tag.

## Credits

Built with open source software and open data. The transit overlay uses
[LOOM](https://github.com/ad-freiburg/loom) (GPL-3.0) for line bundling and
vector tile rendering, its companion tools
[osm2gtfs](https://github.com/gilleshz/osm2gtfs) and
[gtfsmerge](https://github.com/gilleshz/gtfsmerge) for feed preparation, and the
[Mobility Database](https://mobilitydatabase.org/) catalog. Map data comes from
[OpenStreetMap](https://www.openstreetmap.org/copyright) contributors (ODbL)
through the [Overpass API](https://wiki.openstreetmap.org/wiki/Overpass_API)
and [Nominatim](https://nominatim.org/), and is drawn with
[MapLibre](https://maplibre.org/).

The server runs on [Symfony](https://symfony.com/),
[API Platform](https://api-platform.com/), [FrankenPHP](https://frankenphp.dev/),
[Mercure](https://mercure.rocks/), [PostgreSQL](https://www.postgresql.org/) and
[PostGIS](https://postgis.net/). The app is built with
[Kotlin](https://kotlinlang.org/) and
[Jetpack Compose](https://developer.android.com/jetpack/compose). Each project
keeps its own license; this project's license is in [LICENSE](LICENSE).

## Legal

This is an unofficial, fan-made companion tool. It is not affiliated with,
endorsed by, or associated with Jet Lag: The Game, Nebula, or Wendover
Productions LLC. All rights to the game's design, branding and content belong
to their respective owners.

The app is a self-hostable aid for playing the physical game; it does not
reproduce or replace it.

Released under the MIT License, see [LICENSE](LICENSE).
