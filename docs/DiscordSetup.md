# How to Create & Use a Discord Bot Token

## Create a Bot Application
Go to: https://discord.com/developers/applications

Click New Application

Go to Bot → Add Bot

Copy the Bot Token (this is the one you use)

## Invite the Bot to Your Server
In the Developer Portal:

Go to OAuth2 → URL Generator

Select:

bot

Under Bot Permissions, select:

Send Messages

View Channels

(optional: Embed Links, Manage Messages, etc.)

Copy the generated URL and open it in your browser to invite the bot to your server.

## Get the Channel ID In Discord:

Enable Developer Mode (User Settings → Advanced → Developer Mode)

Right-click the channel → Copy Channel ID

## Manual Testing

### Send a Messagge via API (Raw HTTP Example)

Example using curl

curl -X POST "https://discord.com/api/v10/channels/{CHANNEL_ID}/messages" \
  -H "Authorization: Bot YOUR_BOT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"content":"Hello from my bot"}'

