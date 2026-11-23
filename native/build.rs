//! Compiles the vendored zstd decoder (zstd/, see update-zstd.sh) and links its objects into the binary.
//! No build dependencies: the compiler is called directly, with the same flags as the C build had.

use std::env;
use std::path::PathBuf;
use std::process::Command;

const DEFINES: &[&str] = &[
    "ZSTD_LEGACY_SUPPORT=0",
    "ZSTD_MULTITHREAD=0",
    "XXH_NAMESPACE=ZSTD_",
    "ZSTD_NO_TRACE",
    "DEBUGLEVEL=0",
];

fn sources() -> Vec<PathBuf> {
    let mut srcs = Vec::new();
    for dir in ["zstd/common", "zstd/decompress"] {
        for e in std::fs::read_dir(dir).unwrap() {
            let p = e.unwrap().path();
            if p.extension().is_some_and(|x| x == "c" || x == "S") {
                srcs.push(p);
            }
        }
    }
    srcs.sort(); // link order is part of the output
    srcs
}

fn run(cmd: &mut Command) {
    let status = cmd
        .status()
        .unwrap_or_else(|e| panic!("cannot run {cmd:?}: {e}"));
    assert!(status.success(), "{cmd:?} failed");
}

fn main() {
    println!("cargo:rerun-if-changed=zstd");
    println!("cargo:rerun-if-env-changed=CC");
    println!("cargo:rerun-if-env-changed=EXTRACT_LIBC");
    let target = env::var("TARGET").unwrap();
    let out = PathBuf::from(env::var("OUT_DIR").unwrap());
    let msvc = target.ends_with("-msvc");

    let mut objs = Vec::new();
    for src in sources() {
        let asm = src.extension().unwrap() == "S";
        if asm && msvc {
            continue; // MSVC builds use ZSTD_DISABLE_ASM
        }
        let obj = out
            .join(src.file_name().unwrap())
            .with_extension(if msvc { "obj" } else { "o" });
        let mut cc = if msvc {
            Command::new("cl")
        } else {
            Command::new(env::var("CC").unwrap_or_else(|_| "cc".into()))
        };
        if msvc {
            cc.args([
                "/nologo",
                "/c",
                "/O2",
                "/MT",
                "/W3",
                "/GS",
                "/guard:cf",
                "/utf-8",
                "/Brepro",
                "/Izstd",
                "/DZSTD_DISABLE_ASM",
            ]);
            cc.args(DEFINES.iter().map(|d| format!("/D{d}")));
            cc.arg(format!("/Fo{}", obj.display()));
        } else {
            cc.args([
                "-c",
                "-O2",
                "-std=c99",
                "-Wall",
                "-Wextra",
                "-Izstd",
                "-ffunction-sections",
                "-fdata-sections",
            ]);
            cc.args([
                "-fstack-protector-strong",
                "-U_FORTIFY_SOURCE",
                "-D_FORTIFY_SOURCE=2",
                "-fPIE",
            ]);
            cc.args(DEFINES.iter().map(|d| format!("-D{d}")));
            if target.contains("apple") {
                let arch = if target.starts_with("aarch64") {
                    "arm64"
                } else {
                    "x86_64"
                };
                cc.args(["-arch", arch, "-mmacosx-version-min=11.0"]);
            }
            cc.arg("-o").arg(&obj);
        }
        cc.arg(&src);
        run(&mut cc);
        objs.push(obj);
    }
    // a static library, so it is linked before the system libraries it needs
    let lib = out.join(if msvc { "zstd.lib" } else { "libzstd.a" });
    let _ = std::fs::remove_file(&lib);
    let mut ar = if msvc {
        let mut c = Command::new("lib");
        c.args(["/nologo", "/Brepro"])
            .arg(format!("/OUT:{}", lib.display()));
        c
    } else if target.contains("apple") {
        let mut c = Command::new("libtool");
        c.args(["-static", "-D", "-o"]).arg(&lib); // -D: no timestamps or uids
        c
    } else {
        let mut c = Command::new(env::var("AR").unwrap_or_else(|_| "ar".into()));
        c.arg("crsD").arg(&lib); // D: no timestamps or uids
        c
    };
    run(ar.args(&objs));
    println!("cargo:rustc-link-search=native={}", out.display());
    println!("cargo:rustc-link-lib=static=zstd");

    let rustc = env::var("RUSTC").unwrap();
    let v = Command::new(rustc)
        .arg("--version")
        .output()
        .unwrap()
        .stdout;
    let v = String::from_utf8(v).unwrap();
    println!(
        "cargo:rustc-env=EXTRACT_RUSTC={}",
        v.split_whitespace().nth(1).unwrap()
    );
}
