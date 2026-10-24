# make test ZM_SRC=/path/to/zoneminder   unit tests against that tree's web/includes
# make lint                              php -l on every PHP file
# make overlay ZM_SRC=...                tools/zm-overlay.sh on that tree
# make docs                              the API reference (docs/openapi) at http://127.0.0.1:8090/
PHP ?= php
ZM_SRC ?=
DOCS_ADDR ?= 127.0.0.1:8090

.PHONY: test lint overlay docs
test:
	@test -n "$(ZM_SRC)" || { echo "make test ZM_SRC=/path/to/zoneminder"; exit 2; }
	ZM_SRC="$(ZM_SRC)" PHP="$(PHP)" tests/run.sh

lint:
	@find api tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l | grep -v '^No syntax errors' ; \
	 find api tests -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l >/dev/null

overlay:
	@test -n "$(ZM_SRC)" || { echo "make overlay ZM_SRC=/path/to/zoneminder"; exit 2; }
	tools/zm-overlay.sh "$(ZM_SRC)"

docs:
	@echo "API reference: http://$(DOCS_ADDR)/  (Ctrl-C to stop)"
	$(PHP) -S $(DOCS_ADDR) -t docs/openapi
