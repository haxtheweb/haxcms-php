#!/usr/bin/env bash
# Integration test for the HAXcms installer precondition-test Docker image.
#
# Builds Dockerfile.precondition-test, starts it, fetches install.php's Step 1
# status report, and asserts that the expected missing-precondition warnings
# are present. Exits non-zero if any assertion fails.
#
# Usage: bash tests/docker-precondition-test.sh
# Requires: docker, curl, grep
#
# This is the test housing requested in haxtheweb/issues#2974.

set -u

IMAGE_TAG="haxcms-precondition-test:local"
CONTAINER_NAME="haxcms-precondition-test-run"
HOST_PORT="${HAXCMS_PRECONDITION_TEST_PORT:-8085}"
START_DIR="$(cd "$(dirname "$0")/.." && pwd)"

fail() {
  echo "FAIL: $1" >&2
  if [ -n "${CONTAINER_ID:-}" ]; then
    docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
  fi
  exit 1
}

pass() {
  echo "PASS: $1"
}

echo "Building precondition-test image from $START_DIR/Dockerfile.precondition-test ..."
docker build -f "$START_DIR/Dockerfile.precondition-test" -t "$IMAGE_TAG" "$START_DIR" >/dev/null 2>&1 || fail "docker build failed"

echo "Starting container on port $HOST_PORT ..."
docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
CONTAINER_ID=$(docker run -d --rm --name "$CONTAINER_NAME" -p "$HOST_PORT:80" "$IMAGE_TAG")
[ -n "$CONTAINER_ID" ] || fail "docker run did not return a container id"

# Wait for Apache to come up (poll the installer endpoint).
echo "Waiting for Apache to respond ..."
READY=0
for i in $(seq 1 30); do
  if curl --silent --fail --max-time 3 "http://localhost:$HOST_PORT/install.php" >/dev/null 2>&1; then
    READY=1
    break
  fi
  sleep 1
done
[ "$READY" -eq 1 ] || fail "Apache did not become reachable on port $HOST_PORT"

echo "Fetching installer Step 1 status report ..."
BODY=$(curl --silent --max-time 10 "http://localhost:$HOST_PORT/install.php")
[ -n "$BODY" ] || fail "Empty response from install.php"

# Assertion 1: PHP curl extension reported as Missing (error tone).
echo "$BODY" | grep -qi 'PHP curl extension' || fail "status table missing 'PHP curl extension' row"
echo "$BODY" | grep -qi 'Missing' || fail "curl extension row did not report 'Missing'"
# The error-tone row gets the status-tone-error class on its <tr>.
echo "$BODY" | grep -q 'status-tone-error' || fail "no error-tone rows present in status report"
pass "PHP curl extension reported as Missing (error)"

# Assertion 2: Git availability reported as Not detected (warning tone).
echo "$BODY" | grep -qi 'Git availability' || fail "status table missing 'Git availability' row"
echo "$BODY" | grep -qi 'Not detected' || fail "git row did not report 'Not detected'"
pass "Git availability reported as Not detected (warning)"

# Assertion 3: directory writability check surfaces a Read-only error.
echo "$BODY" | grep -qi 'Read-only' || fail "no 'Read-only' directory status present"
pass "Directory writability reported as Read-only (error)"

# Assertion 4: the stepped wizard Step 1 welcome UI is present.
echo "$BODY" | grep -qi 'Welcome to HAXcms' || fail "Step 1 'Welcome to HAXcms' heading not present"
pass "Stepped wizard Step 1 welcome UI present"

# Assertion 5: the step indicator is rendered.
echo "$BODY" | grep -q 'step-indicator' || fail "step indicator markup not present"
pass "Step indicator rendered"

# Assertion 6: the Continue button to Step 2 is present.
echo "$BODY" | grep -qi 'Continue' || fail "Continue button to Step 2 not present"
pass "Continue button to Step 2 present"

# --- Security (SEC-DEPLOY): Apache hardening + installer API gate ----------
# The image ships scripts/docker/apache2.conf; these assertions prove its
# .htaccess files are honoured (AllowOverride All), directory listings are off
# and _config is never web-readable.

# Assertion 7: the system API rewrite works and the API refuses to run while
# HAXcms is not installed (no _config/config.php yet).
API_CODE=$(curl --silent --max-time 10 -o /tmp/haxcms-precondition-api.$$ -w '%{http_code}' "http://localhost:$HOST_PORT/system/api/v1/session/connection-settings")
API_BODY=$(cat /tmp/haxcms-precondition-api.$$ 2>/dev/null); rm -f /tmp/haxcms-precondition-api.$$
[ "$API_CODE" = "503" ] || fail "system API returned HTTP $API_CODE before install (expected 503)"
echo "$API_BODY" | grep -q 'HAXcms is not installed' || fail "system API 503 body missing 'HAXcms is not installed'"
pass "System API rewrite works and is gated (503) until installed"

# Plant a fake secret in _config and an index-less directory inside the
# running container (the webroot is read-only for www-data, not for root).
SENTINEL="haxcms-precondition-salt-$$-$RANDOM"
docker exec "$CONTAINER_NAME" sh -c "mkdir -p /var/www/html/_config /var/www/html/zz-listing-probe && printf '%s' '$SENTINEL' > /var/www/html/_config/SALT.txt && printf probe > /var/www/html/zz-listing-probe/probe.txt" \
  || fail "could not plant _config/SALT.txt fixture in the container"

# Assertion 8: _config/SALT.txt is not web-readable.
SALT_CODE=$(curl --silent --max-time 10 -o /tmp/haxcms-precondition-salt.$$ -w '%{http_code}' "http://localhost:$HOST_PORT/_config/SALT.txt")
if grep -q "$SENTINEL" /tmp/haxcms-precondition-salt.$$ 2>/dev/null; then
  rm -f /tmp/haxcms-precondition-salt.$$
  fail "_config/SALT.txt contents were served over HTTP"
fi
rm -f /tmp/haxcms-precondition-salt.$$
[ "$SALT_CODE" = "403" ] || fail "_config/SALT.txt returned HTTP $SALT_CODE (expected 403)"
pass "_config/SALT.txt is not web-readable (403)"

# Assertion 9: directory listing is off (both _config/ and an index-less dir).
for DIR_PATH in /_config/ /zz-listing-probe/; do
  DIR_BODY=$(curl --silent --max-time 10 "http://localhost:$HOST_PORT$DIR_PATH")
  echo "$DIR_BODY" | grep -qi 'Index of' && fail "directory listing served for $DIR_PATH"
done
pass "Directory listing is disabled"

echo ""
echo "All precondition-test assertions passed."
docker rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
exit 0
