# 🖥️ Desktop-Independent Telegram to Google Sheets

**Your bot runs 24/7 on the cloud - no desktop required!**

## 🎯 What You Get

✅ **Bot runs continuously** on Railway (free tier available)  
✅ **Automatic imports** when you forward messages  
✅ **Instant Google Sheets updates**  
✅ **No desktop/computer needed**  

## 🚀 Quick Setup (Railway)

### 1. Deploy to Railway (2 minutes)

```bash
# Follow the Railway deployment guide above
# Your bot will be live at: https://your-app.up.railway.app
```

### 2. Set Telegram Webhook

```bash
curl "https://api.telegram.org/bot8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8/setWebhook?url=https://your-app.up.railway.app/webhook"
```

### 3. Test

1. Forward any message to `@molavisale_bot`
2. Check your Google Sheet - data appears instantly!
3. Bot replies with confirmation

## 📊 How It Works

```
User forwards message → Telegram → Railway (app.py) → Google Sheet
                                      ↑
                            The only required process
```

A desktop process is optional. Leave it off unless you are testing on your own machine.

Cloudflare Workers is not in this path. Do not add one to front the bot.

## 🛠️ Files Overview

- `app.py` - Flask webhook handler for Railway
- `webhook_bot.py` - Telegram webhook bot
- `google_apps_script.js` - Google Sheets updater
- `railway.json` - Railway deployment config
- `deploy_to_railway.py` - Setup instructions

## 🎉 Benefits

- **Always online** - Works even when your computer is off
- **Instant processing** - No polling delays
- **Scalable** - Handles multiple users
- **Free tier** - Railway gives $5/month credits
- **Reliable** - Cloud infrastructure

## Optional local run

Railway is the process that stays up. Use a computer only when you want to test:

```bash
# Webhook on this machine
python app.py

# Polling, until you press Ctrl+C
python webhook_bot.py
```

## 🔧 Troubleshooting

**Bot not responding?**
```bash
# Check webhook status
curl "https://api.telegram.org/bot8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8/getWebhookInfo"
```

**Railway deployment issues?**
- Check Railway logs in dashboard
- Verify environment variables
- Ensure webhook URL is correct

## 🎊 Success!

Your wholesale channel import system now runs **completely independently** of your desktop! 🎉

**Forward messages anytime → Automatic Google Sheets updates**

