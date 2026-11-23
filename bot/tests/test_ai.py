"""Tests for the AI assistant, with OpenAI replaced by a fake transport. All students are invented."""

import asyncio
import json
import logging
from zoneinfo import ZoneInfo

import httpx
import pytest

from darimaa_bot import texts
from darimaa_bot.ai import AIError, Answer, Assistant, Labels, OpenAIClient
from darimaa_bot.config import Settings
from darimaa_bot.conversation import Bot
from darimaa_bot.messenger import Incoming

from .fakes import FakeMessenger, FakeMoodle, attention_student, course

API_KEY = "sk-test-openai-key"
NOW = 1790000000.0
ZONE = ZoneInfo("Asia/Ulaanbaatar")
TEACHER = "psid-teacher"
STUDENT = "psid-student"


def call(name: str, arguments: dict, call_id: str = "call_1") -> dict:
    return {"type": "function_call", "id": f"fc_{call_id}", "call_id": call_id, "name": name,
            "arguments": json.dumps(arguments)}


def message(text: str) -> dict:
    return {"type": "message", "role": "assistant", "content": [{"type": "output_text", "text": text}]}


class FakeOpenAI:
    """Answers each request with the next scripted response and keeps the request bodies."""

    def __init__(self, *responses: httpx.Response | dict) -> None:
        self.responses = list(responses)
        self.requests: list[dict] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        assert str(request.url) == "https://api.openai.com/v1/responses"
        assert request.headers["authorization"] == f"Bearer {API_KEY}"
        self.requests.append(json.loads(request.content))
        reply = self.responses.pop(0)
        if isinstance(reply, httpx.Response):
            return reply
        return httpx.Response(200, json={"output": reply["output"], "usage": {"input_tokens": 10, "output_tokens": 5}})

    def sent_text(self) -> str:
        return json.dumps(self.requests, ensure_ascii=False)


def teacher_moodle() -> FakeMoodle:
    moodle = FakeMoodle()
    moodle.links[TEACHER] = 9
    moodle.links[STUDENT] = 7
    moodle.courses[9] = [course(12, "Биологи 101", students=25)]
    moodle.attention[12] = {"total": 25, "students": [
        attention_student("B200910003", "Саруул Тэмүүлэн", ["never", "missing"], missing=2),
        attention_student("B200910002", "Болд Ганзориг", ["inactive"], dayssince=12),
    ]}
    return moodle


def ask(fake: FakeOpenAI, question: str, moodle: FakeMoodle, sender: str = TEACHER, teacher: bool = True) -> str:
    async def go():
        async with httpx.AsyncClient(transport=httpx.MockTransport(fake)) as http:
            assistant = Assistant(OpenAIClient("https://api.openai.com/v1/", API_KEY, "gpt-6.2-sol", http))
            return await assistant.answer(question, {"isteacher": teacher}, moodle, sender, ZONE, NOW)
    return asyncio.run(go()).text


def test_labels_hide_students_and_come_back():
    labels = Labels()
    first = labels.label({"code": "B1", "fullname": "Ану Мөнх"})
    assert first == "S1"
    assert labels.label({"code": "B2", "fullname": "Болд"}) == "S2"
    assert labels.label({"code": "B1", "fullname": "Ану Мөнх"}) == "S1"
    assert labels.restore("S1, S2 нар; S9 гэж байхгүй. CS101 хэвээр.") == "B1 Ану Мөнх, B2 Болд нар; S9 гэж байхгүй. CS101 хэвээр."


def test_teacher_question_uses_a_tool_and_names_come_back_only_in_the_answer():
    fake = FakeOpenAI(
        {"output": [call("list_courses", {}, "c1")]},
        {"output": [{"type": "reasoning", "id": "rs_1", "encrypted_content": "opaque"},
                    call("get_attention", {"courseid": 12}, "c2")]},
        {"output": [message("Хамгийн түрүүнд S1-д анхаарна уу: огт ороогүй, 2 даалгавар дутуу. Дараа нь S2.")]},
    )
    answer = ask(fake, "Биологи дээр хэнд түрүүлж анхаарах вэ?", teacher_moodle())

    assert answer == ("Хамгийн түрүүнд B200910003 Саруул Тэмүүлэн-д анхаарна уу: огт ороогүй, 2 даалгавар дутуу. "
                      "Дараа нь B200910002 Болд Ганзориг.")
    # The model never sees a student's name or code.
    for private in ("Саруул", "Болд", "B2009"):
        assert private not in fake.sent_text()

    first, second, third = fake.requests
    assert first["model"] == "gpt-6.2-sol"
    assert first["store"] is False
    assert first["include"] == ["reasoning.encrypted_content"]
    assert first["input"][-1] == {"role": "user", "content": "Биологи дээр хэнд түрүүлж анхаарах вэ?"}
    assert "2026" in first["input"][0]["content"] and "teacher" in first["input"][0]["content"]
    assert {tool["name"] for tool in first["tools"]} == {
        "get_my_deadlines", "list_courses", "get_attention", "get_inactive_students", "get_quiz_results",
        "get_missing_assignments", "find_student", "prepare_message"}
    assert all(tool["strict"] and tool["parameters"]["additionalProperties"] is False for tool in first["tools"])
    # Each call is answered, and the model's own items (reasoning included) go back unchanged.
    courses = json.loads(second["input"][-1]["output"])
    assert second["input"][-1]["call_id"] == "c1"
    assert courses == [{"courseid": 12, "name": "Биологи 101", "students": 25, "quizzes": []}]
    assert {"type": "reasoning", "id": "rs_1", "encrypted_content": "opaque"} in third["input"]
    attention = json.loads(third["input"][-1]["output"])
    assert [row["student"] for row in attention["students"]] == ["S1", "S2"]
    assert attention["students"][0]["signs"] == ["never", "missing"]


def test_students_get_only_their_own_deadlines():
    moodle = teacher_moodle()
    moodle.deadlines[7] = [{"name": "Эссэ", "coursename": "Түүх", "timedue": int(NOW) + 86400, "overdue": False}]
    fake = FakeOpenAI(
        {"output": [call("get_attention", {"courseid": 12}, "c1"), call("get_my_deadlines", {}, "c2")]},
        {"output": [message("Маргааш «Эссэ» даалгаврын хугацаа дуусна.")]},
    )
    ask(fake, "Би юу хийх ёстой вэ?", moodle, sender=STUDENT, teacher=False)

    assert [tool["name"] for tool in fake.requests[0]["tools"]] == ["get_my_deadlines"]
    outputs = [item for item in fake.requests[1]["input"] if item.get("type") == "function_call_output"]
    # A teacher tool asked for by a student's conversation does not exist for it.
    assert json.loads(outputs[0]["output"]) == {"error": "unknown tool"}
    assert json.loads(outputs[1]["output"])[0]["name"] == "Эссэ"
    assert "get_attention" not in moodle.calls


def test_moodle_refusals_and_bad_arguments_go_back_to_the_model():
    fake = FakeOpenAI(
        {"output": [call("get_attention", {"courseid": 99}, "c1"), call("get_attention", {"course": 1}, "c2")]},
        {"output": [message("Энэ хичээлийг харах эрх танд алга.")]},
    )
    ask(fake, "99 дугаар хичээл", teacher_moodle())
    outputs = [json.loads(item["output"]) for item in fake.requests[1]["input"] if item.get("type") == "function_call_output"]
    assert outputs == [{"error": "nopermissions"}, {"error": "wrong arguments"}]


def test_a_model_that_refuses_include_is_asked_again_without_it():
    refusal = httpx.Response(400, json={"error": {"message": "Unsupported parameter", "type": "invalid_request_error",
                                                  "param": "include", "code": "unsupported_parameter"}})
    fake = FakeOpenAI(refusal, {"output": [message("Сайн байна уу!")]})
    assert ask(fake, "сайн уу", teacher_moodle()) == "Сайн байна уу!"
    assert "include" in fake.requests[0] and "include" not in fake.requests[1]


@pytest.mark.parametrize("reply", [
    httpx.Response(401, json={"error": {"message": "Incorrect API key provided", "type": "invalid_request_error",
                                        "code": "invalid_api_key"}}),
    httpx.Response(429, json={"error": {"message": "quota", "type": "insufficient_quota", "code": "insufficient_quota"}}),
    {"output": []},
])
def test_failures_raise_without_the_key(caplog, reply):
    with caplog.at_level(logging.DEBUG), pytest.raises(AIError) as error:
        ask(FakeOpenAI(reply), "асуулт", teacher_moodle())
    assert API_KEY not in str(error.value)
    assert API_KEY not in caplog.text


def test_endless_tool_calls_stop():
    fake = FakeOpenAI(*[{"output": [call("list_courses", {}, f"c{n}")]} for n in range(5)])
    with pytest.raises(AIError):
        ask(fake, "асуулт", teacher_moodle())
    assert len(fake.requests) == 5


# In the conversation.


class FakeAssistant:
    def __init__(self, answer: str = "S1-д анхаараарай.", error: Exception | None = None, allow: bool = True) -> None:
        self.questions: list[str] = []
        self.reply, self.error, self.allowed = answer, error, allow

    def allow(self, sender: str) -> bool:
        return self.allowed

    async def answer(self, question, user, moodle, sender, zone, now) -> str:
        self.questions.append(question)
        if self.error:
            raise self.error
        return Answer(self.reply)


def bot_with(assistant: FakeAssistant):
    moodle, messenger = teacher_moodle(), FakeMessenger()
    return Bot(moodle, messenger, clock=lambda: NOW, ai=assistant), moodle, messenger


def text(sender: str, words: str, key: str) -> Incoming:
    return Incoming(sender, "text", text=words, key=key)


def test_free_questions_go_to_the_assistant_and_the_rules_keep_their_words():
    assistant = FakeAssistant("Саруулд анхаараарай.")
    bot, moodle, messenger = bot_with(assistant)

    # A full sentence goes to the assistant, even with a word the rules know ("хоцорч").
    asyncio.run(bot.handle(text(TEACHER, "Биологи дээр хэн хамгийн их хоцорч байна?", "k1")))
    reply = messenger.last()
    assert reply.text == f"Саруулд анхаараарай.\n\n{texts.AI_NOTE}"
    assert reply.quick_replies == bot.menu({"isteacher": True})

    # Short phrases the rules know, and taps, never reach the assistant.
    asyncio.run(bot.handle(text(TEACHER, "анхаарах оюутан", "k2")))
    assert messenger.last().text.startswith("«Биологи 101»: 25 оюутнаас 2 нь анхаарал хэрэгтэй:")
    asyncio.run(bot.handle(Incoming(TEACHER, "postback", payload="HELP", key="k3")))
    asyncio.run(bot.handle(Incoming(TEACHER, "other", key="k4")))
    assert assistant.questions == ["Биологи дээр хэн хамгийн их хоцорч байна?"]
    assert "send_reminders" not in moodle.calls


def test_assistant_limits_and_failures_get_a_plain_answer(caplog):
    bot, _, messenger = bot_with(FakeAssistant(allow=False))
    asyncio.run(bot.handle(text(TEACHER, "ямар нэг асуулт байна гэж бодъё", "k1")))
    assert messenger.last().text == texts.AI_TOO_MANY

    bot, _, messenger = bot_with(FakeAssistant(error=AIError("OpenAI refused a request: 429")))
    with caplog.at_level(logging.DEBUG):
        asyncio.run(bot.handle(text(TEACHER, "Саруул яагаад хичээлдээ ирэхгүй байгаа юм бэ?", "k2")))
    assert messenger.last().text == texts.AI_ERROR
    # The question, which may name a student, stays out of the log.
    assert "Саруул" not in caplog.text


def test_without_the_assistant_nothing_changes():
    moodle, messenger = teacher_moodle(), FakeMessenger()
    bot = Bot(moodle, messenger, clock=lambda: NOW)
    asyncio.run(bot.handle(text(TEACHER, "цаг агаар ямар байна?", "k1")))
    assert messenger.last().text == texts.UNKNOWN


# Settings.


def test_ai_settings(monkeypatch):
    monkeypatch.setenv("DARIMAA_ENV_FILE", "no-such-file.env")
    base = {"MOODLE_URL": "https://moodle.test", "MOODLE_TOKEN": "t", "TELEGRAM_BOT_TOKEN": "1:x",
            "TELEGRAM_SECRET": "a-long-telegram-secret"}
    assert not Settings.from_env(base).ai_enabled
    on = Settings.from_env(base | {"OPENAI_API_KEY": API_KEY, "OPENAI_MODEL": "gpt-6.2-sol", "AI_QUESTIONS_PER_HOUR": "5"})
    assert on.ai_enabled and on.openai_model == "gpt-6.2-sol" and on.ai_per_hour == 5
    assert on.openai_url == "https://api.openai.com/v1"
    with pytest.raises(RuntimeError, match="OPENAI_MODEL"):
        Settings.from_env(base | {"OPENAI_API_KEY": API_KEY})
    with pytest.raises(RuntimeError, match="AI_QUESTIONS_PER_HOUR"):
        Settings.from_env(base | {"OPENAI_API_KEY": API_KEY, "OPENAI_MODEL": "m", "AI_QUESTIONS_PER_HOUR": "0"})


def test_app_starts_the_assistant_and_keeps_the_key_out_of_the_log(caplog):
    from fastapi.testclient import TestClient

    from darimaa_bot.app import create_app

    settings = Settings(moodle_url="https://moodle.test", moodle_token="moodle-token", telegram_token="1:bot-token",
                        telegram_secret="a-long-telegram-secret", openai_api_key=API_KEY, openai_model="gpt-6.2-sol")
    with caplog.at_level(logging.INFO), TestClient(create_app(settings, transport=httpx.MockTransport(FakeOpenAI()))):
        pass
    assert "(Telegram), AI: gpt-6.2-sol" in caplog.text
    assert API_KEY not in caplog.text
