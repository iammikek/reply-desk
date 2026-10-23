# Reply desk

Reply desk is the open queue for practice client email. Filament is the screen. HubSpot clock notes are the record. A draft stays on this machine and does not stop the clock.

The panel has a single sign-in, for the person working the queue.

[![Tests](https://github.com/iammikek/reply-desk/actions/workflows/tests.yml/badge.svg)](https://github.com/iammikek/reply-desk/actions/workflows/tests.yml)

## What the queue shows

Each open row is an inbound clock note with no matching sent note.

- Client, shown as company and contact
- Summary
- Inbound time
- Clock start
- Reply due
- A draft, when one is saved

Due time is calculated when the page or a command runs. It is not stored. Monday to Friday, 08:00 to 20:00 Europe/London, the clock starts at receipt and the reply is due 24 hours later. Outside that window, including the weekend, the clock starts at 08:00 on the next working day.

## Where the panel lives

The site is the Filament panel. Sign-in is at `/login`.

- `app/Filament/Pages/OpenQueue.php` is the queue
- `app/Filament/Tables/OpenRepliesTable.php` is the table, the overdue filter, and the row actions
- `app/Filament/Widgets/OpenQueueOverview.php` counts open and overdue rows
- `app/Providers/Filament/AdminPanelProvider.php` registers the panel

Log inbound, save a draft, discard a draft, send, and mark sent are actions on that page. Send stays on the panel.

## Run the showable copy

The memory store needs no HubSpot token and no real names. Mail uses the log mailer and is not delivered.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed
npm install
php artisan serve
```

Sign in as `desk@example.com` with password `password`. The seed loads two stand-in companies, one overdue row and one open row, and a draft on the overdue row.

`php artisan db:seed` refuses to run when `REPLY_DESK_STORE` is `hubspot`, and it refuses portal `145255358`.

Leave these in `.env` for the showable copy:

```
REPLY_DESK_STORE=memory
MAIL_MAILER=log
```

## Live desk

Set `REPLY_DESK_STORE=hubspot`, `HUBSPOT_ACCESS_TOKEN`, and `HUBSPOT_PORTAL_ID`. A write stops unless the token's portal matches `HUBSPOT_PORTAL_ID`. The token stays in `.env` and is never printed.

`php artisan reply:backfill {path}` prints the inbound notes it would write from an Open table. It writes only with `--write`, and only when the store is HubSpot and the portal is `145255358`.

## Commands

The same actions the panel uses, except send:

```bash
php artisan reply:list-open
php artisan reply:log-inbound {company} {email} {receipt} {source} {summary}
php artisan reply:save-draft {inbound} {subject} {body}
php artisan reply:discard-draft {inbound}
php artisan reply:mark-sent {inbound} {sent} {subject}
```

`receipt`, `sent`, and `--now` are `Y-m-d H:i` in Europe/London. `source` is `mail client` or `logged`.

## Cursor

Start the stdio server from this repo:

```bash
npm run mcp
```

Cursor runs `node mcp-server/index.mjs`. The tools are `list_open_replies`, `log_inbound`, `save_draft`, `discard_draft`, and `mark_reply_sent`. There is no send tool. The process shells to the Artisan commands. It does not talk to HubSpot itself.

## Tests

```bash
composer test
```

That runs the Pest suite. One test starts the MCP server and checks the tool list. The suite uses the memory store, recorded HubSpot responses, and the array mailer. It does not call a live portal or a real mailbox.

GitHub Actions runs the same command on every push and pull request: [`.github/workflows/tests.yml`](.github/workflows/tests.yml). The job uses PHP 8.4 and Node 22.
