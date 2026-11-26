"""End-to-end tests of the /rag endpoints through the FastAPI app."""
from pathlib import Path

from fastapi.testclient import TestClient

from main import app

client = TestClient(app)
KEY = {"X-RAG-Key": "test-key"}
FIXTURES = Path(__file__).parent / "fixtures"


def add(course: str, key: str, name: str) -> dict:
    with open(FIXTURES / name, "rb") as f:
        return client.post("/rag/documents", headers=KEY, files={"file": (name, f)},
                           data={"course_id": course, "ext_key": key,
                                 "url": "http://moodle/mod/resource/view.php?id=1"}).json()


def ask(question: str, course_ids: list[str], role: str = "student") -> dict:
    r = client.post("/rag/query", headers=KEY, json={"question": question, "course_ids": course_ids, "role": role})
    assert r.status_code == 200
    return r.json()


def test_requires_api_key():
    assert client.get("/rag/status").status_code == 401
    assert client.get("/rag/status", headers={"X-RAG-Key": "wrong"}).status_code == 401
    assert client.get("/rag/status", headers=KEY).status_code == 200


def test_index_dedupe_query_isolation_and_prune():
    assert add("10", "k1", "de_cuong_csdl.docx")["status"] == "added"
    assert add("10", "k1", "de_cuong_csdl.docx")["status"] == "unchanged"  # same key: not re-indexed
    assert add("11", "k2", "python_co_ban.md")["status"] == "added"

    r = ask("Khóa ngoại là gì?", ["10"])
    assert r["sources"] and r["sources"][0]["url"].startswith("http://moodle/")
    assert "foreign key" in r["answer"]
    assert ask("Khóa ngoại là gì?", ["11"])["sources"] == []          # other course's material is invisible
    assert ask("vong lap while dung khi nao", ["10", "11"])["sources"][0]["course_id"] == "11"  # no accents
    assert "60%" in ask("thi cuối kỳ chiếm bao nhiêu phần trăm", ["10"])["answer"]

    assert client.post("/rag/documents/prune", headers=KEY,
                       json={"course_id": "10", "keep_keys": []}).json()["deleted"] == 1
    assert client.get("/rag/documents", headers=KEY, params={"course_id": "10"}).json() == []


def test_off_topic_smalltalk_and_task_requests():
    add("12", "k3", "python_co_ban.md")
    for off_topic in ["Công thức tính đạo hàm của sin x", "cách nấu phở bò", "Định luật Newton thứ hai là gì?"]:
        assert ask(off_topic, ["12"])["sources"] == [], off_topic
    hi = ask("Hi", ["12"])
    assert hi["sources"] == [] and "không tìm thấy" not in hi["answer"].lower()
    assert ask("Which concepts are students likely to confuse?", ["12"], role="teacher")["sources"]
    assert ask("Tóm tắt nội dung chính của tài liệu", ["12"])["sources"]


def test_unsupported_file_is_skipped():
    r = client.post("/rag/documents", headers=KEY, files={"file": ("x.exe", b"MZ")},
                    data={"course_id": "13", "ext_key": "k4"})
    assert r.json()["status"] == "skipped"
