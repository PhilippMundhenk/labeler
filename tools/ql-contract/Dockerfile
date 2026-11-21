# brother_ql (a maintained fork) plus PHP, to compare the raster jobs Labeler's ql driver writes
# with those brother_ql writes for the same label. No printer is needed.
#   docker build -t labeler-ql-contract --build-arg BROTHER_QL=brother_ql-next -f tools/ql-contract/Dockerfile tools
#   docker run --rm -v "$PWD:/work" labeler-ql-contract
FROM python:3.12-slim-bookworm
ARG BROTHER_QL=brother_ql-next
RUN echo 'Acquire::Retries "5";' > /etc/apt/apt.conf.d/80-retries \
 && apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends php-cli \
 && rm -rf /var/lib/apt/lists/* \
 && pip install --no-cache-dir --retries 10 --timeout 60 "$BROTHER_QL" \
 && pip show "$BROTHER_QL" | sed -n 's/^Version: /brother_ql version /p'
WORKDIR /work
CMD ["sh", "tools/ql-contract/run.sh"]
