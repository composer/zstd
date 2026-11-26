# Toolchain for the Linux binaries. The builder workflow publishes it as ghcr.io/composer/zstd-builder, and
# build-linux.sh pins that image by digest: apk installs whatever is current, so only the built image is stable.
# The Rust version must match rust-toolchain.toml.
FROM rust:1.99.1-alpine3.24@sha256:44819b27be4c97b0e4d7203bd2e02c4820f97700211b6a37fb4c246a2de59383

LABEL org.opencontainers.image.source=https://github.com/composer/zstd
LABEL org.opencontainers.image.description="Toolchain that builds the zstd-extract Linux binaries of composer/zstd"

# gcc compiles the zstd decoder and links; std's link line needs libunwind, though none of it ends up in the binary
# with panic = "immediate-abort"; musl needs fortify-headers for _FORTIFY_SOURCE to do anything
RUN apk add --no-cache gcc musl-dev fortify-headers llvm-libunwind-static

# std is rebuilt from rust-src (see Cargo.toml), and the builds run offline: fetch the crates it depends on now
RUN rustup component add rust-src \
    && RUSTC_BOOTSTRAP=1 cargo fetch --locked --manifest-path "$(rustc --print sysroot)/lib/rustlib/src/rust/library/Cargo.toml"
