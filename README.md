# Boogle Client

**Exception reporting for Laravel** — send errors and stack traces from your app to your [Boogle](https://boogle.app) instance (or any compatible ingestion endpoint).

---

## Features

- **Laravel-first** — service provider, facade `Boogle`, Artisan helpers
- **Exception payload** — class, message, file, line, stack, app/storage info
- **HTTP snapshot** — in `exception.http`: full URL, query, body payload, cookies, method, IP, user agent, client hints (browser / OS), plus optional session and headers; see below
- **Auth** — `exception.user` with `id`, optional `uuid`, `email`, `name` when logged in
- **No-op if unconfigured** — `BOOGLE_*` missing → nothing sent; use `Boogle::isEnabled()`
- **Environments, ignores, dedup** — same as `config/boogle.php` (`environments`, `except`, `sleep`, `blacklist`)

---

## Installation

```bash
composer require andreapollastri/boogle-client
php artisan boogle:install
```

| `.env` | |
|--------|---|
| `BOOGLE_KEY` | API token |
| `BOOGLE_PROJECT_KEY` | Project id |
| `BOOGLE_SERVER` | Ingestion URL (default `https://boogle.app/api/log`) |

---

## Exception handler wiring

| Laravel | |
|---------|---|
| **11+** | `bootstrap/app.php` → `withExceptions` |
| **≤10** | `app/Exceptions/Handler` → `register` |

**Laravel 11+**

```php
use Boogle\Facade as Boogle;
use Illuminate\Foundation\Configuration\Exceptions;

->withExceptions(function (Exceptions $exceptions) {
    Boogle::registerExceptionHandler($exceptions);
})
```

**Laravel 10 and below** — in `App\Exceptions\Handler::register()`:

```php
$this->reportable(function (\Throwable $e) {
    Boogle::handle($e);
});
```

---

## JSON shape sent to the server

**Root**

| Key | |
|-----|---|
| `key` | `BOOGLE_PROJECT_KEY` |
| `token` | `BOOGLE_KEY` |
| `exception` | object below |

**`exception` (main fields)**

| Key | Contenuto |
|-----|------------|
| `exception` | FQCN eccezione |
| `error` | Messaggio |
| `file`, `line`, `class` | Punto d’errore |
| `fileType` | default `php` o quello passato a `handle()` |
| `executor` | stack trace (limite da `lines_count`) |
| `storage` | PHP, Laravel, DB `driver`, memoria |
| `user` | se loggato: `id`, `uuid`, `email`, `name` — altrimenti `null` |
| `http` | **snapshot request** (tutto ciò che serve a Boogle) |
| `host` | host della richiesta (fallback hostname se no HTTP) |
| `method` | stesso di `http.method` (retro-compatibilità con vecchie viste) |
| `fullUrl` | stesso di `http.url` |

**`exception.http` (invio a ogni report che passa le regole in config)**

| Campo | Descrizione |
|-------|-------------|
| `url` | URL completo, query string inclusa (`$request->fullUrl()`) |
| `query` | Array parametri query (subject a `include_query`) |
| `payload` | Corpo: JSON parse come array, o campi form POST, senza duplicare i parametri in query; GET → `[]` |
| `cookies` | Array nome → valore (con `blacklist`) oppure `cookie_values` false in config → `nome → [REDACTED]` |
| `method` | `GET`, `POST`, `PUT`, … |
| `ip` | IP client |
| `user_agent` | Stringa completa user-agent |
| `client` | `browser` e `os` (euristica + Client Hints se presenti) |
| `content_type` | header `Content-Type` |
| `is_json` | richiesta riconosciuta come JSON |
| `wants_json` | `expectsJson()` |
| `is_ajax` | `ajax()` (XMLHttpRequest) |
| `is_secure` | HTTPS |
| `referer` | `Referer` se presente |
| `headers` | solo se `include_headers: true` (con masking) |
| `session` | solo se `include_session: true` (con masking) |

**Merge** — terzo argomento: `Boogle::handle($e, 'php', ['http' => ['note' => 'x']])` con [`array_replace_recursive`](https://www.php.net/array_replace_recursive) su `http`. Altri campi in quell’array si uniscono a `exception` (come prima).

`blacklist` in config maschera password/token ecc. in query, payload, cookie, session, header.

---

## Configuration (`config/boogle.php`)

| Key | |
|-----|---|
| `environments` | es. `['production']` |
| `except` | classi di eccezioni non inviate |
| `lines_count` | frame stack |
| `sleep` | dedup in secondi (`0` = ogni throw invia) |
| `blacklist` | sottochiavi rifiutate → `[REDACTED]` |
| `http` | `include_query`, `include_payload`, `include_cookies`, `cookie_values`, `include_session`, `include_headers` |

Vecchia chiave `context` (pubblicata in versioni passate) è ancora letta: `include_input` mappa a `include_payload` per la compatibilità.

---

## Artisan & testing

- `boogle:install` — publish config
- `boogle:test` — invia una eccezione di prova (no-op se Boogle disabilitato)

In test: sostituire il binding con `Boogle\Fakes\BoogleFake`.

---

## Cosa aggiornare in Boogle (app / API)

Cose da allineare lato prodotto [Boogle](https://boogle.app) o istanza self‑hosted, così in dashboard e DB compare tutto quello che il client manda oggi:

1. **Ingestion (`POST` verso l’URL di log)**  
   - Persisti l’oggetto **`exception` intero** (JSON column o equivalente), non solo 4–5 campi.  
   - In particolare mappa e salva **`exception.http`**.

2. **Modello / DB**  
   - Campo o colonna (JSON) per **`http`**; oppure colonne piatte derivate: `url`, `method`, `ip`, `user_agent`, + JSON per `query`, `payload`, `cookies`.  
   - Colonne per `exception.user` (`user_id` nullable, ecc.) se serve indicizzazione.

3. **Vista dettaglio errore**  
   - Sezione “Richiesta / contesto” che mostri: `http.url`, `http.method`, `http.query`, `http.payload`, `http.cookies`, `http.ip`, `http.user_agent`, `http.client`, più eventuali `referer` / `is_ajax`.  
   - Sezione “Utente” che legga `exception.user` (id / email) quando non `null`.  
   - Non confondere con un eventuale form “invia feedback testuale” (feature separata).

4. **Validazione**  
   - Non rifiutare body con `exception.http` pieno: evitare `$request->validate()` troppo stretta sul payload del client Laravel.

5. **Indici e privacy**  
   - Opzionale: troncare o hashare in UI valori in `http.payload` / cookie sensibili (il client applica già `blacklist`).

6. **Documentazione interna**  
   - Specificare per integrazioni che il contratto ufficiale è: `key`, `token`, `exception` con sotto-struttura sopra.

---

## License

MIT (see `composer.json`).
