# Toolchain for the Linux binaries. The builder workflow publishes it as ghcr.io/composer/zstd-builder, and
# build-linux.sh pins that image by digest: apk installs whatever is current, so only the built image is stable.
FROM alpine:3.23@sha256:ff7f0c727dd796aa36787bfcd9a64bf35a6ce72a73451b3359fde849b6703d63

LABEL org.opencontainers.image.source=https://github.com/composer/zstd
LABEL org.opencontainers.image.description="Toolchain that builds the zstd-extract Linux binaries of composer/zstd"

# musl needs fortify-headers for _FORTIFY_SOURCE to do anything
RUN apk add --no-cache gcc musl-dev make fortify-headers
