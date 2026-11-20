#!/bin/bash
# Writes a CycloneDX SBOM (<binary>.cdx.json) for each binary in $1, from the <binary>.toolchain file its build
# left there, then removes the toolchain files. Nothing in the output depends on when or where this runs.
set -euo pipefail

dir=$1
version=$(sed -n 's/^#define EXTRACT_VERSION "\(.*\)"$/\1/p' native/src/main.c)
zstd=$(sed -n 's/.* v\([0-9.]*\) release.*/\1/p' native/zstd/README.md)
zstd_sha=$(sed -n 's/^SHA-256: //p' native/zstd/README.md)
[ -n "$version" ] && [ -n "$zstd" ] && [ -n "$zstd_sha" ]

# value of a key in the toolchain file, which is CRLF on Windows
tc() {
    local value
    value=$(tr -d '\r' < "$toolchain" | sed -n "s/^$1=//p")
    [ -n "$value" ] || { echo "$toolchain: no $1" >&2; exit 1; }
    printf '%s' "${value%\\}"
}

apk() {
    jq -n --arg name "$1" --arg pkg "$2" --arg version "$(tc "$2")" --arg license "$3" --arg arch "$arch" --arg alpine "$(tc alpine)" '{
        type: "library", "bom-ref": $name, name: $name, version: $version,
        licenses: [if ($license | test(" ")) then {expression: $license} else {license: {id: $license}} end],
        purl: "pkg:apk/alpine/\($pkg)@\($version)?arch=\($arch)&distro=alpine-\($alpine | split(".")[:2] | join("."))"
    }'
}

tool() {
    jq -n --arg name "$1" --arg version "$2" '{type: "application", name: $name, version: $version}'
}

for name in zstd-extract-linux-x86_64 zstd-extract-linux-aarch64 zstd-extract-darwin zstd-extract-windows-x86_64.exe; do
    toolchain="$dir/$name.toolchain"
    case $name in
        *-linux-*)
            arch=${name##*-}
            image=$(tc image)
            # linked statically: libc, compiler runtime (libgcc.a is part of the gcc package) and the inlined checks
            linked=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(apk musl musl MIT)" \
                "$(apk libgcc gcc 'GPL-3.0-or-later WITH GCC-exception-3.1')" \
                "$(apk fortify-headers fortify-headers 0BSD)")
            tools=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(apk gcc gcc 'GPL-3.0-or-later' | jq '.type = "application" | del(."bom-ref")')" \
                "$(apk binutils binutils 'GPL-3.0-or-later' | jq '.type = "application" | del(."bom-ref")')" \
                "$(jq -n --arg image "$image" '{type: "container", name: ($image | split("@")[0]), version: ($image | split("@")[1])}')")
            ;;
        *-darwin)
            # libSystem is linked dynamically, so nothing but zstd is part of the binary
            linked='[]'
            tools=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(tool 'Apple clang' "$(tc clang)")" "$(tool 'macOS SDK' "$(tc sdk)")")
            ;;
        *-windows-*)
            license='{"license": {"name": "Microsoft Visual Studio and Windows SDK license terms"}}'
            linked=$(jq -n --arg msvc "$(tc msvc)" --arg ucrt "$(tc ucrt)" --argjson license "$license" '[
                {type: "library", "bom-ref": "vcruntime", name: "Microsoft Visual C++ runtime (static)", version: $msvc, licenses: [$license]},
                {type: "library", "bom-ref": "ucrt", name: "Microsoft Universal C runtime (static)", version: $ucrt, licenses: [$license]}
            ]')
            tools=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(tool 'MSVC' "$(tc msvc)")" "$(tool 'Windows SDK' "$(tc sdk)")")
            ;;
    esac

    jq -n --arg name "$name" --arg version "$version" --arg sha "$(sha256sum "$dir/$name" | cut -d' ' -f1)" \
        --arg zstd "$zstd" --arg zstd_sha "$zstd_sha" --argjson linked "$linked" --argjson tools "$tools" '{
        bomFormat: "CycloneDX",
        specVersion: "1.6",
        version: 1,
        metadata: {
            component: {
                type: "application", "bom-ref": "zstd-extract", name: $name, version: $version,
                supplier: {name: "Composer", url: ["https://github.com/composer/zstd"]},
                hashes: [{alg: "SHA-256", content: $sha}],
                licenses: [{license: {id: "MIT"}}]
            }
        },
        components: ([{
            type: "library", "bom-ref": "zstd", name: "zstd", version: $zstd,
            description: "Decoder only (lib/common and lib/decompress), built without legacy format support",
            licenses: [{license: {id: "BSD-3-Clause"}}],
            cpe: "cpe:2.3:a:facebook:zstandard:\($zstd):*:*:*:*:*:*:*",
            purl: "pkg:github/facebook/zstd@v\($zstd)",
            externalReferences: [{
                type: "distribution",
                url: "https://github.com/facebook/zstd/releases/download/v\($zstd)/zstd-\($zstd).tar.gz",
                hashes: [{alg: "SHA-256", content: $zstd_sha}]
            }]
        }] + $linked),
        dependencies: [{ref: "zstd-extract", dependsOn: (["zstd"] + ($linked | map(."bom-ref")))}],
        formulation: [{components: $tools}]
    }' > "$dir/$name.cdx.json"
    rm "$toolchain"
done
