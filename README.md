# Escalate by ISP Ledger

Public escalation platform for escalate.ispledger.com. When a customer feels normal support let them down, they raise an escalation here: it is published on a public wall and posted straight to the ISP Ledger Telegram channel, so nothing gets buried. Escalations can be raised on the site itself or from the billing panel (Extras > Escalate).

## What an escalation contains

- Company name (published). On the public form this IS the panel subdomain: the form checks live in DNS that `<company>.<PANEL_DOMAIN>` exists before accepting, so every escalation binds to a real account and shows up on that tenant's panel Escalate page. Panel submissions are key-authenticated and skip the DNS gate (custom domains exist).
- Topic, chosen from `ESCALATION_TOPICS` (wall filter chips, shown on cards)
- Account manager, written by the customer (configured names appear as typing suggestions)
- The issue, minimum 100 words
- 1 to 4 pictures of the issue (required)
- A screenshot of the reply normal support gave, or a declaration that support never responded
- A follow-up phone number (only shown masked publicly, staff see it in full)

## Files

| File | Purpose |
|------|---------|
| `index.php` | Public escalation wall: stats, filters, search, cards |
| `view.php` | Single escalation: full story, image gallery, support reply, official response |
| `submit.php` | Public submission form with live word counter, image previews, and paste (Ctrl+V) / drag-and-drop uploads |
| `api.php` | JSON API used by the billing panels (create + list); never redirects |
| `admin.php` | Staff area: status + reply composer (paste/drag-and-drop images), Telegram retry, delete |
| `lib.php` | Shared helpers: validation, uploads, Telegram posting, reminders, page chrome |
| `cron.php` | No-response reminder / auto-resolve worker (cron or HTTP) |
| `db.php` | PDO connection, auto-creates the `escalations` table (BIGINT ids) |
| `config.sample.php` | Copy to `config.php` and fill in |
| `assets/style.css` | The deep-space theme |
| `uploads/` | Stored images (gitignored, PHP execution disabled) |

## Setup

1. Create a MySQL database and user:

   ```sql
   CREATE DATABASE escalate CHARACTER SET utf8mb4;
   CREATE USER 'escalate'@'localhost' IDENTIFIED BY 'strong_password';
   GRANT ALL PRIVILEGES ON escalate.* TO 'escalate'@'localhost';
   ```

2. `cp config.sample.php config.php` and fill in the database credentials, `BASE_URL` and `ADMIN_PASSWORD`. The table is auto-created on first hit.

3. Make uploads writable by the web user: `chown -R www-data:www-data uploads`.

### Telegram channel posting

1. Talk to `@BotFather`, create a bot, copy the token into `TELEGRAM_BOT_TOKEN`.
2. Add the bot as an Administrator of the channel with the Post Messages permission.
3. Public channel: set `TELEGRAM_CHAT_ID` to `@channelusername`. Private channel: forward any channel post to `@userinfobot` (or call `getUpdates`) to get the `-100...` id and use that.
4. If a post fails (token missing, network), the escalation still saves; the admin area shows "not on Telegram" with a Retry button.

### Nginx site (Ubuntu, PHP-FPM)

```nginx
server {
    server_name escalate.ispledger.com;
    root /var/www/escalate;
    index index.php;

    client_max_body_size 30m;

    location ~ ^/uploads/.*\.php$ { deny all; }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }
}
```

Then point the `escalate` A record at the server and run `certbot --nginx -d escalate.ispledger.com`.

Also raise PHP upload limits in php.ini so 4 pictures plus a screenshot fit: `upload_max_filesize = 6M`, `post_max_size = 30M`.

## No-response reminders

When staff replied last and the customer has been silent for `NUDGE_AFTER_HOURS` (default 48h), a reminder is posted on the thread (public page, panel and Telegram): *no response received in 2 days, the escalation resolves in 24 hours*. If the silence continues for `NUDGE_RESOLVE_AFTER_HOURS` more (default 24h), the status flips to Resolved with a closing note. A customer reply at any point cancels the countdown, and so does any staff action on the escalation.

The pass runs opportunistically on page loads at most once an hour; for punctual delivery add a cron:

```
*/30 * * * * php /var/www/escalate/cron.php
```

## Panel API

Create (multipart POST to `/api.php`):

```
company_name=Skyline WiFi Ltd
subdomain=skyline
follow_up_number=+254712345678
issue=<at least 100 words>
account_manager=Manager Name
topic=Billing & Payments        (must be one of ESCALATION_TOPICS, else stored as Other)
no_support_reply=0
images[]=<file> (1 to 4)
support_screenshot=<file> (required unless no_support_reply=1)
```

Response: `{"ok":true,"id":"<public_id>","url":"https://escalate.ispledger.com/view.php?id=..."}` or `{"ok":false,"error":"...","errors":[...]}` with HTTP 422.

List a tenant's escalations: `GET /api.php?action=list&sub=<subdomain>` returns `{"ok":true,"items":[...],"managers":[...],"topics":[...]}` including status, topic and any official reply.

Check an account exists: `GET /api.php?action=checksub&sub=<name>` returns `{"ok":true,"sub":"skyline","host":"skyline.ispledger.com","valid":true}` (a live DNS lookup; the public form calls this as the customer types).

## Anti-abuse

- Honeypot field on the public form
- Rate limit per IP per hour (`RATE_LIMIT_PER_HOUR`)
- Images validated by real MIME type (JPG, PNG, WEBP, GIF), size capped, random names, PHP execution disabled in `uploads/`
- Staff can delete spam and everything it uploaded from `admin.php`
