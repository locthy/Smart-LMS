"""Semantic embeddings through an OpenAI-compatible ``/embeddings`` API (e.g. LM Studio).

Enabled by ``EMBED_MODEL`` (e.g. ``text-embedding-vietnamese_embedding_v2``). The server is
``EMBED_BASE_URL``, falling back to ``LLM_BASE_URL``. When unset or unreachable, retrieval
falls back to keyword search only.
"""
from __future__ import annotations

import json
import os
import urllib.error
import urllib.request

import numpy as np

SUB_CHUNK_SIZE = 300     # embed small sub-chunks so their meaning is not diluted
SUB_CHUNK_OVERLAP = 60


class Embedder:
    def __init__(self, base_url: str, model: str, api_key: str = "", batch: int = 32, timeout: int = 300):
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.api_key = api_key
        self.batch = batch
        self.timeout = timeout

    @classmethod
    def from_env(cls) -> "Embedder | None":
        model = os.getenv("EMBED_MODEL", "").strip()
        if not model:
            return None
        base = os.getenv("EMBED_BASE_URL") or os.getenv("LLM_BASE_URL") or "http://127.0.0.1:1234/v1"
        return cls(base, model, os.getenv("EMBED_API_KEY") or os.getenv("LLM_API_KEY", ""))

    def embed(self, texts: list[str]) -> np.ndarray:
        """Return a ``(len(texts), dim)`` matrix of unit-length vectors."""
        out = []
        for i in range(0, len(texts), self.batch):
            part = [t.replace("\n", " ")[:8000] or " " for t in texts[i:i + self.batch]]
            headers = {"Content-Type": "application/json"}
            if self.api_key:
                headers["Authorization"] = "Bearer " + self.api_key
            req = urllib.request.Request(self.base_url + "/embeddings",
                                         json.dumps({"model": self.model, "input": part}).encode(), headers)
            try:
                with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                    data = json.load(resp)
            except urllib.error.HTTPError as e:
                raise RuntimeError(f"HTTP {e.code}: {e.read().decode(errors='replace')[:300]}") from e
            rows = sorted(data["data"], key=lambda d: d.get("index", 0))
            out.extend(r["embedding"] for r in rows)
        vecs = np.asarray(out, dtype=np.float32)
        norms = np.linalg.norm(vecs, axis=1, keepdims=True)
        return vecs / np.maximum(norms, 1e-12)
