#!/usr/bin/bash
# haxcms-php devcontainer start
# -----------------------------------------------------------------------------
# Runs every time the container starts (postStartCommand), including when a
# Codespace resumes after being idled. setup.sh (postCreateCommand) only runs
# once when the container/Codespace is first created, so Apache needs to be
# (re)started here too.
# -----------------------------------------------------------------------------

if [ "$(id -u)" -ne 0 ]; then
  SUDO=sudo
else
  SUDO=
fi

echo ">> Starting Apache"
$SUDO apache2ctl start 2>/dev/null || $SUDO apache2ctl graceful || true
