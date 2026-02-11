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
   - `GOOGLE_WEB_APP_URL`: Your deployed Google Apps Script URL.
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
- Check your Google Sheet to see the data appearing in `MessageData` and `Products` tabs.

## 5. Troubleshooting
- **403 Error**: Ensure Google Apps Script is deployed as "Anyone" (even anonymous).
- **Webhook Not Working**: Check the webhook status:
  `https://api.telegram.org/botYOUR_BOT_TOKEN/getWebhookInfo`
- **Missing Data**: Check the `ExecutionLogs` tab in your Google Sheet for error details from the Apps Script side.
