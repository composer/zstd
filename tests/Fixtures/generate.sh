#!/bin/bash
# Regenerates the compressed fixtures. Needs GNU tar and the zstd CLI (ZSTD env var to override).
set -euo pipefail
cd "$(dirname "$0")"
ZSTD=${ZSTD:-zstd}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/src/sub/deeper"
cp ../../native/zstd/common/*.h "$work/src/"
cp ../../native/zstd/decompress/zstd_decompress_block.c "$work/src/sub/deeper/"
printf '#!/bin/sh\necho hi\n' > "$work/src/sub/run.sh"
chmod 755 "$work/src/sub/run.sh"
: > "$work/src/empty"
ln -s sub/run.sh "$work/src/link-to-script"
ln -s ../mem.h "$work/src/sub/up-link"
mkdir -p "$work/src/$(printf 'long%.0s' {1..40})"
echo 'long path' > "$work/src/$(printf 'long%.0s' {1..40})/$(printf 'name%.0s' {1..30}).txt"

tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner --format=gnu -C "$work/src" -cf source.tar .

"$ZSTD" -q -f -1 source.tar -o level1.tar.zst
"$ZSTD" -q -f -19 --no-check source.tar -o level19.tar.zst
"$ZSTD" -q -f --ultra -22 --long=27 source.tar -o ultra22.tar.zst
"$ZSTD" -q -f --fast=5 source.tar -o fast5.tar.zst
# two frames with a skippable frame in between
head -c 300000 source.tar | "$ZSTD" -q -3 > "$work/part1.zst"
tail -c +300001 source.tar | "$ZSTD" -q -3 > "$work/part2.zst"
printf '\x50\x2a\x4d\x18\x04\x00\x00\x00skip' | cat "$work/part1.zst" - "$work/part2.zst" > multiframe.tar.zst
sha256sum source.tar | cut -d' ' -f1 > source.tar.sha256
rm source.tar
