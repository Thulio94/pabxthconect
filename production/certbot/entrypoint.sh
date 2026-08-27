#!/bin/sh
set -eu

: "${CLOUDFLARE_DNS_API_TOKEN:?CLOUDFLARE_DNS_API_TOKEN ausente}"
: "${TURN_CERT_DOMAIN:?TURN_CERT_DOMAIN ausente}"
: "${TURN_CERT_EMAIL:?TURN_CERT_EMAIL ausente}"

case "${TURN_CERT_RENEW_INTERVAL_SECONDS:-43200}" in
  ''|*[!0-9]*) echo "TURN_CERT_RENEW_INTERVAL_SECONDS inválido" >&2; exit 1 ;;
esac

credentials_file="/run/thconect-cloudflare.ini"
certificate_dir="/etc/letsencrypt/live/${TURN_CERT_DOMAIN}"
archive_dir="/etc/letsencrypt/archive/${TURN_CERT_DOMAIN}"

cleanup() {
  rm -f "$credentials_file"
}

trap cleanup EXIT INT TERM

issue_or_renew() {
  umask 077
  printf 'dns_cloudflare_api_token = %s\n' "$CLOUDFLARE_DNS_API_TOKEN" > "$credentials_file"

  certbot certonly \
    --non-interactive \
    --agree-tos \
    --email "$TURN_CERT_EMAIL" \
    --cert-name "$TURN_CERT_DOMAIN" \
    --keep-until-expiring \
    --dns-cloudflare \
    --dns-cloudflare-credentials "$credentials_file" \
    --dns-cloudflare-propagation-seconds 30 \
    -d "$TURN_CERT_DOMAIN"

  # Coturn executes as nobody:nogroup. The private key stays confined to this
  # named volume and is readable only by that service account.
  chown root:65534 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive
  chmod 0750 /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive
  chown root:65534 "$certificate_dir" "$archive_dir"
  chmod 0750 "$certificate_dir" "$archive_dir"
  find "$archive_dir" -type f -exec chown 65534:65534 {} \; -exec chmod 0640 {} \;
  test -s "$certificate_dir/fullchain.pem"
  test -s "$certificate_dir/privkey.pem"

  # Coturn reloads TLS material on SIGUSR2. The shared PID namespace avoids a
  # privileged Docker socket and preserves established calls.
  kill -USR2 1 2>/dev/null || true
}

while :; do
  if ! issue_or_renew; then
    echo "Emissão/renovação do certificado TURN falhou; nova tentativa será feita." >&2
  fi

  sleep "${TURN_CERT_RENEW_INTERVAL_SECONDS:-43200}" &
  wait $!
done
