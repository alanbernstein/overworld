Overworld is a web app for viewing all conceivable types of 2D world map data (including georeferenced raster overlays).

It can display points, routes, regions, tileserver data, and static raster overlays.

It can show current user location, search for locations by address or business name, and do basic routing.

It supports a variety of base layers including [cyclOSM](https://www.cyclosm.org), and multiple geodata formats, with a preference for geojson.

It can render georeferenced tif files, which can easily be created at https://mapwarper.net/ (upload, rectify, export).

Data sources are defined with simple json files. There is no capability to upload or edit data within the app.

![screenshot](screenshot.png)

# URL fragments

State is encoded in the URL fragment and updates live, so links can be shared to a specific view.

Parameters:
- `r` — comma-separated region keys to expand (`austin`, `seattle`, `texas`, `usa`). Omitted when all regions are expanded (the default).
- `l` — comma-separated layer IDs to enable. Layer IDs are the lowercased, hyphenated layer names.

Examples:

```
# Open with only the Seattle section expanded, centered on Seattle
#r=seattle

# Open with Austin and Texas sections expanded
#r=austin,texas

# Open with two layers enabled
#l=austin-zip-codes,austin-city-council-districts

# Open centered on Seattle with a specific layer enabled
#r=seattle&l=seattle-link-rail-stations
```

# Alternatives
- google earth
- qgis
- https://github.com/qgis/qwc2-demo-app
- https://github.com/NASA-AMMOS/MMGIS
- https://www.offline-maps.net/

- https://docs.mapstore.geosolutionsgroup.com/en/latest/ - not sure if it supports general uploads
- https://terria.io/plans - expensive
- arcgis - expensive
- https://felt.com/ - no free export or import ($200/month)
- https://sepal.io/ - more focused on professional remote sensing?
- https://geonode.org/ - awaiting account approval
- https://mapcarta.com/ - don't see a data upload option?

https://old.reddit.com/r/gis/comments/zo7ee9/open_source_alternatives_to_arcgis_online/
