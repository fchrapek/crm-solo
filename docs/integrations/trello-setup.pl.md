# Konfiguracja integracji Trello

## 1. Uzyskaj klucz API

- Wejdź na [Trello Power-Up Admin](https://trello.com/power-ups/admin)
- Lub bezpośrednio: [https://trello.com/app-key](https://trello.com/app-key)
- Skopiuj swój **API Key** (Klucz API)

## 2. Wygeneruj token API

- Na tej samej stronie kliknij link **"Token"**
- Autoryzuj aplikację — przyznaj dostęp do odczytu tablic
- Skopiuj **Token**

## 3. Dodaj do `.env`

```env
TRELLO_API_KEY=twój-klucz-api
TRELLO_API_TOKEN=twój-token
```

## 4. Skonfiguruj w CRM

- Przejdź do **Integracje > Trello**
- Wklej **Token API** w pole klucza API
- Włącz integrację
- Kliknij **Synchronizuj teraz**

## 5. Powiąż tablicę z projektem

Powiązanie odbywa się na poziomie projektu, na stronie edycji klienta
(zakładka **Praca**). W sekcji projektu kliknij **Połącz z Trello** —
dialog oferuje dwa tryby:

- **Utwórz nową tablicę** — tworzy świeżą tablicę Trello z domyślnymi
  listami (Backlog / To-Do / Doing / Testing / Done) i etykietami priorytetów.
- **Powiąż istniejącą tablicę** — picker wszystkich tablic z Twojego
  konta Trello, każda oznaczona jako `available` / `orphan` / `linked`.
  Tablice "orphan" (zsynchronizowane wcześniej bez klienta) zostaną
  cicho przejęte.

Po powiązaniu synchronizacja przepisuje nazwy list Trello na kanoniczne
kolumny CRM przez `project.settings.trello_list_mapping` (auto-detekcja
po nazwie; zmień per-lista przez akcję "Konfiguracja mapowania list" w
sekcji projektu).

## Komendy CLI

```bash
# Synchronizuj wszystkie tablice
php artisan trello:sync --force --sync

# Synchronizuj dla konkretnego konta
php artisan trello:sync --account=1 --force --sync
```

## Uwagi

- Trello używa uwierzytelniania Klucz API + Token (nie OAuth)
- Klucz API jest ustawiony w `.env` (wspólny), Token jest per użytkownik (przechowywany jako `api_key` integracji)
- Synchronizacja jest jednokierunkowa: Trello → CRM (tylko odczyt)
- Karty synchronizują się z nazwą listy, etykietami, terminem i statusem ukończenia
