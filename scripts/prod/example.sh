#!/bin/bash
# Worked example of a site script. Copy it, change the five facts, done.
#
# Everything that runs lives in _lib.sh. A site script is a declaration, not
# a program: keeping it that way is what lets one fix reach every site.
#
# Usage:  ./example.sh dump | update | verify | all

DOMAIN="example.test"
SSH_ALIAS="example-host"                 # an entry in ~/.ssh/config
REMOTE_PATH="~/domains/example.test/public_html"
VAULT="$HOME/backups/example"            # folder holding the {YYYY}/{YYYYMMDD} tree
EXCLUDE=""                               # e.g. "--exclude=some-licensed-plugin"

# Optional, for hosts whose shell PHP is older than the web PHP:
# WP_BIN="php83 /usr/local/bin/wp"

source "$(dirname "$0")/_lib.sh"
run_site "$@"
