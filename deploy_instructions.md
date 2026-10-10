# 🚀 Railway Deployment Instructions

This project is optimized for deployment on [Railway.app](https://railway.app). Follow these steps to deploy the Telegram-to-Google-Sheets bridge.

## 1. Prerequisites
- A [Railway.app](https://railway.app) account (GitHub login recommended).
- A Telegram Bot Token from [@BotFather](https://t.me/BotFather).
- A Google Apps Script Web App URL (deployed as "Anyone" with access).

## 2. Deployment Steps
1. **Connect Repository**:
   - Go to Railway Dashboard -> **New Project** -> **Deploy from GitHub repo**.
   - Select your `Wholesale-project` repository.

2. **Configure Environment Variables**:
   In the Railway project settings, go to the **Variables** tab and add:
   - `TELEGRAM_BOT_TOKEN`: Your bot token from BotFather.
   - `GOOGLE_SERVICE_ACCOUNT_JSON`: Service account key JSON, on one line. This is the catalog writer.
   - `GOOGLE_SHEET_ID`: Spreadsheet that receives `MessageData`, `Offers`, and `Products`.
   - `GOOGLE_WEB_APP_URL`: Legacy Apps Script URL. Used only when the service account is not set. It does not write the offer log or the current-book columns.
   - `PORT`: `8080` (Railway usually sets this automatically).
   - `RAILWAY_ENVIRONMENT`: `production`.

3. **Wait for Build**:
   Railway will automatically detect the `Dockerfile` and `railway.json`. It will install dependencies from `requirements.txt` and start the Flask app using `python app.py`.

## 3. Set Telegram Webhook
Once the app is "Active" on Railway:
1. Copy your **Public Networking URL** from the Railway "Settings" tab (e.g., `https://wholesale-project-production.up.railway.app`).
2. Run this command in your local terminal (replace `YOUR_BOT_TOKEN` and `YOUR_RAILWAY_URL`):
   ```bash
   curl "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=YOUR_RAILWAY_URL/webhook"
   ```

## 4. Verification
- Send/Forward a message to your Telegram bot.
- Check the Railway "Logs" tab to see the incoming webhook and processing.
- Check your Google Sheet. `MessageData` keeps the forwarded post, `Offers` appends every price, and `Products` shows the latest price for that supplier and product, with the previous price beside it.
- `Items` is where you type the real item name and the price you charge. Those two cells are left as you typed them.
- `Decisions` is the list to work: a missing unit price, a price that moved, an odd gap, an item you have not named, or a supplier who costs more than another for the same confirmed name. Quiet rows drop off. After you edit `Items`, the next forwarded post rebuilds the list. `POST /desk/refresh` rebuilds it immediately.

## 5. What this deploy is

Railway runs the container. A desktop server is not required. `python app.py` and `python webhook_bot.py` remain available if you want to test on a computer.

Cloudflare Workers is not used. This repository has no Worker script and no Wrangler config. A "Workers Builds: wholesale-project" check on a pull request comes from the Cloudflare Workers GitHub connection on the repository. Disconnect that project in the Cloudflare dashboard, or remove the Cloudflare Workers and Pages app from the GitHub repository settings. The Railway deploy does not depend on that check.

## 6. Troubleshooting
- **403 Error**: Ensure Google Apps Script is deployed as "Anyone" (even anonymous).
- **Webhook Not Working**: Check the webhook status:
  `https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo`
- **Missing Data**: Check the `ExecutionLogs` tab in your Google Sheet for error details from the Apps Script side.
