#!/usr/bin/env bash
#
# Build a publishable Perdita Core plugin zip and its update manifest.
#
# Adapted from the theme's bin/build-release.sh, and it exists for the same
# reason: a build cut from somebody's working tree once shipped code that was
# not committed until six days later, and a security fix sat on main for a day
# while users kept downloading the vulnerable build. This script packages
# `git archive HEAD`, so the artifact can only ever contain committed code,
# and it refuses to run on a dirty tree.
#
# Usage:
#   bin/build-release.sh [output-dir]            (default: ./dist)
#   bin/build-release.sh --wporg [output-dir]
#
# --wporg strips inc/class-perdita-core-updater.php from the staged tree, for
# the wordpress.org build: a plugin in the directory takes its updates from
# the directory, and a second updater bolted on top of that is both redundant
# and a guidelines violation. That build gets its own filename and no
# manifest, so it can never be mistaken for the self-hosted artifact or
# checked against its checksum.
#
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

# Parsed without arrays on purpose: macOS still ships bash 3.2, where
# "${args[0]:-default}" on an empty array is an unbound-variable error under
# `set -u`.
wporg=0
out_dir=""
for arg in "$@"; do
	case "$arg" in
		--wporg) wporg=1 ;;
		-*) printf '\033[31merror:\033[0m unknown flag %s\n' "$arg" >&2; exit 1 ;;
		*) out_dir="$arg" ;;
	esac
done
[ -n "$out_dir" ] || out_dir="$repo_root/dist"

slug="perdita-core"
update_host="perdita.ericrosenberg.com"

# Paths that are development-only and must not reach a user's site.
EXCLUDE_TOP=(
	.gitignore
	README.md
)
EXCLUDE_DIRS=(bin tests dist)

die() { printf '\033[31merror:\033[0m %s\n' "$1" >&2; exit 1; }
ok()  { printf '\033[32m  ok\033[0m %s\n' "$1"; }

# Clear any previous artifacts BEFORE the gates run. Publishing is a separate
# step, so a failed build that left last run's zip and manifest sitting in
# dist/ under the same version name is a loaded gun: both files are internally
# consistent and the checksum matches, so nothing downstream can tell that the
# artifact does not correspond to the code.
# Each build only clears its own artifacts, so making a wordpress.org zip never
# deletes the self-hosted zip and manifest that still describe a live release.
mkdir -p "$out_dir"
if [ "$wporg" = "1" ]; then
	rm -f "$out_dir"/${slug}-*-wporg.zip
else
	find "$out_dir" -maxdepth 1 -name "${slug}-*.zip" ! -name '*-wporg.zip' -delete 2>/dev/null || true
	rm -f "$out_dir"/${slug}.json
fi

# --- 1. refuse to build anything that is not committed -----------------------
git rev-parse --verify HEAD >/dev/null 2>&1 \
	|| die "no commits yet. The zip is built from HEAD, so there is nothing to package."
git diff --quiet HEAD -- 2>/dev/null \
	|| die "working tree is dirty. Commit first -- the zip is built from HEAD, so uncommitted changes would silently NOT ship."
ok "working tree clean"

# --- 2. version must agree in both places it is declared ---------------------
version="$(git show HEAD:${slug}.php | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//')"
[ -n "$version" ] || die "could not read the Version header from ${slug}.php"
const_version="$(git show HEAD:${slug}.php | sed -n "s/.*PERDITA_CORE_VERSION'[[:space:]]*,[[:space:]]*'\([^']*\)'.*/\1/p" | head -1)"
[ "$version" = "$const_version" ] \
	|| die "version drift: the plugin header says '$version' but PERDITA_CORE_VERSION says '$const_version'"
ok "version $version (plugin header and PERDITA_CORE_VERSION agree)"

# --- 2c. lock step with the sibling checkouts --------------------------------
# The theme, Perdita Core, and Perdita Pro ship as one release under one
# version number, and each warns in wp-admin when the installed versions
# differ. A build of one piece at a version the others do not share is a
# release that warns on every site it reaches. bin/bump-version.sh (theme
# repo) moves all of them at once; this refuses to package anything else.
for sibling in perdita perdita-core perdita-pro; do
	sibling_dir="$(cd "$repo_root/../$sibling" 2>/dev/null && pwd || true)"
	if [ -z "$sibling_dir" ] || [ "$sibling_dir" = "$repo_root" ] || [ ! -d "$sibling_dir/.git" ]; then
		continue
	fi
	if [ "$sibling" = perdita ]; then
		sibling_version="$(git -C "$sibling_dir" show HEAD:style.css 2>/dev/null | sed -n 's/^[[:space:]]*Version:[[:space:]]*//p' | head -1 | tr -d '\r')"
	else
		sibling_version="$(git -C "$sibling_dir" show "HEAD:$sibling.php" 2>/dev/null | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//')"
	fi
	[ "$sibling_version" = "$version" ] \
		|| die "lock step: $sibling is at '$sibling_version' (committed) but this build is '$version'. Run bin/bump-version.sh $version and commit every repo."
	ok "lock step: $sibling is also $version"
done

# --- 2b. a version number may name exactly one artifact ----------------------
# Perdita_Core_Updater offers an update only on version_compare(manifest,
# installed, '>'), so re-publishing the same version with different bytes
# silently reaches nobody who already has it. Refuse to build that. Skipped
# for --wporg, which publishes nothing to the update server.
if [ "$wporg" != "1" ] && command -v curl >/dev/null 2>&1; then
	live="$(curl -fsS --max-time 20 "https://${update_host}/updates/${slug}.json" 2>/dev/null || true)"
	if [ -n "$live" ]; then
		live_version="$(printf '%s' "$live" | sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
		if [ "$live_version" = "$version" ]; then
			PUBLISHED_CHECK="$version"
			ok "live manifest already advertises $version -- will verify bytes match before finishing"
		fi
	else
		printf '\033[33m  warn\033[0m could not reach the update server; skipped already-published check\n'
	fi
fi

# --- 2c. no mutable artifact filenames on the update server ------------------
# This script only ever emits ${slug}-<version>.zip. The theme once had a
# hand-cut perdita-latest.zip published alongside the versioned zips, left
# holding an aborted build after its replacement shipped, so one version
# string named three different artifacts and stopped being usable to tell a
# patched deployment from an unpatched one. /updates is served with a
# ten-year max-age, so whatever a mutable name holds at the next edge purge is
# pinned for a decade.
#
# Warn rather than die: this is a condition on the server, not in the build, so
# it must not stand between an urgent security fix and a release.
if [ "$wporg" != "1" ] && command -v curl >/dev/null 2>&1; then
	stray="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 20 \
		"https://${update_host}/updates/${slug}-latest.zip" 2>/dev/null || true)"
	if [ "$stray" = "200" ]; then
		printf '\033[33m  warn\033[0m %s-latest.zip is publicly reachable (HTTP 200).\n' "$slug"
		printf '        Nothing generates it -- it must not be published. If it was just\n'
		printf '        removed at origin, this is the CDN edge still serving it and the\n'
		printf '        URL needs a purge. Publish only versioned zips.\n'
	else
		ok "${slug}-latest.zip is not publicly reachable (HTTP ${stray:-none})"
	fi
fi

# --- 3. stage a tree straight out of the commit ------------------------------
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/$slug"
git archive HEAD | tar -x -C "$work/$slug"
for p in "${EXCLUDE_TOP[@]}";  do rm -f  "$work/$slug/$p"; done
for d in "${EXCLUDE_DIRS[@]}"; do rm -rf "$work/$slug/$d"; done

if [ "$wporg" = "1" ]; then
	rm -f "$work/$slug/inc/class-perdita-core-updater.php"
	[ ! -f "$work/$slug/inc/class-perdita-core-updater.php" ] || die "--wporg: the self-hosted updater is still in the staged tree"
	ok "--wporg: self-hosted updater removed from the staged tree"
	# Every remaining reference to the class must sit next to a file_exists()
	# guard, or a directory install would fatal on the first admin page.
	while IFS= read -r f; do
		grep -q 'file_exists' "$f" || die "--wporg: $f references Perdita_Core_Updater without a file_exists() guard"
	done < <(grep -rl 'Perdita_Core_Updater' "$work/$slug" --include='*.php' || true)
	ok "--wporg: every Perdita_Core_Updater reference is file_exists()-guarded"
fi

# git archive stamps every file with the commit date, but the directories --
# the top-level plugin folder that mkdir just made, and anything tar recreated
# -- get the current time, and zip records directory entries too. That is a
# two-byte difference in the DOS seconds field, invisible to `unzip -lv`
# because its listing is only minute-granular, and it is enough to change the
# sha256 on every build.
#
# Pin to a FIXED epoch, not to the commit time. Pinning to the commit time
# makes the checksum depend on which commit you happen to be standing on, so a
# later commit that does not touch a single packaged file (a README edit, say,
# which is excluded from the zip) still changes the artifact. With a fixed
# epoch the zip is a pure function of the packaged tree, which is the property
# that actually lets someone verify a published checksum against source.
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-315532800}" # 1980-01-01, the zip format's floor
# BSD touch (macOS) has no -d "@epoch"; -t is understood by both BSD and GNU.
stamp="$(date -u -r "$SOURCE_DATE_EPOCH" +%Y%m%d%H%M.%S 2>/dev/null || date -u -d "@$SOURCE_DATE_EPOCH" +%Y%m%d%H%M.%S)"
find "$work" -exec touch -t "$stamp" {} +
ok "staged $(find "$work/$slug" -type f | wc -l | tr -d ' ') files from $(git rev-parse --short HEAD)"

# --- 4. release gates on the staged tree, not on the repo --------------------
# Anything here is a thing that has actually shipped broken before.

# 4a. every secret-wiping call must be capability-guarded. sodium_compat makes
#     function_exists() true without the extension, so the guard has to be
#     extension_loaded(), and this checks the file we are about to hand to
#     users rather than the one in the repo.
while IFS= read -r f; do
	awk -v file="$f" '
		/extension_loaded\([[:space:]]*.sodium./ { guard = NR }
		/sodium_memzero\(/ {
			if (guard == 0 || NR - guard > 4) {
				printf "%s:%d: unguarded sodium_memzero()\n", file, NR
				bad = 1
			}
		}
		END { exit bad }
	' "$f" || die "release gate failed: unguarded sodium_memzero() in the packaged tree"
done < <(grep -rl 'sodium_memzero' "$work/$slug" --include='*.php' || true)
ok "no unguarded sodium_memzero() in packaged tree"

# 4b. no debugging output in a shipped build. var_dump()/print_r()/var_export()
#     print straight into a live page, and error_log() fills a customer's disk
#     with lines only we can read. Every one of these belongs in a local
#     session, never in an artifact.
if grep -rn --include='*.php' -E '\b(var_dump|var_export|print_r)[[:space:]]*\(|\berror_log[[:space:]]*\(' "$work/$slug" >/dev/null 2>&1; then
	grep -rn --include='*.php' -E '\b(var_dump|var_export|print_r)[[:space:]]*\(|\berror_log[[:space:]]*\(' "$work/$slug" | sed 's|^'"$work"'/|    |'
	die "release gate failed: debugging output in the packaged tree"
fi
if grep -rn --include='*.js' -E '\bconsole\.(log|debug)[[:space:]]*\(' "$work/$slug" >/dev/null 2>&1; then
	grep -rn --include='*.js' -E '\bconsole\.(log|debug)[[:space:]]*\(' "$work/$slug" | sed 's|^'"$work"'/|    |'
	die "release gate failed: console.log() in the packaged tree"
fi
ok "no debugging output in packaged tree"

# 4c. stale version strings in user-facing docs sent a reviewer to the wrong
#     zip once. Warn loudly rather than block the build.
if grep -rn --include='*.md' --include='*.pot' -E '[0-9]+\.[0-9]+\.[0-9]+(-[a-z]+)?' "$work/$slug" \
	| grep -v "$version" >/dev/null 2>&1; then
	printf '\033[33m  warn\033[0m version strings not matching %s in packaged docs:\n' "$version"
	grep -rn --include='*.md' --include='*.pot' -E '[0-9]+\.[0-9]+\.[0-9]+(-[a-z]+)?' "$work/$slug" \
		| grep -v "$version" | sed 's|^'"$work"'/|    |'
fi
# readme.txt lists older versions in its Changelog on purpose; only the Stable
# tag has to agree with the plugin header, and wordpress.org rejects a mismatch.
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$work/$slug/readme.txt" | head -1 | tr -d '\r')"
[ "$stable" = "$version" ] || die "readme.txt Stable tag is '$stable' but the plugin header Version is '$version'"
ok "readme.txt Stable tag matches"

# 4d. syntax-check everything we are about to ship, if a PHP is available.
if command -v php >/dev/null 2>&1; then
	n=0
	while IFS= read -r f; do
		php -l "$f" >/dev/null || die "PHP syntax error in $f"
		n=$((n + 1))
	done < <(find "$work/$slug" -name '*.php')
	ok "php -l clean across $n files"
else
	printf '\033[33m  warn\033[0m no php on PATH -- skipped syntax check\n'
fi

# 4e. a plugin WordPress will refuse to install, or wordpress.org will refuse
#     to list, is not a release.
for required in "${slug}.php" readme.txt uninstall.php; do
	[ -f "$work/$slug/$required" ] || die "packaged tree is missing $required"
done
grep -q '^[[:space:]]*\*[[:space:]]*Plugin Name:' "$work/$slug/${slug}.php" \
	|| die "packaged ${slug}.php has no Plugin Name header"
ok "required plugin files present"

# --- 5. emit the zip and, for the self-hosted build, its manifest ------------
if [ "$wporg" = "1" ]; then
	zip_name="${slug}-${version}-wporg.zip"
else
	zip_name="${slug}-${version}.zip"
fi
# -X drops the "extended timestamp" extra field, which carries each file's
# atime/ctime. Those change on every extraction, so without -X the zip has a
# different sha256 on every build even when the contents are byte-identical.
( cd "$work" && zip -qrX "$out_dir/$zip_name" "$slug" -x '.*' )
checksum="$( (sha256sum "$out_dir/$zip_name" 2>/dev/null || shasum -a 256 "$out_dir/$zip_name") | cut -d' ' -f1)"

if [ "$wporg" = "1" ]; then
	printf '\n\033[32mbuilt\033[0m %s\n  sha256 %s\n' "$out_dir/$zip_name" "$checksum"
	printf '\nThis is the wordpress.org build: no updater, and no manifest on purpose.\n'
	printf 'Do not publish it to /updates -- the self-hosted updater would install it\nand leave those sites with no update path at all.\n'
	exit 0
fi

# Same version already live? Then the bytes must be identical, or this build is
# an invisible release: the updater will not offer it to anyone who has it.
if [ -n "${PUBLISHED_CHECK:-}" ]; then
	live_checksum="$(printf '%s' "$live" | sed -n 's/.*"checksum"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
	if [ "$live_checksum" != "$checksum" ]; then
		rm -f "$out_dir/$zip_name"
		die "$version is already published with a different checksum.
    published: $live_checksum
    this build: $checksum
  Re-publishing one version with different bytes reaches nobody who already has
  it (version_compare requires strictly greater). Bump the version instead."
	fi
	ok "$version already published and byte-identical -- nothing to do"
fi

# Read the compatibility headers from the packaged readme.txt so the manifest
# can never drift from what the plugin itself declares.
hdr() { sed -n "s/^$1:[[:space:]]*//p" "$work/$slug/readme.txt" | head -1 | tr -d '\r'; }
requires="$(hdr 'Requires at least')"; requires_php="$(hdr 'Requires PHP')"; tested="$(hdr 'Tested up to')"
[ -n "$requires" ] && [ -n "$requires_php" ] && [ -n "$tested" ] \
	|| die "readme.txt is missing a Requires at least / Requires PHP / Tested up to header"

cat > "$out_dir/${slug}.json" <<EOF
{
  "name": "Perdita Core", "version": "${version}",
  "download_url": "https://${update_host}/updates/${zip_name}",
  "checksum": "${checksum}",
  "requires": "${requires}", "requires_php": "${requires_php}", "tested": "${tested}",
  "url": "https://${update_host}", "last_updated": "$(git show -s --format=%cd --date=short HEAD)"
}
EOF

printf '\n\033[32mbuilt\033[0m %s\n  sha256 %s\n  manifest %s\n' \
	"$out_dir/$zip_name" "$checksum" "$out_dir/${slug}.json"
printf '\nPublish with ~/Code/perdita/bin/publish-release.sh (uploads the zip, then the\nmanifest, then proves the live channel names this build and serves these bytes):\n'
printf '  ~/Code/perdita/bin/publish-release.sh %s\n' "$out_dir"
printf '\nOr by hand (order matters -- zip first, manifest last, or the\nupdater will fail checksum verification for every user in between), then run\n~/Code/perdita/bin/publish-release.sh --check:\n'
printf '  scp %s cloudpanel:/tmp/ && ssh cloudpanel "sudo install -o perdita -g perdita -m 644 /tmp/%s %s/"\n' \
	"$out_dir/$zip_name" "$zip_name" "/home/perdita/htdocs/${update_host}/updates"
printf '  ...then the same for %s.json\n' "$slug"
