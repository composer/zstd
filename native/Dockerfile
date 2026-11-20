# Toolchain for the Linux binaries. The builder workflow publishes it as ghcr.io/composer/zstd-builder, and
# build-linux.sh pins that image by digest: apk installs whatever is current, so only the built image is stable.
FROM alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf

LABEL org.opencontainers.image.source=https://github.com/composer/zstd
LABEL org.opencontainers.image.description="Toolchain that builds the zstd-extract Linux binaries of composer/zstd"

# musl needs fortify-headers for _FORTIFY_SOURCE to do anything
RUN apk add --no-cache gcc musl-dev make fortify-headers
