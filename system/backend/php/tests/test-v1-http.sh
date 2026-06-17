#!/bin/bash
#
# HAXcms PHP v1 API HTTP Integration Tests
#
# Requires: curl, jq (optional), a running web server with HAXcms installed
# Usage: ./test-v1-http.sh [BASE_URL] [ADMIN_USER] [ADMIN_PASS]
# Example: ./test-v1-http.sh http://localhost:8080 admin admin
#

set -euo pipefail

BASE_URL="${1:-http://localhost}"
ADMIN_USER="${2:-admin}"
ADMIN_PASS="${3:-admin}"

PASS=0
FAIL=0

function test_endpoint() {
    local method="$1"
    local path="$2"
    local expected_status="$3"
    local description="$4"
    local extra_curl_args="${5:-}"
    local full_url="${BASE_URL}${path}"

    echo "[TEST] ${method} ${full_url} - ${description}"

    local response
    local http_code
    response=$(curl -s -o /tmp/haxcms_test_response.json -w "%{http_code}" \
        -X "${method}" \
        -H "Accept: application/json" \
        ${extra_curl_args} \
        "${full_url}" || true)
    http_code="${response}"

    if [[ "${http_code}" == "${expected_status}" ]]; then
        echo "  PASS (status: ${http_code})"
        PASS=$((PASS + 1))
    else
        echo "  FAIL (expected: ${expected_status}, got: ${http_code})"
        if [[ -f /tmp/haxcms_test_response.json ]]; then
            echo "  Response: $(cat /tmp/haxcms_test_response.json | head -c 200)"
        fi
        FAIL=$((FAIL + 1))
    fi
}

echo "=========================================="
echo "HAXcms PHP v1 API HTTP Integration Tests"
echo "Base URL: ${BASE_URL}"
echo "=========================================="
echo ""

# 1. Public discovery endpoints (no auth)
test_endpoint "GET" "/system/api/v1" "200" "System API discovery"
test_endpoint "GET" "/system/api/v1/openapi" "200" "System OpenAPI YAML"
test_endpoint "GET" "/system/api/v1/openapi.json" "200" "System OpenAPI JSON"

# 2. Public session endpoints (no auth)
test_endpoint "POST" "/system/api/v1/session/login" "200" "Login endpoint accepts POST"
test_endpoint "POST" "/system/api/v1/session/connection-test" "200" "Connection test endpoint"

# 3. Authenticated endpoints without token (should 401)
test_endpoint "GET" "/system/api/v1/sites" "401" "Sites list without auth returns 401"
test_endpoint "GET" "/system/api/v1/status" "401" "System status without auth returns 401"
test_endpoint "GET" "/system/api/v1/blocks" "401" "Blocks list without auth returns 401"
test_endpoint "GET" "/system/api/v1/themes" "401" "Themes list without auth returns 401"
test_endpoint "GET" "/system/api/v1/skeletons" "401" "Skeletons list without auth returns 401"

# 4. Obtain JWT token via login
echo ""
echo "[TEST] Authenticating as ${ADMIN_USER}..."
AUTH_RESPONSE=$(curl -s -X POST "${BASE_URL}/system/api/v1/session/login" \
    -H "Content-Type: application/json" \
    -d "{\"u\":\"${ADMIN_USER}\",\"p\":\"${ADMIN_PASS}\"}")

# Extract JWT from response (JSON response may vary; try common patterns)
JWT=$(echo "${AUTH_RESPONSE}" | grep -oP '"jwt"\s*:\s*"\K[^"]+' || echo "")
if [[ -z "${JWT}" ]]; then
    JWT=$(echo "${AUTH_RESPONSE}" | grep -oP '"token"\s*:\s*"\K[^"]+' || echo "")
fi

if [[ -z "${JWT}" ]]; then
    echo "  WARNING: Could not extract JWT from login response."
    echo "  Response: ${AUTH_RESPONSE}"
    echo "  Skipping authenticated endpoint tests."
else
    echo "  Authenticated. JWT obtained."

    # 5. Authenticated endpoints with Bearer token
test_endpoint "GET" "/system/api/v1/sites" "200" "Sites list with Bearer token" \
        "-H \"Authorization: Bearer ${JWT}\""
test_endpoint "GET" "/system/api/v1/status" "200" "System status with Bearer token" \
        "-H \"Authorization: Bearer ${JWT}\""
test_endpoint "GET" "/system/api/v1/blocks" "200" "Blocks list with Bearer token" \
        "-H \"Authorization: Bearer ${JWT}\""
test_endpoint "GET" "/system/api/v1/themes" "200" "Themes list with Bearer token" \
        "-H \"Authorization: Bearer ${JWT}\""
test_endpoint "GET" "/system/api/v1/skeletons" "200" "Skeletons list with Bearer token" \
        "-H \"Authorization: Bearer ${JWT}\""

    # 6. Site API discovery (read-only, no auth)
test_endpoint "GET" "/x/api" "200" "Site API discovery"
test_endpoint "GET" "/x/api/openapi.json" "200" "Site OpenAPI JSON"

    # 7. connectionSettings should return v1 paths (no auth)
CONNECTION_SETTINGS=$(curl -s "${BASE_URL}/system/api/v1/session/connection-settings")
    if echo "${CONNECTION_SETTINGS}" | grep -q "system/api/v1/session/login"; then
        echo "  PASS connection-settings contains v1 login path"
        PASS=$((PASS + 1))
    else
        echo "  FAIL connection-settings missing v1 login path"
        FAIL=$((FAIL + 1))
    fi

    if echo "${CONNECTION_SETTINGS}" | grep -q "x/api/v1/content/{idOrSlug}"; then
        echo "  PASS connection-settings contains v1 content path"
        PASS=$((PASS + 1))
    else
        echo "  FAIL connection-settings missing v1 content path"
        FAIL=$((FAIL + 1))
    fi

    # 8. Logout (should succeed with or without token)
    test_endpoint "POST" "/system/api/v1/session/logout" "200" "Logout endpoint" \
        "-H \"Authorization: Bearer ${JWT}\""
fi

# Summary
echo ""
echo "=========================================="
echo "Results: ${PASS} passed, ${FAIL} failed"
echo "=========================================="

if [[ ${FAIL} -gt 0 ]]; then
    exit 1
fi
exit 0
