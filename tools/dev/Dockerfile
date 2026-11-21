# Development and test image: PHP with pcov, Composer, Node and Playwright's Chromium.
# Not used on the device. Run everything with tools/dev.sh.
FROM mcr.microsoft.com/playwright:v1.65.0-noble
RUN echo 'Acquire::Retries "5";' > /etc/apt/apt.conf.d/80-retries \
 && apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      php8.3-cli php8.3-xml php8.3-mbstring php8.3-pcov php8.3-zip unzip zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /work
