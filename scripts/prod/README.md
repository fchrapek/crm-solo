# Prod maintenance scripts

Deterministic per-site maintenance for the month close, one script per site:

    scripts/prod/sites/<client>/<site>.sh   # ~9 lines of facts
    scripts/prod/example.sh                 # the shape, with fictional values
    scripts/prod/_lib.sh                    # all shared logic

A site script declares facts only: domain, ssh alias, remote path, backup
destination and any exclusions. Everything that runs lives in `_lib.sh`, so a
fix lands once for every site instead of being copy-pasted around a fleet.

`sites/` holds the real per-client scripts and is excluded from the public
snapshot: a fleet catalogue names which hosts to reach and where each install
sits, which is reconnaissance whether or not it carries a password.

Verbs: `dump | update | verify | all`. Every mutation sits between gates
(baseline health, same-day dump on file, post-update health plus checksums);
any red gate stops that site with a machine-readable `step|status|detail`
line. Verbs are idempotent, so a second run skips whatever is already done
and `verify` never writes. Re-running after a crash, or purely for proof,
costs nothing.

Per-site facts worth knowing about, as they come up:

- `WP_BIN` sets how to invoke wp-cli on hosts whose shell PHP lags the web
  PHP. A host serving 8.3 with a 7.4 CLI default needs an explicit binary.
- `EXCLUDE` lists plugins that `plugin update --all` must skip, typically a
  commercial plugin whose licence is not active on that install.
- Dumps read database credentials through `wp config get` at runtime, so it
  does not matter how a host splits wp-config.
- A Composer-managed site with no wp-cli on the host is a different shape:
  the dump parses the live defines, and code updates go through the deploy
  (git push plus composer install) rather than wp-cli.
- A site with no ssh access has no script here at all. Panel-only hosting is
  a manual client.

The backup destination is the CRM's `projects.backup_path` verbatim. Archive
layouts vary per client, so it is recorded per site rather than derived.
