#!/usr/bin/bash
# Host-only sanity test: bash >= 4 + openssl. Run: bash backend/tests/entrypoint-secrets-test.sh

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="$ROOT/docker-entrypoint-lib.sh"

PASS=0
FAIL=0

report() {
    if [ "$2" = pass ]; then
        PASS=$((PASS + 1))
        echo "ok   - $1"
    else
        FAIL=$((FAIL + 1))
        echo "FAIL - $1"
    fi
}

run_case() {
    local name="$1" envfile_content="$2" snippet="$3" expected="$4" expected_msg="${5:-}"
    local tmp envfile snippetfile out rc
    tmp="$(mktemp -d)"
    envfile="$tmp/app.env"
    snippetfile="$tmp/case.sh"
    printf '%s\n' "$envfile_content" > "$envfile"
    printf '%s\n' "$snippet" > "$snippetfile"
    out="$(env -i PATH="$PATH" HOME="$HOME" SECRETS_DIR="$tmp/secrets" \
        ENTRYPOINT_ENV_FILE="$envfile" bash -c "set -e; . '$LIB'; . '$snippetfile'" 2>&1)"
    rc=$?
    if [ "$rc" -eq "$expected" ] && { [ -z "$expected_msg" ] || printf '%s' "$out" | grep -q "$expected_msg"; }; then
        report "$name" pass
    else
        report "$name" fail
        echo "    expected exit $expected, got $rc"
        [ -n "$expected_msg" ] && echo "    expected message match: $expected_msg"
        printf '%s\n' "$out" | sed 's/^/    /'
    fi
    rm -rf "$tmp"
}

BASIC_ENV='APP_ENV=prod
PUBLIC_URL=https://example.com'

run_case "no docker env: generates 4 persisted secrets, publisher != subscriber, idempotent" \
    "$BASIC_ENV" \
    'resolve_secrets
     [ -f "$SECRETS_DIR/app_secret" ]
     [ -f "$SECRETS_DIR/app_api_key" ]
     [ -f "$SECRETS_DIR/mercure_publisher_jwt" ]
     [ -f "$SECRETS_DIR/mercure_subscriber_jwt" ]
     [ "$MERCURE_PUBLISHER_JWT_KEY" != "$MERCURE_SUBSCRIBER_JWT_KEY" ]
     first_api="$(cat "$SECRETS_DIR/app_api_key")"
     first_pub="$(cat "$SECRETS_DIR/mercure_publisher_jwt")"
     first_sub="$(cat "$SECRETS_DIR/mercure_subscriber_jwt")"
     resolve_secrets
     [ "$(cat "$SECRETS_DIR/app_api_key")" = "$first_api" ]
     [ "$(cat "$SECRETS_DIR/mercure_publisher_jwt")" = "$first_pub" ]
     [ "$(cat "$SECRETS_DIR/mercure_subscriber_jwt")" = "$first_sub" ]' \
    0

run_case "docker APP_API_KEY=dev-shared-key refuses boot" \
    "$BASIC_ENV" \
    'export APP_API_KEY=dev-shared-key
     resolve_secrets' \
    1 'dev-shared-key'

run_case "docker APP_API_KEY=change-me-* refuses boot" \
    "$BASIC_ENV" \
    'export APP_API_KEY=change-me-my-key
     resolve_secrets' \
    1 'change-me'

run_case "docker APP_SECRET honored, others generated" \
    "$BASIC_ENV" \
    'export APP_SECRET=real-app-secret-32-chars-aaaaaaaaaaaaaa
     resolve_secrets
     [ "$APP_SECRET" = "real-app-secret-32-chars-aaaaaaaaaaaaaa" ]
     [ ! -f "$SECRETS_DIR/app_secret" ]
     [ -f "$SECRETS_DIR/app_api_key" ]
     [ -f "$SECRETS_DIR/mercure_publisher_jwt" ]' \
    0

run_case "legacy migration: pre-seeded mercure_jwt file seeds both keys" \
    "$BASIC_ENV" \
    'mkdir -p "$SECRETS_DIR"
     printf "%s" "legacy-volume-secret-1234567890abcdef" > "$SECRETS_DIR/mercure_jwt"
     resolve_secrets
     [ "$(cat "$SECRETS_DIR/mercure_publisher_jwt")" = "legacy-volume-secret-1234567890abcdef" ]
     [ "$(cat "$SECRETS_DIR/mercure_subscriber_jwt")" = "legacy-volume-secret-1234567890abcdef" ]
     [ "$MERCURE_PUBLISHER_JWT_KEY" = "legacy-volume-secret-1234567890abcdef" ]' \
    0

run_case "docker MERCURE_JWT_SECRET=legacy seeds both keys" \
    "$BASIC_ENV" \
    'export MERCURE_JWT_SECRET=legacy-docker-secret-9876543210zyxw
     resolve_secrets
     [ "$(cat "$SECRETS_DIR/mercure_publisher_jwt")" = "legacy-docker-secret-9876543210zyxw" ]
     [ "$MERCURE_SUBSCRIBER_JWT_KEY" = "legacy-docker-secret-9876543210zyxw" ]' \
    0

run_case "the deprecated MERCURE_JWT_SECRET alias is no longer exported" \
    "$BASIC_ENV" \
    'resolve_secrets
     [ -z "${MERCURE_JWT_SECRET:-}" ]' \
    0

run_case "prod PUBLIC_URL=http:// refuses boot" \
    'APP_ENV=prod
PUBLIC_URL=http://example.com' \
    'resolve_secrets
     guard_prod_config' \
    1 'PUBLIC_URL must start with https://'

run_case "prod PUBLIC_URL=https:// passes" \
    "$BASIC_ENV" \
    'export DATABASE_URL=postgresql://jetlag:jetlag@db:5432/jetlag
     resolve_secrets
     guard_prod_config' \
    0

run_case "prod without docker DATABASE_URL refuses boot" \
    "$BASIC_ENV" \
    'resolve_secrets
     guard_prod_config' \
    1 'DATABASE_URL is not provided'

run_case "dev passes with plain-http PUBLIC_URL and no DATABASE_URL" \
    'APP_ENV=dev
PUBLIC_URL=http://localhost' \
    'resolve_secrets
     guard_prod_config' \
    0

run_case "empty docker APP_SECRET treated as unset and generated" \
    "$BASIC_ENV" \
    'export APP_SECRET=
     resolve_secrets
     [ -f "$SECRETS_DIR/app_secret" ]
     [ -n "$APP_SECRET" ]' \
    0

run_case "docker APP_ENV=dev wins over baked prod env after restore" \
    'APP_ENV=prod
PUBLIC_URL=http://baked.example
DATABASE_URL=postgresql://baked:baked@db:5432/baked' \
    'export APP_ENV=dev
     export PUBLIC_URL=http://docker:8080
     resolve_secrets
     [ "$APP_ENV" = "dev" ]
     [ "$PUBLIC_URL" = "http://docker:8080" ]
     guard_prod_config' \
    0

run_case "docker empty APP_API_KEY overrides baked value with a generated one" \
    'APP_API_KEY=change-me-baked-key
APP_SECRET=change-me-baked-secret' \
    'export APP_API_KEY=
     resolve_secrets
     [ -f "$SECRETS_DIR/app_api_key" ]
     [ -n "$APP_API_KEY" ]
     [ "$APP_API_KEY" != "change-me-baked-key" ]' \
    0

echo
echo "entrypoint-secrets-test: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] && [ "$PASS" -eq 13 ]
