# Intelligent Online Learning Management System Integrating AI

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
4. **Automatically create and manage `moodledata`** via Docker Volume (no manual folder creation required).
5. Automatically generate `config.php` if it does not already exist.
6. Build and launch the AI service (FastAPI) with live-reload enabled.

---

## Alternative: Manual Setup (Without Docker)

If any team member wants to run Moodle directly on Windows/Linux without Docker:

### 1. Create `moodledata` Directory
Create an empty folder named `moodledata` in the project root (sibling to `moodle`):
```text
project-root/
├── moodle/
└── moodledata/     <-- Create this empty directory
```

### 2. Configure `config.php`
Copy `moodle/config.example.php` to `moodle/config.php` and update your local database credentials if needed:
```bash
cp moodle/config.example.php moodle/config.php
```

### 3. Restore Database
Create a PostgreSQL database named `moodle` and restore the schema:
```bash
psql -U moodleuser -d moodle -f scripts/moodle_backup.sql
```

### 4. Run Web Server & AI Service
- **Moodle Web**:
  ```bash
  cd moodle/public
  php -S localhost:8000
  ```
- **AI Backend**:
  ```bash
  cd ai_service
  pip install -r requirements.txt
  uvicorn main:app --reload --port 8001
  ```

---

## Access Endpoints

| Service | URL | Description |
| :--- | :--- | :--- |
| **Moodle LMS Web** | [http://localhost:8000](http://localhost:8000) | LMS web interface (Student, Instructor, Admin)<br>Default Admin: `admin` \| Pass: `123` |
| **AI Service Docs** | [http://localhost:8001/docs](http://localhost:8001/docs) | Swagger UI for exploring and testing AI APIs |
| **PostgreSQL DB** | `localhost:5432` | User: `moodleuser` \| Pass: `moodlepass` \| DB: `moodle` |

---

## Project Directory Structure

```text
.
├── docker-compose.yml              # Manages the entire multi-container system
├── docker/                         # Dockerfile and configurations for Moodle
├── moodle/                         # Moodle 5.x source code
│   ├── config.example.php          # Configuration template
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
