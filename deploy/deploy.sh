#!/bin/bash
# Copy the app from this repo checkout into the website. Run by cPanel (see
# .cpanel.yml); see deploy/README.md for the whole process.
#
#   APP_DEST   where the app goes        (default ~/public_html/overworld)
#   DATA_DEST  the shared data folder    (default ~/public_html/overworld-data)
#
# Files are copied, never deleted, so anything uploaded by hand stays put.
# The data folder only gets the listing script and its .htaccess; data
# files themselves are uploaded with the cPanel File Manager.
set -euo pipefail
cd "$(dirname "$0")/.."

APP_DEST=${APP_DEST:-$HOME/public_html/overworld}
DATA_DEST=${DATA_DEST:-$HOME/public_html/overworld-data}

mkdir -p "$APP_DEST/layers" "$APP_DEST/api" "$APP_DEST/data/samples" "$APP_DEST/status" "$DATA_DEST"

# app shell
cp index.html sw.js manifest.json favicon.png icon-*.png "$APP_DEST/"
cp layers/*.json "$APP_DEST/layers/"
cp deploy/config.json "$APP_DEST/config.json"
cp deploy/app.htaccess "$APP_DEST/.htaccess"
cp deploy/api/route.php "$APP_DEST/api/"

# small committed example data, with its static listing
cp -R data/samples/. "$APP_DEST/data/samples/"

# shared data folder: dynamic _files.json
cp deploy/data/_files.php "$DATA_DEST/"
cp deploy/data/data.htaccess "$DATA_DEST/.htaccess"

# status page at /overworld/status; it refuses to run until Directory Privacy protects
# its folder (which keeps its own .htaccess there, untouched by deploys). Copied
# without the #! line, which PHP 7.2 would print at the top of the page.
sed '1{/^#!/d;}' tools/status.php > "$APP_DEST/status/index.php"

echo "deployed $(git rev-parse --short HEAD 2>/dev/null || echo '?') to $APP_DEST"
