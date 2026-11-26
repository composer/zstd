#!/bin/sh
# Builds build/zstd-extract-linux-<arch> for the current architecture, statically linked against musl (MIT
# licensed, unlike glibc's LGPL), in a pinned toolchain container. This is how the released binaries are built.
set -eu

# built from Dockerfile by the builder workflow, which prints the digest to put here
IMAGE=ghcr.io/composer/zstd-builder@sha256:776bf6f1dad19bd5c98c0f579dfd9d3e142ee0841172c72882dd6dd34c3baecd

cd "$(dirname "$0")"
mkdir -p build
docker run --rm --network none -v "$PWD:/src:ro" -v "$PWD/build:/out" -e OWNER="$(id -u):$(id -g)" -e IMAGE="$IMAGE" "$IMAGE" sh -euc '
    version() { apk --no-network info -v 2>/dev/null | sed -n "s/^$1-\([0-9].*\)/\1/p"; }
    arch=$(uname -m)
    mkdir /tmp/native
    cp -r /src/.cargo /src/static-pie-cc /src/Cargo.toml /src/Cargo.lock /src/rust-toolchain.toml /src/build.rs /src/src /src/zstd /tmp/native/
    cd /tmp/native
    EXTRACT_LIBC="musl $(version musl)" RUSTC_BOOTSTRAP=1 cargo build -q --release --locked --offline --target "$arch-unknown-linux-musl"
    echo "#include <string.h>" | gcc -O2 -D_FORTIFY_SOURCE=2 -E - | grep -q /fortify/string.h # fortify is really on
    cp "target/$arch-unknown-linux-musl/release/zstd-extract" "/out/zstd-extract-linux-$arch"
    # the versions that end up in the SBOM
    {
        for pkg in musl gcc binutils fortify-headers; do echo "$pkg=$(version $pkg)"; done
        echo "rustc=$(rustc --version | cut -d" " -f2)"
        echo "alpine=$(cat /etc/alpine-release)"
        echo "image=$IMAGE"
    } > "/out/zstd-extract-linux-$arch.toolchain"
    chown "$OWNER" /out/zstd-extract-linux-*
'
