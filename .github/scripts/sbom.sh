#!/bin/bash
# Writes a CycloneDX SBOM (<binary>.cdx.json) for each binary in $1, from the <binary>.toolchain file its build
# left there, then removes the toolchain files. Nothing in the output depends on when or where this runs.
# Needs the pinned Rust toolchain with its rust-src component, for the versions of the crates std is built from.
set -euo pipefail

dir=$1
sysroot=$(cd native && rustc --print sysroot)
rustc_version=$(cd native && rustc --version | cut -d' ' -f2)
std_lock="$sysroot/lib/rustlib/src/rust/library/Cargo.lock"
[ -f "$std_lock" ] || { echo "no $std_lock, install the rust-src component" >&2; exit 1; }
version=$(sed -n '1,/^\[/!b; s/^version = "\(.*\)"$/\1/p' native/Cargo.toml)
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

# crates.io crates of std that end up in the binary, with their licenses. With panic = "immediate-abort" the
# backtrace printing (gimli, addr2line, object, ...) is gone: hashbrown backs HashMap, and libc is used by std
# everywhere but on Windows. The binary has no source paths left to find them by, check with nm on an unstripped
# build after a toolchain update.
declare -A crate_license=(
    [hashbrown]="MIT OR Apache-2.0"
    [libc]="MIT OR Apache-2.0"
)

# Rust's std and the crates it brings into the binary
rust_components() {
    local bin=$1 windows=$2 crates=hashbrown
    [ "$windows" = 1 ] || crates="$crates libc"
    [ "$(tc rustc)" = "$rustc_version" ] || { echo "$bin: built with rustc $(tc rustc), but $rustc_version is installed" >&2; exit 1; }
    local list=() name lock_version
    for name in $crates; do
        lock_version=$(awk -v n="$name" '$0 == "name = \"" n "\"" { getline; gsub(/version = |"/, ""); print; exit }' "$std_lock")
        [ -n "$lock_version" ] || { echo "$bin: $name is not one of std's dependencies" >&2; exit 1; }
        list+=("$(jq -n --arg name "$name" --arg version "$lock_version" --arg license "${crate_license[$name]}" '{
            type: "library", "bom-ref": "crate-\($name)", name: $name, version: $version,
            description: "Part of the Rust standard library build",
            licenses: [{expression: $license}],
            purl: "pkg:cargo/\($name)@\($version)"
        }')")
    done
    jq -n --arg version "$rustc_version" '[
        {
            type: "library", "bom-ref": "rust-std", name: "Rust standard library", version: $version,
            licenses: [{expression: "(MIT OR Apache-2.0) AND Unicode-3.0"}],
            purl: "pkg:github/rust-lang/rust@\($version)",
            cpe: "cpe:2.3:a:rust-lang:rust:\($version):*:*:*:*:*:*:*"
        },
        {
            type: "library", "bom-ref": "compiler-builtins", name: "compiler-builtins", version: $version,
            description: "Part of the Rust toolchain, with code ported from LLVM compiler-rt",
            licenses: [{expression: "MIT AND Apache-2.0 WITH LLVM-exception AND (MIT OR Apache-2.0)"}],
            purl: "pkg:github/rust-lang/rust@\($version)#library/compiler-builtins"
        }
    ] + ($ARGS.positional | map(fromjson))' --args "${list[@]}"
}

for name in zstd-extract-linux-x86_64 zstd-extract-linux-aarch64 zstd-extract-darwin zstd-extract-windows-x86_64.exe; do
    toolchain="$dir/$name.toolchain"
    case $name in
        *-linux-*)
            arch=${name##*-}
            image=$(tc image)
            # linked statically: libc, the inlined checks of the C part, and Rust's std (nothing of libunwind is left)
            linked=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(apk musl musl MIT)" \
                "$(apk fortify-headers fortify-headers 0BSD)")
            tools=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(tool rustc "$(tc rustc)")" \
                "$(apk gcc gcc 'GPL-3.0-or-later' | jq '.type = "application" | del(."bom-ref")')" \
                "$(apk binutils binutils 'GPL-3.0-or-later' | jq '.type = "application" | del(."bom-ref")')" \
                "$(jq -n --arg image "$image" '{type: "container", name: ($image | split("@")[0]), version: ($image | split("@")[1])}')")
            ;;
        *-darwin)
            # libSystem is linked dynamically, so only zstd and Rust's std are part of the binary
            linked='[]'
            tools=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(tool rustc "$(tc rustc)")" "$(tool 'Apple clang' "$(tc clang)")" "$(tool 'macOS SDK' "$(tc sdk)")")
            ;;
        *-windows-*)
            license='{"license": {"name": "Microsoft Visual Studio and Windows SDK license terms"}}'
            linked=$(jq -n --arg msvc "$(tc msvc)" --arg ucrt "$(tc ucrt)" --argjson license "$license" '[
                {type: "library", "bom-ref": "vcruntime", name: "Microsoft Visual C++ runtime (static)", version: $msvc, licenses: [$license]},
                {type: "library", "bom-ref": "ucrt", name: "Microsoft Universal C runtime (static)", version: $ucrt, licenses: [$license]}
            ]')
            tools=$(jq -n '$ARGS.positional | map(fromjson)' --args \
                "$(tool rustc "$(tc rustc)")" "$(tool 'MSVC' "$(tc msvc)")" "$(tool 'Windows SDK' "$(tc sdk)")")
            ;;
    esac
    case $name in *-windows-*) windows=1 ;; *) windows=0 ;; esac
    linked=$(jq -n --argjson linked "$linked" --argjson rust "$(rust_components "$dir/$name" "$windows")" '$linked + $rust')

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
