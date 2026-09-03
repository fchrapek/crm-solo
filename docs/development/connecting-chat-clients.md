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

The browser and phone cases are not missing features, they are the *next* piece of work: see [When the CRM is hosted](#when-the-crm-is-hosted) below.

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

It can: read the day's attention list, brief a client, start and stop timers, log time after the fact, complete tasks, write journal notes, capture and move leads, and drive the month-close checklist.

It cannot: issue invoices to Infakt (deliberate — that writes to an external system, so it stays a terminal command), or do anything the CRM itself cannot do. It is the same set of actions the CLI has, reached a different way.

Two behaviours worth knowing, because they look like quirks and are not:

- **Times are local.** Saying "log 30 minutes ending 15:30" means half past three where you are. The CRM stores UTC internally and converts.
- **A lead's source cannot be changed later.** It is permanent attribution, so an unrecognised source is rejected with the valid ones listed rather than quietly accepted.

---

## When the CRM is hosted

Everything above depends on the CRM being on the same machine. Giving it a public address (planned, not built) changes three things for the person setting it up:

- **The browser and phone start working.** claude.ai and chatgpt.com both accept a remote server by web address, so setup becomes "paste a URL, sign in" — no file editing, no paths, no Docker running locally.
- **Setup moves into the UI.** Claude Desktop's **Settings → Connectors → Add custom connector** and ChatGPT's equivalent both take a URL. The JSON block on this page becomes unnecessary.
- **It needs a login.** A public address means access control: each client sends a token, and the CRM resolves *which user* that token belongs to. Attribution stops being "the owner" and starts being the real person.

The code is already arranged for this. The transport is one line in `routes/ai.php`, and identity resolution sits behind `AgentIdentityResolver`, which a hosted setup rebinds to read the token. The tools themselves do not change — which is the point of keeping them free of transport concerns.

Until then, a genuinely one-click local install is also possible by packaging the server as a Claude Desktop extension (Settings → Extensions), which would remove the file editing without needing a public address.
