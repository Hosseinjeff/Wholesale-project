# Channel Posts to Google Sheets Importer

This Python application reads posts from various channels (Telegram, Discord, Slack) and imports them into Google Spreadsheets.

## Features

- **Multi-platform support**: Telegram, Discord, and Slack channels
- **Google Sheets integration**: Direct import to Google Spreadsheets
- **Configurable**: Easy setup via environment variables
- **Logging**: Comprehensive logging for debugging and monitoring
- **Error handling**: Robust error handling with retry mechanisms

## Quick Start

### 1. Install Dependencies

```bash
pip install -r requirements.txt
```

### 2. Configure Your Credentials

1. Copy the example configuration:
   ```bash
   cp env_example.txt .env
   ```

2. Edit `.env` and fill in your credentials (see setup sections below)

### 3. Run the Importer

```bash
python channel_to_sheets.py --channel telegram --sheet-id YOUR_SHEET_ID
```

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

## Automation

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
