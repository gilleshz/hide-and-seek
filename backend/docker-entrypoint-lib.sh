#!/usr/bin/bash
# Fail-closed secret resolution + prod config guards for the Jet Lag entrypoint.

declare -A PROVIDED=()

resolve_secrets() {
    PROVIDED=()
    for var in APP_SECRET APP_API_KEY MERCURE_PUBLISHER_JWT_KEY \
               MERCURE_SUBSCRIBER_JWT_KEY MERCURE_JWT_SECRET DATABASE_URL PUBLIC_URL; do
        if [ -n "${!var+docker_set}" ] && [ -n "${!var}" ]; then
            PROVIDED[$var]="${!var}"
        fi
    done

    _tmpenv="$(mktemp)"
    export -p > "$_tmpenv"
    set -a
    . "${ENTRYPOINT_ENV_FILE:-/app/.env}"
    set +a

    # Docker env wins: restore it (-g: declare in a function scopes locally) before generating secrets.
    sed -i 's/^declare -x /declare -gx /' "$_tmpenv"
    . "$_tmpenv"
    rm -f "$_tmpenv"

    SECRETS_DIR="${SECRETS_DIR:-/app/config/secrets}"
    mkdir -p "$SECRETS_DIR"

    is_placeholder() { case "$1" in *change-me*|*dev-shared-key*) return 0;; *) return 1;; esac; }
    refuse() {
        echo "jetlag-entrypoint: ERROR: $1 is set to a known placeholder value ('$2')." >&2
        echo "  Refusing to boot with a publicly-known secret. Set $1 to a strong random value" >&2
        echo "  in your compose stack environment, or remove it to let the entrypoint generate one." >&2
        exit 1
    }

    for var in APP_SECRET APP_API_KEY MERCURE_PUBLISHER_JWT_KEY \
               MERCURE_SUBSCRIBER_JWT_KEY MERCURE_JWT_SECRET; do
        if [ -n "${PROVIDED[$var]+x}" ] && is_placeholder "${PROVIDED[$var]}"; then
            refuse "$var" "${PROVIDED[$var]}"
        fi
    done

    if [ -z "${PROVIDED[APP_SECRET]+x}" ]; then
        [ -f "$SECRETS_DIR/app_secret" ] || openssl rand -hex 32 > "$SECRETS_DIR/app_secret"
        export APP_SECRET="$(cat "$SECRETS_DIR/app_secret")"
    fi

    if [ -z "${PROVIDED[APP_API_KEY]+x}" ]; then
        [ -f "$SECRETS_DIR/app_api_key" ] || openssl rand -hex 32 > "$SECRETS_DIR/app_api_key"
        export APP_API_KEY="$(cat "$SECRETS_DIR/app_api_key")"
    fi

    resolve_mercure_key() {
        if [ -n "${PROVIDED[$1]+x}" ]; then
            export "$1"="${PROVIDED[$1]}"
        elif [ -f "$SECRETS_DIR/$2" ]; then
            export "$1"="$(cat "$SECRETS_DIR/$2")"
        else
            openssl rand -hex 32 > "$SECRETS_DIR/$2"
            export "$1"="$(cat "$SECRETS_DIR/$2")"
        fi
    }

    if [ -z "${PROVIDED[MERCURE_PUBLISHER_JWT_KEY]+x}" ] \
       && [ -z "${PROVIDED[MERCURE_SUBSCRIBER_JWT_KEY]+x}" ] \
       && [ ! -f "$SECRETS_DIR/mercure_publisher_jwt" ] \
       && [ ! -f "$SECRETS_DIR/mercure_subscriber_jwt" ]; then
        legacy=""
        [ -n "${PROVIDED[MERCURE_JWT_SECRET]+x}" ] && legacy="${PROVIDED[MERCURE_JWT_SECRET]}"
        [ -f "$SECRETS_DIR/mercure_jwt" ] && legacy="$(cat "$SECRETS_DIR/mercure_jwt")"
        if [ -n "$legacy" ] && ! is_placeholder "$legacy"; then
            printf '%s' "$legacy" > "$SECRETS_DIR/mercure_publisher_jwt"
            printf '%s' "$legacy" > "$SECRETS_DIR/mercure_subscriber_jwt"
        fi
    fi
    resolve_mercure_key MERCURE_PUBLISHER_JWT_KEY  mercure_publisher_jwt
    resolve_mercure_key MERCURE_SUBSCRIBER_JWT_KEY mercure_subscriber_jwt
}

guard_prod_config() {
    if [ "${APP_ENV:-prod}" = "prod" ]; then
        case "$PUBLIC_URL" in
            https://*) ;;
            *)
                echo "jetlag-entrypoint: ERROR: PUBLIC_URL must start with https:// in prod (got '$PUBLIC_URL')." >&2
                echo "  Set PUBLIC_URL to your public https:// URL (TLS terminates at your reverse proxy)." >&2
                exit 1
                ;;
        esac
        if [ -z "${PROVIDED[DATABASE_URL]+x}" ]; then
            echo "jetlag-entrypoint: ERROR: DATABASE_URL is not provided in prod. The baked default uses" >&2
            echo "  the known jetlag:jetlag credentials. Set DATABASE_URL (deploy compose derives it" >&2
            echo "  from POSTGRES_*) or refuse to boot." >&2
            exit 1
        fi
    fi
}
