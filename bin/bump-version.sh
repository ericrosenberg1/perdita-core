#!/usr/bin/env bash
# The Perdita theme, Perdita Core, and Perdita Pro share one version number and
# bump together. The real script lives in the theme repo, next to this
# checkout; this wrapper only finds it.
real="$(cd "$(dirname "$0")/../.." && pwd)/perdita/bin/bump-version.sh"
[ -x "$real" ] || { printf 'bump-version: expected the theme checkout at %s\n' "$(dirname "$real")/.." >&2; exit 1; }
exec "$real" "$@"
