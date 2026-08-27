#!/bin/sh
set -eu

: "${TURN_AUTH_SECRET:?TURN_AUTH_SECRET ausente}"
: "${TURN_REALM:?TURN_REALM ausente}"
: "${TURN_EXTERNAL_IP:?TURN_EXTERNAL_IP ausente}"

# Coturn is behind Docker NAT. Explicitly pair the public VPS address with
# the container address so relayed ICE candidates never advertise 172.x.x.x.
turn_internal_ip="${TURN_INTERNAL_IP:-$(hostname -i | awk '{print $1}') }"
turn_internal_ip="$(printf '%s' "$turn_internal_ip" | tr -d '[:space:]')"
: "${turn_internal_ip:?TURN_INTERNAL_IP não pôde ser determinado}"

set -- turnserver -n --log-file=stdout --fingerprint --lt-cred-mech --use-auth-secret \
  --static-auth-secret="$TURN_AUTH_SECRET" --realm="$TURN_REALM" --external-ip="$TURN_EXTERNAL_IP/$turn_internal_ip" --relay-ip="$turn_internal_ip" \
  --listening-port="${TURN_PORT:-3478}" --tls-listening-port="${TURN_TLS_PORT:-5349}" \
  --min-port="${TURN_RELAY_MIN_PORT:-49160}" --max-port="${TURN_RELAY_MAX_PORT:-49359}" \
  --no-cli --no-multicast-peers --no-rfc5780 --stale-nonce=600 --user-quota=4 --total-quota=240 --pidfile=/tmp/turnserver.pid

if [ "${TURN_TLS_ENABLED:-true}" = "true" ]; then
  : "${TURN_TLS_CERT_FILE:?TURN_TLS_CERT_FILE ausente}"
  : "${TURN_TLS_KEY_FILE:?TURN_TLS_KEY_FILE ausente}"
  test -r "$TURN_TLS_CERT_FILE"
  test -r "$TURN_TLS_KEY_FILE"
  set -- "$@" --cert="$TURN_TLS_CERT_FILE" --pkey="$TURN_TLS_KEY_FILE"
else
  set -- "$@" --no-tls --no-dtls
fi

exec "$@"
