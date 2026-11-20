#!/bin/sh
# Builds build/zstd-extract-linux-<arch> for the current architecture, statically linked against musl (MIT
# licensed, unlike glibc's LGPL), in a pinned toolchain container. This is how the released binaries are built.
set -eu

# built from Dockerfile by the builder workflow, which prints the digest to put here
IMAGE=ghcr.io/composer/zstd-builder@sha256:0ba4ec79a3cd95e656004e2fc527d8c160a450b83f733a49383f69f6ee48a968

cd "$(dirname "$0")"
mkdir -p build
docker run --rm --network none -v "$PWD:/src:ro" -v "$PWD/build:/out" -e OWNER="$(id -u):$(id -g)" -e IMAGE="$IMAGE" "$IMAGE" sh -euc '
    version() { apk --no-network info -v 2>/dev/null | sed -n "s/^$1-\([0-9].*\)/\1/p"; }
    cp -r /src /tmp/native
    rm -rf /tmp/native/build
    make -s -C /tmp/native linux LIBC="musl $(version musl)"
    echo "#include <string.h>" | gcc -O2 -D_FORTIFY_SOURCE=2 -E - | grep -q /fortify/string.h # fortify is really on
    cp /tmp/native/build/zstd-extract-linux-* /out/
    # the versions that end up in the SBOM
    {
        for pkg in musl gcc binutils fortify-headers; do echo "$pkg=$(version $pkg)"; done
        echo "alpine=$(cat /etc/alpine-release)"
        echo "image=$IMAGE"
    } > "/out/zstd-extract-linux-$(uname -m).toolchain"
    chown "$OWNER" /out/zstd-extract-linux-*
'
