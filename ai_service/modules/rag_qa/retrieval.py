"""RAG core: document parsing, chunking, indexing and hybrid retrieval.

Keyword retrieval uses TF-IDF over word and character n-grams, which works well for
Vietnamese with or without diacritics and needs no model. When an embedding model is
configured (see ``embeddings.py``) semantic similarity is combined with it (FR-CHAT-09).
"""
from __future__ import annotations

import json
import os
import re
import threading
import unicodedata
import uuid
from dataclasses import dataclass, asdict
from datetime import datetime
from pathlib import Path

import numpy as np
from scipy.sparse import hstack
from sklearn.feature_extraction.text import TfidfVectorizer

from .embeddings import SUB_CHUNK_OVERLAP, SUB_CHUNK_SIZE, Embedder

SUPPORTED_EXT = {".pdf", ".docx", ".txt", ".md"}
CHUNK_SIZE = 900
CHUNK_OVERLAP = 150


def strip_accents(text: str) -> str:
    """Remove Vietnamese diacritics so queries typed without accents still match."""
    text = text.replace("đ", "d").replace("Đ", "D")
    nfkd = unicodedata.normalize("NFD", text)
    return "".join(c for c in nfkd if unicodedata.category(c) != "Mn")


def normalize(text: str) -> str:
    return strip_accents(text.lower())


# Stop words and request words that carry no subject content (Vietnamese and English).
_STOP = """là gì của và các những có không được trong cho với thế nào như khi bao nhiêu sao tại vì này
đó một thì mà ở về để cách hãy giải thích nêu cho biết hỏi em tôi mình bạn thầy cô ạ nhé vậy ra làm
sự việc theo từ đến trên dưới hay hoặc nhưng nếu thì đã đang sẽ rất nhất cũng chỉ còn mỗi mọi bị do
tóm tắt nội dung tài liệu bài giảng soạn câu hỏi trắc nghiệm ôn tập dàn ý liệt kê trình bày
khái niệm quan trọng phân biệt so sánh ví dụ the a an of is are what how why which in on to for and
please explain summary summarize document""".split()
STOP_ACC = set(_STOP)
STOP_NOACC = {strip_accents(w) for w in _STOP}


def has_accents(text: str) -> bool:
    return strip_accents(text) != text


def tokens(text: str, accented: bool) -> set[str]:
    text = text.lower()
    if not accented:
        text = strip_accents(text)
    return set(re.findall(r"\w+", text))


def content_terms(query: str) -> tuple[list[str], bool]:
    """Content keywords of a query.

    Accented queries are matched with accents (so 'hàm' differs from 'hám');
    unaccented queries are matched against accent-stripped text.
    """
    accented = has_accents(query)
    stop = STOP_ACC if accented else STOP_NOACC
    terms = [t for t in tokens(query, accented) if t not in stop and (len(t) > 1 or t.isdigit())]
    return sorted(set(terms)), accented


# Requests that work on the material as a whole rather than asking for one fact
# (summaries, quizzes, outlines, commonly confused concepts...). Matched on normalized text.
TASK_RE = re.compile(
    r"\b(tom tat|tong quan|noi dung chinh|y chinh|soan|cau hoi (trac nghiem|on tap)|trac nghiem|on tap|dan y"
    r"|de cuong bai giang|nham lan|de nham|hay nham|khai niem quan trong|liet ke cac khai niem|bai tap"
    r"|summar\w*|overview|main points|key points|quiz\w*|questions?|outline|lecture|confus\w*|review|exercises?)\b")


def is_task_query(query: str) -> bool:
    return bool(TASK_RE.search(normalize(query)))


# ---------------------------------------------------------------- parsing
def extract_pages(path: Path) -> list[tuple[int, str]]:
    """Return ``[(page_number, text)]``. Formats without pages are returned as page 1."""
    ext = path.suffix.lower()
    if ext == ".pdf":
        from pypdf import PdfReader

        reader = PdfReader(str(path))
        return [(i + 1, page.extract_text() or "") for i, page in enumerate(reader.pages)]
    if ext == ".docx":
        import docx

        d = docx.Document(str(path))
        parts = [p.text for p in d.paragraphs]
        for table in d.tables:
            for row in table.rows:
                parts.append(" | ".join(cell.text for cell in row.cells))
        return [(1, "\n".join(parts))]
    if ext in (".txt", ".md"):
        raw = path.read_bytes()
        for enc in ("utf-8-sig", "utf-16", "cp1258", "latin-1"):
            try:
                return [(1, raw.decode(enc))]
            except UnicodeDecodeError:
                continue
    raise ValueError(f"Unsupported file type: {ext}")


def split_text(text: str, size: int = CHUNK_SIZE, overlap: int = CHUNK_OVERLAP) -> list[str]:
    """Split text on sentences/lines and pack the pieces into chunks of about ``size`` characters."""
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text).strip()
    if not text:
        return []
    pieces = re.split(r"(?<=[.!?。])\s+|\n+", text)
    pieces = [p.strip() for p in pieces if p.strip()]
    chunks, cur = [], ""
    for p in pieces:
        while len(p) > size:  # very long sentence
            head, p = p[:size], p[size - overlap:]
            if cur:
                chunks.append(cur)
                cur = ""
            chunks.append(head)
        if len(cur) + len(p) + 1 <= size:
            cur = f"{cur} {p}".strip()
        else:
            chunks.append(cur)
            tail = cur[-overlap:] if overlap else ""
            tail = tail[tail.find(" ") + 1:] if " " in tail else tail
            cur = f"{tail} {p}".strip()
    if cur:
        chunks.append(cur)
    return chunks


# ---------------------------------------------------------------- data
@dataclass
class Chunk:
    id: str
    doc_id: str
    doc_name: str
    course: str           # Moodle course id
    page: int
    text: str
    url: str = ""


@dataclass
class Document:
    id: str
    name: str
    course: str
    uploaded_by: str
    uploaded_at: str
    pages: int
    chunks: int
    url: str = ""
    ext_key: str = ""      # caller key (e.g. Moodle cm id + contenthash) used to skip re-indexing
    course_name: str = ""


class KnowledgeBase:
    def __init__(self, data_dir: Path):
        self.data_dir = data_dir
        self.files_dir = data_dir / "files"
        self.files_dir.mkdir(parents=True, exist_ok=True)
        self.store_path = data_dir / "store.json"
        self.lock = threading.Lock()
        self.docs: dict[str, Document] = {}
        self.chunks: list[Chunk] = []
        # Semantic vectors: each chunk is split into small sub-chunks, one vector each.
        self.embedder = Embedder.from_env()
        self.emb_path = data_dir / "embeddings.npz"
        self.sub_owner: list[str] = []          # chunk id owning each vector
        self.sub_vecs: np.ndarray | None = None
        self.embed_status = "off" if not self.embedder else "pending"
        self.min_semantic = float(os.getenv("EMBED_MIN_SCORE", "0.45"))
        self._load()
        self._load_embeddings()
        self._build_index()
        if self.embedder:  # embed missing chunks in the background so startup is not blocked
            threading.Thread(target=self.ensure_embeddings, daemon=True).start()

    # --------------------------------------------------- persistence
    def _load(self):
        if self.store_path.exists():
            data = json.loads(self.store_path.read_text(encoding="utf-8"))
            self.docs = {d["id"]: Document(**d) for d in data.get("docs", [])}
            self.chunks = [Chunk(**c) for c in data.get("chunks", [])]

    def _save(self):
        data = {
            "docs": [asdict(d) for d in self.docs.values()],
            "chunks": [asdict(c) for c in self.chunks],
        }
        tmp = self.store_path.with_suffix(".tmp")
        tmp.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")
        tmp.replace(self.store_path)

    def _load_embeddings(self):
        if not self.embedder or not self.emb_path.exists():
            return
        data = np.load(self.emb_path, allow_pickle=False)
        if str(data["model"]) != self.embedder.model:  # model changed: re-embed everything
            return
        self.sub_owner = [str(x) for x in data["owners"]]
        self.sub_vecs = data["vecs"].astype(np.float32)

    def _save_embeddings(self):
        if self.sub_vecs is None:
            self.emb_path.unlink(missing_ok=True)
            return
        tmp = self.emb_path.with_name("embeddings.tmp.npz")
        np.savez(tmp, model=np.array(self.embedder.model), owners=np.array(self.sub_owner), vecs=self.sub_vecs)
        tmp.replace(self.emb_path)

    def ensure_embeddings(self) -> int:
        """Embed chunks that have no vectors yet. Return the number of chunks processed."""
        if not self.embedder:
            return 0
        with self.lock:
            have = set(self.sub_owner)
            todo = [c for c in self.chunks if c.id not in have]
        if not todo:
            self.embed_status = "ready"
            return 0
        owners, texts = [], []
        for c in todo:
            for part in split_text(c.text, size=SUB_CHUNK_SIZE, overlap=SUB_CHUNK_OVERLAP) or [c.text]:
                owners.append(c.id)
                texts.append(f"{c.doc_name}: {part}")
        try:
            vecs = self.embedder.embed(texts)
        except Exception as e:  # embedding server down: keyword search still works
            self.embed_status = f"error: {e}"
            print(f"[embeddings] {e}", flush=True)
            return 0
        with self.lock:
            alive = {c.id for c in self.chunks}
            keep = [i for i, o in enumerate(owners) if o in alive]
            owners = [owners[i] for i in keep]
            vecs = vecs[keep]
            if self.sub_vecs is not None and self.sub_vecs.shape[1] != vecs.shape[1]:
                self.sub_owner, self.sub_vecs = [], None
            self.sub_owner += owners
            self.sub_vecs = vecs if self.sub_vecs is None else np.vstack([self.sub_vecs, vecs])
            self._save_embeddings()
            self._build_index()
        self.embed_status = "ready"
        return len(todo)

    def _build_index(self):
        pos = {c.id: i for i, c in enumerate(self.chunks)}
        self.sub_idx = np.array([pos.get(o, -1) for o in self.sub_owner], dtype=np.int64)
        self.word_vec = self.char_vec = self.matrix = None
        self.tok_acc = [tokens(f"{c.doc_name} {c.text}", True) for c in self.chunks]
        self.tok_noacc = [tokens(f"{c.doc_name} {c.text}", False) for c in self.chunks]
        if not self.chunks:
            return
        corpus = [normalize(f"{c.doc_name} {c.text}") for c in self.chunks]
        self.word_vec = TfidfVectorizer(ngram_range=(1, 2), sublinear_tf=True, min_df=1)
        self.char_vec = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5), sublinear_tf=True)
        w = self.word_vec.fit_transform(corpus)
        c = self.char_vec.fit_transform(corpus)
        # weights: words 0.6, characters 0.4 (character n-grams tolerate typos)
        self.matrix = hstack([w * np.sqrt(0.6), c * np.sqrt(0.4)]).tocsr()

    # --------------------------------------------------- operations
    def add_document(self, path: Path, original_name: str, course: str, uploaded_by: str,
                     url: str = "", ext_key: str = "", course_name: str = "") -> Document:
        pages = extract_pages(path)
        doc_id = uuid.uuid4().hex[:12]
        course = course.strip() or "default"
        new_chunks = []
        for page_no, text in pages:
            for part in split_text(text):
                new_chunks.append(Chunk(uuid.uuid4().hex[:12], doc_id, original_name, course, page_no, part, url))
        if not new_chunks:
            raise ValueError("No text could be extracted (the file may be a scanned image).")
        doc = Document(doc_id, original_name, course, uploaded_by,
                       datetime.now().strftime("%Y-%m-%d %H:%M"), len(pages), len(new_chunks),
                       url, ext_key, course_name)
        with self.lock:
            self.docs[doc_id] = doc
            self.chunks.extend(new_chunks)
            self._save()
            self._build_index()
        self.ensure_embeddings()
        return doc

    def delete_document(self, doc_id: str) -> bool:
        with self.lock:
            if doc_id not in self.docs:
                return False
            del self.docs[doc_id]
            self.chunks = [c for c in self.chunks if c.doc_id != doc_id]
            for f in self.files_dir.glob(f"{doc_id}_*"):
                f.unlink(missing_ok=True)
            if self.sub_vecs is not None:
                alive = {c.id for c in self.chunks}
                keep = [i for i, o in enumerate(self.sub_owner) if o in alive]
                self.sub_owner = [self.sub_owner[i] for i in keep]
                self.sub_vecs = self.sub_vecs[keep] if keep else None
                self._save_embeddings()
            self._save()
            self._build_index()
        return True

    def courses(self) -> list[str]:
        return sorted({d.course for d in self.docs.values()})

    def find_by_key(self, course: str, ext_key: str) -> Document | None:
        return next((d for d in self.docs.values() if d.course == course and d.ext_key == ext_key), None)

    def search(self, query: str, k: int = 5, course=None, min_coverage: float = 0.5):
        """Hybrid search: keywords (TF-IDF + keyword coverage) and semantics (embeddings).

        A chunk is kept when it matches enough keywords OR its semantic similarity is at least
        ``EMBED_MIN_SCORE``; kept chunks are ranked by a combined score. Without embeddings only
        keywords are used. Generic requests (e.g. "summarise the material") get the first chunks
        of each document; task requests get topical chunks topped up with those.
        ``course`` may be one course id or a list of ids (FR-CHAT-02).
        """
        qvec = None
        if self.embedder and self.sub_vecs is not None and query.strip():
            try:
                qvec = self.embedder.embed([query])[0]
            except Exception as e:
                print(f"[embeddings] query: {e}", flush=True)
        with self.lock:
            if self.matrix is None or not query.strip():
                return []
            q = normalize(query)
            qv = hstack([self.word_vec.transform([q]) * np.sqrt(0.6),
                         self.char_vec.transform([q]) * np.sqrt(0.4)]).tocsr()
            scores = (self.matrix @ qv.T).toarray().ravel()
            allowed = set(course) if isinstance(course, (list, tuple, set)) else ({course} if course else None)
            idxs = [i for i in range(len(self.chunks)) if allowed is None or self.chunks[i].course in allowed]
            terms, accented = content_terms(query)
            seen_docs, representative = {}, []
            for i in idxs:  # representative chunks: first two of each document
                d = self.chunks[i].doc_id
                if seen_docs.get(d, 0) < 2:
                    seen_docs[d] = seen_docs.get(d, 0) + 1
                    representative.append((self.chunks[i], 0.0))
            if not terms:  # generic question without content keywords
                return representative[:k]
            task = is_task_query(query)
            sem = None
            if qvec is not None and self.sub_vecs is not None and self.sub_vecs.shape[1] == qvec.shape[0]:
                sem = np.full(len(self.chunks), -1.0, dtype=np.float32)
                valid = self.sub_idx >= 0
                np.maximum.at(sem, self.sub_idx[valid], self.sub_vecs[valid] @ qvec)
            results = []
            for i in idxs:
                toks = self.tok_acc[i] if accented else self.tok_noacc[i]
                matched = sum(t in toks for t in terms)
                cov = matched / len(terms)
                # Require min_coverage of the keywords and at least two of them (when the query has two or more).
                lexical_ok = cov >= min_coverage and matched >= min(2, len(terms))
                lex = float(scores[i]) + 0.3 * cov
                if sem is None:
                    if lexical_ok:
                        results.append((self.chunks[i], lex))
                    continue
                s_ = float(sem[i])
                if lexical_ok or s_ >= self.min_semantic:
                    results.append((self.chunks[i], 0.5 * min(lex, 1.0) + 0.5 * max(s_, 0.0)))
            results.sort(key=lambda x: -x[1])
            if task:  # topical chunks first, then representative chunks up to k
                have = {c.id for c, _ in results}
                results += [r for r in representative if r[0].id not in have]
            return results[:k]
