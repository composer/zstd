#!/bin/sh
# Builds build/zstd-extract-linux-<arch> for the current architecture, statically linked against musl (MIT
# licensed, unlike glibc's LGPL), in a pinned toolchain container. This is how the released binaries are built.
set -eu

# built from Dockerfile by the builder workflow, which prints the digest to put here
IMAGE=ghcr.io/composer/zstd-builder@sha256:c3d79cc03d7f87bdc162957ed70dbac075b59ba87be8d091f09cc85296d5cd9d

cd "$(dirname "$0")"
mkdir -p build
docker run --rm --network none -v "$PWD:/src:ro" -v "$PWD/build:/out" -e OWNER="$(id -u):$(id -g)" "$IMAGE" sh -euc '
    cp -r /src /tmp/native
    rm -rf /tmp/native/build
    make -s -C /tmp/native linux
    echo "#include <string.h>" | gcc -O2 -D_FORTIFY_SOURCE=2 -E - | grep -q /fortify/string.h # fortify is really on
    cp /tmp/native/build/zstd-extract-linux-* /out/
    chown "$OWNER" /out/zstd-extract-linux-*
'
