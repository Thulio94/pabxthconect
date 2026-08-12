#!/bin/sh
set -eu

ami_username="${PBX_AMI_USERNAME:-laravel-local}"
ami_secret="${PBX_AMI_SECRET:-change-me-local}"

case "$ami_username" in
  *[!A-Za-z0-9._-]*) echo "PBX_AMI_USERNAME contém caracteres inválidos." >&2; exit 1 ;;
esac
case "$ami_secret" in
  *[!A-Za-z0-9._~!@%+=:-]*) echo "PBX_AMI_SECRET contém caracteres inválidos." >&2; exit 1 ;;
esac

mkdir -p /etc/asterisk/generated
umask 077
printf '[%s]\nsecret = %s\nread = system,call,log,verbose,command,agent,user\nwrite = system,call,command,originate\n' \
  "$ami_username" "$ami_secret" > /etc/asterisk/generated/manager_credentials.conf

exec "$@"
