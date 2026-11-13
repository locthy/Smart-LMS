# Intelligent Online Learning Management System Integrating AI

---

## Development & Deployment Options

You can run this project in three different ways depending on your environment:

1. **[Option A: Hybrid Mode (Fastest for Windows Dev without WSL)](#option-a-hybrid-mode-fastest-for-windows-dev-without-wsl)** – Database & AI run in Docker, Moodle web runs directly on host PHP. Instant page loads with zero WSL setup.
2. **[Option B: Full Docker (Recommended for Linux, WSL2 Ubuntu, & Production)](#option-b-full-docker-recommended-for-linux-wsl2-ubuntu--production)** – Everything containerized with a single command. Runs at full speed on Linux, macOS, and **Ubuntu on Windows (WSL2)**.
3. **[Option C: Full Manual Setup (Without Docker)](#option-c-full-manual-setup-without-docker)** – Native installation of PHP, PostgreSQL, and Python on the host OS.

---

## Option A: Hybrid Mode (Fastest for Windows Dev without WSL)

> **Why Hybrid?** On Windows, running Moodle through Docker directly from a Windows drive (`D:\...`) can have file I/O latency. Running Moodle with host PHP while keeping PostgreSQL and the AI service in Docker gives you **instant page loads (<200ms)** without needing to configure WSL or use C: drive space.

### 1. Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.
- [PHP 8.2+](https://windows.php.net/download/) installed locally on Windows with extensions enabled in `php.ini`:
  `pdo_pgsql`, `pgsql`, `gd`, `intl`, `zip`, `soap`, `bcmath`, `exif`, `opcache`.

### 2. Create the `moodledata` Directory
Since `moodledata` contains local caches and uploaded files, it is excluded from Git (`.gitignore`). Create it once in the project root:
- **PowerShell (Windows):**
  ```powershell
  New-Item -ItemType Directory -Path .\moodledata -Force
  ```
- **Bash / Linux / macOS:**
  ```bash
  mkdir -p moodledata
  ```

### 3. Ensure `config.php` Exists
If you haven't created `moodle/config.php` yet, copy the template:
- **PowerShell:**
  ```powershell
  Copy-Item moodle\config.example.php moodle\config.php
  ```
- **Bash:**
  ```bash
  cp moodle/config.example.php moodle/config.php
  ```
*(Default settings automatically connect to PostgreSQL on `localhost:5432` and use the local `moodledata` folder).*

### 4. Start Database & AI Service in Docker
```bash
docker compose up -d postgres ai-service
```
*(PostgreSQL will initialize on host port `5432` and automatically restore the starter dataset from `scripts/moodle_backup.sql`).*

### 5. Start Moodle Web Server
In a new terminal:
```bash
cd moodle/public
php -S localhost:8000
```
Open **[http://localhost:8000](http://localhost:8000)** in your browser.

---

## Option B: Full Docker (Recommended for Linux, WSL2 Ubuntu, & Production)

Run the entire system in Docker (PostgreSQL 17 + Moodle 5.x + FastAPI AI Backend) without installing PHP, Python, or PostgreSQL on your host.

### Case 1: Running on Ubuntu on Windows (WSL2)

If you are on Windows and have **Ubuntu on WSL2**, this is the gold-standard workflow. It gives you the full convenience of Docker with **100% native Linux speed**, provided the project is cloned **inside the Ubuntu filesystem**.

> **Important:** Do **NOT** run Docker from `/mnt/c/` or `/mnt/d/` in Ubuntu. Clone the repository into your Ubuntu Linux home directory (`~` or `/home/<username>/`).

#### Step-by-Step for Ubuntu on Windows:
1. **Open Ubuntu Terminal:**
   Open Windows Terminal and select **Ubuntu**, or open PowerShell and type:
   ```bash
   wsl
   ```

2. **Navigate to your Linux home directory and clone the project:**
   ```bash
   cd ~
   mkdir -p projects && cd projects
   git clone <repository-url> Smart-LMS
   cd Smart-LMS
   ```

3. **Start the containers:**
   ```bash
   docker compose up -d --build
   ```

4. **Edit code using VS Code:**
   Inside the project directory in Ubuntu, launch VS Code:
   ```bash
   code .
   ```
   *(VS Code will open connected directly to Ubuntu via the WSL extension, allowing you to edit files on Windows with full native Linux performance).*

5. **Access in your Windows browser:**
   Open **[http://localhost:8000](http://localhost:8000)** directly in Chrome / Edge on Windows. WSL2 automatically forwards localhost ports to Windows!

---

### Case 2: Running on Native Linux / macOS / Production Server

On an Ubuntu / Debian server, VPS, or macOS:

1. **Prerequisites:** Docker and Docker Compose installed.
2. **Start the containers:**
   ```bash
   docker compose up -d --build
   ```
3. Docker will automatically:
   - Start PostgreSQL 17 on port `5432` (mapped from container `5432`).
   - **Auto-restore sample database** from `scripts/moodle_backup.sql`.
   - Build the Moodle 5.x image with all required PHP extensions.
   - **Auto-manage `moodledata`** via an internal Docker volume (`smart-lms_moodledata`).
   - Auto-generate `config.php` if missing.
   - Launch the Python AI Service (FastAPI) on port `8001` with live-reload.

---

## Option C: Full Manual Setup (Without Docker)

For developers who already have local PostgreSQL and Python environments installed natively on their OS.

### 1. Create `moodledata` Directory
```bash
mkdir -p moodledata
```

### 2. Configure `config.php`
```bash
cp moodle/config.example.php moodle/config.php
```
Open `moodle/config.php` and update your database credentials (`dbuser`, `dbpass`, `dbport`).

### 3. Restore Database
Create a database named `moodle` in your local PostgreSQL and import the schema:
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
  python -m venv .venv
  # Windows: .venv\Scripts\activate | Linux: source .venv/bin/activate
  pip install -r requirements.txt
  uvicorn main:app --reload --port 8001
  ```

---

## Data Storage Overview

| Data Type | Hybrid Mode | Full Docker (WSL2 / Linux) | Manual Setup |
| :--- | :--- | :--- | :--- |
| **Database Data** (Users, Courses, Grades, AI Logs) | Docker Volume `smart-lms_pgdata` | Docker Volume `smart-lms_pgdata` | Local PostgreSQL system |
| **Uploaded Files & Cache** (Avatars, PDFs, CSS Cache) | Root `./moodledata` folder | Docker Volume `smart-lms_moodledata` | Root `./moodledata` folder |
| **AI Models & Vector DB** | `./ai_service` directory | `./ai_service` directory | `./ai_service` directory |

> **Note on `moodledata`:** The database is the single source of truth for all structured data (users, courses, settings). The `moodledata` folder stores file attachments and compiled caches. In Full Docker (Case 1 & 2), Docker manages `moodledata` automatically via an isolated volume.

---

## Access Endpoints

| Service | URL | Description | Default Credentials |
| :--- | :--- | :--- | :--- |
| **Moodle LMS Web** | [http://localhost:8000](http://localhost:8000) | LMS web interface (Student, Teacher, Admin) | User: `admin` \| Pass: `123` |
| **AI Service Docs** | [http://localhost:8001/docs](http://localhost:8001/docs) | Interactive Swagger API docs | *None* |
| **PostgreSQL DB** | `localhost:5432` | Database connection from host | User: `moodleuser` \| Pass: `moodlepass` \| DB: `moodle` |

---

## Project Directory Structure

```text
.
├── docker-compose.yml              # Multi-container orchestration (Postgres, Moodle, AI Service)
├── docker/                         # Dockerfile and entrypoint configurations for Moodle
├── moodledata/                     # Local Moodle data root (gitignored, created on demand)
├── moodle/                         # Moodle 5.x core source code
│   ├── config.example.php          # Configuration template for Docker & Local runs
│   ├── config.php                  # Active Moodle config (gitignored)
│   └── public/local/               # Custom LMS AI plugins:
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
│   └── moodle_backup.sql           # Database schema & seed data (UTF-8, auto-loaded)
├── docs/                           # Architecture specifications and documentation
└── report/                         # Capstone project thesis report (LaTeX XeTeX source)
    ├── main.tex                    # Master LaTeX entry point
    ├── images/                     # Architectural diagrams, usecases & BK logos
    └── sections/                   # Individual chapters, analysis & appendices
```

---

## 🤝 Contributing & Git Workflow

We follow a **Feature-Branch Workflow** (Simplified GitFlow) and **Scoped Conventional Commits** to keep our multi-service repository clean, reliable, and conflict-free:

- **Branch Naming**: `<type>/<scope>-<short-description>` (e.g., `feat/plugin-risk-extractor`, `feat/ai-rag-pipeline`).
- **Commit Format**: `<type>(<scope>): <imperative description>` (e.g., `feat(ai): add streaming endpoint`).
- **Branch Lifecycle**: Never reuse merged branches. Always delete after merge and cut fresh branches from `develop`.

👉 **Please read our full [Contributing Guidelines (CONTRIBUTING.md)](./CONTRIBUTING.md)** for detailed rules, type/scope tables, PR review checklists, and local branch cleanup commands before opening a Pull Request.

---

## Compiling the Capstone Report (LaTeX)

The capstone thesis documentation is located in the `report/` directory.

### Prerequisites
- **Compiler Engine**: **XeLaTeX** (mandatory for `fontspec` and native *Times New Roman* font rendering).
- **Bibliography Processor**: **Biber** (configured via `biblatex`).
- **Distributions**:
  - **Windows**: [MiKTeX](https://miktex.org/download) or TeX Live (Windows includes system *Times New Roman*).
  - **Ubuntu / WSL2 / Linux**:
    ```bash
    sudo apt update
    sudo apt install -y texlive-xetex texlive-fonts-recommended texlive-latex-extra latexmk biber ttf-mscorefonts-installer
    ```
  - **macOS**: [MacTeX](https://www.tug.org/mactex/).

### How to Compile

#### Method A: Using `latexmk` (Recommended - Automated)
```bash
cd report
latexmk -xelatex main.tex
```

#### Method B: Direct CLI Execution (Manual passes)
To resolve all Table of Contents, citations, and List of Tables spacing:
```bash
cd report
xelatex -interaction=nonstopmode main.tex
biber main
xelatex -interaction=nonstopmode main.tex
xelatex -interaction=nonstopmode main.tex
```

#### Method C: Using VS Code / Antigravity IDE (LaTeX Workshop)
1. Open any `.tex` file in `report/`.
2. Trigger compilation with recipe `latexmk (xelatex)` or set the default compiler tool to `xelatex`.
3. Output preview is generated at `report/main.pdf`.

---

## Stopping the System

- **Stop Docker containers:**
  ```bash
  docker compose down
  ```
  *(Data stored in database volumes is safely preserved).*
- **Stop local Moodle PHP server:**
  Press `Ctrl + C` in the terminal running `php -S localhost:8000`.
