#!/usr/bin/env bash
#
# Run Perdita Core's smoke tests against a WordPress that has the Perdita theme
# active and this plugin activated.
#
#   bash tests/run.sh /path/to/wordpress
#   WP_PATH=/path/to/wordpress bash tests/run.sh
#   PHP=/opt/homebrew/bin/php bash tests/run.sh /path/to/wordpress
#
# The suite runs through a generated wrapper that requires wp-load.php, NOT
# through wp-cli. That is deliberate: wp-cli defines WP_CLI, and several
# assertions here are about what is and is not constructed on an ordinary
# front-end request. Loading WordPress this way gives the harness the same
# shape as a page view, which is the only context in which "the updater is not
# built" and "the admin screen is not built" mean anything.
#
# Exits non-zero if any test fails.

set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"
WP_PATH="${1:-${WP_PATH:-}}"

if [ -z "$WP_PATH" ]; then
	echo "usage: bash tests/run.sh /path/to/wordpress" >&2
	exit 1
fi
if [ ! -f "$WP_PATH/wp-load.php" ]; then
	echo "no wp-load.php in $WP_PATH -- point this at the WordPress root" >&2
	exit 1
fi
if ! command -v "$PHP" >/dev/null 2>&1 && [ ! -x "$PHP" ]; then
	echo "no PHP at '$PHP' -- set PHP=/path/to/php" >&2
	exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
wrapper="$work/smoke-run.php"

cat > "$wrapper" <<PHPEOF
<?php
// A plain wp-load.php bootstrap. No WP_CLI, no is_admin(), no REST_REQUEST:
// the same shape as a front-end page view. WordPress falls back to the siteurl
// option for URL building when a CLI process has no HTTP_HOST, which is what
// rest_url() needs here.
require_once '$WP_PATH/wp-load.php';
require '$DIR/smoke.php';
PHPEOF

exec "$PHP" "$wrapper"
