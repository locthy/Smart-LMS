# AI Course Q&A (`local_ai_qa`)

The `local_ai_qa` plugin adds the **AI learning assistant (RAG chatbot)** to Moodle. Students and teachers ask questions in Vietnamese or English and get answers grounded in the course materials, with citations (file, page) linking back to the Moodle activity.

The plugin is the Moodle side of the feature: it renders the chat UI, authenticates the user, checks course enrolment and capabilities, and calls the Python AI service (`ai_service/modules/rag_qa`, endpoints `/rag/*`) server-to-server.

---

## User Interface

- A vertical **"Assistant" tab** is pinned to the left edge of every page (it moves aside when Boost's course index drawer is open).
- Clicking it opens the **assistant drawer**:
  - **Drag** its right edge to resize (double-click to reset, or focus the edge and use ←/→).
  - **Pin** pushes the page content to the right instead of overlaying it.
  - **«** or **Esc** collapses it back into the tab; **+** starts a new conversation.
- Width, open and pinned state are stored as **Moodle user preferences**; the conversation is kept per course for the browser session.
- Answers render light Markdown and **LaTeX math through Moodle's MathJax filter**. Citations `[1]` expand the matching source passage.
- Teachers get teaching-oriented suggestions (quiz questions, lecture outline, commonly confused concepts).

## Scope & Permissions

| Page | Searched material |
| :--- | :--- |
| Course or activity page | That course only |
| Outside courses (Dashboard...) | All courses the user is enrolled in (setting *Show outside courses*) |

| Capability | Default roles | Allows |
| :--- | :--- | :--- |
| `local/ai_qa:use` | student, teacher, editingteacher, manager | Ask questions |
| `local/ai_qa:manage` | editingteacher, manager | **Sync course files** and teacher-oriented answers |

Indexing (**Sync course files** in the drawer, or the CLI below) sends the **visible** File and Folder activities (PDF, DOCX, TXT, MD) and Page activities to the AI service. Hidden activities are never indexed, so students cannot extract hidden material (e.g. answer keys). Unchanged files are skipped and files removed from the course are removed from the index.

---

## Configuration

*Site administration › Plugins › Local plugins › AI Course Q&A*

| Setting | Default | Notes |
| :--- | :--- | :--- |
| Service URL | `AI_SERVICE_URL` env, else `http://localhost:8001` | Full Docker: `http://ai-service:8001` (set by `docker-compose.yml`). Hybrid mode: `http://localhost:8001`. |
| Service API key | `AI_SERVICE_KEY` env, else `smartlms-dev-key` | Must equal `RAG_API_KEY` of the AI service. **Change it outside local development.** |
| Show outside courses | on | Search all enrolled courses on non-course pages. |

LLM and embedding models are configured on the AI service side, see `ai_service/modules/rag_qa/README.md`.

---

## CLI

Run from the Smart-LMS project root (inside the `lms-moodle` container in full Docker mode: `docker exec -it lms-moodle php ...` with paths under `/var/www/html/...`).

```bash
# Index one course (same as the "Sync course files" button)
php moodle/public/local/ai_qa/cli/sync_course.php --shortname=CO3117

# Index every course
php moodle/public/local/ai_qa/cli/sync_course.php --all

# LOCAL TESTBED: create a course from a folder of documents, enrol users and index it
php moodle/public/local/ai_qa/cli/import_course_files.php --dir=/path/to/pdfs --shortname=CO3117 \
    --fullname="Machine Learning" --teacher=teacher1 --student=student1 --sync
```

---

## Files

| Path | Purpose |
| :--- | :--- |
| `classes/hook_callbacks.php` | Injects the drawer through `core\hook\output\before_footer_html_generation` |
| `classes/scope.php` | Courses a user may search from a page context, role detection |
| `classes/api_client.php` | HTTP client for `/rag/query`, `/rag/documents`, `/rag/documents/prune` |
| `classes/course_sync.php` | Collects course files/pages and syncs them with the index |
| `classes/external/{ask,get_documents,sync_course}.php` | AJAX web services used by the drawer |
| `amd/src/drawer.js` | Drawer behaviour (plain AMD; `amd/build/drawer.min.js` is a copy, no grunt build needed) |
| `templates/drawer.mustache`, `styles.css` | Drawer markup and styles (Bootstrap 5 / Boost) |

After editing `amd/src/drawer.js`, copy it to `amd/build/drawer.min.js` and purge caches (`php admin/cli/purge_caches.php`).
