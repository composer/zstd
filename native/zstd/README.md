Vendored zstd decoder
=====================

Unmodified subset of the [zstd](https://github.com/facebook/zstd) v1.5.7 release, used under its BSD license
(see LICENSE).

Source: https://github.com/facebook/zstd/releases/download/v1.5.7/zstd-1.5.7.tar.gz
SHA-256: eb33e51f49a15e023950cd7825ca74a4a2b43db8354825ac24fc1b7ee09e6fa3

Contents: `LICENSE` and, from `lib/`, `zstd.h`, `zstd_errors.h`, `common/` (minus `pool.*` and `threading.*`)
and `decompress/`.

To update, run `native/update-zstd.sh <version> <sha256>`, then rebuild and commit the binaries.
