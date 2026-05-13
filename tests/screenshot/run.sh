#!/usr/bin/env bash
# Boot a clean WordPress Playground, populate the curated screenshot events
# via the blueprint, then run Playwright to capture both
# .wordpress-org/screenshot-1.png (main log view) and
# .wordpress-org/screenshot-10.png (dashboard widget) in a single run.
# Tears the playground down on exit.

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

echo "==> Capturing screenshots (main log + dashboard widget)..."
# --workers=1 forces the two specs to run sequentially against the single
# playground instance — parallel runs race on the SQLite log and time out.
PLAYWRIGHT_BASE_URL="http://127.0.0.1:$PORT" \
	WP_ADMIN_USER=admin \
	WP_ADMIN_PASSWORD=password \
	npx playwright test --project=screenshot --workers=1

echo "==> Done. Updated files:"
echo "    .wordpress-org/screenshot-1.png"
echo "    .wordpress-org/screenshot-10.png"
