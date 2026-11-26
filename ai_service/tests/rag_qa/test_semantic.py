"""Hybrid (keyword + semantic) retrieval with a fake embedder, no embedding server needed."""
import tempfile
from pathlib import Path

import numpy as np

from modules.rag_qa.retrieval import KnowledgeBase  # noqa: E402

# Each "concept" is one axis; a text gets a component on the axes whose words it contains.
CONCEPTS = [("khóa chính", "định danh duy nhất", "primary key"), ("vắng", "nghỉ học", "cấm thi"), ("vòng lặp", "lặp lại")]


class FakeEmbedder:
    model = "fake"

    def embed(self, texts):
        out = []
        for t in texts:
            t = t.lower()
            v = np.array([1.0 if any(w in t for w in group) else 0.0 for group in CONCEPTS] + [0.3])
            out.append(v / np.linalg.norm(v))
        return np.array(out, dtype=np.float32)


def make_kb():
    d = Path(tempfile.mkdtemp())
    kb = KnowledgeBase(d)
    kb.embedder, kb.min_semantic = FakeEmbedder(), 0.6
    (d / "a.md").write_text("Khóa chính (primary key) xác định mỗi bản ghi. Sinh viên vắng quá 20% bị cấm thi.",
                            encoding="utf-8")
    (d / "b.md").write_text("Vòng lặp while lặp lại khi điều kiện còn đúng.", encoding="utf-8")
    kb.add_document(d / "a.md", "a.md", "1", "t")
    kb.add_document(d / "b.md", "b.md", "2", "t")
    return kb


def test_paraphrase_found_by_meaning_not_keywords():
    kb = make_kb()
    assert kb.sub_vecs is not None and len(kb.sub_owner) >= 2
    hits = kb.search("Thuộc tính định danh duy nhất của bảng?", course="1")  # no keyword overlap with "khóa chính"
    assert hits and hits[0][0].doc_name == "a.md"
    assert kb.search("Thuộc tính định danh duy nhất của bảng?", course="2") == []  # course isolation
    assert kb.search("Thủ đô nước Pháp", course=["1", "2"]) == []                  # off topic


def test_embeddings_persist_and_prune():
    kb = make_kb()
    doc_b = next(d for d in kb.docs.values() if d.name == "b.md")
    n_before = len(kb.sub_owner)
    kb.delete_document(doc_b.id)
    assert 0 < len(kb.sub_owner) < n_before
    data = np.load(kb.emb_path)
    assert len(data["owners"]) == len(kb.sub_owner)
