"""Self-check for bot.py without network: python test_bot.py (needs `anthropic` installed)."""

import asyncio
import os
from types import SimpleNamespace

os.environ.setdefault("ANTHROPIC_API_KEY", "test")  # the client is built but never called

import bot  # noqa: E402

PRIVATE = {"type": "private", "id": 1}
GROUP = {"type": "supergroup", "id": -100}


def msg(text, chat=PRIVATE, reply_to=None, mid=10):
    m = {"message_id": mid, "text": text, "chat": chat, "from": {"id": 7}}
    if reply_to:
        m["reply_to_message"] = {"from": {"username": reply_to}}
    return m


def test_helpers():
    docs = bot.DOCS_DIR
    assert bot.doc_url(docs / "administration" / "main-lb-cluster.md", docs) == bot.DOCS_SITE + "administration/main-lb-cluster/"
    assert bot.doc_url(docs / "README.md", docs) == bot.DOCS_SITE

    text = bot.load_docs()
    assert text == bot.load_docs(), "the docs must load identically, or the prompt cache never hits"
    assert 'url="https://vateron-media.github.io/XC_VM/administration/main-lb-cluster/"' in text
    lean = bot.load_docs(skip={"development"})
    assert f'url="{bot.DOCS_SITE}development/' not in lean and len(lean) < len(text), "no development page"

    chunks = bot.split_message(("line\n" * 30 + "\n") * 40, limit=500)
    assert all(len(c) <= 500 for c in chunks) and len(chunks) > 1
    assert bot.split_message("short") == ["short"]
    assert bot.split_message("x" * 1200, limit=500) == ["x" * 500, "x" * 500, "x" * 200]

    assert bot.question_for_bot(msg("how do I update?"), "XcBot") == "how do I update?"
    assert bot.question_for_bot(msg("hello all", GROUP), "XcBot") is None
    assert bot.question_for_bot(msg("@xcbot how do I update?", GROUP), "XcBot") == "how do I update?"
    assert bot.question_for_bot(msg("and the LB?", GROUP, reply_to="XcBot"), "XcBot") == "and the LB?"
    assert bot.question_for_bot(msg("/ask@XcBot what is mode 2?", GROUP), "XcBot") == "what is mode 2?"
    assert bot.question_for_bot(msg("/ask", GROUP), "XcBot") == ""

    limiter = bot.RateLimiter(2)
    assert limiter.allow(1, 0) and limiter.allow(1, 1) and not limiter.allow(1, 2)
    assert limiter.allow(2, 2), "per user"
    assert limiter.allow(1, 3700), "an hour later"


class FakeAssistant:
    def __init__(self):
        self.asked, self.resets = [], []

    async def answer(self, key, question):
        self.asked.append((key, question))
        return "answer: " + question

    def reset(self, key):
        self.resets.append(key)


class FakeTelegram(bot.TelegramBot):
    def __init__(self):
        super().__init__("TOKEN", FakeAssistant())
        self.username = "XcBot"
        self.sent = []

    async def call(self, method, **params):
        if method == "sendMessage":
            self.sent.append((params["chat_id"], params["text"]))
        return {}


def test_handling():
    async def run():
        t = FakeTelegram()
        await t.handle(msg("/start"))
        assert "support assistant" in t.sent[-1][1]
        await t.handle(msg("/home/xc_vm/console.php says permission denied"))
        assert t.sent[-1][1] == "answer: /home/xc_vm/console.php says permission denied", "a pasted path is a question"
        n = len(t.sent)
        await t.handle(msg("lunch?", GROUP))
        await t.handle(msg("/start@OtherBot", GROUP))
        assert len(t.sent) == n, "the bot stays quiet in a group unless addressed"
        await t.handle(msg("/ask@XcBot what is a flow?", GROUP))
        assert t.sent[-1] == (-100, "answer: what is a flow?")
        await t.handle(msg("/reset"))
        assert t.assistant.resets == [(1, 7)]
    asyncio.run(run())


def test_assistant():
    sent = []

    async def create(**params):
        sent.append(params)
        if params["messages"][-1]["content"] == "bad":
            return SimpleNamespace(stop_reason="refusal", content=[], usage=usage)
        return SimpleNamespace(stop_reason="end_turn", content=[SimpleNamespace(type="thinking"), SimpleNamespace(type="text", text="Hello!")], usage=usage)

    usage = SimpleNamespace(input_tokens=1, cache_read_input_tokens=0, cache_creation_input_tokens=0, output_tokens=1)
    a = bot.Assistant("<documentation/>")
    a.client = SimpleNamespace(beta=SimpleNamespace(messages=SimpleNamespace(create=create)))

    assert asyncio.run(a.answer(("c", 1), "hi")) == "Hello!"
    assert asyncio.run(a.answer(("c", 1), "and?")) == "Hello!"
    p = sent[-1]
    assert p["model"] == bot.MODEL and p["fallbacks"] == "default" and p["betas"] == ["server-side-fallback-2026-07-01"]
    assert p["system"][-1]["cache_control"] == {"type": "ephemeral", "ttl": "1h"}, "the docs are the cached prefix"
    assert [m["role"] for m in p["messages"]] == ["user", "assistant", "user"], "the conversation is remembered"
    assert "can't help" in asyncio.run(a.answer(("c", 2), "bad"))
    assert len(a.history[("c", 2)]) == 0, "a refused turn is not remembered"


if __name__ == "__main__":
    test_helpers()
    test_handling()
    test_assistant()
    print("ok")
