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

After changing app files, bump `APP_CACHE` in `sw.js` so installed copies update.

## Deploying data

Upload into `public_html/overworld-data/` with File Manager, keeping the `<region>/<file>`
layout the layer configs use. To upload many files, upload one zip and use
File Manager's Extract.

There is no `_files.json` to maintain: `_files.php` lists the folder whenever
the app asks. Layers whose files aren't uploaded are hidden
(`hide_unavailable` in `deploy/config.json`).

Only upload what should be public. In particular, never upload the Strava
export zip or `.strava-bbox-cache.json`. Heatmaps and activity tracks show
where you live and ride.

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
