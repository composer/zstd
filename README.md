composer/zstd
=============

Safe extraction of `.tar.zst` archives into a directory, with whatever is available: the zstd PHP extension, a
small bundled native binary, a `zstd` executable, or pure PHP.

[![Continuous Integration](https://github.com/composer/zstd/actions/workflows/continuous-integration.yml/badge.svg?branch=main)](https://github.com/composer/zstd/actions)

Core principle: same behavior everywhere
----------------------------------------

**Which method extracts an archive must never change the result.** Every method produces the same files, or
rejects the same archives, as decompressing with the reference zstd implementation (libzstd / the `zstd` CLI)
and then applying this library's tar rules.

- **Decompression follows libzstd.** Valid input gives identical output. Input libzstd rejects is rejected too:
  bad checksums, truncation, trailing data, unknown frames, reserved bits, required dictionaries, and windows
  over 128MB (zstd's default `--memory` limit, so `--long=27` at most). The bundled binary and ext-zstd use
  libzstd itself. The PHP decoder mirrors its checks, and ConformanceTest runs it against the zstd CLI on
  thousands of corrupted inputs.
- **Tar extraction has one set of rules**, implemented twice: in C for the bundled binary, in PHP for the other
  methods. Every bad-actor archive in BadActorTest goes through every method, and all must reject it the same
  way.
- **Gaps:** two cases can differ from libzstd, and both only matter for corrupted input:
  - In frames without a checksum, corrupted data that libzstd doesn't detect decodes to garbage. Which garbage
    depends on libzstd's internal decoding path (fast and slow paths differ), so it can't be reproduced exactly.
    Where that is known to happen (Huffman streams not consumed exactly, which libzstd's fast path lets through),
    the PHP decoder rejects the input instead of returning different content.
  - The pure PHP decoder can only verify checksums on PHP 8.1+, where ext-hash has XXH64. zstd adds checksums by
    default.

  Archives should be authenticated (signed) before extraction anyway.

Any other difference is a bug.

Installation
------------

```bash
composer require composer/zstd
```

Requires PHP 7.2.5+. The pure PHP decoder needs a 64-bit PHP build.

Usage
-----

```php
use Composer\Zstd\Extractor;
use Composer\Zstd\ExtractionException;

$extractor = new Extractor();

try {
    // the destination must not exist or be an empty directory
    $extractor->extract('/path/to/package.tar.zst', '/path/to/destination');
} catch (ExtractionException $e) {
    // invalid or unsafe archive, or I/O error; the destination was cleaned up
}

$extractor->getLastMethod();         // which method was used
$extractor->getUnavailableReasons(); // why others were skipped

// e.g. for a diagnose command: warn when only the slow pure PHP fallback is left
if ($extractor->getAvailableMethods()[0] === Extractor::METHOD_PHP) {
    // suggest installing zstd or ext-zstd
}
```

Constructor arguments, all optional:

- `$cacheDir`: Composer's cache directory. When the bundled binary cannot run in place (from inside a phar), it is
  copied to the `zstd` subdirectory. Defaults to the directory Composer itself uses (`COMPOSER_CACHE_DIR`,
  `COMPOSER_HOME/cache`, `%LOCALAPPDATA%/Composer`, `~/Library/Caches/composer`, `$XDG_CACHE_HOME/composer`…,
  resolved like `Composer\Factory::getCacheDir()`).
- `$methods`: methods to try, in order. The first available one is used.
- `$bundledPath`: another `zstd-extract` binary to use instead of the bundled one.
- `$zstdCliPath`: a `zstd` executable to use instead of looking one up in `PATH`.
- `$maxSize`: the most an archive may decompress to, in bytes (the size of the tar stream). Defaults to 1024MB
  (`Extractor::DEFAULT_MAX_SIZE`). Larger archives are rejected, by every method.

`Composer\Zstd\ZstdDecoder::decompress(string $data): string` can also be used on its own.

Methods
-------

Default order (`getMethodOrder()`):

| method | used when | how |
|---|---|---|
| `bundled` | `proc_open` works and a bundled binary fits the platform | streaming decompression and extraction in C, in a separate process |
| `ext-zstd` | the [zstd extension](https://github.com/kjdev/php-ext-zstd) is loaded | libzstd in-process, tar extraction in PHP |
| `zstd-cli` | a `zstd` 1.4+ executable is in `PATH` | `zstd -d` in a separate process, tar extraction in PHP |
| `php` | always (64-bit PHP) | pure PHP decoder, tar extraction in PHP |

`prepare()` picks the first available one. The bundled binary is only picked once it is known to work. From a
phar this means it was copied to the cache directory safely (see below) and ran. Otherwise the next method is
used.

A method that runs and rejects the archive ends the extraction, and the next methods are not tried.

`getAvailableMethods()` lists what works in the current environment, in order. For the bundled binary this
includes copying it if needed and running it once. When only `php` is left, extraction is several times slower.

### Performance

259 popular packages (630MB unpacked, about 59k files), archive to unpacked directory, arm64 Linux, 12 cores:

| method | one at a time | 10 in parallel |
|---|---|---|
| `bundled` | 4.1-5.1s | **1.3s** |
| `ext-zstd` (0.15.2, libzstd 1.5.5) | 5.4-7.3s | n/a, in-process |
| `zstd-cli` | 6.3-7.5s | 4.7s |
| `php` | 12.1s (8.0s with opcache JIT) | n/a, in-process |
| for comparison: `unzip` of the zip dists | 10.8s | 2.7s |

Writing the files from PHP takes about 4.5s of these numbers, and it cannot run in parallel. So only the bundled
binary, which writes the files in its own process, scales with parallelism. ext-zstd itself decompresses
everything in about 1.1s.

### Parallel extraction

`prepare()` returns an `Extraction`. For process based methods it exposes the command, so you can run several
extractions in parallel with your own process runner and pass each result back to `complete()`. With Composer's
`ProcessExecutor`, for example:

```php
$extraction = $extractor->prepare($archive, $destination);
$command = $extraction->getCommand();
if ($command === null) {
    $extraction->run(); // in-process method

    return \React\Promise\resolve(null);
}

return $process->executeAsync($command)->then(static function (Process $result) use ($extraction): void {
    // throws an ExtractionException if the archive was rejected; if the command could not run at all,
    // falls back to the next available method
    $extraction->complete($result->getExitCode(), $result->getOutput(), $result->getErrorOutput());
});
```

Commands from `getCommand()` refer to the bundled binary by path. When `extract()`/`run()` start it themselves on
Linux, they use the open file handle instead (see below), which a generic process runner cannot pass on.

### ext-zstd and the zstd CLI

ext-zstd only receives archives whose framing was validated in PHP first. `zstd_uncompress()` handles a single
frame, and depending on the extension version it silently ignores trailing data or returns partial output for
truncated input.

The `zstd` CLI gets the archive as is. Depending on how it was built, it also decompresses gzip, xz, lz4 and
legacy (pre-1.0) zstd streams, which the other methods reject. Only the zstd format is covered by the core
principle.

Neither can limit its output on its own:

- `zstd_uncompress()` only runs once the frame headers and block counts show that the output fits in `$maxSize`.
  When they don't (no declared size, and enough blocks to possibly go over), the pure PHP decoder measures the
  actual size first.
- `zstd` is stopped once its output goes over `$maxSize`. A command from `getCommand()` has no such limit, the
  size is only checked by `complete()`.

### Why not the system tar

- It is not faster. `tar --zstd -xf` took 6.4-7.9s one at a time on the same benchmark, since it runs zstd as a
  second process.
- Its behavior varies between GNU tar, bsdtar and busybox: symlinks, `..` and absolute paths are handled
  differently, as are what counts as an error and how far extraction gets before one. That would break the
  core principle.

Bundled binaries
----------------

`bin/` contains one self-contained `zstd-extract` executable per platform:

| file | runs on |
|---|---|
| `zstd-extract-linux-x86_64` | Linux x86_64, any libc |
| `zstd-extract-linux-aarch64` | Linux arm64, any libc, 4K to 64K pages |
| `zstd-extract-darwin` | macOS 11+, universal x86_64 + arm64 |
| `zstd-extract-windows-x86_64.exe` | Windows x64, and Windows 11 arm64 through emulation |

They are built from `native/` (a small C program plus the vendored zstd decoder) on GitHub Actions. On Linux, gcc
links them statically against musl (static-pie, RELRO, stack protector, `_FORTIFY_SOURCE`), inside a toolchain
container pinned by digest (`native/build-linux.sh`). macOS uses Apple clang, and links the system library dynamically as macOS has
no static libc. Windows uses MSVC with a static C runtime (`/GS`, Control Flow Guard, ASLR). Where no binary runs,
e.g. Windows 10 on arm64 or other architectures, the next method is used. musl is
used instead of glibc because it is MIT licensed, where a statically linked glibc would bring LGPL obligations. It
is also as fast and 5x smaller. The builds are reproducible: every build runs twice and fails unless both
results are identical. Two checks build on that:

- The [binaries](.github/workflows/binaries.yml) workflow rebuilds them for every change to `native/` or `bin/`, and
  fails unless the committed `bin/` is byte-identical to the fresh build.
- The [release](.github/workflows/release.yml) workflow rebuilds them again from the release tag. If the result is
  identical to `bin/`, it signs a build provenance attestation from that run, covering the build steps, and attaches
  the files to the release.

As the files are identical, that attestation covers the committed copies as well:

```bash
gh attestation verify bin/zstd-extract-linux-x86_64 --repo composer/zstd
```

The Linux toolchain is the `ghcr.io/composer/zstd-builder` image, built from `native/Dockerfile` and pinned by
digest, so any release's Linux binaries can be rebuilt identically. The macOS and Windows toolchains come from the
runner images, so those builds are only reproducible until GitHub updates them. Older releases cannot be rebuilt
identically for these two later on, but their attestations stay valid.

### Running the binary safely

When this package is installed normally, the binary in `vendor/composer/zstd/bin/` runs in place. Anyone able to
replace it could equally modify the PHP code next to it.

From inside a phar the binary cannot be executed directly, so it is copied out first:

1. The copy goes to `<cacheDir>/zstd/`, which must be owned by the current user and not writable by
   group or others. Every ancestor directory must be owned by the user or root, and be sticky if it is writable by
   others (like `/tmp`). Otherwise the method is skipped.
2. The file is named after its SHA-256, written to a random temporary name and renamed into place.
3. It is then opened and its hash and owner are verified through that open handle.
4. On Linux, `extract()` and `run()` execute the binary from that same handle (`/proc/self/fd/N`), so even
   replacing the file afterwards has no effect. Otherwise, including commands from `getCommand()`, it runs by
   path from the private directory.

So only the current user (or root) could swap the file between the check and execution, and they can already
do anything that process can.

Tar safety rules
----------------

- The destination must not exist or be an empty directory. On failure it is removed (or emptied if it existed).
- Supported entries: regular files, directories and symlinks, with GNU long names and pax `path`/`linkpath`.
  Hardlinks, devices, FIFOs, sparse files, pax `size` overrides and entries over 8GB are rejected.
- Every header checksum is verified.
- The decompressed tar stream may not exceed `$maxSize` (1024MB by default). The bundled binary stops writing at
  that point. The other methods hold the decompressed data in memory, so PHP's `memory_limit` can end the
  extraction earlier.
- Entry names may not be absolute or contain `..` components, backslashes or control characters. `.` and empty
  components are dropped. On Windows, `<>:"|?*`, trailing dots or spaces and reserved device names
  (`CON`, `NUL`, `COM1`…) are also rejected.
- Files are created exclusively (`O_EXCL`, which never follows a symlink). When a name appears twice the last
  entry wins, but only a regular file is ever replaced. Directories are only traversed when they are real
  directories, never through symlinks.
- Symlink targets must be relative and contain no backslashes or control characters. Symlinks are created after
  all files and directories, so nothing is ever written through one. Once all of them exist, each one is resolved
  on the real filesystem, following other links like the OS would. If any step leaves the destination, or there
  are more than 40 hops, all symlinks are removed and extraction fails. The same happens when verifying all
  symlinks takes more than a million path components, which no real archive comes close to. Targets that don't
  exist yet are resolved lexically.
- File modes are `0666` or `0777` (if any execute bit is set) minus the umask. Owners and timestamps are not
  restored.

### Symlinks on Windows

On Windows, symlinks are written as plain files containing the target path, by every method. git does the same
with `core.symlinks=false`, and so does Composer today when it extracts zip archives with `ZipArchive` on Windows,
so this keeps the current behavior.

Real alternatives exist, but each has a catch:

- Symbolic links need Developer Mode or admin rights, so the result would depend on the machine.
- Directory junctions need no privileges, but they store absolute paths and only work for directories. Composer
  extracts into a temporary directory and then moves it, which would leave junctions pointing at the old
  location.

Development
-----------

```bash
make -C native linux     # or darwin; build-windows.cmd on Windows
ZSTD_EXTRACT_BINARY=$PWD/native/build/zstd-extract-linux-$(uname -m) vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Tests run against every method available locally:

| variable | effect |
|---|---|
| `ZSTD_EXTRACT_BINARY` | binary for `bundled`, defaults to `bin/` |
| `ZSTD_CLI` | `zstd` for `zstd-cli` and ConformanceTest, defaults to `PATH` |
| `ZSTD_TEST_REQUIRE_METHODS` | comma-separated methods that must be available; CI sets it so nothing is silently skipped |

### Updating the binaries

1. Change `native/` and push. The binaries workflow fails, since `bin/` no longer matches the build.
2. Take the binaries from that run, as the failure message explains:
   `gh run download <run-id> --repo composer/zstd --name zstd-extract-binaries --dir bin/`
3. Commit them, executable and with `bin/SHA256SUMS`: `chmod +x bin/zstd-extract-* && git add --chmod=+x bin/`.
   The next run must then find them identical.

The same applies when GitHub updates a macOS or Windows runner image and the toolchain output changes: CI shows
it, and the binaries are refreshed the same way.

The Linux toolchain only changes on purpose. To update it (new gcc or musl packages, or a new Alpine release in
`native/Dockerfile`), run the [builder](.github/workflows/builder.yml) workflow, put the image digest it prints
into `native/build-linux.sh`, and refresh the binaries as above.

### Releases

Publish the GitHub release soon after the last CI run on the tagged commit, before a macOS or Windows runner image update
can change the toolchains. The release workflow rebuilds, compares, attests, and uploads the binaries, `SHA256SUMS` and
license files. If the rebuild differs from `bin/`, it fails before attesting or uploading anything. Refresh the
binaries as above and tag again.

```bash
gh release download v1.0.0 --repo composer/zstd --pattern 'zstd-extract-*'
gh attestation verify zstd-extract-linux-x86_64 --repo composer/zstd
```

To update zstd itself, run `native/update-zstd.sh <version> <tarball sha256>`.

License
-------

composer/zstd is licensed under the MIT License, see the LICENSE file for details.

The bundled binaries also contain:

- zstd (decoder only), used under its BSD license (zstd is dual-licensed BSD/GPLv2): `bin/LICENSE.zstd`
- on Linux, musl libc, MIT licensed: `bin/LICENSE.musl`
- on Windows, the statically linked Microsoft C runtime, which Microsoft's Visual C++ license allows to be
  redistributed as part of an application, without notice requirements

These notices ship with the package in `bin/`, and are attached to each GitHub release next to the binaries.
When redistributing the binaries, e.g. inside a phar, keep `LICENSE`, `bin/LICENSE.zstd` and `bin/LICENSE.musl`
with them.
