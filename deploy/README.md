# Deploying to the shared host

The app is static apart from two small PHP files. Code goes up through cPanel's
Git Version Control; data files go up by hand with the cPanel File Manager. The
two live in separate folders, so neither ever overwrites the other.

```
~/repos/overworld/          cPanel's clone of this repo (never edit it on the server)
~/secrets/ors-key.txt       OpenRouteService key, outside the website
~/public_html/
  overworld/                the app: written by deploy.sh on every deploy
    config.json               from deploy/config.json
    api/route.php             routing proxy, holds the key server-side
    status/index.php          status page: layer sets vs. data (password-protected)
    data/samples/             the committed example data
  overworld-data/           shared data folder: uploaded by hand
    _files.php, .htaccess     written by deploy.sh; make every _files.json dynamic
    layers/seattle.json       layer sets kept with the data
    seattle/...
```

## One-time setup

1. cPanel → Git Version Control → Create. Clone URL
   `https://github.com/alanbernstein/overworld.git`, repository path
   `repos/overworld`. (A private repo needs a deploy key, which needs cPanel's
   SSH Access page; a public repo just works.)
2. File Manager: create `~/secrets/` (next to `public_html`, not inside it)
   and a file `ors-key.txt` in it holding the OpenRouteService key.
3. Deploy (below), then visit `https://<site>/overworld/`.

## Deploying code

1. Commit and `git push`.
2. cPanel → Git Version Control → Manage → Pull or Deploy:
   **Update from Remote**, then **Deploy HEAD Commit**.

That runs `.cpanel.yml`, which runs `deploy/deploy.sh`. It only copies files, so
anything removed from the repo has to be deleted on the server by hand.

After changing app files, change `APP_CACHE` in `sw.js` (any new name) so installed copies update.

## Deploying data

Upload into `public_html/overworld-data/` with File Manager, keeping the `<region>/<file>`
layout the layer configs use. To upload many files, upload one zip and use
File Manager's Extract.

There is no `_files.json` to maintain: `_files.php` lists the folder whenever
the app asks. Layer sets go in `overworld-data/layers/` and are loaded
automatically, so a new region is just its folder plus `layers/<region>.json`,
with no config change or deploy. Edit layer sets in the local synced data
folder and upload them, rather than editing the server copy. Layers whose files aren't uploaded are hidden
(`hide_unavailable` in `deploy/config.json`).

Only upload what should be public. In particular, never upload the Strava
export zip or `.strava-bbox-cache.json`. Heatmaps and activity tracks show
where you live and ride.

## Checking data

`https://<site>/overworld/status` compares the layer sets with the uploaded
files: which file each layer uses, layers whose file is missing, files no layer
uses, and entries the app ignores. Add `?problems` to see only those.

It lists every file, including ones in protected folders, so it refuses to run
until its folder is behind a password: cPanel → Directory Privacy →
`public_html/overworld/status` → enable, and add a user. (Deploys don't touch
the `.htaccess` that creates there.) The same report runs locally with
`make status`, which writes `status.html`.

## Private data

cPanel → Directory Privacy can password-protect a folder such as
`overworld-data/private/`. `_files.php` never lists a protected folder or reveals its
contents, so give it a static listing: run `tools/index-data.py` on a local copy
and upload the `_files.json` it writes. Then add `"/overworld-data/private/"` before `"/overworld-data/"` in
`roots` in `deploy/config.json`. Note that visitors are then asked for a
password when the app loads.

## Testing the deploy script locally

```sh
APP_DEST=/tmp/site/overworld DATA_DEST=/tmp/site/overworld-data deploy/deploy.sh
```
