# Konfiguracja integracji Clockify

## 1. Uzyskaj klucz API

- Zaloguj się do [Clockify](https://clockify.me)
- Przejdź do **Ustawień profilu** (kliknij awatar w prawym górnym rogu)
- Przewiń do sekcji **API Key**
- Kliknij **Generate** jeśli nie masz klucza
- Skopiuj klucz API

## 2. Dodaj do `.env`

```env
CLOCKIFY_API_KEY=twój-klucz-api
```

## 3. Skonfiguruj w CRM

- Przejdź do **Integracje > Clockify**
- Wklej **klucz API** w pole klucza
- Włącz integrację
- Kliknij **Synchronizuj teraz**

## Komendy CLI

```bash
# Synchronizuj wszystkie wpisy czasu
php artisan clockify:sync --force --sync

# Synchronizuj wpisy po określonej dacie
php artisan clockify:sync --force --sync --since=2026-01-01

# Synchronizuj dla konkretnego konta
php artisan clockify:sync --account=1 --force --sync
```

## Uwagi

- Synchronizacja jest jednokierunkowa: Clockify → CRM (tylko odczyt)
- Wpisy czasu synchronizują się z opisem, czasem trwania, flagą płatności i tagami
- Wpisy są automatycznie dopasowywane do projektów po nazwie
- Synchronizacja co 15 minut gdy scheduler jest uruchomiony
- Używa pierwszego workspace'a znalezionego na koncie Clockify
