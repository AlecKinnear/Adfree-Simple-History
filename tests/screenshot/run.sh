#!/usr/bin/env bash
# Boot a clean WordPress Playground, populate the curated screenshot events
# via the blueprint, then run Playwright to capture all wordpress.org marketing
# screenshots (screenshot-1.png through -11.png) in a single run. Each spec
# under tests/playwright/screenshot-*.spec.js owns one image. Tears the
# playground down on exit.

set -euo pipefail

PORT="${SCREENSHOT_PORT:-9445}"
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_ROOT"

echo "==> Starting playground on port $PORT..."
npx @wp-playground/cli server \
	--mount=.:/wordpress/wp-content/plugins/simple-history \
	--blueprint=tests/screenshot/blueprint.json \
	--port="$PORT" &
PLAYGROUND_PID=$!

cleanup() {
	echo "==> Stopping playground (pid $PLAYGROUND_PID)..."
	kill "$PLAYGROUND_PID" 2>/dev/null || true
	wait "$PLAYGROUND_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

echo "==> Waiting for playground to come up..."
for i in {1..60}; do
	if curl -sf "http://127.0.0.1:$PORT/wp-login.php" >/dev/null 2>&1; then
		echo "==> Playground ready"
		break
	fi
	if [ "$i" = "60" ]; then
		echo "ERROR: Playground did not become ready in 60s"
		exit 1
	fi
	sleep 1
done

# Give the blueprint runPHP a beat to finish populating events.
sleep 3

echo "==> Capturing screenshots (11 specs)..."
# --workers=1 forces the specs to run sequentially against the single
# playground instance — parallel runs race on the SQLite log and time out.
PLAYWRIGHT_BASE_URL="http://127.0.0.1:$PORT" \
	WP_ADMIN_USER=admin \
	WP_ADMIN_PASSWORD=password \
	npx playwright test --project=screenshot --workers=1

if command -v pngquant >/dev/null 2>&1; then
	echo "==> Optimizing PNGs with pngquant..."
	# --skip-if-larger keeps the original if compression doesn't help.
	# --quality=80-95 is near-imperceptible on UI screenshots, ~60-80% smaller.
	pngquant --skip-if-larger --strip --force --ext .png --quality=80-95 \
		.wordpress-org/screenshot-*.png || true
else
	echo "==> pngquant not installed — skipping optimization (brew install pngquant)"
fi

echo "==> Done. Updated files:"
ls -lh .wordpress-org/screenshot-*.png | awk '{ printf "    %s  %s\n", $5, $NF }'
