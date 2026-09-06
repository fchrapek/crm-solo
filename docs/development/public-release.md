# Cutting a public release

The public repository at `fchrapek/crm-solo` is a snapshot of this one, not a
fork. It shares no history: this repo's early commits carry client names that
predate the identity scrub, so a filtered import would have meant rewriting
around 700 commits with no way to prove the result complete.

## The command

```bash
./scripts/public-export.sh develop        # or any ref
cd ../crm-solo-public && git status       # this reads as the release diff
```

`git archive` of the ref, minus everything `.gitattributes` marks
`export-ignore`, written over `../crm-solo-public`. The destination's `.git` is
carried across the rebuild, so the published history and its remote survive and
each release is a commit rather than a new repository. Files are still replaced
wholesale, so anything dropped since the last release shows up as a deletion.

The script refuses to hand over an export whose scans find a credential, a real
client name, a private path or an unexpected email address. It is itself
`export-ignore`d, because it carries the blocklist it hunts for, and it asserts
its own absence from the result.

## What must stay true of the export

Seeded data has to be unusable, not merely fictional-looking:

- domains on a reserved TLD, `.test` by convention
- tax ids that fail the Polish NIP checksum
- phone numbers in an unassigned range
- company names carrying a fiction marker (`Przykładow*` / `Testow*`), because
  a plausible Polish company name is almost always somebody's
- no real Infakt, Trello or Clockify identifiers, which are pointers into
  third-party accounts

Four tests pin this: `DemoDataIsFictionalTest` (domains, tax ids, the factory
path the README's `migrate --seed` actually runs), `DemoSeederTest` (the fiction
marker), `NoRealIntegrationIdsTest` (integration id shapes).

## Verifying before you push

Run the exported copy, do not just read it. Give it its own ports so it cannot
reach this instance's database:

```bash
cd ../crm-solo-public
cp .env.example .env
# then set COMPOSE_PROJECT_NAME and DB_PORT / REDIS_PORT / MAIL_PORT
composer install && bun install --frozen-lockfile
php artisan key:generate && docker compose up -d --wait
php artisan migrate --seed && php artisan test && bun run build
```

Check `php artisan db:show` names the export's own port before seeding. A clone
that inherits this repo's ports fails to bind and silently uses this database
instead, because `.env.example` ships the same credentials in both.

## Pushing main deploys the demo

demo.crm-solo.com runs this public repository. A push to `main` triggers
`.github/workflows/deploy-demo.yml`: build `ghcr.io/fchrapek/crm-solo:demo`,
sync `compose.demo.yaml` to the server, pull, restart, `demo:reset`. The push
is the release and the deploy in one step, so the verification above is what
stands between a bad export and a broken demo. The jobs are gated on the
repository name, so a fork or the private repo carrying the same file never
deploys anything. Secrets live in the public repo's `demo` environment,
restricted to `main`; the server directory is provisioned by hand, and the
workflow refuses to deploy into one that lacks a `.env` with `DEMO_IMAGE`.

## Things that live outside the export

`resources/prompts/report-narrative.md` ships the report output contract. An
instance overrides the whole prompt with `reports:prompt-import <file>`, which
writes a settings row; `reports:prompt-show` says which source resolved. The
split protects wording, not secrets: the hours-bank reasoning is readable in
CLAUDE.md and in `StructuredListComposer` either way.

`config/leadgen.php` ships illustrative funnel numbers. The real ones are not
read by any code and live in the vault.
