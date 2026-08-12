FROM andrius/asterisk:20@sha256:c0a992ca8e0ee3f93b35250bb9c788fe19120be427250027d86c602597adafaa

COPY docker/asterisk/entrypoint.sh /usr/local/bin/thconect-asterisk-entrypoint
RUN chmod 0755 /usr/local/bin/thconect-asterisk-entrypoint

ENTRYPOINT ["/usr/local/bin/thconect-asterisk-entrypoint"]
CMD ["asterisk", "-f", "-vvv"]
