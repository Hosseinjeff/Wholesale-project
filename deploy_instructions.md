# Desktop-Independent Deployment

## 🎯 Goal: Bot runs 24/7 without your desktop

## Method 1: Railway (Easiest - FREE)

### Step 1: Create Railway Account
- Go to: https://railway.app
- Sign up with GitHub
- Connect your GitHub repo

### Step 2: Deploy
```bash
# Railway will auto-detect Python
# Add environment variables in Railway dashboard:

TELEGRAM_BOT_TOKEN=8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8
GOOGLE_WEB_APP_URL=https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec
LOG_LEVEL=INFO
```

### Step 3: Set Webhook
```bash
# After deployment, Railway gives you a URL like:
# https://your-project.up.railway.app

# Set the webhook:
curl "https://api.telegram.org/bot8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8/setWebhook?url=https://your-project.up.railway.app/webhook"
```

## Method 2: Render (Also FREE)

### Step 1: Create Render Account
- Go to: https://render.com
- Connect GitHub

### Step 2: Create Web Service
- New → Web Service
- Connect repo
- Runtime: Python 3
- Build Command: `pip install -r requirements.txt`
- Start Command: `python webhook_bot.py`

### Step 3: Environment Variables
```
TELEGRAM_BOT_TOKEN=8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8
GOOGLE_WEB_APP_URL=https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec
LOG_LEVEL=INFO
```

### Step 4: Set Webhook
```bash
# Render URL will be: https://your-service.onrender.com
curl "https://api.telegram.org/bot8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8/setWebhook?url=https://your-service.onrender.com/webhook"
```

## Method 3: Vercel (FREE)

### Step 1: Install Vercel CLI
```bash
npm install -g vercel
```

### Step 2: Deploy
```bash
cd your-project
vercel --prod

# Add environment variables:
vercel env add TELEGRAM_BOT_TOKEN
vercel env add GOOGLE_WEB_APP_URL
```

### Step 3: Set Webhook
```bash
# Vercel URL will be shown after deployment
curl "https://api.telegram.org/bot8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8/setWebhook?url=https://your-vercel-url.vercel.app/api/webhook"
```

## Method 4: Heroku (Requires Credit Card)

### Step 1: Create Heroku App
```bash
heroku create your-bot-name
```

### Step 2: Set Environment Variables
```bash
heroku config:set TELEGRAM_BOT_TOKEN=8266854184:AAEvZqs0tbjctOeQsh3JGpYr84r272tbxd8
heroku config:set GOOGLE_WEB_APP_URL=https://script.google.com/macros/s/YOUR_SCRIPT_ID/exec
```

### Step 3: Deploy
```bash
git push heroku main
```

### Step 4: Set Webhook
```bash
# Get Heroku URL:
heroku info -s | grep web_url

# Set webhook with the Heroku URL
```

## 🧪 Testing

After deployment:

1. **Forward a message** to `@molavisale_bot`
2. **Check your Google Sheet** - data should appear automatically!
3. **Bot responds** with confirmation message

## 📱 How It Works (Desktop-Independent)

1. **Telegram sends webhook** to your cloud server when messages arrive
2. **Your bot processes** the forwarded message instantly
3. **Data is sent** to Google Apps Script
4. **Google Sheet updates** automatically
5. **No desktop required!**

## 🚀 Recommended: Start with Railway

Railway is the easiest:
- FREE tier available
- Automatic deployments
- Built-in database if needed later
- Excellent Python support

**Want me to help you deploy to Railway specifically?** I can guide you through each step!
