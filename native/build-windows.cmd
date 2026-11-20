@echo off
rem Builds build\zstd-extract-windows-x86_64.exe with MSVC (statically linked CRT).
setlocal
set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"
for /f "usebackq tokens=*" %%i in (`"%VSWHERE%" -latest -products * -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64 -property installationPath`) do set "VSDIR=%%i"
if not defined VSDIR (echo Visual Studio with C++ tools not found & exit /b 1)
call "%VSDIR%\VC\Auxiliary\Build\vcvarsall.bat" x64 >nul || exit /b 1
cd /d "%~dp0"
if not exist build mkdir build
cl /nologo /O2 /MT /W3 /GS /guard:cf /utf-8 /Brepro /Izstd /DZSTD_LEGACY_SUPPORT=0 /DZSTD_MULTITHREAD=0 /DXXH_NAMESPACE=ZSTD_ /DZSTD_NO_TRACE /DDEBUGLEVEL=0 /DZSTD_DISABLE_ASM /D_CRT_SECURE_NO_WARNINGS src\main.c zstd\common\*.c zstd\decompress\*.c /Fobuild\ /Fe:build\zstd-extract-windows-x86_64.exe /link /RELEASE /Brepro /DYNAMICBASE /HIGHENTROPYVA /NXCOMPAT /guard:cf || exit /b 1
del build\*.obj
rem the versions that end up in the SBOM
(echo msvc=%VCToolsVersion%& echo ucrt=%UCRTVersion%& echo sdk=%WindowsSDKVersion%)> build\zstd-extract-windows-x86_64.exe.toolchain
