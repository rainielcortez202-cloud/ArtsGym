# 🏛️ ARTS GYM: THE MASTER TECHNICAL REVIEWER (FINAL EXHAUSTIVE EDITION)

This document is the **definitive single source of truth** for the Arts Gym Management System. It is engineered for a 360-degree capstone defense, providing an exhaustive analysis of every script, database construct, security layer, and data flow.

---

## 1️⃣ SYSTEM ARCHITECTURE & TECH STACK

### 1.1 High-Level Architecture
The system uses a **Hybrid Client-Server Architecture**. 
- **Frontend**: Single-Page-Application (SPA) features within a Multi-Page-Application (MPA) structure. 
- **Backend**: PHP (7.4/8.x) serving as the API and logic layer. 
- **Database**: PostgreSQL (Supabase Cloud) with Row-Level Security (RLS).
- **Communication**: RESTful AJAX (jQuery) and direct server-side rendering (SSR).

### 1.2 Core Technology Layer
| Component | Technology | Purpose |
| :--- | :--- | :--- |
| **Logic Layer** | PHP (PDO) | Server-side execution and DB abstraction. |
| **UI Framework** | Bootstrap 5.3.2 | Responsive layout and UI components. |
| **Data Layer** | PostgreSQL | Relational storage and advanced query execution. |
| **Interaction** | jQuery & Vanilla JS | DOM manipulation, AJAX, and event handling. |
| **Communication**| Brevo API | Transactional and marketing email delivery. |
| **Real-time** | Supabase JS/WS | WebSocket-based real-time UI updates. |

---

## 2️⃣ DATABASE ARCHITECTURE: THE ENGINE

### 2.1 Table-by-Table Mapping & Relationships
| Table | PK | FK | Description / Constraints |
| :--- | :--- | :--- | :--- |
| **`users`** | `id` | - | Primary identity table. Includes `role` (Enum), `status`, `qr_code` (Unique), and `fts` (tsvector). |
| **`sales`** | `id` | `user_id` | Subscription/Payment records. Includes `expires_at` and `amount`. |
| **`attendance`** | `id` | `user_id` | Log of entries. `user_id` is NULL for walk-ins. Includes `visitor_name`. |
| **`muscle_groups`**| `id` | - | Top-level categories (e.g., Chest, Back). |
| **`muscles`** | `id` | `group_id`| Specific muscles (e.g., Pectoralis Major). |
| **`exercises`** | `id` | `muscle_id`| Movement library (name, video_url, instructions). |
| **`workout_plans`**| `id` | `user_id` | Member calendar entries. |
| **`activity_log`** | `id` | `user_id` | Audit trail with `action`, `details`, and `ip_address`. |

### 2.2 Advanced Database Constructs
1.  **Row Level Security (RLS)**: Policies are enforced at the DB level (e.g., `auth.uid() = id`). Even if PHP is bypassed, the database blocks unauthorized row access.
2.  **Full-Text Search (FTS)**: A generated `tsvector` column (`fts`) and **GIN Index** allow instant keyword matching across name and email.
3.  **Composite Indexes**: `idx_sales_user_expiry (user_id, expires_at DESC)` optimized for `LATERAL JOIN` performance.

### 2.3 Sample Data (Mock Rows)
**Table: `users`**
| id | full_name | email | role | status |
| :--- | :--- | :--- | :--- | :--- |
| `uuid-1` | Juan Dela Cruz | juan@email.com | member | active |

**Table: `sales`**
| id | user_id | amount | sale_date | expires_at |
| :--- | :--- | :--- | :--- | :--- |
| `101` | `uuid-1` | 500.00 | 2026-02-01 | 2026-03-01 |

---

## 3️⃣ SECURITY ANALYSIS: THE MULTI-LAYER DEFENSE

| Security Measure | Implementation | Why it's Critical |
| :--- | :--- | :--- |
| **SQLi Prevention** | PDO Prepared Statements | Prevents malicious SQL code from being treated as commands. |
| **XSS Prevention** | `htmlspecialchars()` / `e()` | Neutralizes malicious scripts by converting them to plain text (entities). |
| **CSRF Protection** | Synchronizer Token Pattern | Ensures requests originate from the actual user site, not a 3rd party tab. |
| **Password Hashing**| Bcrypt (`password_hash`) | One-way irreversible fingerprinting with a cost-factor of 10. |
| **Session Security** | HttpOnly & SameSite Flags | Prevents JS from stealing cookies and stops cross-site session riding. |
| **CSP** | Content Security Policy | Restricts script sources to trusted domains (Google, Cloudflare). |
| **Membership Gate**| `auth.php` | Real-time membership check on *every* page load. |

---

## 4️⃣ BACKEND & FEATURE DATA FLOWS

### 4.1 Feature 1: QR Scan & Attendance
1.  **Scanner** ➔ Hits `attendance_scan.php` with QR Hex.
2.  **Backend** ➔ Fetches user info and latest `expires_at` using a **LATERAL JOIN**.
3.  **Validation** ➔ If `expires_at >= current_date` AND `status == 'active'`, proceed.
4.  **Database** ➔ Inserts into `attendance`. 
5.  **Broadcast** ➔ Supabase Realtime notifies the Admin Dashboard list.

### 4.2 Feature 2: Registration & Verification
1.  **Signup** ➔ `register.php` validates input and hashes password.
2.  **Verification** ➔ Generates 64-char `hex` token and sends via **Brevo API**.
3.  **Activation** ➔ `verify_email.php` updates user status to `active` based on the token match.

---

## 📂 5 CUSTOM LOGIC & HIDDEN SCRIPTS

### 5.1 The "Lazy-Cron" Maintenance (`connection.php`)
The system performs maintenance automatically during the first database connection of each day:
*   **Trigger**: Queries `settings` table for `last_auto_cleanup`.
*   **Action**: Runs `includes/auto_cleanup.php` to purge logs > 90 days and unverified users > 7 days.

### 5.2 Status Synchronizer (`status_sync.php`)
*   **Logic**: Instead of a daily cron job, it uses "Page-Load Sync." It updates the `users.status` column to 'inactive' if their membership has expired *only when that user's record is displayed*. This maximizes performance.

---

## 🛠️ 6 PERFORMANCE & OPTIMIZATIONS

1.  **SQL: LATERAL JOIN**: Efficiently fetches the single latest payment for each user in paginated results.
2.  **Debouncing**: A 300ms delay in `members.php` live search prevents sending 10 requests for a single word.
3.  **GIN Indexing**: Specialized for Full-Text Search, making name/email lookups O(log n) regardless of data size.
4.  **Selective Syncing**: Membership status is reconciled only for the 10 members currently on screen.

---

## 🎯 7 PANEL PREPARATION (Q&A)

**Q: How do you handle a scenario where the internet is down but a local QR scan occurs?**
*A: The system requires an active connection to the Supabase Cloud. For true offline capability, a local SQLite cache would be the next developmental step, but currently, it uses SSL-secured cloud queries for data integrity.*

**Q: Why not use a standard JSON column for workout plans?**
*A: Relational tables (`workout_plans` and `workout_plan_exercises`) were chosen to maintain **Data Integrity** and allow for complex reporting (e.g., "Which exercises are most popular?"), which is harder with flat JSON.*

**Q: Explain how the system prevents a member from accessing admin pages.**
*A: Every page includes `auth.php`. It checks the `$_SESSION['role']`. If the role doesn't match the required level (e.g., 'admin'), the server sends a `header("Location: ../login.php")` immediately, terminating script execution.*

---
**Summary**: The Arts Gym Management System is built for **Scalability**, **Security**, and **User Experience**, utilizing advanced PostgreSQL features and defensive PHP coding to ensure a professional-grade product.
