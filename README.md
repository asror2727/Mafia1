# Genzo Mafia PHP bot — GitHub + Render + PostgreSQL

This package keeps the original PHP game logic and replaces its JSON-file storage API (`J()` / `S()`) with a PostgreSQL JSONB key-value store. The first request creates the `mafia_kv` table automatically.

## Before deploying

1. Create a private GitHub repository and upload these files. Do not upload `.env` or real bot credentials.
2. In Render, create a PostgreSQL database.
3. Create a **Web Service** from the GitHub repository and select **Docker** as the runtime.
4. In the web service Environment settings, add:
   - `TELEGRAM_BOT_TOKEN` — token from BotFather
   - `TELEGRAM_OWNER_ID` — your numeric Telegram user ID
   - `DATABASE_URL` — the database's **internal** connection string from Render
   - `CRON_SECRET` — a long random secret (at least 32 characters)
   - `TELEGRAM_WEBHOOK_SECRET` — another random secret, 1–256 chars, using only A-Z, a-z, 0-9, `_`, `-`
5. Deploy. Check `https://YOUR-SERVICE.onrender.com/healthz`; it should return `{"ok":true,"service":"genzo-mafia"}`.

## Set the Telegram webhook

After deployment, open this URL in a browser, replacing placeholders with your real values. URL-encode the bot token if needed. Telegram's `secret_token` must match `TELEGRAM_WEBHOOK_SECRET`.

`https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=https%3A%2F%2FYOUR-SERVICE.onrender.com%2F&secret_token=YOUR_WEBHOOK_SECRET`

Telegram should return `"ok":true`. Do not share that URL because it contains the bot token.

## Game timer (tick)

The code includes a protected `?tick=1&key=...` endpoint. Configure an external scheduler/cron service to request this URL every minute:

`https://YOUR-SERVICE.onrender.com/?tick=1&key=YOUR_CRON_SECRET`

Use the same value as `CRON_SECRET`. Do not publish it. A sleeping free web service may delay games, so reliable timed games generally need an always-on paid web service or another reliable scheduler.

## Important limitations / next checks

- This is a deployment adaptation, not a full security audit or live Telegram test. Syntax validation does not prove every game/payment flow works.
- Existing local JSON data is not automatically imported into PostgreSQL. This is a fresh storage setup unless you separately migrate existing `mafiya_data/*.json` files.
- The code's Uzcard integration is not confirmed against a real merchant account. Keep automated payments disabled until provider credentials, callback signature and amount/order validation are verified.
- Keep one web-service instance for now. The original game logic uses read-modify-write collections; running multiple instances can cause concurrent update conflicts.
- The owner ID must be a numeric Telegram user ID, not a username.
