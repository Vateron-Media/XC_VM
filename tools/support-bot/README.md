# XC_VM support bot for Telegram

A Telegram bot that answers questions about XC_VM. It uses Claude (Anthropic's AI) and the
project's own documentation, and explains things in simple words, in the language the person
writes in. Use it for small support: "how do I add a load balancer?", "what does this warning
mean?", "why is my stream offline?".

It answers from the documentation in `docs/en` and the main `README.md`. When the docs don't cover
something, it says so and points the person to the GitHub issues page instead of guessing.

## What you need

- **A server to run it on.** Any Linux machine with Python 3.10 or newer and internet access. It
  does not need to be your XC_VM server; a small VPS is enough.
- **A Telegram bot token.** Free, from @BotFather (step 1).
- **An Anthropic API key.** From [console.anthropic.com](https://console.anthropic.com) (step 2).
  Using Claude costs money per question; see [Costs](#costs).

## Setup

### 1. Create the bot in Telegram

1. In Telegram, open a chat with **@BotFather**.
2. Send `/newbot`, then choose a display name (for example *XC_VM Support*) and a username ending
   in `bot` (for example `xcvm_support_bot`).
3. BotFather replies with a **token** like `123456789:AAH...`. Keep it secret: anyone with it
   controls your bot.
4. Optional, for groups: by default a bot in a group only sees commands such as `/ask` and replies
   to its own messages. To let it answer when someone mentions it (`@xcvm_support_bot ...`), send
   BotFather `/setprivacy`, choose your bot and pick **Disable**. Then remove the bot from the group
   and add it again.

### 2. Get an Anthropic API key

1. Sign in at [console.anthropic.com](https://console.anthropic.com) and add a payment method.
2. Open **API Keys** and create a key. It starts with `sk-ant-`.
3. Recommended: set a monthly **spend limit** in the console, so a busy month can't surprise you.

### 3. Install

On the server, as root:

```bash
# Get the code and the docs (the large media files are not needed)
GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 https://github.com/Vateron-Media/XC_VM /opt/xc_vm-support
cd /opt/xc_vm-support/tools/support-bot

# A private Python environment with the one library the bot needs
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt

# Your settings
cp .env.example .env
chmod 600 .env
nano .env        # paste your Telegram token and Anthropic key
```

### 4. Test it

```bash
.venv/bin/python test_bot.py                     # checks the bot's logic, prints "ok"

set -a; . ./.env; set +a                         # load your settings into this shell
.venv/bin/python bot.py --ask "How do I add a load balancer?"
```

The second command asks Claude one question and prints the answer, without Telegram. If it
answers, your key works. Then start the bot for real with `.venv/bin/python bot.py`, send your bot
a message in Telegram, and press Ctrl+C when you are done.

### 5. Run it all the time

Install it as a service, so it starts at boot and restarts if it stops:

```bash
cp xc-vm-support-bot.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now xc-vm-support-bot
systemctl status xc-vm-support-bot               # should say "active (running)"
journalctl -u xc-vm-support-bot -f               # live log; Ctrl+C to leave
```

The service file expects the folder `/opt/xc_vm-support`. If you cloned somewhere else, change
the paths in it first.

## Using the bot

| Where | How to ask |
|---|---|
| Private chat | Just write your question |
| Group | Start with `/ask`, for example `/ask how do I update the panel?`, or reply to one of the bot's messages. With privacy mode off, a mention works too |

Commands: `/start` or `/help` shows a short welcome, `/reset` makes the bot forget the current
conversation. The bot remembers the last few questions of a conversation, so follow-up questions
work. It forgets after 30 minutes of silence.

## Settings

All settings live in `.env`. Only the first two are required.

| Setting | Default | What it does |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | (required) | The token from @BotFather |
| `ANTHROPIC_API_KEY` | (required) | Your Anthropic API key |
| `BOT_ALLOWED_CHATS` | everyone | Comma-separated chat IDs the bot answers in. Group IDs start with `-100`. Empty means every chat |
| `BOT_QUESTIONS_PER_HOUR` | `20` | Questions one person may ask per hour |
| `BOT_SUPPORT_URL` | GitHub issues | Where the bot sends people it cannot help |
| `BOT_DOCS_SKIP` | (none) | Documentation folders to leave out, for example `development,builds`. Answers stay focused on users, and each question costs about half |
| `BOT_MODEL` | `claude-opus-5-5` | The Claude model |
| `BOT_EFFORT` | `medium` | How hard Claude thinks: `low` is faster and cheaper, `high` is more thorough |
| `BOT_HISTORY_TURNS` | `6` | How many earlier question-and-answer pairs the bot remembers |

## Costs

Every question sends the whole documentation to Claude with it. Claude keeps it in a cache, so
most questions only pay a small "cache read" price for it. These are rough estimates for
`claude-opus-5-5`; the exact numbers depend on your docs and questions:

| | All docs (default) | `BOT_DOCS_SKIP=development,builds` |
|---|---|---|
| A normal question | about $0.05 to $0.10 | about $0.03 to $0.08 |
| The first question after an hour with no questions (fills the cache) | about $1.20 | about $0.60 |

Ways to spend less: set `BOT_DOCS_SKIP`, lower `BOT_QUESTIONS_PER_HOUR`, use `BOT_ALLOWED_CHATS` so
only your own group can use it, and always set a spend limit in the Anthropic console. The log
shows each answer's token counts (`cache_read`, `cache_write`, `in`, `out`).

## Keeping answers up to date

The bot reads the documentation when it starts. To update it after the docs change:

```bash
cd /opt/xc_vm-support && GIT_LFS_SKIP_SMUDGE=1 git pull && systemctl restart xc-vm-support-bot
```

## Privacy

- Questions are sent to Anthropic's API to be answered. The bot tells people never to post
  passwords or licence keys, and to change any secret they post by mistake.
- The log records who asked (Telegram IDs) and token counts, never the questions or answers.

## Troubleshooting

| Problem | Fix |
|---|---|
| `Telegram getUpdates: Conflict: terminated by other getUpdates request` | The bot runs twice. Stop the other copy (`systemctl stop xc-vm-support-bot`, or the terminal where you started it) |
| `Telegram getMe: Unauthorized` | The `TELEGRAM_BOT_TOKEN` is wrong. Copy it again from @BotFather |
| The bot answers "Something went wrong on my side" | Look at the log. `401` means a wrong `ANTHROPIC_API_KEY`; a credit or billing error means the Anthropic account needs a payment method or a higher spend limit |
| The bot ignores a group | Use `/ask`, or turn privacy mode off (setup step 1.4). Check `BOT_ALLOWED_CHATS` if you set it |
| The bot says "You've asked a lot of questions this hour" | That person reached `BOT_QUESTIONS_PER_HOUR` |
