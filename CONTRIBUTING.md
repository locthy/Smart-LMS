# Contributing to Smart-LMS

Smart-LMS is an Intelligent Online Learning Management System that integrates AI microservices (RAG Q&A chatbot, course advisor, and student academic risk prediction) with Moodle 5.x.

We are a collaborative capstone team working across multiple subsystems: Moodle LMS core, custom PHP plugins, a Python FastAPI AI engine, Docker orchestration, and an academic thesis report. To ensure code stability, smooth peer integration, and a clean Git history, all contributors must follow this guide.

---

## Development Process Overview

Our workflow is adapted from the official [Moodle Development Process][1] and modernized with a **Feature-Branch Workflow** (Simplified GitFlow) and **Scoped Conventional Commits** suited for our decoupled architecture:

1. Every new feature, bug fix, or refactor is developed in its own isolated branch branched from `develop`.
2. Changes are organized into **atomic, logical commits**.
3. You test your changes locally using our hybrid or Docker environment.
4. You open a Pull Request (PR) targeting `develop`.
5. Your patch is peer-reviewed by at least one teammate, verified, and merged.
6. The feature branch is deleted upon integration.

> [!IMPORTANT]
> **Protected Branches:** Direct commits and force pushes to `main` and `develop` are strictly prohibited. All changes must arrive via reviewed Pull Requests.

---

## Git Branching Strategy

```text
Time  ────────────────────────────────────────────────────────────────────────────────────────►

main (production)       ●─────────────────────────────────────────────────● [Milestone / Demo]
                        │                                                 ▲
                        │                                  (PR Merged)    │ (Milestone Release)
develop (integration)   └──► ●──────────────●─────────────────●───────────┴────────► (Active)
                             │              ▲                 ▲
                             │ (Branch off) │ (PR Merge)      │ (Next feature PR)
feat/<scope>-<name>          └──► ●─────────┴─► [Deleted]     └──► ...
                                 (Work & Atomic Commits)
```

### 1. Permanent Branches

- **`main`**: Milestone-ready branch. Contains only stable, tested builds prepared for faculty advisor demonstrations, midterm evaluations, and final thesis defense.
- **`develop`**: Primary integration branch. All active development converges here.

### 2. Working Branches (1 Feature = 1 Branch)

Never bundle multiple unrelated features into a single branch. Each branch must have a single clear objective.

#### Branch Naming Convention
```text
<type>/<scope>-<short-description>
```
*(All lowercase, words hyphen-separated)*

- **Types (`<type>/`):**
  - `feat/`: A new user-facing feature, API endpoint, or AI capability.
  - `fix/`: A bug fix or runtime issue resolution.
  - `refactor/`: Code restructuring without modifying behavior.
  - `chore/`: Build tasks, Docker configuration, or dependency updates.
  - `docs/`: System documentation, diagrams, or LaTeX thesis report updates.

- **Scopes (`<scope>`):**
  - `plugin`: Moodle local plugins (`ai_advisor`, `ai_qa`, `risk_predictor`, etc.).
  - `ai`: Python AI backend service (`rag_qa`, `advisor`, `risk_prediction`, vector DB).
  - `gateway`: API gateway / backend middleware (if applicable).
  - `ui`: Frontend components, Moodle Mustache templates, or custom CSS.
  - `docker` / `infra`: Docker Compose, PostgreSQL container, environment files.
  - `docs` / `report`: Thesis LaTeX report, draw.io diagrams, architecture docs.

#### Examples:
```text
feat/plugin-extract-student-metrics
feat/ai-rag-qdrant-pipeline
fix/docker-pg-utf8-collation
docs/report-chapter3-architecture
refactor/ai-risk-heuristic-rules
```

---

## Commit Message Guidelines

Our commit standard is directly inspired by Moodle's component-scoped commit style (`MDL-XXXXX <component>: <summary>`), adapted into standard Conventional Commits for our multi-service repository.

### Commit Format
```text
<type>(<scope>): <short imperative description>

[optional body: explain WHAT changed and WHY]
```

### Writing Rules:
1. **Length**: Keep the subject line under **72 characters**.
2. **Mood**: Use the **imperative mood** in the subject line (*"add"*, not *"added"* or *"adds"*; *"fix"*, not *"fixed"* or *"fixes"*).
3. **Punctuation**: Do not end the subject line with a period.
4. **Case**: Use lowercase for `<type>` and `(<scope>)`.

### Atomic Commits Rule
Follow Moodle's core integration philosophy: **split changes into logical, sequential commits** rather than squashing everything into one massive commit. 

For example, when introducing a new Moodle plugin feature that connects to the AI service, split it across sequential commits:
1. `feat(plugin): define db schema and install.xml for risk tracking`
2. `feat(plugin): implement external service endpoint for grade history`
3. `feat(ai): integrate student grade endpoint into risk predictor`
4. `test(ai): add unit test for student risk scoring heuristic`

### Allowed Types & Scopes Reference

| Type | Description | Example |
| :--- | :--- | :--- |
| `feat` | New feature, endpoint, or model | `feat(ai): add streaming response to course qa chatbot` |
| `fix` | Bug fix or exception resolution | `fix(plugin): fix null check on student inactivity calculation` |
| `docs` | Documentation or thesis report | `docs(report): update sequence diagram in chapter 3` |
| `refactor` | Code improvement without behavior change | `refactor(ai): modularize prompt templates for course advisor` |
| `chore` | Maintenance, dependencies, Docker | `chore(docker): bump postgres image to 17-alpine` |
| `test` | Automated or manual test suite updates | `test(ai): add mock test cases for course recommendation` |

---

## Pull Request & Peer Review Workflow

### Step-by-Step Procedure:

1. **Pull the latest `develop` branch before starting:**
   ```bash
   git checkout develop
   git pull origin develop
   ```

2. **Create your feature branch:**
   ```bash
   git checkout -b feat/<scope>-<feature-name>
   ```

3. **Develop and commit atomically:**
   Make incremental, well-described commits as you progress.
   ```bash
   git add <files>
   git commit -m "feat(<scope>): <concise message>"
   ```

4. **Verify locally before submitting:**
   - [ ] Moodle web runs and loads without PHP errors (`http://localhost:8000`).
   - [ ] FastAPI Swagger documentation loads without syntax or import errors (`http://localhost:8001/docs`).
   - [ ] Docker containers build and start cleanly (`docker compose up -d`).

5. **Push and open a Pull Request:**
   ```bash
   git push -u origin feat/<scope>-<feature-name>
   ```
   - Target base branch: **`develop`**.
   - PR Description: Clearly state what changed, which components are impacted, and how reviewers can test it.

6. **Peer Review & Integration:**
   - Every PR requires review and approval from at least **one other team member**.
   - Reviewers must check for merge conflicts, container health, and code cleanliness.
   - Once approved, merge the PR into `develop`.

---

## Branch Lifecycle: Never Reuse Merged Branches

> [!IMPORTANT]
> **Feature branches are strictly ephemeral.** 
> Never push new commits to a branch after its Pull Request has been merged into `develop`. Continuing work on an old branch causes diverged commit history, phantom diffs in future PRs, and severe merge conflicts.

### Post-Merge Local Cleanup Protocol:
Immediately after your PR is merged on GitHub:

1. **Delete the remote branch on GitHub** by clicking the **"Delete branch"** button on the PR page.
2. **Clean up your local environment:**
   ```bash
   # Switch to develop
   git checkout develop

   # Pull all newly merged changes
   git pull origin develop

   # Delete the local feature branch
   git branch -d feat/<scope>-<feature-name>

   # Prune deleted remote tracking references
   git fetch --prune
   ```

3. **Start your next feature:**
   Always cut a brand-new branch from your newly updated `develop`:
   ```bash
   git checkout -b feat/<scope>-<next-feature-name>
   ```

---

## Useful References

- [Moodle Development Processes][1]
- [Moodle Git Guide for Developers][2]
- [Moodle Coding Style & Commit Guidelines][3]

[1]: https://moodledev.io/general/development/process
[2]: https://moodledev.io/general/development/tools/git
[3]: https://moodledev.io/general/development/policies/codingstyle
