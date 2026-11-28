#!/bin/bash
# Copy the app from this repo checkout into the website. Run by cPanel (see
# .cpanel.yml); see deploy/README.md for the whole process.
#
#   APP_DEST   where the app goes        (default ~/public_html/overworld)
#   DATA_DEST  the shared data folder    (default ~/public_html/overworld-data)
#
# Files are copied, never deleted, so anything uploaded by hand stays put; files
# that should go are listed in to-delete.txt (see RETIRED below) for you to remove.
# config.json is created from config.example.json on the first deploy and never
# overwritten after, so edit it on the server. The data folder only gets the listing script and its
# .htaccess; data files themselves are uploaded with the cPanel File Manager.
set -euo pipefail
cd "$(dirname "$0")/.."

APP_DEST=${APP_DEST:-$HOME/public_html/overworld}
DATA_DEST=${DATA_DEST:-$HOME/public_html/overworld-data}

# Files earlier deploys (or old setups) left on the server that are no longer
# used. Nothing is deleted: whichever still exist are written to to-delete.txt
# in the repo checkout, to remove by hand (File Manager). Safe to trim later.
RETIRED=("$APP_DEST/layers/example.json" "$APP_DEST/layers/index.json"
         "$APP_DEST/data/samples/_files.json"
         "$APP_DEST/data/samples/austin/LFL-from-littlefreelibrary.org-map.geojson"
         "$APP_DEST/data/samples/austin/aisd-schools-v12-202601.xml"
         "$APP_DEST/manifest.json" "$APP_DEST/favicon.png" "$APP_DEST/icon-48.png" "$APP_DEST/icon-96.png"
         "$APP_DEST/icon-192.png" "$APP_DEST/icon-512.png"   # moved to assets/
         "$APP_DEST/config.local.json"     # no longer read: move its keys into config.json first
         "$DATA_DEST/layers/example.json") # a copy of the samples' layer set

mkdir -p "$APP_DEST/api" "$APP_DEST/status" "$DATA_DEST"

found=()
for p in "${RETIRED[@]}"; do [ -e "$p" ] && found+=("$p"); done
if [ ${#found[@]} -gt 0 ]; then
  printf '%s\n' "${found[@]}" > to-delete.txt
  echo "${#found[@]} unused file(s) on the server; see $(pwd)/to-delete.txt"
else
  rm -f to-delete.txt
fi

# app shell
mkdir -p "$APP_DEST/assets"
cp index.html sw.js "$APP_DEST/"
cp assets/manifest.json assets/favicon.png assets/icon-*.png "$APP_DEST/assets/"
cp deploy/app.htaccess "$APP_DEST/.htaccess"
cp deploy/api/route.php "$APP_DEST/api/"

# per-server settings: created once, then edited on the server
if [ ! -e "$APP_DEST/config.json" ]; then
  cp config.example.json "$APP_DEST/config.json"
  echo "created $APP_DEST/config.json from config.example.json; edit it there"
fi

# shared data folder: dynamic _files.json
cp deploy/data/_files.php "$DATA_DEST/"
cp deploy/data/data.htaccess "$DATA_DEST/.htaccess"

# status page at /overworld/status; it refuses to run until Directory Privacy protects
# its folder (which keeps its own .htaccess there, untouched by deploys). Copied
# without the #! line, which PHP 7.2 would print at the top of the page.
sed '1{/^#!/d;}' tools/status.php > "$APP_DEST/status/index.php"

echo "deployed $(git rev-parse --short HEAD 2>/dev/null || echo '?') to $APP_DEST"
