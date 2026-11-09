# Labeler interface (and optionally a printer agent) in a container.
#   docker build -t labeler .
#   docker run --rm -p 8080:8080 -e LABELER_DEMO=1 labeler        # try it with a simulated printer
# See docker-compose.yml and docs/DOCKER.md.

# ptouch-print from the upstream sources, for a printer plugged into the Docker host
# (--build-arg PTOUCH_PATCHES=1: with tools/ptouch-patches, see docs/PTOUCH-PRINT.md)
FROM debian:bookworm-slim AS ptouch
ARG PTOUCH_REF=v1.9
ARG PTOUCH_PATCHES=0
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      ca-certificates git cmake gcc make pkg-config gettext libgd-dev libusb-1.0-0-dev \
 && rm -rf /var/lib/apt/lists/*
COPY tools/ptouch-patches /patches
# upstream is a small self-hosted server: try the clone up to three times
RUN for i in 1 2 3; do git clone https://git.familie-radermacher.ch/linux/ptouch-print.git /src && break; rm -rf /src; sleep 10; done \
 && cd /src && git -c advice.detachedHead=false checkout "$PTOUCH_REF" \
 && if [ "$PTOUCH_PATCHES" = 1 ]; then git -c user.name=build -c user.email=build@localhost am -q /patches/*.patch; fi \
 && cmake -S . -B build && cmake --build build -j"$(nproc)" \
 && install -m 755 build/ptouch-print /usr/local/bin/ptouch-print  && mkdir -p /doc && cp COPYING /doc/  && git archive --format=tar.gz --prefix="ptouch-print-$PTOUCH_REF/" -o "/doc/ptouch-print-$PTOUCH_REF-source.tar.gz" HEAD

FROM debian:bookworm-slim
ARG VERSION=dev
LABEL org.opencontainers.image.title="Labeler" \
      org.opencontainers.image.description="Web interface for Brother P-Touch label printers" \
      org.opencontainers.image.source="https://github.com/PhilippMundhenk/labeler" \
      org.opencontainers.image.licenses="MIT"
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      php-cli libgd3 libusb-1.0-0 ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && groupadd --gid 1000 labeler && useradd --uid 1000 --gid 1000 --no-create-home --shell /usr/sbin/nologin labeler \
 && mkdir -p /data && chown 1000:1000 /data
COPY --from=ptouch /usr/local/bin/ptouch-print /usr/local/bin/ptouch-print
# ptouch-print is GPL-3.0: its license and the exact sources of this binary come with the image
COPY --from=ptouch /doc/ /usr/share/doc/ptouch-print/
COPY public /app/public
COPY src /app/src
COPY tools/ptouch-sim /app/tools/ptouch-sim
COPY config.example.php /app/
COPY docker/entrypoint.sh /usr/local/bin/labeler-entrypoint
RUN sed -i "s/VERSION = '[^']*'/VERSION = '${VERSION}'/" /app/src/Config.php \
 && chmod 755 /usr/local/bin/labeler-entrypoint /app/tools/ptouch-sim/ptouch-print
ENV LABELER_DATA_DIR=/data \
    LABELER_FONTS_DIR=/data/fonts \
    PORT=8080
VOLUME ["/data"]
EXPOSE 8080
USER 1000:1000
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s \
  CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:' . getenv('PORT') . '/api.php?action=health') === false ? 1 : 0);"]
ENTRYPOINT ["labeler-entrypoint"]
