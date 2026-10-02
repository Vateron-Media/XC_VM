#!/usr/bin/env python3
"""XC_VM support bot: answers questions about XC_VM on Telegram with Claude.

Its knowledge is the project's own documentation (docs/en and the README),
sent as one cached system prompt, so an answer is only as good as the docs.
Settings come from the environment; see README.md beside this file.

    python bot.py                      run the bot (long polling)
    python bot.py --ask "question"     one answer in the terminal, no Telegram
"""

import asyncio
import logging
import os
import sys
import time
from collections import deque
from pathlib import Path

import anthropic
import httpx2

REPO = Path(__file__).resolve().parents[2]
DOCS_SITE = "https://vateron-media.github.io/XC_VM/"
TELEGRAM_LIMIT = 4000  # Telegram's cap is 4096 characters per message
IDLE_RESET_SEC = 1800  # a conversation forgets itself after 30 idle minutes

MODEL = os.environ.get("BOT_MODEL", "claude-opus-5-5")
EFFORT = os.environ.get("BOT_EFFORT", "medium")
SUPPORT_URL = os.environ.get("BOT_SUPPORT_URL", "https://github.com/Vateron-Media/XC_VM/issues")
DOCS_DIR = Path(os.environ.get("BOT_DOCS_DIR", REPO / "docs" / "en"))
HISTORY_TURNS = int(os.environ.get("BOT_HISTORY_TURNS", "6"))
QUESTIONS_PER_HOUR = int(os.environ.get("BOT_QUESTIONS_PER_HOUR", "20"))
DOCS_SKIP = {d.strip() for d in os.environ.get("BOT_DOCS_SKIP", "").split(",") if d.strip()}
ALLOWED_CHATS = {c.strip() for c in os.environ.get("BOT_ALLOWED_CHATS", "").split(",") if c.strip()}

log = logging.getLogger("xc_vm_support_bot")

INSTRUCTIONS = f"""You are the XC_VM support assistant, talking to people on Telegram.

XC_VM is an open-source IPTV management panel (Xtream-Codes style): one MAIN server runs the admin
panel and database, load balancers serve streams and viewers. The people who ask are panel
administrators of every skill level, and many are not developers.

How to answer:
- Answer in the language the person writes in.
- Use plain, friendly words and short sentences. When something has steps, give them as a numbered
  list with the exact menu path (for example Servers → Install Load Balancer) or the exact command.
  If you must use a technical term, explain it in a few words.
- Keep answers short enough to read on a phone. Offer to go into more detail instead of writing
  everything at once.
- Take facts from the documentation below. If it does not cover the question, or you are not sure,
  say so plainly and suggest opening an issue at {SUPPORT_URL} with the panel version, the server's
  operating system and the exact error message. Never invent menu names, settings, commands or
  version numbers.
- When a documentation page helps, give its link (the url attribute of its <doc> tag).
- Never ask for passwords, licence keys, API keys or private server details. If someone pastes one,
  tell them to delete the message and change that secret.
- Help with installing, running and troubleshooting XC_VM, including the Linux and networking basics
  it needs. Politely decline unrelated requests, and do not help anyone obtain or share content they
  have no rights to.
- Telegram shows your answer as plain text: do not use Markdown (no **, no #, no tables). Use
  numbered steps or lines starting with "- ", and put each command on its own line."""


def doc_url(path: Path, docs_dir: Path) -> str:
    """The published page for a file under docs/en (MkDocs: README.md is its folder's index)."""
    rel = path.relative_to(docs_dir)
    page = rel.parent if rel.name == "README.md" else rel.with_suffix("")
    return DOCS_SITE + ("" if str(page) == "." else page.as_posix() + "/")


def load_docs(docs_dir: Path = DOCS_DIR, readme: Path = REPO / "README.md", skip: set[str] = DOCS_SKIP) -> str:
    """Every English doc page and the README, in a fixed order so the prompt cache keeps hitting.

    `skip` leaves out top-level folders of the docs (BOT_DOCS_SKIP), e.g. {"development"}.
    """
    parts = []
    if readme.is_file():
        parts.append(f'<doc url="https://github.com/Vateron-Media/XC_VM" path="README.md">\n{readme.read_text()}\n</doc>')
    for path in sorted(docs_dir.rglob("*.md")):
        if path.relative_to(docs_dir).parts[0] in skip:
            continue
        parts.append(f'<doc url="{doc_url(path, docs_dir)}" path="{path.relative_to(docs_dir)}">\n{path.read_text()}\n</doc>')
    return "<documentation>\n" + "\n".join(parts) + "\n</documentation>"


def split_message(text: str, limit: int = TELEGRAM_LIMIT) -> list[str]:
    """Telegram-sized chunks, cut at a paragraph or line break where there is one."""
    chunks = []
    while len(text) > limit:
        cut = max(text.rfind("\n\n", 0, limit), text.rfind("\n", 0, limit))
        cut = cut if cut > limit // 2 else limit
        chunks.append(text[:cut].rstrip())
        text = text[cut:].lstrip()
    return chunks + [text] if text else chunks


def question_for_bot(message: dict, bot_username: str) -> str | None:
    """The question in a message, or None when it is not for the bot.

    In a private chat every text is a question. In a group the bot answers only
    /ask, a mention of it, or a reply to one of its messages.
    """
    text = (message.get("text") or "").strip()
    if not text:
        return None
    mention = "@" + bot_username.lower()
    words = text.split(maxsplit=1)
    if words[0].lower().split("@")[0] == "/ask":
        return words[1].strip() if len(words) > 1 else ""
    if message.get("chat", {}).get("type") == "private":
        return text
    replied = message.get("reply_to_message", {}).get("from", {}).get("username", "")
    if mention in text.lower() or replied.lower() == bot_username.lower():
        return " ".join(w for w in text.split() if w.lower() != mention).strip()
    return None


class RateLimiter:
    """At most `per_hour` questions per user in any hour."""

    def __init__(self, per_hour: int):
        self.per_hour = per_hour
        self.seen: dict[int, deque] = {}

    def allow(self, user_id: int, now: float | None = None) -> bool:
        now = time.time() if now is None else now
        q = self.seen.setdefault(user_id, deque())
        while q and now - q[0] > 3600:
            q.popleft()
        if len(q) >= self.per_hour:
            return False
        q.append(now)
        return True


class Assistant:
    """Claude with the docs as a cached system prompt, and a short memory per conversation."""

    def __init__(self, docs: str):
        self.client = anthropic.AsyncAnthropic()
        # The docs block carries the cache marker: instructions + docs are read from the cache
        # (1-hour TTL: support questions come in bursts with gaps longer than 5 minutes).
        self.system = [
            {"type": "text", "text": INSTRUCTIONS},
            {"type": "text", "text": docs, "cache_control": {"type": "ephemeral", "ttl": "1h"}},
        ]
        self.history: dict[tuple, deque] = {}
        self.last_seen: dict[tuple, float] = {}

    def reset(self, key: tuple) -> None:
        self.history.pop(key, None)

    async def answer(self, key: tuple, question: str) -> str:
        if time.time() - self.last_seen.get(key, 0) > IDLE_RESET_SEC:
            self.reset(key)
        self.last_seen[key] = time.time()
        past = self.history.setdefault(key, deque(maxlen=HISTORY_TURNS * 2))
        try:
            response = await self.client.beta.messages.create(
                model=MODEL,
                max_tokens=16000,
                output_config={"effort": EFFORT},
                # A declined request is re-run on Anthropic's recommended fallback model.
                betas=["server-side-fallback-2026-07-01"],
                fallbacks="default",
                system=self.system,
                messages=[*past, {"role": "user", "content": question}],
            )
        except anthropic.RateLimitError:
            return "I'm getting a lot of questions right now. Please try again in a minute."
        except anthropic.APIStatusError as e:
            log.error("Claude API error %s: %s", e.status_code, e.message)
            if e.status_code >= 500:
                return "I can't reach my answer service right now. Please try again in a few minutes."
            return f"Something went wrong on my side. If it keeps happening, please ask at {SUPPORT_URL}"
        except anthropic.APIConnectionError:
            log.error("Claude API unreachable")
            return "I can't reach my answer service right now. Please try again in a few minutes."

        u = response.usage
        log.info("answered %s: in=%s cache_read=%s cache_write=%s out=%s stop=%s", key, u.input_tokens,
                 u.cache_read_input_tokens, u.cache_creation_input_tokens, u.output_tokens, response.stop_reason)
        if response.stop_reason == "refusal":
            return f"Sorry, I can't help with that one. For XC_VM questions, you can also ask at {SUPPORT_URL}"
        text = "".join(b.text for b in response.content if b.type == "text").strip()
        if not text:
            return "Sorry, I couldn't put an answer together. Could you ask in a different way?"
        past.extend([{"role": "user", "content": question}, {"role": "assistant", "content": text}])
        return text


COMMANDS = ("/start", "/help", "/reset", "/ask")

WELCOME = f"""Hi! I'm the XC_VM support assistant.

Ask me anything about installing, setting up or fixing your XC_VM panel, in your own words and your own language. I answer from the official documentation:
{DOCS_SITE}

- In a group, start your message with /ask or mention me.
- /reset starts a fresh conversation.
- Never send me passwords or licence keys.

If I can't solve it, open an issue here: {SUPPORT_URL}"""


class TelegramBot:
    def __init__(self, token: str, assistant: Assistant):
        self.api = f"https://api.telegram.org/bot{token}/"
        self.http = httpx2.AsyncClient(timeout=70)
        self.assistant = assistant
        self.limiter = RateLimiter(QUESTIONS_PER_HOUR)
        self.busy = asyncio.Semaphore(4)  # answers worked on at once
        self.username = ""

    async def call(self, method: str, **params) -> dict:
        r = await self.http.post(self.api + method, json=params)
        body = r.json()
        if not body.get("ok"):
            raise RuntimeError(f"Telegram {method}: {body.get('description')}")
        return body["result"]

    async def reply(self, message: dict, text: str) -> None:
        for chunk in split_message(text):
            await self.call("sendMessage", chat_id=message["chat"]["id"], text=chunk,
                            reply_parameters={"message_id": message["message_id"], "allow_sending_without_reply": True},
                            link_preview_options={"is_disabled": True})

    async def typing(self, chat_id: int) -> None:
        while True:  # Telegram shows "typing…" for 5 s per call
            try:
                await self.call("sendChatAction", chat_id=chat_id, action="typing")
            except (httpx2.HTTPError, RuntimeError):
                pass  # only a courtesy: the answer goes out anyway
            await asyncio.sleep(4)

    def command(self, text: str) -> str:
        """The bot command a message starts with, or "" (a pasted path such as /home/... is text)."""
        word = text.split(maxsplit=1)[0].lower() if text.startswith("/") else ""
        name, _, to = word.partition("@")
        if name not in COMMANDS or (to and to != self.username.lower()):
            return ""
        return name

    async def handle(self, message: dict) -> None:
        chat_id = message["chat"]["id"]
        if ALLOWED_CHATS and str(chat_id) not in ALLOWED_CHATS:
            return
        text = (message.get("text") or "").strip()
        command = self.command(text)
        if text.startswith("/") and not command and message["chat"]["type"] != "private":
            return  # another bot's command, in a group
        key = (chat_id, message.get("from", {}).get("id", 0))
        if command in ("/start", "/help"):
            return await self.reply(message, WELCOME)
        if command == "/reset":
            self.assistant.reset(key)
            return await self.reply(message, "Done, let's start fresh. What would you like to know?")
        question = question_for_bot(message, self.username)
        if question is None:
            return
        if not question:
            return await self.reply(message, "What would you like to know? Write your question after /ask.")
        if len(question) > 3000:
            return await self.reply(message, "That's a long message. Please ask a shorter question, with only the important part of any error.")
        if not self.limiter.allow(key[1]):
            return await self.reply(message, "You've asked a lot of questions this hour. Please try again a bit later.")
        async with self.busy:
            typing = asyncio.create_task(self.typing(chat_id))
            try:
                answer = await self.assistant.answer(key, question)
            finally:
                typing.cancel()
        await self.reply(message, answer)

    async def safe_handle(self, message: dict) -> None:
        try:
            await self.handle(message)
        except Exception:
            log.exception("failed to handle message %s", message.get("message_id"))

    async def run(self) -> None:
        me = await self.call("getMe")
        self.username = me["username"]
        await self.call("deleteWebhook")  # long polling and a webhook cannot both be set
        log.info("running as @%s with %s", self.username, MODEL)
        offset = 0
        while True:
            try:
                updates = await self.call("getUpdates", offset=offset, timeout=50, allowed_updates=["message"])
            except (httpx2.HTTPError, RuntimeError) as e:
                log.warning("getUpdates failed: %s", e)
                await asyncio.sleep(5)
                continue
            for update in updates:
                offset = update["update_id"] + 1
                if "message" in update:
                    asyncio.create_task(self.safe_handle(update["message"]))


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx2").setLevel(logging.WARNING)  # its request log would print the bot token
    docs = load_docs()
    log.info("loaded %d characters of documentation from %s", len(docs), DOCS_DIR)
    assistant = Assistant(docs)
    if len(sys.argv) == 3 and sys.argv[1] == "--ask":
        print(asyncio.run(assistant.answer(("cli",), sys.argv[2])))
        return
    token = os.environ.get("TELEGRAM_BOT_TOKEN")
    if not token:
        sys.exit("Set TELEGRAM_BOT_TOKEN (from @BotFather). See README.md.")
    try:
        asyncio.run(TelegramBot(token, assistant).run())
    except RuntimeError as e:  # Telegram refused the start (getMe, deleteWebhook)
        sys.exit(f"{e}. Check TELEGRAM_BOT_TOKEN (see README.md, Troubleshooting).")
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
