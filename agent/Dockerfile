# syntax=docker/dockerfile:1
#
# Pelican Pouch Agent
#
# A dedicated Caddy instance plus a small poller. The poller asks the panel what
# the Caddy configuration should look like and applies it through Caddy's local
# admin API. The admin API is never exposed outside the container.
FROM caddy:2-alpine

ARG AGENT_VERSION=1.0.0
ENV POUCH_AGENT_VERSION=${AGENT_VERSION}

RUN apk add --no-cache curl jq

COPY entrypoint.sh /usr/local/bin/pouch-agent
RUN chmod +x /usr/local/bin/pouch-agent

# Caddy's certificate store. Persist this, otherwise every container restart
# requests new certificates and you will hit ACME rate limits.
VOLUME ["/data"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:2019/config/ >/dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/pouch-agent"]
