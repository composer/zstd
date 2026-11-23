@echo off
rem Builds build\zstd-extract-windows-x86_64.exe with MSVC (statically linked CRT, see .cargo\config.toml).
setlocal
set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
for /f "usebackq tokens=*" %%i in (`"%VSWHERE%" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do set "VSDIR=%%i"
if not defined VSDIR (echo Visual Studio with C++ tools not found & exit /b 1)
call "%VSDIR%\VC\Auxiliary\Build\vcvarsall.bat" x64 >nul || exit /b 1
cd /d "%~dp0"
rem installs the version from rust-toolchain.toml
rustup toolchain install || exit /b 1
cargo build -q --release --locked --target x86_64-pc-windows-msvc || exit /b 1
if not exist build mkdir build
copy /y target\x86_64-pc-windows-msvc\release\zstd-extract.exe build\zstd-extract-windows-x86_64.exe >nul || exit /b 1
for /f "tokens=2" %%v in ('rustc --version') do set "RUSTC=%%v"
rem the versions that end up in the SBOM
(echo msvc=%VCToolsVersion%& echo ucrt=%UCRTVersion%& echo sdk=%WindowsSDKVersion%& echo rustc=%RUSTC%)> build\zstd-extract-windows-x86_64.exe.toolchain
