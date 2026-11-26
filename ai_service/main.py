from dotenv import load_dotenv
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import os

load_dotenv()  # optional ai_service/.env (see .env.example); real env vars take precedence

from modules.rag_qa import router as rag_qa_router  # noqa: E402

app = FastAPI(
    title="Intelligent LMS AI Backend",
    description="AI Microservices for Moodle: Advisor, Course Material RAG, and Early Warning Risk Analytics",
    version="1.0.0"
)

# Enable CORS for Moodle integration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Course Material RAG Q&A (AI learning assistant)
app.include_router(rag_qa_router)


@app.get("/")
def read_root():
    return {
        "status": "online",
        "service": "LMS AI Backend Service",
        "docs_url": "/docs"
    }


@app.get("/health")
def health_check():
    return {"status": "healthy"}
