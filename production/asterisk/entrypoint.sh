#!/bin/sh
set -eu

ami_username="${PBX_AMI_USERNAME:?PBX_AMI_USERNAME ausente}"
ami_secret="${PBX_AMI_SECRET:?PBX_AMI_SECRET ausente}"
public_ip="${PBX_PUBLIC_IP:?PBX_PUBLIC_IP ausente}"
local_net="${PBX_LOCAL_NET:-10.0.0.0/8}"

case "$ami_username" in
  *[!A-Za-z0-9._-]*) echo "PBX_AMI_USERNAME contém caracteres inválidos." >&2; exit 1 ;;
esac
case "$ami_secret" in
  *[!A-Za-z0-9._~!@%+=:-]*) echo "PBX_AMI_SECRET contém caracteres inválidos." >&2; exit 1 ;;
esac
case "$public_ip" in
  *[!0-9a-fA-F.:]*) echo "PBX_PUBLIC_IP inválido." >&2; exit 1 ;;
esac
case "$local_net" in
  *[!0-9a-fA-F.:/]*) echo "PBX_LOCAL_NET inválido." >&2; exit 1 ;;
esac

mkdir -p /etc/asterisk/generated /var/spool/asterisk/monitor
umask 077
printf '[%s]\nsecret = %s\nread = system,call,log,verbose,command,agent,user\nwrite = system,call,command,originate\n' \
  "$ami_username" "$ami_secret" > /etc/asterisk/generated/manager_credentials.conf

sed -e "s|__PBX_PUBLIC_IP__|$public_ip|g" -e "s|__PBX_LOCAL_NET__|$local_net|g" \
  /etc/asterisk/pjsip.conf.template > /etc/asterisk/pjsip.conf

exec "$@"
