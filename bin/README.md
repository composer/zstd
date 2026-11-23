Native binaries
===============

`zstd-extract` binaries built reproducibly from `native/`. CI checks that they are byte-identical to a fresh build
(see the [binaries](../.github/workflows/binaries.yml) workflow). Each release rebuilds them, attests that build,
and attaches the identical files. You can check a binary against a release's attestation with:

```
gh attestation verify bin/zstd-extract-linux-x86_64 --repo composer/zstd
```

Each `<binary>.cdx.json` is the binary's CycloneDX SBOM, listing the exact versions of everything linked into it.

Besides this package's own MIT licensed code (see ../LICENSE), they contain the zstd decoder (BSD licensed, see
LICENSE.zstd), the Rust standard library (MIT licensed, see LICENSE.rust) and, on Linux, the musl C library (MIT
licensed, see LICENSE.musl).
