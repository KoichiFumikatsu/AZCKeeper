#!/usr/bin/env bash
# ════════════════════════════════════════════
# AZCKeeper Build Script — Linux
# Equivalente a build-release.bat para Fumilinux.
# Requisitos: dotnet-sdk-8.0, zip.
# Uso: ./build-release.sh [version]
#   Ej: ./build-release.sh 3.9.0.0
# ════════════════════════════════════════════
set -euo pipefail

# Forzar SDK de Microsoft (incluye Microsoft.NET.Sdk.WindowsDesktop).
# El SDK del apt de Ubuntu es subset y NO trae WindowsDesktop → no compila WinForms.
export PATH="/home/kelsie/.dotnet:$PATH"
export DOTNET_ROOT="/home/kelsie/.dotnet"

VERSION="${1:-3.9.0.0}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="$SCRIPT_DIR/build"
CONFIG="Release"
RUNTIME="win-x64"

echo "╔════════════════════════════════════════╗"
echo "║  AZCKeeper Build (Linux)               ║"
echo "║  VERSION = $VERSION                    "
echo "╚════════════════════════════════════════╝"

# [1/5] Limpiar SOLO los intermediarios (updater/ + package/), preservando los
#       ZIPs ya generados de versiones previas. Útil para encadenar
#       varias versiones (v3.0.2.0, v3.0.2.1, ...) en un mismo build/.
echo ""
echo "[1/5] Limpiando intermediarios..."
rm -rf "$BUILD_DIR/updater" "$BUILD_DIR/package"
mkdir -p "$BUILD_DIR/updater" "$BUILD_DIR/package"

# [2/5] Updater (self-contained single-file trimmed)
echo ""
echo "[2/5] Compilando Updater..."
cd "$SCRIPT_DIR/AZCKeeperUpdater"
dotnet publish \
  -c "$CONFIG" \
  -r "$RUNTIME" \
  --self-contained true \
  -p:PublishSingleFile=true \
  -p:PublishTrimmed=true \
  -p:Version="$VERSION" \
  -o "$BUILD_DIR/updater" \
  --nologo -v minimal

# [3/5] Cliente (self-contained, multi-file, ReadyToRun)
#       -p:Version es CRÍTICO — el cliente lee Assembly.GetName().Version
#       en ConfigManager.SyncVersionFromAssembly() y lo reporta al backend
#       como keeper_devices.client_version. Sin esto, queda en 1.0.0.0.
echo ""
echo "[3/5] Compilando Cliente..."
cd "$SCRIPT_DIR/AZCKeeper_Client"
dotnet publish \
  -c "$CONFIG" \
  -r "$RUNTIME" \
  --self-contained true \
  -p:PublishSingleFile=false \
  -p:PublishReadyToRun=true \
  -p:PublishTrimmed=false \
  -p:EnableWindowsTargeting=true \
  -p:Version="$VERSION" \
  -o "$BUILD_DIR/package" \
  --nologo -v minimal

# [4/5] Empaquetar: copiar updater + install.bat, limpiar pdb
echo ""
echo "[4/5] Preparando paquete..."
cp "$BUILD_DIR/updater/AZCKeeperUpdater.exe" "$BUILD_DIR/package/"
cp "$SCRIPT_DIR/install.bat" "$BUILD_DIR/package/"
cp "$SCRIPT_DIR/azc-killer.ps1" "$BUILD_DIR/package/"
find "$BUILD_DIR/package" -name '*.pdb' -delete

# [5/5] ZIP
echo ""
echo "[5/5] Comprimiendo..."
cd "$BUILD_DIR/package"
ZIP_NAME="AZCKeeper_v${VERSION}.zip"
zip -r9 "../$ZIP_NAME" . > /dev/null
cd "$SCRIPT_DIR"

ZIP_PATH="$BUILD_DIR/$ZIP_NAME"
SIZE_BYTES=$(stat -c '%s' "$ZIP_PATH")
SIZE_MB=$(awk "BEGIN {printf \"%.2f\", $SIZE_BYTES / 1048576}")

echo ""
echo "╔════════════════════════════════════════╗"
echo "║  ✓ Build OK                            ║"
echo "╚════════════════════════════════════════╝"
echo "  Archivo: $ZIP_PATH"
echo "  Tamaño:  $SIZE_MB MB  ($SIZE_BYTES bytes)"
echo ""
echo "Para crear release en GitHub:"
echo "  gh release create v${VERSION} \"$ZIP_PATH\" \\"
echo "    --title \"v${VERSION}\" --notes-file release-notes.md"
