# AI Open Source Contribution Assistant

An automated, end-to-end system designed to discover high-impact public repositories, execute static and runtime security scanning, generate bounded-optimized code fixes using **DeepSeek-V4-Pro** and **OpenHands**, and present a **3-Panel Code Comparison & Optimization Dashboard** for single-click pull request approvals.

---

## 🏗️ Architecture Overview

```
+-----------------------------------------------------------------------------------+
|                            APPLICATION ARCHITECTURE                               |
|                                                                                   |
|  +---------------------------+        REST API        +-------------------------+ |
|  | Next.js Frontend          | <--------------------> | PHP 8.2 Backend         | |
|  | (Hosted on Vercel)        |                        | (Hosted on Hostinger)   | |
|  +---------------------------+                        +-------------------------+ |
|                                                                   |               |
|                                                                   v               |
|  +---------------------------+        Webhooks        +-------------------------+ |
|  | GitHub Actions Runners    | <--------------------> | MySQL Database          | |
|  | (Semgrep, CodeQL, Trivy,  |                        | (Hosted on Hostinger)   | |
|  | Gitleaks, ZAP, OpenHands) |                        +-------------------------+ |
|  +---------------------------+                                                    |
|               |                                                                   |
|               v                                                                   |
|  +------------------------------------------------------------------------------+ |
|  | NVIDIA API Catalog (DeepSeek-V4-Pro/Flash + GPT-OSS 120B/20B Multi-Tier Failover) | |
|  +------------------------------------------------------------------------------+ |
+-----------------------------------------------------------------------------------+
```

---

## ✨ Key Features

- **Automated Repository Discovery**: Deterministic ranking formula scores repositories based on stars, commit recency, documentation completeness (`README`, `CONTRIBUTING`, `LICENSE`), and issue quality.
- **Multi-Tier LLM Failover**: Primary reasoning powered by `deepseek-ai/deepseek-v4-pro` and `deepseek-ai/deepseek-v4-flash` with zero-downtime automatic failover to `openai/gpt-oss-120b` and `openai/gpt-oss-20b`.
- **Multi-Scanner Security Pipeline**: Executes **Semgrep CE**, **CodeQL**, **Trivy**, and **Gitleaks** inside ephemeral devcontainer runners (`.github/workflows/analyze.yml`).
- **Bounded-Optimized Code Fixes**: OpenHands fix agent fixes bugs strictly within touched lines/functions without expanding into unconstrained rewrites. Pre-merge Gitleaks rescanning prevents secret leaks.
- **Runtime Security Scanning**: OWASP ZAP and Postman/Newman scan sandboxed localhost instances (`runtime-scan.yml`).
- **Code Comparison & Optimization Dashboard**: 3-panel Next.js interface displaying live compare diffs, Lizard cyclomatic complexity deltas with mandatory non-dismissable captions, and wall-clock test suite runtime deltas.
- **Single-Click PR Gate**: Consolidates verified fixes into a single PR from fork to upstream repository.
- **Automated Lifecycle & Digest**: Batches notifications into daily digest emails via Resend and auto-expires unapproved forks after 14 days.

---

## 🛠️ Technology Stack & Tooling

| Component | Technology | Hosting / Environment |
|---|---|---|
| **Backend Orchestrator** | PHP 8.2 (Vanilla OOP + PDO) | Hostinger Basic (Shared PHP) |
| **Database** | MySQL / MariaDB | Hostinger Basic (Shared MySQL) |
| **Frontend Dashboard** | Next.js 14 / TypeScript / Vanilla CSS | Vercel (Hobby Free Tier) |
| **Primary LLMs** | DeepSeek-V4-Pro & DeepSeek-V4-Flash | NVIDIA API Catalog |
| **Failover Backup LLMs** | GPT-OSS-120B & GPT-OSS-20B | NVIDIA API Catalog |
| **Security Scanners** | Semgrep, CodeQL, Trivy, Gitleaks, OWASP ZAP | GitHub Actions Runners |
| **Fix Generation Agent** | OpenHands (Python) inside Devcontainer | GitHub Actions Runners |
| **Code Graph Context** | Sourcegraph Cody GraphQL API | Backend Service Call |
| **Complexity Measurement**| Lizard (`lizard`) | GitHub Actions Runner |
| **Email Delivery** | Resend API | PHP Backend Service Call |

---

## 🚀 Step-by-Step Production Deployment Guide

### Module 1: PHP Backend & MySQL Database (Hostinger Basic)

#### **1. Database Setup**
1. Open Hostinger hPanel -> **Databases** -> **MySQL Databases**.
2. Create a new database:
   - **Database Name**: `ai_oss_assistant`
   - **Database User**: `ai_oss_user`
   - **Database Password**: `<your_secure_password>`
3. Run all 10 SQL migration scripts in sequence from `ai-oss-assistant-backend/migrations/` using phpMyAdmin or CLI:
   `001_create_users.sql` ... `010_create_reports.sql`.

#### **2. Upload Backend Files**
1. Upload `ai-oss-assistant-backend` contents to `public_html/api` or root.
2. In Hostinger Terminal / SSH, run:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

#### **3. Environment Configuration (`.env`)**
Create `.env` file in `ai-oss-assistant-backend/.env`:
```ini
APP_ENV=production
APP_DEBUG=false
API_BEARER_TOKEN=<generate_secure_token>

DB_HOST=localhost
DB_NAME=ai_oss_assistant
DB_USER=ai_oss_user
DB_PASS=<your_secure_password>
DB_PORT=3306

NVIDIA_BASE_URL=https://integrate.api.nvidia.com/v1
NVIDIA_MODEL_PRO=meta/llama-3.2-11b-vision-instruct
NVIDIA_API_KEY_PRO=<your_nvidia_api_key_pro>

NVIDIA_MODEL_FLASH=meta/llama-3.2-11b-vision-instruct
NVIDIA_API_KEY_FLASH=<your_nvidia_api_key_flash>

NVIDIA_API_KEY_PRO_BACKUP1=<your_nvidia_api_key_backup1>
NVIDIA_API_KEY_PRO_BACKUP2=<your_nvidia_api_key_backup2>
NVIDIA_API_KEY_FLASH_BACKUP1=<your_nvidia_api_key_backup3>
NVIDIA_API_KEY_FLASH_BACKUP2=<your_nvidia_api_key_backup4>

GITHUB_APP_ID=<your_github_app_id>
GITHUB_APP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\n..."
GITHUB_WEBHOOK_SECRET=<your_webhook_secret>

RESEND_API_KEY=re_123456789
```

#### **4. Configure Cron Jobs**
In Hostinger hPanel -> **Advanced** -> **Cron Jobs**:

| Cron Script | Schedule | Command |
|---|---|---|
| **Discovery** | Daily at 2:00 AM (`0 2 * * *`) | `/usr/bin/php /home/user/public_html/api/cron/discover-repos.php` |
| **Backup Poller** | Every 10 mins (`*/10 * * * *`) | `/usr/bin/php /home/user/public_html/api/cron/poll-pending-jobs.php` |
| **Daily Digest** | Daily at 8:00 AM (`0 8 * * *`) | `/usr/bin/php /home/user/public_html/api/cron/send-digest.php` |
| **Fork Expiry** | Daily at 3:00 AM (`0 3 * * *`) | `/usr/bin/php /home/user/public_html/api/cron/expire-forks.php` |

---

### Module 2: Next.js Dashboard (Vercel)

1. Push `ai-oss-assistant-frontend` directory to your GitHub account.
2. Go to [Vercel](https://vercel.com) -> **Add New Project** -> Select `ai-oss-assistant-frontend`.
3. Configure Environment Variables in Vercel settings:
   - `NEXT_PUBLIC_API_BASE_URL` = `https://your-domain.com/api` (Hostinger backend URL)
   - `NEXT_PUBLIC_API_TOKEN` = `<matching_API_BEARER_TOKEN>`
4. Click **Deploy**. Vercel will host your dashboard on `https://your-project.vercel.app`.

---

### Module 3: GitHub Actions Reusable Workflows (`ai-oss-assistant-actions`)

1. Push `ai-oss-assistant-actions` directory to your GitHub account (e.g. `github.com/your-username/ai-oss-assistant-actions`).
2. Navigate to **Settings** -> **Secrets and variables** -> **Actions** in your GitHub repo and add:
   - `NVIDIA_API_KEY_PRO`: `<your_nvidia_api_key_pro>`
   - `BACKEND_WEBHOOK_URL`: `https://your-domain.com/api/webhooks/github.php`
   - `BACKEND_WEBHOOK_SECRET`: `<matching_GITHUB_WEBHOOK_SECRET>`
3. Workflows (`analyze.yml`, `fix.yml`, `runtime-scan.yml`) trigger automatically via `workflow_dispatch` calls from your Hostinger backend.

---

### Module 4: Email Delivery (Resend)

1. Register on [Resend.com](https://resend.com) and create an API Key.
2. Add key to Hostinger `.env` (`RESEND_API_KEY=re_123456789`).
3. Daily digest emails will deliver candidate summaries to user inbox.

---

## 🧪 Testing & Verification

Run the PHPUnit test suite to verify 100% green status across all unit, integration, and endpoint acceptance tests:

```bash
cd ai-oss-assistant-backend
C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit
```

### Expected Output:
```
PHPUnit 10.5.64 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\AI\ai-oss-assistant-backend\phpunit.xml

........................................                          40 / 40 (100%)

Time: 00:00.761, Memory: 8.00 MB

OK (40 tests, 71 assertions)
```

---

## 🔒 Security Hardening

- **Zero Hardcoded Fallback Secrets**: All API keys and secrets load strictly from environment variables (`.env`).
- **Strict TLS Verification**: All cURL HTTP clients enforce `CURLOPT_SSL_VERIFYPEER => true`.
- **Webserver Hardening Headers**: `.htaccess` injects `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `X-XSS-Protection`, and dotfile blocking.
- **HMAC Signature Verification**: All webhook routes verify `X-Hub-Signature-256` signatures against `GITHUB_WEBHOOK_SECRET`.
- **Pre-Call Spend Cap Enforcement**: Checks `users.spend_cap_usd` before every LLM API call.
