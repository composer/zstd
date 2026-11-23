#!/bin/sh
# Builds build/zstd-extract-darwin, universal x86_64 + arm64, ad-hoc signed.
set -eu
cd "$(dirname "$0")"
export MACOSX_DEPLOYMENT_TARGET=11.0
# installs the version from rust-toolchain.toml
rustup toolchain install
rustup target add x86_64-apple-darwin aarch64-apple-darwin
for target in x86_64-apple-darwin aarch64-apple-darwin; do
    cargo build -q --release --locked --target "$target"
done
mkdir -p build
bin=build/zstd-extract-darwin
lipo -create -output "$bin" target/x86_64-apple-darwin/release/zstd-extract target/aarch64-apple-darwin/release/zstd-extract
codesign --force --sign - "$bin"
# the versions that end up in the SBOM
{
    echo "clang=$(cc --version | sed -n "1s/.*version //p")"
    echo "sdk=$(xcrun --show-sdk-version)"
    echo "rustc=$(rustc --version | cut -d' ' -f2)"
} > "$bin.toolchain"
