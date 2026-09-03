# Clockify Integration Setup

## 1. Get API Key

- Log into [Clockify](https://clockify.me)
- Go to **Profile Settings** (click your avatar in the top-right)
- Scroll down to **API Key** section
- Click **Generate** if you don't have one
- Copy the API key

## 2. Add to `.env`

```env
CLOCKIFY_API_KEY=your-api-key
```

## 3. Configure in CRM

- Go to **Integrations > Clockify**
- Paste the **API Key** in the API key field
- Enable the integration
- Click **Sync Now**

## CLI Commands

```bash
# Sync all time entries
php artisan clockify:sync --force --sync

# Sync entries after a specific date
php artisan clockify:sync --force --sync --since=2026-01-01

# Sync for a specific account
php artisan clockify:sync --account=1 --force --sync
```

## Notes

- Sync is one-way: Clockify → CRM (read-only)
- Time entries are synced with description, duration, billable flag, and tags
- Entries are automatically matched to projects by name
- Syncs every 15 minutes when scheduler is running
- Uses the first workspace found in your Clockify account
