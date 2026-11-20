#!/bin/bash
# Replaces native/zstd with the decoder subset of an upstream zstd release.
# Usage: native/update-zstd.sh 1.5.7 eb33e51f49a15e023950cd7825ca74a4a2b43db8354825ac24fc1b7ee09e6fa3
set -euo pipefail
version=$1
sha=$2
cd "$(dirname "$0")"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

curl -sSfL -o "$work/zstd.tar.gz" "https://github.com/facebook/zstd/releases/download/v$version/zstd-$version.tar.gz"
echo "$sha  $work/zstd.tar.gz" | sha256sum -c -
tar -xzf "$work/zstd.tar.gz" -C "$work"
src="$work/zstd-$version"

find zstd -mindepth 1 ! -name README.md -delete
cp "$src/LICENSE" zstd/
cp -r "$src/lib/zstd.h" "$src/lib/zstd_errors.h" "$src/lib/common" "$src/lib/decompress" zstd/
rm zstd/common/pool.* zstd/common/threading.*
sed -i "s/v[0-9.]* release/v$version release/; s#download/v[0-9.]*/zstd-[0-9.]*\.tar\.gz#download/v$version/zstd-$version.tar.gz#; s/^SHA-256: .*/SHA-256: $sha/" zstd/README.md
