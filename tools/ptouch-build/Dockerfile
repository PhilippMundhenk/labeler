# A static ptouch-print: one file without any library dependencies, so a device only has to
# download it instead of installing a compiler and about a hundred -dev packages and building it
# (which takes long on a Raspberry Pi Zero). Built with musl on Alpine, whose armhf port is
# ARMv6 (Pi Zero, Pi 1) and therefore also runs on every newer Pi.
#
# Licenses: ptouch-print is GPL-3.0, libusb LGPL-2.1; the build exports their sources next to the
# binary. NOTICE.md lists every component.
#
# libgd is built with PNG support only: Labeler prints images (--image); ptouch-print's own
# --text needs FreeType and is not available in this build.
#
#   docker buildx build --platform linux/arm/v6 -f tools/ptouch-build/Dockerfile --output type=local,dest=build/ptouch tools
#   docker buildx build --build-arg PTOUCH_PATCHES=1 ...      # with tools/ptouch-patches
#
# The build context is the tools/ folder (for ptouch-patches/).
FROM alpine:3.22 AS build
ARG PTOUCH_REF=v1.9
ARG PTOUCH_PATCHES=0
ARG GD_VERSION=2.3.3
ARG GD_SHA256=dd3f1f0bb016edcc0b2d082e8229c822ad1d02223511997c80461481759b1ed2
ARG USB_VERSION=1.0.29
ARG USB_SHA256=5977fc950f8d1395ccea9bd48c06b3f808fd3c2c961b44b0c2e6e29fc3a70a85
RUN apk add --no-cache build-base cmake git gettext musl-libintl argp-standalone linux-headers \
      zlib-dev zlib-static libpng-dev libpng-static curl bash
WORKDIR /build
RUN curl -fsSL -o gd.tar.gz "https://github.com/libgd/libgd/releases/download/gd-$GD_VERSION/libgd-$GD_VERSION.tar.gz" \
 && echo "$GD_SHA256  gd.tar.gz" | sha256sum -c - \
 && tar -xzf gd.tar.gz && cd "libgd-$GD_VERSION" \
 && cmake -S . -B b -DCMAKE_BUILD_TYPE=Release -DCMAKE_INSTALL_PREFIX=/opt/static \
      -DBUILD_SHARED_LIBS=OFF -DBUILD_STATIC_LIBS=ON -DENABLE_PNG=ON \
      -DENABLE_JPEG=OFF -DENABLE_TIFF=OFF -DENABLE_WEBP=OFF -DENABLE_XPM=OFF -DENABLE_FREETYPE=OFF \
      -DENABLE_FONTCONFIG=OFF -DENABLE_HEIF=OFF -DENABLE_AVIF=OFF -DENABLE_LIQ=OFF -DENABLE_ICONV=OFF \
 && cmake --build b -j"$(nproc)" && cmake --install b >/dev/null
RUN curl -fsSL -o usb.tar.bz2 "https://github.com/libusb/libusb/releases/download/v$USB_VERSION/libusb-$USB_VERSION.tar.bz2" \
 && echo "$USB_SHA256  usb.tar.bz2" | sha256sum -c - \
 && tar -xjf usb.tar.bz2 && cd "libusb-$USB_VERSION" \
 && ./configure --prefix=/opt/static --disable-shared --enable-static --disable-udev >/dev/null \
 && make -j"$(nproc)" >/dev/null && make install >/dev/null
COPY ptouch-patches /patches
# upstream is a small self-hosted server: try the clone up to three times
RUN for i in 1 2 3; do git clone -q https://git.familie-radermacher.ch/linux/ptouch-print.git src && break; rm -rf src; sleep 10; done \
 && cd src && git -c advice.detachedHead=false checkout -q "$PTOUCH_REF" \
 && if [ "$PTOUCH_PATCHES" = 1 ]; then git -c user.name=ci -c user.email=ci@localhost am -q /patches/*.patch; fi \
 && PKG_CONFIG_PATH=/opt/static/lib/pkgconfig cmake -S . -B b -DCMAKE_BUILD_TYPE=Release \
      -DGD_INCLUDE_DIR=/opt/static/include -DGD_LIBRARY=/opt/static/lib/libgd.a \
      -DCMAKE_EXE_LINKER_FLAGS="-static" -DCMAKE_C_STANDARD_LIBRARIES="-lpng16 -lz -lm" \
 && cmake --build b -j"$(nproc)" \
 && strip b/ptouch-print \
 && ./b/ptouch-print --version \
 && file_info=$(readelf -d b/ptouch-print 2>/dev/null | grep -c NEEDED || true) \
 && [ "$file_info" = 0 ] \
 && mkdir /out && cp b/ptouch-print /out/ \
 && suffix=$([ "$PTOUCH_PATCHES" = 1 ] && echo -labeler || true) \
 && git archive --format=tar.gz --prefix="ptouch-print-$PTOUCH_REF$suffix/" -o "/out/ptouch-print-$PTOUCH_REF$suffix-source.tar.gz" HEAD \
 && cp /build/gd.tar.gz "/out/libgd-$GD_VERSION.tar.gz" && cp /build/usb.tar.bz2 "/out/libusb-$USB_VERSION.tar.bz2"

# ptouch-print is GPL-3.0 and libusb LGPL-2.1: the binary is published with the exact sources
# it was built from (see NOTICE.md)
FROM scratch
COPY --from=build /out/ /
