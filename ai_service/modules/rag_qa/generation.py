"""Answer generation from retrieved course passages.

The LLM is any server exposing the OpenAI-compatible ``/chat/completions`` API
(LM Studio, Ollama, vLLM, OpenAI, Gemini's OpenAI endpoint...). Configuration:

- ``LLM_PROVIDER``: ``auto`` (default: use the LLM when ``LLM_BASE_URL`` is set), ``openai`` or ``extractive``.
- ``LLM_BASE_URL``, ``LLM_MODEL``, ``LLM_API_KEY`` (optional), ``LLM_TEMPERATURE``,
  ``LLM_MAX_TOKENS``, ``LLM_TIMEOUT``.
- ``LLM_REASONING_EFFORT``: ``none`` (default) disables the "thinking" phase of reasoning
  models such as Gemma 4, which otherwise spend their whole token budget before answering.

Without an LLM (or when it fails) the service falls back to *extractive* mode: it returns
the most relevant sentences of the retrieved passages, with citations.
"""
from __future__ import annotations

import json
import os
import re
import urllib.error
import urllib.request

from .retrieval import content_terms, strip_accents, tokens

# Prompts are written in Vietnamese (the primary audience) but ask the model to answer
# in the language of the question, so English questions get English answers.
SYSTEM_PROMPTS = {
    "student": (
        "Bạn là trợ lý học tập tra cứu tài liệu môn học cho SINH VIÊN. "
        "Chỉ trả lời dựa trên các đoạn tài liệu được cung cấp. Giải thích rõ ràng, dễ hiểu, "
        "có ví dụ khi phù hợp. Khi dùng thông tin từ đoạn nào, ghi trích dẫn dạng [1], [2]. "
        "Nếu tài liệu không chứa câu trả lời, nói rõ là không tìm thấy trong tài liệu và gợi ý "
        "sinh viên hỏi giảng viên — không bịa thông tin. "
        "Trả lời hoàn toàn bằng ngôn ngữ của câu hỏi (câu hỏi tiếng Việt thì chỉ viết tiếng Việt, "
        "không chèn tiếng Trung, tiếng Anh hay ngôn ngữ khác, trừ thuật ngữ và mã lệnh)."
    ),
    "teacher": (
        "Bạn là trợ lý tra cứu tài liệu cho GIẢNG VIÊN. Chỉ dựa trên các đoạn tài liệu được cung cấp. "
        "Trả lời súc tích, chính xác. Với yêu cầu phục vụ giảng dạy (tóm tắt, soạn câu hỏi ôn tập/trắc nghiệm, "
        "dàn ý bài giảng, chỉ ra các khái niệm sinh viên dễ nhầm lẫn, gợi ý bài tập…) bạn ĐƯỢC phân tích, so sánh "
        "và suy luận từ nội dung tài liệu — ví dụ các khái niệm gần nhau trong tài liệu thường dễ nhầm — "
        "nhưng không đưa thêm kiến thức không có trong tài liệu. Ghi trích dẫn dạng [1], [2] (luôn có số). "
        "Nếu tài liệu không đủ thông tin, nói rõ. "
        "Trả lời hoàn toàn bằng ngôn ngữ của câu hỏi (không chèn ngôn ngữ khác, trừ thuật ngữ và mã lệnh)."
    ),
}

SMALLTALK_SYSTEM = (
    "Bạn là trợ lý tra cứu tài liệu môn học trong Moodle. Người dùng vừa chào hỏi hoặc trò chuyện xã giao. "
    "Đáp lại ngắn gọn, thân thiện (1–2 câu), giới thiệu rằng bạn trả lời câu hỏi dựa trên tài liệu của khóa học "
    "(giảng viên còn có thể nhờ tóm tắt, soạn câu hỏi ôn tập, dàn ý bài giảng) và mời họ đặt câu hỏi. "
    "Không trả lời kiến thức chuyên môn. Trả lời bằng ngôn ngữ của người dùng."
)

NOT_FOUND = ("Mình không tìm thấy nội dung liên quan trong tài liệu của khóa học. "
             "Bạn thử diễn đạt lại câu hỏi, hoặc hỏi giảng viên nhé.")

# Greetings / small talk (matched on lower-cased, accent-stripped text).
SMALLTALK_RE = re.compile(
    r"(hi|hello|hey|alo|chao|xin chao|chao (ban|em|thay|co|anh|chi|tro ly)|cam on( ban| nhieu| nhe)*|thanks?( you)?"
    r"|ok(e|ay)?|tam biet|bye|ban la ai|ban la gi|ban (lam|giup) (duoc )?(gi|nhung gi)( cho (em|toi|minh))?"
    r"|help|giup (em|toi|minh) voi)( (ban|nhe|a|nha|chatbot|tro ly|nhieu))*")


def llm_mode() -> str:
    """Return the active generation mode: ``llm`` or ``extractive``."""
    provider = os.getenv("LLM_PROVIDER", "auto").strip().lower()
    if provider == "extractive":
        return "extractive"
    if provider == "openai" or os.getenv("LLM_BASE_URL"):
        return "llm"
    return "extractive"


def build_context(hits) -> str:
    blocks = []
    for i, (ch, _score) in enumerate(hits, 1):
        blocks.append(f"[{i}] (Tài liệu: {ch.doc_name}, trang {ch.page})\n{ch.text}")
    return "\n\n".join(blocks)


def build_messages(question: str, hits, history: list[dict]) -> list[dict]:
    messages = []
    for turn in history[-6:]:
        if turn.get("role") in ("user", "assistant") and turn.get("content"):
            messages.append({"role": turn["role"], "content": str(turn["content"])[:4000]})
    while messages and messages[0]["role"] != "user":  # the first message must come from the user
        messages.pop(0)
    context = build_context(hits) if hits else "(Không tìm thấy đoạn tài liệu nào liên quan.)"
    messages.append({
        "role": "user",
        "content": f"<tai_lieu>\n{context}\n</tai_lieu>\n\nCâu hỏi: {question}\n\n"
                   "Chỉ dùng thông tin trong <tai_lieu>, ghi trích dẫn [số] sau mỗi ý. Viết bằng ngôn ngữ của câu hỏi.",
    })
    return messages


def chat_complete(system: str, messages: list[dict], max_tokens: int | None = None) -> str:
    """Call an OpenAI-compatible ``/chat/completions`` endpoint and return the answer text."""
    base = os.getenv("LLM_BASE_URL", "http://127.0.0.1:1234/v1").rstrip("/")
    payload = {
        "model": os.getenv("LLM_MODEL", ""),
        "messages": [{"role": "system", "content": system}] + messages,
        "temperature": float(os.getenv("LLM_TEMPERATURE", "0.2")),
        "max_tokens": max_tokens or int(os.getenv("LLM_MAX_TOKENS", "1200")),
        "stream": False,
    }
    effort = os.getenv("LLM_REASONING_EFFORT", "none").strip()
    if effort:
        payload["reasoning_effort"] = effort
    headers = {"Content-Type": "application/json"}
    if os.getenv("LLM_API_KEY"):
        headers["Authorization"] = "Bearer " + os.environ["LLM_API_KEY"]
    req = urllib.request.Request(base + "/chat/completions", json.dumps(payload).encode(), headers)
    try:
        with urllib.request.urlopen(req, timeout=int(os.getenv("LLM_TIMEOUT", "180"))) as resp:
            data = json.load(resp)
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"HTTP {e.code}: {e.read().decode(errors='replace')[:300]}") from e
    content = (data["choices"][0]["message"].get("content") or "").strip()
    content = re.sub(r"<think>.*?</think>", "", content, flags=re.S).strip()
    if not content:
        raise RuntimeError("the model returned no content (try a larger LLM_MAX_TOKENS)")
    return content


def answer_extractive(question: str, hits) -> str:
    """Answer without an LLM: list the passage sentences that best match the question."""
    if not hits:
        return NOT_FOUND
    terms, accented = content_terms(question)
    scored = []
    for idx, (ch, score) in enumerate(hits, 1):
        text = re.sub(r"#+\s*", "", ch.text)
        for pos, sent in enumerate(re.split(r"(?<=[.!?])\s+", text)):
            if len(sent) < 20:
                continue
            if terms:
                cov = sum(t in tokens(sent, accented) for t in terms) / len(terms)
            else:  # generic request: prefer the first sentences of each passage
                cov = 1.0 / (pos + 1)
            if cov > 0:
                scored.append((cov, score, idx, sent.strip()))
    scored.sort(key=lambda x: (-x[0], -x[1]))
    best = scored[0][0] if scored else 0
    scored = [s for s in scored if s[0] >= best * 0.5]
    seen, lines = set(), []
    for _, _, idx, sent in scored:
        if sent in seen:
            continue
        seen.add(sent)
        lines.append(f"- {sent} [{idx}]")
        if len(lines) >= 5:
            break
    if not lines:
        lines = [f"- {hits[0][0].text[:400]}… [1]"]
    return ("**Các nội dung liên quan nhất trong tài liệu:**\n\n" + "\n".join(lines) +
            "\n\n_(Chế độ trích xuất — chưa bật LLM nên chưa có tổng hợp bằng AI.)_")


def is_smalltalk(question: str) -> bool:
    n = re.sub(r"[^\w\s]", " ", strip_accents(question.lower()))
    n = re.sub(r"\s+", " ", n).strip()
    return 0 < len(n) <= 40 and bool(SMALLTALK_RE.fullmatch(n))


def answer_smalltalk(question: str, role: str, mode: str) -> str:
    if mode == "llm":
        try:
            return chat_complete(SMALLTALK_SYSTEM, [{"role": "user", "content": question}], max_tokens=200)
        except Exception:
            pass
    extra = (" Thầy/cô cũng có thể nhờ mình tóm tắt tài liệu, soạn câu hỏi ôn tập hoặc dàn ý bài giảng."
             if role == "teacher" else "")
    return ("Xin chào! Mình là trợ lý tra cứu tài liệu của khóa học. Hãy đặt câu hỏi về nội dung môn học, "
            "mình sẽ trả lời kèm trích dẫn nguồn." + extra)


def generate_answer(question: str, hits, role: str, history: list[dict]) -> tuple[str, str]:
    """Return ``(answer, mode)`` where mode is ``llm`` or ``extractive``."""
    mode = llm_mode()
    if is_smalltalk(question):
        return answer_smalltalk(question, role, mode), mode
    # Nothing relevant retrieved: do not call the LLM (avoids hallucinated answers, FR-CHAT-04).
    if mode == "extractive" or not hits:
        return answer_extractive(question, hits), "extractive"
    try:
        system = SYSTEM_PROMPTS.get(role, SYSTEM_PROMPTS["student"])
        return chat_complete(system, build_messages(question, hits, history)), "llm"
    except Exception as e:  # LLM down or timed out: still answer from the documents
        return answer_extractive(question, hits) + f"\n\n_(LLM error: {e})_", "extractive"
