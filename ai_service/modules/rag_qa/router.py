"""FastAPI endpoints of the course-material RAG module (``/rag/*``).

These endpoints are called server-to-server by the Moodle plugin ``local_ai_qa``.
Every request must carry the shared secret in the ``X-RAG-Key`` header (``RAG_API_KEY``).
Moodle authenticates the user and checks course enrolment/capabilities; this service
only searches the ``course_ids`` Moodle sends (FR-CHAT-02, FR-INT-02).
"""
from __future__ import annotations

import os
import secrets
from functools import lru_cache
from pathlib import Path

from fastapi import APIRouter, Depends, File, Form, Header, HTTPException, UploadFile
from pydantic import BaseModel, Field

from .generation import generate_answer, is_smalltalk, llm_mode
from .retrieval import SUPPORTED_EXT, KnowledgeBase

DEFAULT_DATA_DIR = Path(__file__).resolve().parents[2] / "vector_store" / "rag_qa"


@lru_cache(maxsize=1)
def get_knowledge_base() -> KnowledgeBase:
    """Single knowledge base per process, stored in ``RAG_DATA_DIR``."""
    return KnowledgeBase(Path(os.getenv("RAG_DATA_DIR", str(DEFAULT_DATA_DIR))))


def require_api_key(x_rag_key: str = Header(default="")) -> None:
    api_key = os.getenv("RAG_API_KEY", "")
    if not api_key or not secrets.compare_digest(x_rag_key, api_key):
        raise HTTPException(status_code=401, detail="Missing or invalid X-RAG-Key header.")


class QueryIn(BaseModel):
    question: str
    course_ids: list[str] = Field(..., description="Courses whose material may be searched")
    role: str = Field("student", description="student or teacher")
    history: list[dict] = Field(default_factory=list, description="Previous turns [{role, content}]")
    top_k: int = 5


class PruneIn(BaseModel):
    course_id: str
    keep_keys: list[str]


router = APIRouter(prefix="/rag", tags=["rag_qa"], dependencies=[Depends(require_api_key)])


@router.get("/status")
def status(kb: KnowledgeBase = Depends(get_knowledge_base)):
    return {"ok": True, "documents": len(kb.docs), "llm": llm_mode(), "embeddings": kb.embed_status}


@router.get("/documents")
def list_documents(course_id: str, kb: KnowledgeBase = Depends(get_knowledge_base)):
    docs = [d.__dict__ for d in kb.docs.values() if d.course == course_id]
    return sorted(docs, key=lambda d: d["name"].lower())


@router.post("/documents")
async def add_document(file: UploadFile = File(...), course_id: str = Form(...), ext_key: str = Form(...),
                       url: str = Form(""), name: str = Form(""), course_name: str = Form(""),
                       kb: KnowledgeBase = Depends(get_knowledge_base)):
    """Index one course file (FR-INT-04). Files already indexed under ``ext_key`` are skipped."""
    name = Path(name or file.filename or "file").name
    existing = kb.find_by_key(course_id, ext_key)
    if existing:
        return {"status": "unchanged", "doc": existing.__dict__}
    ext = Path(name).suffix.lower()
    if ext not in SUPPORTED_EXT:
        return {"status": "skipped", "reason": f"unsupported type {ext}"}
    tmp = kb.files_dir / f"_upload_{secrets.token_hex(4)}{ext}"
    tmp.write_bytes(await file.read())
    try:
        doc = kb.add_document(tmp, name, course_id, "moodle", url=url, ext_key=ext_key, course_name=course_name)
    except Exception as e:
        tmp.unlink(missing_ok=True)
        return {"status": "error", "reason": str(e)}
    tmp.replace(kb.files_dir / f"{doc.id}_{name}")
    return {"status": "added", "doc": doc.__dict__}


@router.post("/documents/prune")
def prune_documents(body: PruneIn, kb: KnowledgeBase = Depends(get_knowledge_base)):
    """Remove the course's documents whose key is not in ``keep_keys`` (FR-INT-05)."""
    keep = set(body.keep_keys)
    stale = [d.id for d in kb.docs.values() if d.course == body.course_id and d.ext_key not in keep]
    for doc_id in stale:
        kb.delete_document(doc_id)
    return {"deleted": len(stale)}


@router.post("/query")
def query(body: QueryIn, kb: KnowledgeBase = Depends(get_knowledge_base)):
    """Answer a question from the given courses' material, with citations (FR-CHAT-01..04)."""
    q = body.question.strip()
    if not q:
        raise HTTPException(status_code=400, detail="Empty question.")
    hits = [] if is_smalltalk(q) else kb.search(q, k=max(1, min(body.top_k, 10)), course=body.course_ids)
    role = "teacher" if body.role == "teacher" else "student"
    answer, mode = generate_answer(q, hits, role, body.history)
    sources = [{"n": i, "doc_name": ch.doc_name, "page": ch.page, "course_id": ch.course,
                "url": ch.url, "score": round(score, 3), "text": ch.text[:500]}
               for i, (ch, score) in enumerate(hits, 1)]
    return {"answer": answer, "mode": mode, "sources": sources}
