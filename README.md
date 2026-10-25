# Channel Posts to Google Sheets Importer

This service turns forwarded supplier posts into a wholesale price book.

The live process is the Railway service in `app.py`. Telegram delivers a forwarded post to the webhook, and the service writes the Google Sheet. Nothing has to stay running on a desktop. A local run is optional and is only for testing.

Cloudflare Workers is not part of this system. Railway builds the container from the `Dockerfile`.

## Storefront and business documentation

The project also includes the WordPress/WooCommerce shop and an older PHP storefront.
The ingestion setup below is not a complete specification of the shopping experience.

- [Business flow and pricing rules](docs/business-flow.md): current code behavior,
  proposed first-release ordering flow, and unresolved business decisions.
- [Start here in another chat](docs/project-handoff.md): project context and the current checkpoint.
- [Whole operating-stream roleplay](docs/workflow-roleplay.md): supplier data entry,
  processing, review, pricing, publication, customer UI, order receipt, management,
  fulfillment, and follow-up. [Customer-journey appendix](docs/roleplay-plan.md).
- [Decision log](docs/decisions.md) and [roleplay state](docs/roleplay-state.json):
  explicit requirements, open proposals, and where to resume.
- [GAS-to-bot migration audit](docs/gas-migration-audit.md) and
  [GAS function inventory](docs/gas-migration-inventory.md): reproduced transition
  gaps in the repository versions. Migration is not signed off.
- [Recovered GAS audit](docs/recovered-gas-audit.md) and
  [176-function inventory](docs/recovered-gas-inventory.md): the user-supplied
  `ReferencePrices`, AI, inventory and storefront implementation, its migration
  differences and reproduced defects. [Original local snapshot](legacy-gas/).
- [Storefront design decisions](docs/design-decisions.md): mockup revisions, desktop/phone
  layouts, maximum versus current discounts, and validation boundaries.
- The interactive mockup remains a local draft; its status and limitations are recorded
  in the design notes. It does not submit orders or replace the live shop.

### GAS source of truth for future chats

The user-supplied export is versioned in
[legacy-gas/recovered-2026-10-25](legacy-gas/recovered-2026-10-25/), with original-file
hashes in its manifest. Start with [the source guide](legacy-gas/README.md), then use
the function inventory to inspect only the needed code. This is the source of truth
for that supplied snapshot; the active deployed version is still unverified. The root
`google_apps_script.js` is an older implementation. No repeat upload is needed when
the chat has access to this repository.

## Features

- **Multi-platform support**: Telegram, Bale, Discord, and Slack channels
- **Google Sheets integration**: Direct import to Google Spreadsheets
- **Configurable**: Easy setup via environment variables
- **Logging**: Comprehensive logging for debugging and monitoring
- **Error handling**: Robust error handling with retry mechanisms

## Quick Start

### 1. Install Dependencies

```bash
pip install -r requirements.txt
```

The optional desktop importers (`channel_to_sheets.py`, `webhook_bot.py`) need the extra libraries in `requirements-desktop.txt`. The Railway service does not install those.

### 2. Configure Your Credentials

1. Copy the example configuration:
   ```bash
   cp env_example.txt .env
   ```

2. Edit `.env` and fill in your credentials (see setup sections below)

### 3. Run it

Deploy `app.py` on Railway. See `deploy_instructions.md`. The service registers its Telegram webhook from `RAILWAY_PUBLIC_DOMAIN` or `RAILWAY_WEBHOOK_URL`.

To try the webhook on a computer instead, run:

```bash
python app.py
```

`channel_to_sheets.py` and `webhook_bot.py` are the older desktop importers. They are optional. The Railway service does not use them.

## Detailed Setup

### Telegram Setup (Forwarding Method)

This project uses a **forwarding approach** - you forward posts from any channel to your bot, and the bot imports them to Google Sheets.

1. **Create a Telegram Bot**:
   - Message `@BotFather` on Telegram
   - Send `/newbot` and follow instructions
   - You'll get a bot token (something like `123456789:ABCdef...`)

2. **Configure in .env**:
   ```
   TELEGRAM_BOT_TOKEN=your_bot_token_from_botfather
   ```

3. **Forward Messages**:
   - Find posts you want to import in any Telegram channel
   - Forward them to your bot (search for your bot's username)
   - The bot will automatically process forwarded messages

**Why this approach?**
- ✅ No API registration required
- ✅ Works with any public channel
- ✅ You control exactly which posts to import
- ✅ No admin rights needed

### Bale Setup

1. **Create a Bale Bot**:
   - Contact `@BotFather` on Bale to create a new bot.
   - Get your bot token.

2. **Configure in .env**:
   ```
   BALE_BOT_TOKEN=your_bale_bot_token
   ```

3. **Forward Messages**:
   - Similar to Telegram, forward posts from any Bale channel to your bot.
   - The bot will process these messages and make them available for import.

### Discord Setup

1. **Create a Discord bot**:
   - Go to https://discord.com/developers/applications
   - Create a new application
   - Go to "Bot" section and create a bot
   - Copy the bot token

2. **Configure in .env**:
   ```
   DISCORD_BOT_TOKEN=your_bot_token
   DISCORD_CHANNEL_ID=channel_id
   ```

3. **Invite bot to your server**:
   - In Discord developer portal, go to "OAuth2" > "URL Generator"
   - Select "bot" scope and necessary permissions
   - Use the generated URL to invite the bot

### Slack Setup

1. **Create a Slack app**:
   - Visit https://api.slack.com/apps
   - Create a new app
   - Add bot scope: `channels:history`
   - Install the app to your workspace

2. **Configure in .env**:
   ```
   SLACK_BOT_TOKEN=xoxb-your-bot-token
   SLACK_CHANNEL_ID=C1234567890
   ```

### Google Sheets Setup

1. **Enable Google Sheets API**:
   - Go to [Google Cloud Console](https://console.cloud.google.com/)
   - Create a new project or select existing one
   - Enable Google Sheets API

2. **Create credentials**:
   - Go to "Credentials" in the left sidebar
   - Click "Create Credentials" > "OAuth 2.0 Client IDs"
   - Choose "Desktop application"
   - Download the JSON file as `credentials.json`

3. **Configure in .env**:
   ```
   GOOGLE_SHEETS_CREDENTIALS_PATH=credentials.json
   ```

## Usage

### Telegram Forwarding Workflow

1. **Find posts** in any Telegram channel you want to import
2. **Forward them** to your bot (`@molavisale_bot`)
3. **Run the importer**:
   ```bash
   python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID
   ```
4. **Data appears** in your Google Sheet with full metadata

### Command Line Options

```bash
python channel_to_sheets.py [OPTIONS]

Options:
  --channel TEXT       Channel type: telegram, discord, slack [required]
  --sheet-id TEXT      Google Sheets ID [required]
  --range TEXT         Starting cell range (default: A1)
  --limit INTEGER      Max posts to fetch (default: 100)
  --config TEXT        Config file path (default: .env)
  --verbose, -v        Enable verbose logging
  --help               Show this message and exit
```

### Examples

**Import forwarded Telegram messages**:
```bash
python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID
```

**Test your bot setup**:
```bash
python demo_forwarding.py
```

**Import with custom range and limit**:
```bash
python channel_to_sheets.py --channel slack --sheet-id 1ABC...xyz --range B2 --limit 50
```

**Verbose logging for debugging**:
```bash
python channel_to_sheets.py --channel discord --sheet-id 1ABC...xyz --verbose
```

## Output Format

The script writes the following columns to Google Sheets:

| Column | Description |
|--------|-------------|
| id | Unique post identifier |
| channel | Channel type (telegram/discord/slack) |
| author | Post author |
| content | Post text content |
| timestamp | Post timestamp (ISO format) |
| url | Direct link to the post |
| has_media | Whether post contains media |
| views | Number of views (if available) |
| reactions | Number of reactions/likes (if available) |

## Optional desktop run

The Railway service replaces a machine that has to stay on. These paths remain if you want to import from a computer:

- `python app.py` serves the webhook locally.
- `python webhook_bot.py` polls Telegram until you stop it.
- `python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID` imports on demand.

### Schedule with Cron (Linux/Mac)

Add to crontab for daily execution:
```bash
crontab -e
# Add this line (adjust path and parameters)
0 9 * * * cd /path/to/project && python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID
```

### Windows Task Scheduler

1. Open Task Scheduler
2. Create a new task
3. Set trigger (daily, etc.)
4. Set action to run: `python.exe`
5. Add arguments: `channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID`
6. Set working directory to your project folder

## Troubleshooting

### Common Issues

1. **Telegram authentication fails**:
   - Ensure phone number format is correct (+country code)
   - Complete 2FA if required
   - Check that channel username is public or you're a member

2. **Google Sheets permission denied**:
   - Verify `credentials.json` is correct
   - Check spreadsheet sharing permissions
   - Ensure Google Sheets API is enabled

3. **Discord bot can't read messages**:
   - Verify bot has proper permissions in the server
   - Check channel ID is correct
   - Ensure bot is added to the server

4. **Slack API errors**:
   - Verify bot token is correct
   - Check app has necessary scopes
   - Ensure bot is installed in the workspace

### Debug Mode

Enable verbose logging to see detailed error information:

```bash
python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID --verbose
```

Check the log file `channel_import.log` for additional details.

## Security Notes

- Never commit `.env` file or `credentials.json` to version control
- Keep API keys and tokens secure
- Use environment-specific credentials for production
- Regularly rotate API tokens

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License

MIT License - feel free to use and modify as needed.
