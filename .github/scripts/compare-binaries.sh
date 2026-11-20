#!/bin/bash
# Checks that bin/ is byte-identical to a fresh build (the zstd-extract-binaries artifact in $1).
set -euo pipefail

built=$1
fail=0
for name in zstd-extract-linux-x86_64 zstd-extract-linux-aarch64 zstd-extract-darwin zstd-extract-windows-x86_64.exe; do
    file="bin/$name"
    if [ ! -f "$built/$name" ]; then
        echo "::error::the build did not produce $name"
        exit 1
    fi
    if [ ! -f "$file" ]; then
        echo "::error file=$file::missing"
        fail=1
        continue
    fi
    if ! cmp -s "$built/$name" "$file"; then
        echo "::error file=$file::differs from a fresh build of native/"
        fail=1
    fi
    mode=$(git ls-files --stage -- "$file" | cut -d' ' -f1)
    if [ "$mode" != "100755" ]; then
        echo "::error file=$file::must be committed as executable (git mode is ${mode:-untracked})"
        fail=1
    fi
done
if ! cmp -s "$built/SHA256SUMS" bin/SHA256SUMS; then
    echo "::error file=bin/SHA256SUMS::differs from the fresh build's checksums"
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    echo "bin/ does not match a build of native/, update it from this run's artifact:"
    echo "  gh run download ${GITHUB_RUN_ID:-<run-id>} --repo ${GITHUB_REPOSITORY:-composer/zstd} --name zstd-extract-binaries --dir bin/"
    echo "  chmod +x bin/zstd-extract-* && git add --chmod=+x bin/"
    exit 1
fi
echo "bin/ is identical to the fresh build"
