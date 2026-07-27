#!/usr/bin/env bash
# Rebuild whochanged-demo.zip into this demo/ folder.
# Run from repo root: ./demo/build-demo-zip.sh
# Or from demo/: ./build-demo-zip.sh
set -euo pipefail

DEMO_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$DEMO_DIR/.." && pwd)"
OVERLAY="$ROOT/playground/demo-overlays"
ZIP_PATH="$DEMO_DIR/whochanged-demo.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if [[ ! -d "$OVERLAY" ]]; then
	echo "Missing demo overlays at $OVERLAY" >&2
	exit 1
fi

mkdir -p "$STAGE/whochanged"

rsync -a \
	--exclude '.git' \
	--exclude '.DS_Store' \
	--exclude 'node_modules' \
	--exclude 'vendor' \
	--exclude 'tests' \
	--exclude 'landing-page' \
	--exclude 'demo-testdrive' \
	--exclude 'playground' \
	--exclude 'demo' \
	--exclude 'dist' \
	--exclude 'composer.json' \
	--exclude 'composer.lock' \
	--exclude 'phpcs.xml.dist' \
	--exclude '.distignore' \
	"$ROOT/" "$STAGE/whochanged/"

cp "$OVERLAY/whochanged.php" "$STAGE/whochanged/whochanged.php"
cp "$OVERLAY/includes/class-demo.php" "$STAGE/whochanged/includes/class-demo.php"
cp "$OVERLAY/includes/class-pro.php" "$STAGE/whochanged/includes/class-pro.php"
cp "$OVERLAY/includes/class-admin.php" "$STAGE/whochanged/includes/class-admin.php"
cp "$OVERLAY/includes/class-logger.php" "$STAGE/whochanged/includes/class-logger.php"
cp "$OVERLAY/includes/freemius-bootstrap.php" "$STAGE/whochanged/includes/freemius-bootstrap.php"

php -r '
$path = $argv[1];
$src = file_get_contents($path);
$src = preg_replace("/^\s*\* Plugin Name:.*$/m", " * Plugin Name: WhoChanged Demo", $src, 1);
$src = preg_replace("/^\s*\* Description:.*$/m", " * Description: Public Playground demo — settings disabled, live logging enabled.", $src, 1);
file_put_contents($path, $src);
' "$STAGE/whochanged/whochanged.php"

rm -f "$ZIP_PATH"
(
	cd "$STAGE"
	zip -qr "$ZIP_PATH" whochanged
)

# Keep dist/ copy in sync for local convenience.
mkdir -p "$ROOT/dist"
cp "$ZIP_PATH" "$ROOT/dist/whochanged-demo.zip"

echo "Built: $ZIP_PATH ($(du -h "$ZIP_PATH" | awk '{print $1}'))"
echo "Also copied to: $ROOT/dist/whochanged-demo.zip"
echo "Push this demo/ folder to: git@github.com:thiendo/wordpress-whochanged-plugin.git"
