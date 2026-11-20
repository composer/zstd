#!/bin/sh
# Builds build/zstd-extract-linux-<arch> for the current architecture, statically linked against musl (MIT
# licensed, unlike glibc's LGPL), in a pinned Alpine container. This is how the released binaries are built.
set -eu

# alpine:3.23, multi-arch index
IMAGE=alpine:3.23@sha256:ff7f0c727dd796aa36787bfcd9a64bf35a6ce72a73451b3359fde849b6703d63

cd "$(dirname "$0")"
mkdir -p build
docker run --rm -v "$PWD:/src:ro" -v "$PWD/build:/out" -e OWNER="$(id -u):$(id -g)" "$IMAGE" sh -euc '
    apk add -q gcc musl-dev make fortify-headers # musl needs these for _FORTIFY_SOURCE to do anything
    cp -r /src /tmp/native
    rm -rf /tmp/native/build
    make -s -C /tmp/native linux
    echo "#include <string.h>" | gcc -O2 -D_FORTIFY_SOURCE=2 -E - | grep -q /fortify/string.h # fortify is really on
    cp /tmp/native/build/zstd-extract-linux-* /out/
    chown "$OWNER" /out/zstd-extract-linux-*
'
