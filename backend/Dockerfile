# Stage 1: build LOOM (transit-map bundling + MVT tile rendering)
FROM ubuntu:22.04 AS loom-builder

RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
    ca-certificates git make cmake g++ \
    libglpk-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# Pin a known-good commit (master, 2025).
ARG LOOM_COMMIT=9d0a87a096abd8fd49d233dbc7df312c0438ad11
# topo samples every SEGL metres but only merges within dCut; see the patch header.
COPY loom-sample-dist.patch /tmp/
RUN git clone --recurse-submodules https://github.com/ad-freiburg/loom.git /loom \
    && cd /loom && git checkout ${LOOM_COMMIT} \
    && git submodule update --init --recursive \
    && git apply /tmp/loom-sample-dist.patch \
    && mkdir build && cd build \
    && cmake .. -DCMAKE_BUILD_TYPE=Release \
    && make -j$(nproc)

# Stage 2: Symfony + API Platform on FrankenPHP (bundles the Caddy web server + Mercure hub).
FROM dunglas/frankenphp:1-php8.4

# PHP extensions for PostgreSQL/PostGIS + Symfony
# pcntl lets messenger:consume handle SIGTERM, so the scheduler worker stops gracefully.
# gd re-encodes uploaded images (kills polyglot payloads, strips EXIF/GPS); exif reads the
# orientation GD must apply so portrait photos stay upright. libwebp-dev must be present
# before gd builds or the WebP codec is silently left out.
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
    libwebp-dev libglpk40 libstdc++6 \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql intl opcache zip pcntl gd exif @composer

# Copy LOOM binaries from the builder stage
COPY --from=loom-builder /loom/build/topo /usr/local/bin/topo
COPY --from=loom-builder /loom/build/loom /usr/local/bin/loom
COPY --from=loom-builder /loom/build/transitmap /usr/local/bin/transitmap
COPY --from=loom-builder /loom/build/gtfs2graph /usr/local/bin/gtfs2graph

# gtfs2graph links libzip.so.4 (Ubuntu builder); Debian trixie ships libzip.so.5, so carry the
# matching runtime library over from the builder stage.
COPY --from=loom-builder /usr/lib/x86_64-linux-gnu/libzip.so.4* /usr/lib/x86_64-linux-gnu/
RUN ldconfig

# osm2gtfs turns an OSM route-relation selection into GTFS with real-geometry shapes, which
# gtfs2graph then feeds into the LOOM pipeline.
# Bump OSM2GTFS_BUMP to invalidate the cache when the upstream repo has fixes.
ARG OSM2GTFS_BUMP=5
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends python3 python3-venv git \
    && python3 -m venv /opt/osm2gtfs \
    && /opt/osm2gtfs/bin/pip install --no-cache-dir \
       "git+https://github.com/gilleshz/osm2gtfs.git" \
       mapbox_vector_tile \
    && echo "osm2gtfs bump: ${OSM2GTFS_BUMP}" \
    && apt-get purge -y git && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# gtfsmerge merges multiple GTFS feeds (OSM-derived + user-provided) into a single feed with
# coordinate-based stop deduplication, feeding the combined result into gtfs2graph.
# Bump GTFSMERGE_BUMP to invalidate the cache when the upstream repo has fixes.
ARG GTFSMERGE_BUMP=7
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends git \
    && /opt/osm2gtfs/bin/pip install --no-cache-dir \
       "git+https://github.com/gilleshz/gtfsmerge.git" \
    && ln -s /opt/osm2gtfs/bin/gtfsmerge /usr/local/bin/gtfsmerge \
    && echo "gtfsmerge bump: ${GTFSMERGE_BUMP}" \
    && apt-get purge -y git && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

# Geo-feature ingest decodes multi-MB Overpass payloads (JSON -> PHP arrays cost several times
# the byte size); the 128M default OOMs on real cities. OverpassService caps the download to
# keep this bounded.
# upload_max_filesize aligns with the GTFS ZIP upload path (feeds can be 50 MB+) and
# chat images (8 MB limit enforced in ChatImageUploadProcessor).
RUN echo "memory_limit = 1024M" > /usr/local/etc/php/conf.d/zz-app.ini \
    && echo "max_execution_time = 420" >> /usr/local/etc/php/conf.d/zz-app.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/zz-app.ini \
    && echo "post_max_size = 110M" >> /usr/local/etc/php/conf.d/zz-app.ini \
    && echo "date.timezone = UTC" >> /usr/local/etc/php/conf.d/zz-app.ini

COPY Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-scripts --prefer-dist --no-progress --no-autoloader
COPY . .
# Symfony's cache:clear needs .env + all referenced env vars to compile the
# container during build. These values also serve as runtime defaults, set
# them to the production values most deployers will use. Docker env vars
# (from compose) take precedence over .env, so they're all overridable.
RUN echo "APP_ENV=prod" > /app/.env \
    && echo "APP_SECRET=change-me-app-secret-at-least-32-chars" >> /app/.env \
    && echo "APP_API_KEY=change-me-shared-api-key" >> /app/.env \
    && echo 'DATABASE_URL="postgresql://jetlag:jetlag@db:5432/jetlag?serverVersion=16&charset=utf8"' >> /app/.env \
    && echo "DEFAULT_URI=http://localhost" >> /app/.env \
    && echo "SERVER_NAME=:80" >> /app/.env \
    && echo "MERCURE_URL=http://localhost/.well-known/mercure" >> /app/.env \
    && echo "PUBLIC_URL=http://localhost" >> /app/.env \
    && echo "MERCURE_JWT_SECRET=change-me-mercure-secret-at-least-32-bytes" >> /app/.env \
    && echo "MERCURE_PUBLISHER_JWT_KEY=change-me-mercure-secret-at-least-32-bytes" >> /app/.env \
    && echo "MERCURE_SUBSCRIBER_JWT_KEY=change-me-mercure-secret-at-least-32-bytes" >> /app/.env \
    && echo "MERCURE_PUBLISHER_JWT_ALG=HS256" >> /app/.env \
    && echo "MERCURE_SUBSCRIBER_JWT_ALG=HS256" >> /app/.env \
    && echo "NOMINATIM_BASE_URL=https://nominatim.openstreetmap.org" >> /app/.env \
    && echo 'NOMINATIM_USER_AGENT="JetLag-HideSeek/1.0 (self-hosted)"' >> /app/.env \
    && echo "OVERPASS_MIRRORS=https://overpass.osm.ch/api/interpreter,https://overpass-api.de/api/interpreter,https://z.overpass-api.de/api/interpreter,https://overpass.maprva.org/api/interpreter" >> /app/.env \
    && echo "OVERPASS_MIRRORS_RANDOMIZE=true" >> /app/.env \
    && echo "MAX_BOUNDARY_SPAN_DEG=4.0" >> /app/.env \
    && echo "TRANSIT_MAX_RESPONSE_BYTES=33554432" >> /app/.env \
    && echo "STADIA_API_KEY=" >> /app/.env \
    && echo "THUNDERFOREST_API_KEY=" >> /app/.env \
    && echo "CHAT_IMAGE_DIR=/app/var/uploads" >> /app/.env \
    && echo "GTFS_STORAGE_DIR=/app/var/uploads/gtfs" >> /app/.env \
    && echo "TILES_DIR=/app/var/tiles" >> /app/.env \
    && mkdir -p /app/var/uploads/gtfs /app/var/tiles/transit
RUN composer dump-autoload --classmap-authoritative \
    && composer run-script post-install-cmd

# The serving processes drop to www-data at boot (see docker-entrypoint.sh).
# Hand the writable tree to that user now so fresh named volumes (which copy-up
# image permissions on first mount) come up writable, and let the unprivileged
# Caddy keep the :80 binding via cap_net_bind_service.
RUN chown -R www-data:www-data /app/var /data /config \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp

COPY docker-entrypoint.sh /usr/local/bin/jetlag-entrypoint
ENTRYPOINT ["/usr/local/bin/jetlag-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]

EXPOSE 80 443
