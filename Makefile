# Local development. Works anywhere python3 is installed (macOS, Linux).
#   make serve               http://localhost:8000
#   make serve PORT=8080
#   make serve BIND=0.0.0.0  reachable from other devices on the LAN

PORT ?= 8000
BIND ?= 127.0.0.1
PYTHON ?= python3

.PHONY: serve index status strava

serve: index
	@echo "serving http://localhost:$(PORT)/"
	python3 -m http.server $(PORT) --bind $(BIND)

# rewrite each data root's _files.json so newly added data files show up
index:
	@python3 tools/index-data.py

# status.html: layer sets compared with the data files in this machine's roots
#   make status ARGS=--problems      only the problems
#   make status ARGS="DIR ..."       these data folders instead of config.json's roots
# exit status 1 from the script just means problems were found; 2 is a real error
status:
	@php tools/status.php $(ARGS) > status.html.tmp; s=$$?; \
	if [ $$s -le 1 ]; then mv status.html.tmp status.html; echo "wrote status.html"; \
	else rm -f status.html.tmp; exit $$s; fi

# rebuild Strava subsets and heatmaps from the newest export in data/local/strava/
#   make strava                       all subsets in data/sync/strava.json
#   make strava ARGS="--subset seattle --no-heatmaps"
# needs: pip install fitdecode numpy rasterio
strava:
	$(PYTHON) tools/strava.py $(ARGS)
	@$(PYTHON) tools/index-data.py
