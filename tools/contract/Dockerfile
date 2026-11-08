# The real ptouch-print, built from the upstream sources, plus PHP and Node to run the
# contract test against it. No printer is needed: --force-tape-width with --writepng.
#   docker build -t labeler-contract --build-arg PTOUCH_REF=v1.9 tools/contract
#   docker run --rm -v "$PWD:/work" labeler-contract
FROM node:25-bookworm
ARG PTOUCH_REF=v1.9
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      cmake gcc make pkg-config gettext git libgd-dev libusb-1.0-0-dev php-cli \
 && rm -rf /var/lib/apt/lists/*
# upstream is a small self-hosted server: try the clone up to three times
RUN for i in 1 2 3; do git clone https://git.familie-radermacher.ch/linux/ptouch-print.git /src/ptouch-print && break; rm -rf /src/ptouch-print; sleep 10; done \
 && cd /src/ptouch-print && git checkout "$PTOUCH_REF" \
 && cmake -S . -B build && cmake --build build -j"$(nproc)" \
 && install -m 755 build/ptouch-print /usr/local/bin/ptouch-print \
 && ptouch-print --version
WORKDIR /work
CMD ["sh", "tools/contract/run.sh"]
