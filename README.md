# Intelligent Online Learning Management System Integrating AI

> **Specialized Project / Graduation Thesis - Ho Chi Minh City University of Technology (HCMUT - VNU-HCM)**  
> Intelligent LMS integrated with Artificial Intelligence (Moodle 5.x + AI Microservices).

---

## Quick Start with Docker (Recommended for Team)

The project is fully containerized using **Docker Compose**: Moodle 5.2.3 + PostgreSQL 17 + AI Backend (FastAPI). Team members **do not need to install PHP or PostgreSQL locally**.

### 1. Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.

### 2. Start the System (Single Command)
Open a terminal or PowerShell in the project root directory and run:

```bash
docker compose up -d --build
```

Docker will automatically:
1. Download and configure PostgreSQL 17.
2. **Automatically restore the sample database** from `scripts/moodle_backup.sql` into the database.
3. Build the Moodle 5.x image with all required PHP extensions (`pgsql`, `gd`, `intl`, `opcache`, `max_input_vars = 5000`, etc.).
4. Automatically generate `config.php` if it does not already exist.
5. Build and launch the AI service (FastAPI) with live-reload enabled.

---

## Access Endpoints

| Service | URL | Description |
| :--- | :--- | :--- |
| **Moodle LMS Web** | [http://localhost:8000](http://localhost:8000) | LMS web interface (Student, Instructor, Admin) user: admin \| pass: 123 |
| **AI Service Docs** | [http://localhost:8001/docs](http://localhost:8001/docs) | Swagger UI for exploring and testing AI APIs |
| **PostgreSQL DB** | `localhost:5432` | User: `moodleuser` \| Pass: `moodlepass` \| DB: `moodle` |

---

## Project Directory Structure

```text
.
├── docker-compose.yml              # Manages the entire multi-container system
├── docker/                         # Dockerfile and configurations for Moodle
├── moodle/                         # Moodle 5.x source code
│   └── public/local/               # Custom Moodle plugins directory:
│       ├── ai_advisor/             # Course recommendation advisor plugin
│       ├── ai_qa/                  # Course material Q&A chatbot plugin (RAG)
│       └── risk_predictor/         # Student academic risk prediction plugin
├── ai_service/                     # AI backend service (Python / FastAPI)
│   ├── main.py                     # FastAPI entry point
│   └── modules/                    # Specialized AI modules:
│       ├── advisor/                # Course advisory algorithms
│       ├── rag_qa/                 # RAG (Retrieval-Augmented Generation) pipeline
│       └── risk_prediction/        # Risk prediction model & explainability
├── scripts/
│   └── moodle_backup.sql           # Database backup loaded automatically on startup
└── docs/                           # Project reports and architecture diagrams
```

---

## Stopping the System

```bash
docker compose down
```

*(Course data and grades are safely persisted in Docker volumes and will not be lost on shutdown).*
