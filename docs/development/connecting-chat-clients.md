# Connecting a chat client to CRM Solo

CRM Solo can be operated from a chat app: you ask in plain language, the app calls the CRM, and what changes shows up in the web UI straight away. This page is the setup guide. It assumes nothing about the terminal — where a command is unavoidable, it is written out to copy whole.

The contract behind it (which tools exist, what they return) lives in [agent-crm-interface.md](./agent-crm-interface.md).

---

## First: which apps can actually reach it?

Today the CRM runs **on your own computer**, so a chat app can only reach it if it also runs on that computer. That single fact decides everything:

| App | Works today? | Why |
|---|---|---|
| **Claude Desktop** (the Mac/Windows app) | Yes | Runs on your machine |
| **ChatGPT desktop** (the Mac/Windows app) | Yes | Runs on your machine |
| **Claude Code** / **Codex CLI** | Yes | Same machine, terminal |
| **claude.ai in a browser** | Not yet | Nothing to reach — the CRM has no public address |
| **chatgpt.com in a browser** | Not yet | Same |
| **Phone apps** | Not yet | Same |

Once the CRM is hosted, Claude Code also reaches it over HTTPS with an agent token. The browser and phone apps need an OAuth sign-in the CRM does not offer yet, so they remain the next piece of work: see [When the CRM is hosted](#when-the-crm-is-hosted) below.

## Before you start

Two things must be true, or every request will fail with a connection error.

1. **The CRM's database is running.** It lives in Docker. Look for the whale icon in the menu bar; if Docker Desktop is not running, start it and wait for the icon to settle.
2. **The CRM code is on this machine**, at a path you know. Everywhere below says `/absolute/path/to/crm-solo` — replace it with the real folder, e.g. `/Users/you/Desktop/dev/crm-solo`.

You also need the full path to PHP. In a terminal, `which php` prints it (commonly `/opt/homebrew/bin/php` on Apple Silicon). **Always use that full path in a config file, never just `php`.** Apps started from the Dock do not inherit a terminal's settings, so a bare `php` cannot be found and the app reports only a vague startup failure. This is the single most common reason a setup that "looks right" does not work.

---

## Claude Desktop

Claude Desktop has no button for adding a local server, so this is a one-time file edit. The app has a menu item that opens the file for you.

1. Open Claude Desktop → **Settings → Developer → Edit Config**. A file called `claude_desktop_config.json` opens in a text editor.
2. Add the `mcpServers` block below. If the file already has other content, put this block just after the opening `{` and leave everything else alone. If the file is empty, paste the whole thing.

```json
{
  "mcpServers": {
    "crm-solo": {
      "command": "/opt/homebrew/bin/php",
      "args": [
        "/absolute/path/to/crm-solo/artisan",
        "mcp:start",
        "crm-solo"
      ]
    }
  }
}
```

3. Save the file, then **quit Claude Desktop completely** (Cmd+Q — closing the window is not enough) and open it again. Config is read only at launch.
4. In a new chat, open the tools/connectors control near the message box. **crm-solo** should be listed with its tools.

A JSON tip if it will not start: every `{` needs its `}`, and items in a list are separated by commas with no trailing comma before a closing bracket. If in doubt, paste the file into a JSON validator.

## ChatGPT desktop

ChatGPT desktop has a form for this, so no file editing is needed.

1. Open ChatGPT desktop → **Settings → MCP servers → Add server**.
2. Fill in:
   - **Name**: `crm-solo`
   - **Command**: `/opt/homebrew/bin/php`
   - **Arguments**: `/absolute/path/to/crm-solo/artisan`, `mcp:start`, `crm-solo`
3. Save, then quit and reopen the app.

If you also use **Codex** (CLI or IDE extension), you do not have to do this twice: ChatGPT desktop, Codex CLI and the Codex IDE extension **share one configuration**. Adding it in any one of them registers it for all three. From a terminal, that one command is:

```bash
codex mcp add crm-solo -- /opt/homebrew/bin/php /absolute/path/to/crm-solo/artisan mcp:start crm-solo
```

## Claude Code

```bash
claude mcp add crm-solo -- /opt/homebrew/bin/php /absolute/path/to/crm-solo/artisan mcp:start crm-solo
```

`claude mcp list` should then show `crm-solo: ✔ Connected`.

---

## Checking it works

Ask these in a normal chat, in order. You never name a tool — the app picks one from what you asked.

1. **"What needs my attention today?"** — overdue and urgent tasks, open month closes, hot leads, running timers. Reads only, so it is a safe first request.
2. **"Give me a brief on <a client name>."** — status, retainer, hours this month against the limit, open tasks, latest report.
3. **"Brief me on <a fragment matching several clients>."** — should come back asking *which* one, listing the matches. Getting a question instead of a guess is the correct behaviour.
4. **"What's the month close status for <client>?"** — the checklist. Asking about a month that was never started shows what it *would* create, without creating it.
5. **"Add a note to <client>: <something>."** — a write. Open that client in the CRM web UI, Activity tab: the entry is there, with your name on it.

Approve the tool call if the app asks. Approving once per tool is normal.

## When something goes wrong

**The server is not listed at all.** The app did not restart fully (Cmd+Q, not just the window), or the config file has a syntax error.

**Everything errors the moment it runs.** Nearly always one of: Docker not running, a wrong path to the project, or `php` written without its full path.

**It worked yesterday, not today.** Docker is stopped, or the project folder moved. Paths in these configs are absolute; moving the folder breaks them.

**A change in CRM settings is not visible.** Each app starts its own copy of the server and reads configuration once. Quit and reopen the app.

**Removing it.** Claude Desktop: delete the `mcpServers` block from the config file. ChatGPT desktop: Settings → MCP servers → remove. Claude Code: `claude mcp remove crm-solo`. Codex: `codex mcp remove crm-solo`.

---

## What it can and cannot do

It can: read the day's attention list, brief a client, read one task with its Trello checklists, comments and files, write a short brief on a task, start and stop timers, log time after the fact, complete tasks, write journal notes, capture and move leads, and drive the month-close checklist.

It cannot: issue invoices to Infakt (deliberate — that writes to an external system, so it stays a terminal command), or do anything the CRM itself cannot do. It is the same set of actions the CLI has, reached a different way.

Two behaviours worth knowing, because they look like quirks and are not:

- **Times are local.** Saying "log 30 minutes ending 15:30" means half past three where you are. The CRM stores UTC internally and converts.
- **A lead's source cannot be changed later.** It is permanent attribution, so an unrecognised source is rejected with the valid ones listed rather than quietly accepted.

---

## When the CRM is hosted

Once the CRM runs on a server (say `https://app.example.test`), the local server above talks to a database nobody uses any more. Clients then connect to the hosted MCP endpoint, `https://app.example.test/mcp`, which has the same tools and the same answers.

**Which apps can use it today.** Claude Code, and any MCP client that can send its own HTTP headers. Every request needs two credentials: the Cloudflare Access service token that lets it past Access, and the CRM's own agent token. claude.ai, Claude Desktop connectors and ChatGPT sign in to remote servers with OAuth and cannot send those headers, so they stay on the local server (or wait for OAuth support, which is not built).

**What you need, once per Mac**

1. The agent token, issued by the owner on the server (`php artisan agent-tokens:issue`), saved as `~/.config/crm-solo/agent.token`. It is shown once when issued. `chmod 600` the file; the tools refuse it otherwise.
2. The Access service token, saved as `~/.config/crm-solo/remote.headers`, also `chmod 600`:

   ```
   CF-Access-Client-Id: <client id>
   CF-Access-Client-Secret: <client secret>
   ```

   Instead of the token file you can keep the agent token in the Keychain (`security add-generic-password -s crm-solo-agent -a "$USER" -w`, which prompts for it) and export `CRM_REMOTE_KEYCHAIN_SERVICE=crm-solo-agent`.

**Claude Code**

```bash
claude mcp remove crm-solo
claude mcp add-json crm-solo '{"type":"http","url":"https://app.example.test/mcp","headersHelper":"/absolute/path/to/crm-solo/bin/crm-mcp-headers"}'
```

`bin/crm-mcp-headers` prints the headers from the two files above at connect time, so the token never sits in Claude Code's own config. If your Claude Code version does not know `headersHelper`, the fallback is static headers (`--header "Authorization: Bearer ..."` and the two `CF-Access-*` headers); they are then stored in plain text in `~/.claude.json`.

**What changes once it is hosted**

- **What a token may do is limited.** A token carries abilities: `read`, plus one write group each for notes, tasks, time, leads and the month close. A token without a write group does not even see those tools. A read-only token is the safe default for an experiment.
- **Writes are attributed.** Every record a tool writes leaves an audit row with the token's name, the tool and the MCP session, and journal entries, timers and ticks carry the same marks. A note written from chat shows the token owner's name in the Activity tab.
- **A revoked or expired token stops at once.** The client then reports an authentication error; ask for a new token rather than retrying.
- **There is a rate limit** (120 requests a minute per token by default). An agent stuck in a loop hits it and gets HTTP 429.

The `crm` command line works against the hosted CRM too; see `agent-crm-interface.md`, "Remote mode".
