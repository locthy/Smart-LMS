# `rag_qa` — Course Material RAG Q&A

Python side of the AI learning assistant. Moodle (`local_ai_qa`) sends course files to be indexed and questions to be answered; this module retrieves relevant passages from the user's courses and generates a grounded answer with citations.

```
Moodle (local_ai_qa: auth + enrolment/capability checks)
   │  X-RAG-Key
   ▼
POST /rag/query ──► hybrid retrieval (keywords + embeddings, filtered by course_ids)
                          │ nothing relevant ──► "not found" (no LLM call)
                          ▼
                    LLM (OpenAI-compatible API) ──► answer + citations (file, page, Moodle URL)
```

## Endpoints (all require header `X-RAG-Key: <RAG_API_KEY>`)

| Method & path | Purpose | Requirement |
| :--- | :--- | :--- |
| `POST /rag/query` | `{question, course_ids[], role, history[]}` → `{answer, mode, sources[]}` | FR-CHAT-01..04 |
| `POST /rag/documents` | multipart `file` + `course_id`, `ext_key`, `url`, `name`: index a file; same `ext_key` is skipped | FR-INT-04 |
| `POST /rag/documents/prune` | `{course_id, keep_keys[]}`: drop documents no longer in the course | FR-INT-05 |
| `GET /rag/documents?course_id=` | Indexed documents of a course | |
| `GET /rag/status` | Document count, LLM mode, embedding status | |

Interactive docs: <http://localhost:8001/docs>.

## How it works

- **Parsing** (`retrieval.py`): PDF (per page, `pypdf`), DOCX (paragraphs + tables), TXT, MD. Text is split into ~900-character chunks with page metadata.
- **Hybrid retrieval** (FR-CHAT-09):
  - keywords: TF-IDF over word and character n-grams on accent-stripped text (works for Vietnamese with or without diacritics), plus a keyword-coverage filter that rejects off-topic questions;
  - semantics (`embeddings.py`, optional): each chunk is embedded as ~300-character sub-chunks; a chunk is kept if its best cosine similarity ≥ `EMBED_MIN_SCORE`;
  - a chunk is kept when **either** signal accepts it, ranked by a combined score.
- **Requests over the whole material** ("summarise", "quiz", "commonly confused concepts"...) get topical chunks topped up with the first chunks of each document. **Small talk** ("hi", "cảm ơn") is answered without retrieval.
- **Generation** (`generation.py`): any OpenAI-compatible `/chat/completions` server. If nothing relevant is retrieved the LLM is not called (FR-CHAT-04). If the LLM fails, the service still answers in *extractive* mode (best sentences + citations).
- Storage: `ai_service/vector_store/rag_qa/` (`store.json`, `embeddings.npz`, original files) — git-ignored. Qdrant can replace this store later without changing the API.

## Configuration (environment)

| Variable | Default | |
| :--- | :--- | :--- |
| `RAG_API_KEY` | — (required) | Shared with the Moodle plugin. Docker default: `smartlms-dev-key`. |
| `RAG_DATA_DIR` | `ai_service/vector_store/rag_qa` | |
| `LLM_PROVIDER` | `auto` | `auto` (LLM when `LLM_BASE_URL` is set), `openai`, `extractive` |
| `LLM_BASE_URL` | — | e.g. LM Studio `http://127.0.0.1:1234/v1` (Docker: `http://host.docker.internal:1234/v1`) |
| `LLM_MODEL`, `LLM_API_KEY` | | e.g. `google/gemma-4-e4b` |
| `LLM_REASONING_EFFORT` | `none` | `none` disables the "thinking" phase of Gemma 4 / Qwen 3 (≈1 s instead of ≈20 s) |
| `LLM_MAX_TOKENS`, `LLM_TEMPERATURE`, `LLM_TIMEOUT` | `1200`, `0.2`, `180` | |
| `EMBED_MODEL` | — | e.g. `text-embedding-vietnamese_embedding_v2`; empty = keyword search only |
| `EMBED_BASE_URL` | `LLM_BASE_URL` | |
| `EMBED_MIN_SCORE` | `0.45` | Calibrated for the Vietnamese embedding model above |

See `ai_service/.env.example`. In Docker the values come from `docker-compose.yml` and can be overridden in a root `.env` file.

### Using LM Studio

1. Load the chat model and the embedding model, then start the server (Developer › Start Server, port 1234).
2. **Keep both models loaded**: by default LM Studio keeps only one JIT-loaded model, so every question would swap models (~10 s). Either disable JIT auto-evict in the server settings or load both explicitly:
   ```bash
   lms load google/gemma-4-e4b --yes
   lms load text-embedding-vietnamese_embedding_v2 --yes
   ```

## Tests

```bash
cd ai_service
pip install -r requirements-dev.txt
python -m pytest -q tests
```

The tests run offline (no LLM, fake embedder) against a temporary data directory.
