#!/bin/bash
# Copy the app from this repo checkout into the website. Run by cPanel (see
# .cpanel.yml); see deploy/README.md for the whole process.
#
#   APP_DEST   where the app goes        (default ~/public_html/overworld)
#   DATA_DEST  the shared data folder    (default ~/public_html/overworld-data)
#
# Files are copied, never deleted, so anything uploaded by hand stays put (the
# one exception is RETIRED below). config.json is created from
# deploy/config.example.json on the first deploy and never overwritten after, so
# edit it on the server. The data folder only gets the listing script and its
# .htaccess; data files themselves are uploaded with the cPanel File Manager.
set -euo pipefail
cd "$(dirname "$0")/.."

APP_DEST=${APP_DEST:-$HOME/public_html/overworld}
DATA_DEST=${DATA_DEST:-$HOME/public_html/overworld-data}

# Files earlier deploys installed that the app no longer uses: exactly those
# files, then their folders if that leaves them empty, so anything else put
# there by hand survives. Never anything in the data folder. Safe to drop later.
RETIRED=(layers/example.json layers/index.json
         data/samples/_files.json data/samples/austin/LFL-from-littlefreelibrary.org-map.geojson
         data/samples/austin/aisd-schools-v12-202601.xml)

mkdir -p "$APP_DEST/api" "$APP_DEST/status" "$DATA_DEST"

for p in "${RETIRED[@]}"; do
  if [ -f "$APP_DEST/$p" ]; then rm -f "$APP_DEST/$p"; echo "removed retired $APP_DEST/$p"; fi
done
for d in layers data/samples/austin data/samples data; do
  rmdir "$APP_DEST/$d" 2>/dev/null || true
done
if [ -e "$APP_DEST/config.local.json" ]; then
  echo "note: $APP_DEST/config.local.json is no longer read; move its keys into config.json there and delete it"
fi

# app shell
cp index.html sw.js manifest.json favicon.png icon-*.png "$APP_DEST/"
cp deploy/app.htaccess "$APP_DEST/.htaccess"
cp deploy/api/route.php "$APP_DEST/api/"

# per-server settings: created once, then edited on the server
if [ ! -e "$APP_DEST/config.json" ]; then
  cp deploy/config.example.json "$APP_DEST/config.json"
  echo "created $APP_DEST/config.json from deploy/config.example.json; edit it there"
fi

# shared data folder: dynamic _files.json
cp deploy/data/_files.php "$DATA_DEST/"
cp deploy/data/data.htaccess "$DATA_DEST/.htaccess"

# status page at /overworld/status; it refuses to run until Directory Privacy protects
# its folder (which keeps its own .htaccess there, untouched by deploys). Copied
# without the #! line, which PHP 7.2 would print at the top of the page.
sed '1{/^#!/d;}' tools/status.php > "$APP_DEST/status/index.php"

echo "deployed $(git rev-parse --short HEAD 2>/dev/null || echo '?') to $APP_DEST"
