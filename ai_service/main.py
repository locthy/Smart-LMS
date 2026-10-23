from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
import os

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
