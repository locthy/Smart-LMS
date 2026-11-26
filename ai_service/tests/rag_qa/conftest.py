"""Test setup: run rag_qa offline (no LLM, no embedding server) in a temporary data dir."""
import os
import sys
import tempfile
from pathlib import Path

AI_SERVICE = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(AI_SERVICE))

os.environ["RAG_DATA_DIR"] = tempfile.mkdtemp(prefix="rag_qa_test_")
os.environ["RAG_API_KEY"] = "test-key"
os.environ["LLM_PROVIDER"] = "extractive"
os.environ["EMBED_MODEL"] = ""
os.environ.pop("LLM_BASE_URL", None)
