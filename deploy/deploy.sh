#!/bin/bash
# Copy the app from this repo checkout into the website. Run by cPanel (see
# .cpanel.yml); see deploy/README.md for the whole process.
#
#   APP_DEST   where the app goes        (default ~/public_html/overworld)
#   DATA_DEST  the shared data folder    (default ~/public_html/data)
#
# Files are copied, never deleted, so anything uploaded by hand stays put.
# The data folder only gets the listing script and its .htaccess; data
# files themselves are uploaded with the cPanel File Manager.
set -euo pipefail
cd "$(dirname "$0")/.."

APP_DEST=${APP_DEST:-$HOME/public_html/overworld}
DATA_DEST=${DATA_DEST:-$HOME/public_html/data}

mkdir -p "$APP_DEST/layers" "$APP_DEST/api" "$APP_DEST/data/samples" "$DATA_DEST"

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

echo "deployed $(git rev-parse --short HEAD 2>/dev/null || echo '?') to $APP_DEST"
