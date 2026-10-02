#!/usr/bin/env python3
"""XC_VM support bot: answers questions about XC_VM on Telegram from the project's docs.

Two ways to answer, picked by the keys in the environment (see README.md):

- a free cloud model behind an OpenAI-compatible API (Groq by default, LLM_API_KEY):
  the bot searches the docs and sends the model only the sections that match;
- Claude (ANTHROPIC_API_KEY): the whole documentation goes as one cached prompt.

    python bot.py                      run the bot (long polling)
    python bot.py --ask "question"     one answer in the terminal, no Telegram
"""

import asyncio
import logging
import math
import os
import re
import sys
import time
from collections import Counter, deque
from pathlib import Path

import anthropic
import httpx2

REPO = Path(__file__).resolve().parents[2]
REPO_URL = "https://github.com/Vateron-Media/XC_VM"
DOCS_SITE = "https://vateron-media.github.io/XC_VM/"
TELEGRAM_LIMIT = 4000  # Telegram's cap is 4096 characters per message
IDLE_RESET_SEC = 1800  # a conversation forgets itself after 30 idle minutes

LLM_BASE_URL = os.environ.get("LLM_BASE_URL", "https://api.groq.com/openai/v1").rstrip("/")
LLM_MODEL = os.environ.get("LLM_MODEL", "openai/gpt-oss-120b")
# How long a reasoning model thinks (low/medium/high); empty for a model that does not reason.
LLM_REASONING_EFFORT = os.environ.get("LLM_REASONING_EFFORT", "medium")  # low invents menus and slips in commands
LLM_API_KEY = os.environ.get("LLM_API_KEY", "")
DOCS_CHARS = int(os.environ.get("BOT_DOCS_CHARS", "8000"))  # documentation sent with each question (free models)
CLAUDE_MODEL = os.environ.get("CLAUDE_MODEL", "claude-opus-5-5")
CLAUDE_EFFORT = os.environ.get("CLAUDE_EFFORT", "medium")
SUPPORT_URL = os.environ.get("BOT_SUPPORT_URL", REPO_URL + "/issues")
DOCS_DIR = Path(os.environ.get("BOT_DOCS_DIR", REPO / "docs" / "en"))
HISTORY_TURNS = int(os.environ.get("BOT_HISTORY_TURNS", "3"))
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
- Always explain how to do it in the admin panel: its menus, pages, fields and buttons, with the
  exact path (for example Servers → Install Load Balancer), as numbered steps.
- Only name menus, pages, fields, buttons and settings that appear in the panel menus or the
  documentation below. Never guess one: a made-up button sends the person looking for something
  that does not exist. If the documentation does not describe a page's fields and buttons, say
  which page to open and that its form guides them, without listing fields.
- Never give SSH, console, Linux or database commands, not even as an extra option for an
  administrator. If the documentation shows no way to do it in the panel, say so in one friendly
  sentence and give the documentation link, so the person can pass it to whoever manages their
  server.
- Write for someone who is not technical: plain, friendly words and short sentences. If you must
  use a technical term, explain it in a few words. Never mention code, file names, database
  tables or fields, or internal setting keys: only what the person sees in the panel.
- Keep answers short enough to read on a phone. Offer to go into more detail instead of writing
  everything at once.
- Take facts from the documentation below. If it does not cover the question, or you are not sure,
  say so plainly and suggest opening an issue at {SUPPORT_URL} with the panel version and the exact
  error message shown in the panel. Never invent menu names, settings or version numbers.
- When a documentation page helps, give its link (the url attribute of its <doc> tag).
- Never ask for passwords, licence keys, API keys or private server details. If someone pastes one,
  tell them to delete the message and change that secret.
- Help with using and running XC_VM. Politely decline unrelated requests, and do not help anyone
  obtain or share content they have no rights to.
- Telegram shows your answer as plain text: do not use Markdown (no **, no #, no tables, no code
  blocks). Use numbered steps or lines starting with "- "."""

KEYWORDS_PROMPT = """Turn the user's question about the XC_VM IPTV panel into 5 to 10 English search keywords
for its English documentation. Reply with the keywords only, separated by spaces."""


def panel_menu(repo: Path = REPO) -> str:
    """The admin panel's real menus and Settings tabs, read from its code, so answers name only what exists."""
    try:
        src = (repo / "src/Core/Module/CoreNavbarProvider.php").read_text()
        names = dict(re.findall(r'^(\w+)\s*=\s*"([^"]*)"', (repo / "src/Core/Localization/lang/en.ini").read_text(), re.M))
        settings = (repo / "src/Public/Views/admin/settings.php").read_text()
    except OSError:
        return ""
    items = {}
    for item_id, body in re.findall(r"new NavbarItem\('([^']+)'\)\)(.*?);", src, re.S):
        label, parent = re.search(r"->label\('(\w+)'\)", body), re.search(r"->parent\('([^']+)'\)", body)
        if label:
            items[item_id] = (names.get(label.group(1), label.group(1)), parent.group(1) if parent else None)

    def path(i: str) -> str:
        label, parent = items[i]
        return (path(parent) + " → " if parent in items else "") + label

    children: dict[str, list[str]] = {}
    for i, (_, parent) in items.items():
        if parent in items:
            children.setdefault(parent, []).append(items[i][0])
    lines = [f"- {path(i)}: " + ", ".join(children[i]) if i in children else f"- {items[i][0]}"
             for i, (_, parent) in items.items() if i in children or parent not in items]
    tabs = re.findall(r'role="tab"><i [^>]*></i><span[^>]*><\?= \$language::get\(\'(\w+)\'\)', settings)
    if tabs:
        lines.append("- Settings page tabs: " + ", ".join(names.get(t, t) for t in tabs))
    return "The admin panel's menus (use only these names; a → b means menu a, item b):\n" + "\n".join(lines) + SECTIONS


SECTIONS = """

What the main sections are for:
- Streams: live TV channels. Streams → Add Stream adds a channel from its source address.
- Created Channels: a channel the panel builds by playing video files or a series in a loop.
- Stations: radio stations.
- Movies and Series: video on demand.
- Bouquets: packages of channels, movies and series that lines get.
- User Lines: the subscribers' accounts (username, password, expiry date, connections).
- MAG Devices and Enigma Devices: set-top boxes.
- Reseller: accounts that sell lines with credits.
- Servers: MAIN and its load balancers. Cluster Nodes shows how each load balancer talks to MAIN.
- Manage Proxies: proxy servers in front of the load balancers."""


def doc_url(path: Path, docs_dir: Path) -> str:
    """The published page for a file under docs/en (MkDocs: README.md is its folder's index)."""
    rel = path.relative_to(docs_dir)
    page = rel.parent if rel.name == "README.md" else rel.with_suffix("")
    return DOCS_SITE + ("" if str(page) == "." else page.as_posix() + "/")


def doc_files(docs_dir: Path = DOCS_DIR, readme: Path = REPO / "README.md", skip: set[str] = DOCS_SKIP) -> list[tuple[Path, str]]:
    """(file, url) for the README and every English doc page, in a fixed order.

    `skip` leaves out top-level folders of the docs (BOT_DOCS_SKIP), e.g. {"development"}.
    """
    files = [(readme, REPO_URL)] if readme.is_file() else []
    return files + [(p, doc_url(p, docs_dir)) for p in sorted(docs_dir.rglob("*.md")) if p.relative_to(docs_dir).parts[0] not in skip]


def load_docs(**kwargs) -> str:
    """The whole documentation as one text, always the same bytes so the prompt cache keeps hitting."""
    parts = [f'<doc url="{url}">\n{path.read_text()}\n</doc>' for path, url in doc_files(**kwargs)]
    return "<documentation>\n" + "\n".join(parts) + "\n</documentation>"


def doc_sections(size: int = 1500, **kwargs) -> list[dict]:
    """The documentation cut at its headings, and long sections at paragraphs: {url, title, text}."""
    out = []
    for path, url in doc_files(**kwargs):
        page, heading, lines, fence = path.stem, "", [], False

        def flush():
            pieces = []
            for para in "\n".join(lines).strip().split("\n\n"):
                while len(para) > size:  # a long list or table: cut it at a line
                    cut = para.rfind("\n", 0, size)
                    cut = cut if cut > 0 else size
                    pieces.append(para[:cut])
                    para = para[cut:].lstrip("\n")
                pieces.append(para)
            chunk = ""
            for para in pieces:
                if chunk and len(chunk) + 2 + len(para) > size:
                    out.append({"url": url, "title": f"{page} / {heading}".strip(" /"), "text": chunk})
                    chunk = ""
                chunk = (chunk + "\n\n" + para).strip()
            if chunk:
                out.append({"url": url, "title": f"{page} / {heading}".strip(" /"), "text": chunk})

        for line in path.read_text().splitlines():
            if line.startswith("```"):
                fence = not fence
            if not fence and line.startswith("#"):  # a heading, not a shell comment in a code block
                flush()
                lines, heading = [], line.lstrip("#").strip()
                if line.startswith("# "):
                    page = heading
            lines.append(line)
        flush()
    return out


WORD = re.compile(r"[^\W_]+")
STOP = set("the and for are with how what why does can you your this that from into its not but when which there "
           "to do in on is it of an or at by be as if my me we so".split())
# Abbreviations users type for what the docs spell out.
ALIASES = {"lb": "lb load balancer", "lbs": "lbs load balancers"}


def words(text: str) -> list[str]:
    """Search words: lower case, no stop words; two-letter words count ("lb", "ip")."""
    out = []
    for w in WORD.findall(text.lower()):
        if len(w) > 1 and w not in STOP:
            out.extend(ALIASES.get(w, w).split())
    return out


def developer_urls(mkdocs: Path = REPO / "mkdocs.yml", docs_dir: Path = DOCS_DIR) -> set[str]:
    """The pages the docs site lists under its Developer Guide tab."""
    try:
        nav = mkdocs.read_text().split("\n  - Developer Guide:", 1)[1]
    except (OSError, IndexError):
        return set()
    nav = re.split(r"\n  - |\n\S", nav, maxsplit=1)[0]  # up to the next top-level entry
    return {doc_url(docs_dir / p, docs_dir) for p in re.findall(r"\ben/(\S+?\.md)", nav)}


class DocIndex:
    """Ranks documentation sections against a question (BM25, the classic search-engine score).

    Developer Guide pages count half, so a user's question lands on the user guide first.
    """

    def __init__(self, sections: list[dict], developer: set[str] | None = None):
        self.sections = sections
        developer = developer_urls() if developer is None else developer
        self.weight = [0.5 if s["url"] in developer else 1.0 for s in sections]
        self.tf = [Counter(words(s["title"]) * 2 + words(s["text"])) for s in sections]  # titles count double
        self.len = [sum(t.values()) for t in self.tf]
        self.avg = sum(self.len) / max(1, len(self.len))
        df = Counter(w for t in self.tf for w in t)
        n = len(sections)
        self.idf = {w: math.log(1 + (n - c + 0.5) / (c + 0.5)) for w, c in df.items()}

    def known(self, text: str) -> float:
        """How much of a text the documentation's words cover: low for a question in another language."""
        q = words(text)
        return sum(w in self.idf for w in q) / len(q) if q else 1.0

    def search(self, query: str, budget: int) -> str:
        """The best-matching sections, as <doc> excerpts of at most `budget` characters in all."""
        q = set(words(query))
        scores = []
        for i, tf in enumerate(self.tf):
            s = self.weight[i] * sum(self.idf[w] * tf[w] * 2.2 / (tf[w] + 1.2 * (0.25 + 0.75 * self.len[i] / self.avg)) for w in q if w in tf)
            if s > 0:
                scores.append((s, i))
        picked, used = [], 0
        for _, i in sorted(scores, reverse=True):
            sec = self.sections[i]
            piece = f'<doc url="{sec["url"]}" title="{sec["title"]}">\n{sec["text"]}\n</doc>'
            if used + len(piece) + 1 > budget:  # + the newline that joins them
                continue
            picked.append(piece)
            used += len(piece) + 1
        return "\n".join(picked)


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
    first = text.split(maxsplit=1)
    if first[0].lower().split("@")[0] == "/ask":
        return first[1].strip() if len(first) > 1 else ""
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


BUSY = "I'm getting a lot of questions right now. Please try again in a minute."
DOWN = "I can't reach my answer service right now. Please try again in a few minutes."
BROKEN = f"Something went wrong on my side. If it keeps happening, please ask at {SUPPORT_URL}"


class FreeModel:
    """A model behind an OpenAI-compatible chat API (Groq, Cerebras, OpenRouter, a local Ollama...).

    Free tiers take a few thousand tokens per request, so each question goes with the
    documentation sections that match it, not the whole documentation.
    """

    def __init__(self, index: DocIndex):
        self.index = index
        self.prefix = INSTRUCTIONS + "\n\n" + panel_menu()  # the same every time: providers can cache it
        self.http = httpx2.AsyncClient(timeout=120)
        self.name = f"{LLM_MODEL} at {LLM_BASE_URL}"

    async def chat(self, messages: list[dict], max_tokens: int) -> str:
        headers = {"Authorization": f"Bearer {LLM_API_KEY}"} if LLM_API_KEY else {}
        payload = {"model": LLM_MODEL, "messages": messages, "max_tokens": max_tokens, "temperature": 0.3}
        if LLM_REASONING_EFFORT:
            payload["reasoning_effort"] = LLM_REASONING_EFFORT
        r = await self.http.post(f"{LLM_BASE_URL}/chat/completions", headers=headers, json=payload)
        r.raise_for_status()
        body = r.json()
        log.info("model usage: %s", body.get("usage"))
        return (body["choices"][0]["message"].get("content") or "").strip()

    async def complete(self, past: list[dict], question: str) -> tuple[str, bool]:
        """(answer, whether to remember the exchange)."""
        try:
            query = question if not past else past[-2]["content"] + " " + question  # follow-ups keep their topic
            if self.index.known(question) < 0.5:  # not English: search with English keywords
                query += " " + await self.chat([{"role": "system", "content": KEYWORDS_PROMPT},
                                                {"role": "user", "content": question}], 400)  # a reasoning model thinks first
            excerpts = self.index.search(query, DOCS_CHARS)
            system = self.prefix + "\n\nThe documentation sections that best match this question:\n" + excerpts
            text = await self.chat([{"role": "system", "content": system}, *past, {"role": "user", "content": question}], 2000)
        except httpx2.HTTPStatusError as e:
            log.error("model API error %s: %s", e.response.status_code, e.response.text[:300])
            code = e.response.status_code
            return (BUSY if code == 429 else DOWN if code >= 500 else BROKEN), False
        except httpx2.TransportError as e:
            log.error("model API unreachable: %s", e)
            return DOWN, False
        if not text:
            return "Sorry, I couldn't put an answer together. Could you ask in a different way?", False
        return text, True


class Claude:
    """Claude with the whole documentation as a cached system prompt."""

    def __init__(self, docs: str):
        self.client = anthropic.AsyncAnthropic()
        self.name = CLAUDE_MODEL
        # The docs block carries the cache marker: instructions + docs are read from the cache
        # (1-hour TTL: support questions come in bursts with gaps longer than 5 minutes).
        self.system = [
            {"type": "text", "text": INSTRUCTIONS + "\n\n" + panel_menu()},
            {"type": "text", "text": docs, "cache_control": {"type": "ephemeral", "ttl": "1h"}},
        ]

    async def complete(self, past: list[dict], question: str) -> tuple[str, bool]:
        """(answer, whether to remember the exchange)."""
        try:
            response = await self.client.beta.messages.create(
                model=CLAUDE_MODEL,
                max_tokens=16000,
                output_config={"effort": CLAUDE_EFFORT},
                # A declined request is re-run on Anthropic's recommended fallback model.
                betas=["server-side-fallback-2026-07-01"],
                fallbacks="default",
                system=self.system,
                messages=[*past, {"role": "user", "content": question}],
            )
        except anthropic.RateLimitError:
            return BUSY, False
        except anthropic.APIStatusError as e:
            log.error("Claude API error %s: %s", e.status_code, e.message)
            return (DOWN if e.status_code >= 500 else BROKEN), False
        except anthropic.APIConnectionError:
            log.error("Claude API unreachable")
            return DOWN, False

        u = response.usage
        log.info("Claude usage: in=%s cache_read=%s cache_write=%s out=%s stop=%s", u.input_tokens,
                 u.cache_read_input_tokens, u.cache_creation_input_tokens, u.output_tokens, response.stop_reason)
        if response.stop_reason == "refusal":
            return f"Sorry, I can't help with that one. For XC_VM questions, you can also ask at {SUPPORT_URL}", False
        text = "".join(b.text for b in response.content if b.type == "text").strip()
        if not text:
            return "Sorry, I couldn't put an answer together. Could you ask in a different way?", False
        return text, True


class Assistant:
    """A short memory per conversation, in front of a model (FreeModel or Claude)."""

    def __init__(self, model):
        self.model = model
        self.history: dict[tuple, deque] = {}
        self.last_seen: dict[tuple, float] = {}

    def reset(self, key: tuple) -> None:
        self.history.pop(key, None)

    async def answer(self, key: tuple, question: str) -> str:
        if time.time() - self.last_seen.get(key, 0) > IDLE_RESET_SEC:
            self.reset(key)
        self.last_seen[key] = time.time()
        past = self.history.setdefault(key, deque(maxlen=HISTORY_TURNS * 2))
        text, remember = await self.model.complete(list(past), question)
        # Telegram shows Markdown literally, and models still write bold and code fences
        text = re.sub(r"^```[\w-]*[ \t]*\n?", "", text.replace("**", ""), flags=re.M)
        if remember:
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
        log.info("running as @%s with %s", self.username, self.assistant.model.name)
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


def pick_model():
    """Claude when an Anthropic key is set, else the free OpenAI-compatible model."""
    if os.environ.get("ANTHROPIC_API_KEY"):
        docs = load_docs()
        log.info("Claude, with %d characters of documentation", len(docs))
        return Claude(docs)
    if not LLM_API_KEY and "api.groq.com" in LLM_BASE_URL:
        sys.exit("Set LLM_API_KEY: a free Groq key from https://console.groq.com/keys (see README.md).")
    sections = doc_sections()
    log.info("%s, searching %d documentation sections", LLM_MODEL, len(sections))
    return FreeModel(DocIndex(sections))


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    logging.getLogger("httpx2").setLevel(logging.WARNING)  # its request log would print the bot token
    assistant = Assistant(pick_model())
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
