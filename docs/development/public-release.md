# Cutting a public release

The public repository at `fchrapek/crm-solo` is a snapshot of this one, not a
fork. It shares no history: this repo's early commits carry client names that
predate the identity scrub, so a filtered import would have meant rewriting
around 700 commits with no way to prove the result complete.

## The command

Run it from the private source checkout: the script and its policy are
export-ignored, so the public repository carries this description but not
the script.

```bash
./scripts/public-export.sh develop        # or any ref
cd ../crm-solo-public && git status       # this reads as the release diff
```

`git archive` of the ref, minus everything `.gitattributes` marks
`export-ignore`, written over `../crm-solo-public`. The destination's `.git` is
carried across the rebuild, so the published history and its remote survive and
each release is a commit rather than a new repository. Files are still replaced
wholesale, so anything dropped since the last release shows up as a deletion.

The export is staged in the destination's index, and a security stage reads
that index as raw blobs, so what is scanned is what a commit there publishes;
the tree's own `.gitattributes` cannot change what a gate sees. Every gate is
hard; a scanner that cannot run, or that warns it skipped something, fails the
run.

| Gate | Fails on |
|---|---|
| blocklist | real clients, people, hosts and private repository names |
| credentials, gitleaks | credential shapes, and gitleaks' default ruleset; in-content `gitleaks:allow` is ignored, an in-tree `.gitleaksignore` or `.gitleaks.toml` is refused, exceptions name an exact synthetic value |
| emails, hosts, ipv4 | anything outside a reviewed allowlist (reserved domains, documented services); `composer.lock` is parsed and only package metadata (authors, support, funding, versions, constraints) is exempt |
| nfd | combining marks: text decomposed (NFD) would spell a blocklisted name past the blocklist |
| local-paths, iban, phones, nip | home directories, account numbers, phone numbers outside the seed range, any checksum-valid NIP |
| names, symlinks | every text gate over file names and symlink targets; targets outside the tree |
| binary | any binary (by content or extension) not pinned by sha256; today only the self-hosted fonts |
| payload, excluded, required, paths, size | env files, dumps, logs, keys, gitleaks config; export-ignored paths present; required files missing; control characters in paths or paths that collide by case; over 100 MB |

The script and its policy lists are `export-ignore`d, because the lists name
what they keep out, and the stage asserts their absence from the result.
`scripts/public-export.sh --self-test` plants one leak per gate (and each
bypass found in review) in a synthetic tree and expects that gate, and only
that gate, to fail; CI runs it in the private repository. Encoded text (a
`\u0144` escape, base64) is not decoded before matching, so a name written
that way gets past the text gates.

## Releases from the private repository

Every release of the private repository (a merge into its main) builds this
export automatically: the security stage, then the exported tree installed
and checked on its own, then a private artifact holding the tree, its tree
id and a manifest of every path with its sha256. Nothing is pushed. A person
reviews the artifact and publishes it by hand; the public repository never
receives a tree that skipped this stage.

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
php artisan migrate --seed && composer run check
```

`composer run check` is the CI gate (`scripts/check.sh`: pint, the suite,
types, ESLint, the token linter, a production build, both audits). The export
carries everything it needs, workflows and lock files included, and the
public repository runs the same gate before its demo deploy.

Check `php artisan db:show` names the export's own port before seeding. A clone
that inherits this repo's ports fails to bind and silently uses this database
instead, because `.env.example` ships the same credentials in both.

## Pushing main deploys the demo

demo.crm-solo.com runs this public repository. A push to `main` triggers
`.github/workflows/deploy-demo.yml`: the CI gate on that commit, then, only if
it passed, build `ghcr.io/fchrapek/crm-solo:demo`,
sync `compose.demo.yaml` to the server, pull, restart, `demo:reset`. The push
is the release and the deploy in one step. The gate stops a commit that fails
its checks from deploying, but it runs after the push, so the verification
above is still what keeps a bad export off the public `main`. The jobs are gated on the
repository name, so a fork or the private repo carrying the same file never
deploys anything. Secrets live in the public repo's `demo` environment,
restricted to `main`; the server directory is provisioned by hand, and the
workflow refuses to deploy into one that lacks a `.env` with `DEMO_IMAGE`.

## Things that live outside the export

`resources/prompts/report-narrative.md` ships the report output contract. An
instance overrides the whole prompt with `reports:prompt-import <file>`, which
writes a settings row; `reports:prompt-show` says which source resolved. The
split protects wording, not secrets: the hours-bank reasoning is readable in
`client-reports.md` and in `StructuredListComposer` either way.

`config/leadgen.php` ships illustrative funnel numbers. The real ones are not
read by any code and live in the vault.
