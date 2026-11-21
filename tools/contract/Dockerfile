# The real ptouch-print, built from the upstream sources, plus PHP and Node to run the
# contract test against it. No printer is needed: --force-tape-width with --writepng, and a
# fake libusb (fake-libusb.c) that records the bytes ptouch-print sends to the printer.
#   docker build -t labeler-contract --build-arg PTOUCH_REF=v1.9 -f tools/contract/Dockerfile tools
#   docker build -t labeler-contract --build-arg PTOUCH_PATCHES=1 -f tools/contract/Dockerfile tools   # with tools/ptouch-patches
#   docker run --rm -v "$PWD:/work" labeler-contract
FROM node:26-bookworm
ARG PTOUCH_REF=v1.9
ARG PTOUCH_PATCHES=0
RUN echo 'Acquire::Retries "5";' > /etc/apt/apt.conf.d/80-retries \
 && apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      cmake gcc make pkg-config gettext git libgd-dev libusb-1.0-0-dev php-cli \
 && rm -rf /var/lib/apt/lists/*
COPY ptouch-patches /patches
COPY ptouch-fetch.sh /usr/local/bin/ptouch-fetch
# ptouch-print's sources: upstream, or a pinned release tag from the GitHub fork when upstream
# cannot be reached (tools/ptouch-fetch.sh)
RUN sh /usr/local/bin/ptouch-fetch "$PTOUCH_REF" /src/ptouch-print \
 && cd /src/ptouch-print \
 && if [ "$PTOUCH_PATCHES" = 1 ]; then git -c user.name=ci -c user.email=ci@localhost am -q /patches/*.patch; fi \
 && cmake -S . -B build && cmake --build build -j"$(nproc)" \
 && install -m 755 build/ptouch-print /usr/local/bin/ptouch-print \
 && ptouch-print --version
COPY contract/fake-libusb.c /src/fake-libusb.c
RUN mkdir -p /usr/local/lib/fake-usb \
 && gcc -shared -fPIC -Wall -Wextra -Werror -Wl,-soname,libusb-1.0.so.0 \
      -o /usr/local/lib/fake-usb/libusb-1.0.so.0 /src/fake-libusb.c
WORKDIR /work
CMD ["sh", "tools/contract/run.sh"]
