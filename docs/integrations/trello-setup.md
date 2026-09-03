# Trello Integration Setup

## 1. Get API Key

- Go to [Trello Power-Up Admin](https://trello.com/power-ups/admin)
- Or directly: [https://trello.com/app-key](https://trello.com/app-key)
- Copy your **API Key**

## 2. Generate API Token

- On the same page, click **"Token"** link (or manually generate)
- Authorize the app — grant read access to your boards
- Copy the **Token**

## 3. Add to `.env`

```env
TRELLO_API_KEY=your-api-key
TRELLO_API_TOKEN=your-token
```

## 4. Configure in CRM

- Go to **Integrations > Trello**
- Paste the **API Token** in the API Key field
- Enable the integration
- Click **Sync Now**

## 5. Connect a Board to a Project

Linking happens per project on the client edit page (**Work** tab). Open a
project section and click **Connect to Trello** — the dialog offers two modes:

- **Create new board** — creates a fresh Trello board with default lists
  (Backlog / To-Do / Doing / Testing / Done) and priority labels.
- **Link existing board** — picker of all boards on your Trello account,
  with each board annotated as `available` / `orphan` / `linked`. Orphan
  boards (synced earlier without a client) get silently adopted.

After linking, sync rewrites Trello list names to canonical CRM lanes via
`project.settings.trello_list_mapping` (auto-detected by name; override per-list
via the project section's "Configure list mapping" action).

## CLI Commands

```bash
# Sync all boards
php artisan trello:sync --force --sync

# Sync for a specific account
php artisan trello:sync --account=1 --force --sync
```

## Notes

- Trello uses API Key + Token authentication (not OAuth)
- The API Key is set in `.env` (shared), the Token is per-user (stored as integration `api_key`)
- Sync is one-way: Trello → CRM (read-only)
- Cards are synced with their list name, labels, due date, and completion status
