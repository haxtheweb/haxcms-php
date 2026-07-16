#!/usr/bin/env bash
# Resolves the DDEV project root from any subdirectory within a DDEV project.
# Walks up the directory tree looking for a .ddev/config.yaml file.
#
# Usage: ./resolve-ddev-root.sh [start-directory]
# Output: JSON with project_root, container_path, relative_path, and project_name
# Exit codes: 0 = found, 1 = not found

set -euo pipefail

CONTAINER_ROOT="/var/www/html"
START_DIR="${1:-$(pwd)}"
CURRENT_DIR="$(cd "$START_DIR" && pwd)"
ORIGINAL_DIR="$CURRENT_DIR"

# Walk up looking for .ddev/config.yaml
while [[ "$CURRENT_DIR" != "/" ]]; do
  if [[ -f "$CURRENT_DIR/.ddev/config.yaml" ]]; then
    RELATIVE_PATH="${ORIGINAL_DIR#"$CURRENT_DIR"}"
    RELATIVE_PATH="${RELATIVE_PATH#/}"

    if [[ -n "$RELATIVE_PATH" ]]; then
      CONTAINER_PATH="${CONTAINER_ROOT}/${RELATIVE_PATH}"
    else
      CONTAINER_PATH="$CONTAINER_ROOT"
    fi

    # Extract project name from config.yaml
    PROJECT_NAME=""
    if command -v grep &>/dev/null; then
      PROJECT_NAME=$(grep -E '^name:\s*' "$CURRENT_DIR/.ddev/config.yaml" | head -1 | sed 's/^name:[[:space:]]*//' | tr -d '"' | tr -d "'" | xargs)
    fi

    # Fallback to directory name
    if [[ -z "$PROJECT_NAME" ]]; then
      PROJECT_NAME=$(basename "$CURRENT_DIR")
    fi

    cat <<EOF
{
  "project_root": "$CURRENT_DIR",
  "container_path": "$CONTAINER_PATH",
  "relative_path": "$RELATIVE_PATH",
  "project_name": "$PROJECT_NAME"
}
EOF
    exit 0
  fi
  CURRENT_DIR="$(dirname "$CURRENT_DIR")"
done

echo '{"error": "No DDEV project found in parent directories"}' >&2
exit 1
