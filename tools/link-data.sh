#!/bin/sh
# Link a data root into the app so the web server can reach it.
#
# Usage:  tools/link-data.sh NAME TARGET
#   e.g.  tools/link-data.sh sync  ~/Sync/m2/web/overworld-data
#         tools/link-data.sh local /data/overworld
#
# Creates data/NAME -> TARGET. The link is gitignored, and if the repo is
# inside Dropbox it is marked ignored there too, so each machine keeps its own.
set -e
[ $# -eq 2 ] || { sed -n '2,10p' "$0"; exit 1; }
cd "$(dirname "$0")/.."
name=$1; target=$2

[ -d "$target" ] || { echo "no such directory: $target"; exit 1; }
ln -sfn "$target" "data/$name"
case "$(pwd -P)" in
  *Dropbox*) xattr -w com.dropbox.ignored 1 "data/$name" 2>/dev/null || true ;;
esac
echo "data/$name -> $target"
