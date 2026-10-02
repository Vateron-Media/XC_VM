# XC_VM support bot for Telegram

A Telegram bot that answers questions about XC_VM. It reads the project's own documentation and
explains things in simple words, in the language the person writes in. Use it for small support:
"how do I add a load balancer?", "what does this warning mean?", "why is my stream offline?".

It answers from the documentation in `docs/en` and the main `README.md`. When the docs don't cover
something, it says so and points the person to the GitHub issues page instead of guessing.

It can use either:

- **a free cloud AI model** (the default): [Groq](https://console.groq.com)'s free tier, or any other
  service with an OpenAI-compatible API (Cerebras, OpenRouter, Mistral, a local Ollama...). For
  each question the bot looks up the matching parts of the documentation and sends only those;
- **Claude** (paid, the best answers): with an Anthropic API key, the bot sends the whole
  documentation with every question.

## What you need

- **A server to run it on.** Any Linux machine with Python 3.10 or newer and internet access. It
  does not need to be your XC_VM server; a small VPS is enough.
- **A Telegram bot token.** Free, from @BotFather (step 1).
- **A free Groq API key** (step 2), or an Anthropic API key for Claude.

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

### 2. Get a free AI key

1. Open [console.groq.com/keys](https://console.groq.com/keys) and sign in with Google or GitHub
   (free, no credit card).
2. Click **Create API Key**, give it a name, and copy the key. It starts with `gsk_`.

Prefer Claude? Create a key at [console.anthropic.com](https://console.anthropic.com) instead (it
starts with `sk-ant-`, and each question costs money; see [Using Claude](#using-claude)).

### 3. Install

On the server, as root:

```bash
# Get the code and the docs (the large media files are not needed)
GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 https://github.com/Vateron-Media/XC_VM /opt/xc_vm-support
cd /opt/xc_vm-support/tools/support-bot

# A private Python environment with the libraries the bot needs
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt

# Your settings
cp .env.example .env
chmod 600 .env
nano .env        # paste your Telegram token and your Groq key
```

### 4. Test it

```bash
.venv/bin/python test_bot.py                     # checks the bot's logic, prints "ok"

set -a; . ./.env; set +a                         # load your settings into this shell
.venv/bin/python bot.py --ask "How do I add a load balancer?"
```

The second command asks one question and prints the answer, without Telegram. If it answers, your
key works. Then start the bot for real with `.venv/bin/python bot.py`, send your bot a message in
Telegram, and press Ctrl+C when you are done.

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

All settings live in `.env`. Only the Telegram token and one AI key are required.

| Setting | Default | What it does |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | (required) | The token from @BotFather |
| `LLM_API_KEY` | (required for Groq) | Your free Groq key, or the key of another OpenAI-compatible service |
| `LLM_BASE_URL` | `https://api.groq.com/openai/v1` | The service's API address (see below) |
| `LLM_MODEL` | `llama-3.3-70b-versatile` | The model to use on that service |
| `BOT_DOCS_CHARS` | `8000` | How much documentation goes with each question. More gives better answers but uses more of the free allowance |
| `ANTHROPIC_API_KEY` | (none) | Set it to use Claude instead of the free model |
| `CLAUDE_MODEL`, `CLAUDE_EFFORT` | `claude-opus-5-5`, `medium` | The Claude model, and how hard it thinks (`low` is faster and cheaper) |
| `BOT_ALLOWED_CHATS` | everyone | Comma-separated chat IDs the bot answers in. Group IDs start with `-100`. Empty means every chat |
| `BOT_QUESTIONS_PER_HOUR` | `20` | Questions one person may ask per hour |
| `BOT_SUPPORT_URL` | GitHub issues | Where the bot sends people it cannot help |
| `BOT_DOCS_SKIP` | (none) | Documentation folders to leave out, for example `development,builds`, to keep answers focused on users |
| `BOT_HISTORY_TURNS` | `3` | How many earlier question-and-answer pairs the bot remembers |

### Other free services

Any service with an OpenAI-compatible API works: set `LLM_BASE_URL`, `LLM_MODEL` and `LLM_API_KEY`.

| Service | `LLM_BASE_URL` | Example `LLM_MODEL` | Get a key |
|---|---|---|---|
| Groq (default) | `https://api.groq.com/openai/v1` | `llama-3.3-70b-versatile` | [console.groq.com/keys](https://console.groq.com/keys) |
| Cerebras | `https://api.cerebras.ai/v1` | `llama-3.3-70b` | [cloud.cerebras.ai](https://cloud.cerebras.ai) |
| OpenRouter (`:free` models) | `https://openrouter.ai/api/v1` | `meta-llama/llama-3.3-70b-instruct:free` | [openrouter.ai/keys](https://openrouter.ai/keys) |
| Ollama on your own server | `http://127.0.0.1:11434/v1` | `llama3.2:3b` | no key (slow without a GPU) |

The model names change over time; each service lists its current models on its website.

## Free limits

Free tiers limit how much you can use per minute and per day. Each question uses a few thousand
tokens (the question, the matching documentation and the answer). On Groq's free tier that is
enough for a small community. When a limit is reached, the bot tells people to try again in a
minute. To use less: lower `BOT_DOCS_CHARS`, lower `BOT_QUESTIONS_PER_HOUR`, or use
`BOT_ALLOWED_CHATS` so only your own group can use it.

## Using Claude

With `ANTHROPIC_API_KEY` set, the bot uses Claude and sends the whole documentation with every
question, kept in Claude's cache. Rough cost for `claude-opus-5-5`: about $0.05 to $0.10 per
question, and about $1.20 for the first question after an hour without any (it fills the cache).
`BOT_DOCS_SKIP=development,builds` halves that. Set a monthly spend limit in the Anthropic console.

## Keeping answers up to date

The bot reads the documentation when it starts. To update it after the docs change:

```bash
cd /opt/xc_vm-support && GIT_LFS_SKIP_SMUDGE=1 git pull && systemctl restart xc-vm-support-bot
```

## Privacy

- Questions are sent to the AI service you chose, to be answered. Free tiers may keep or use
  them; read your service's terms. The bot tells people never to post passwords or licence keys,
  and to change any secret they post by mistake.
- The log records who asked (Telegram IDs) and token counts, never the questions or answers.

## Troubleshooting

| Problem | Fix |
|---|---|
| `Telegram getUpdates: Conflict: terminated by other getUpdates request` | The bot runs twice. Stop the other copy (`systemctl stop xc-vm-support-bot`, or the terminal where you started it) |
| `Telegram getMe: Unauthorized` | The `TELEGRAM_BOT_TOKEN` is wrong. Copy it again from @BotFather |
| `Set LLM_API_KEY` when it starts | Add your Groq key to `.env` |
| The bot answers "Something went wrong on my side" | Look at the log (`journalctl -u xc-vm-support-bot`). `401` means a wrong key; `404` or "model not found" means `LLM_MODEL` is not offered by that service |
| The bot says "I'm getting a lot of questions right now" | The free limit was reached; it works again after a minute (or the next day for the daily limit) |
| The bot ignores a group | Use `/ask`, or turn privacy mode off (setup step 1.4). Check `BOT_ALLOWED_CHATS` if you set it |
| The bot says "You've asked a lot of questions this hour" | That person reached `BOT_QUESTIONS_PER_HOUR` |
