# BloodLink — Blood Donation Coordination System

A secure, role-based web application for coordinating blood donations between donors and hospitals in Nepal. Built with PHP 8+, MySQL/MariaDB, and Bootstrap 5.

## What It Does

Hospitals post blood requests specifying blood type, urgency, and time slots. The system automatically finds and ranks compatible donors using a composite scoring algorithm that considers geographic distance, donation recency, profile freshness, and request urgency. Donors browse matching requests, book time slots, and track their donation history. Administrators oversee the platform — verifying hospitals, managing users, importing location data, and reviewing reports.

## Tech Stack

- **Backend:** PHP 8+ (vanilla, no framework)
- **Database:** MySQL 8 / MariaDB via PDO prepared statements
- **Frontend:** Bootstrap 5, Font Awesome 6, Chart.js 4
- **Font:** Plus Jakarta Sans (Google Fonts)
- **Server:** XAMPP (Apache + MySQL) on localhost
- **Security:** bcrypt (cost 12), CSRF tokens, session management, RBAC

## Setup

1. Start Apache and MySQL in XAMPP
2. Open phpMyAdmin and run `sql/schema.sql` — creates the `bloodbank` database and all 17 tables
3. Visit `http://localhost/bloodbank/seed_admin.php` to create the first admin account
4. **Delete `seed_admin.php` after use** (it contains plaintext credentials)
5. Log in at `http://localhost/bloodbank/modules/auth/login.php`

**Default admin credentials** (defined in seed_admin.php — change before running if needed):

| Field | Value |
|-------|-------|
| Email | admin@bloodlink.com |
| Password | Admin123 |
| Login PIN | 1234 |
| Security Q | What is the system name? |
| Security A | bloodlink |

## Importing Location Data Before registering users.... Imporatant

1. Log in as admin
2. Go to Dashboard → Import CSV
3. Upload `sample_locations.csv` (267 Nepal locations included)
4. Or create your own CSV with columns: `country, city, area, latitude, longitude`

The system auto-creates countries and cities if they don't exist, and skips duplicate areas.

## Project Structure

```
bloodbank/
├── api/                        # AJAX endpoints (cities, areas for location picker)
│   ├── areas.php               # Returns areas for a given city
│   └── cities.php              # Returns cities for a given country
├── config/
│   ├── config.php              # App settings, DB creds, blood compatibility map
│   └── database.php            # PDO singleton connection
├── includes/
│   ├── bootstrap.php           # Loads config, DB, functions, starts session
│   ├── footer.php              # Shared page footer with links
│   ├── functions.php           # Core helpers: auth, CSRF, matching engine, notifications
│   ├── header.php              # Navbar with role-based nav, notification bell, toast
│   └── location_picker.php     # Reusable country > city > area dropdown component
├── modules/
│   ├── admin/
│   │   ├── dashboard.php       # Stats, Chart.js visualisations, quick nav
│   │   ├── hospitals.php       # Hospital verification (approve/reject)
│   │   ├── import.php          # Bulk CSV location import
│   │   ├── locations.php       # Manage countries, cities, areas
│   │   ├── logs.php            # Paginated system audit log viewer
│   │   ├── messages.php        # Contact message management with replies
│   │   ├── profile.php         # Admin credentials, create new admins, security checklist
│   │   ├── reports.php         # User report review with messaging
│   │   └── users.php           # User management with search, filter, CSV export
│   ├── auth/
│   │   ├── forgot_password.php # 3-step password recovery via security question
│   │   ├── login.php           # Multi-step login (email/pw then PIN/security Q)
│   │   ├── logout.php          # POST-only logout with CSRF protection
│   │   └── register.php        # Donor/hospital registration with validation
│   ├── booking/
│   │   ├── book.php            # Donor books a time slot for a blood request
│   │   ├── hospital_bookings.php # Hospital manages bookings (confirm/reject/complete)
│   │   └── my_bookings.php     # Donor views and cancels their bookings
│   ├── donor/
│   │   ├── dashboard.php       # Donor stats, eligibility, availability toggle
│   │   ├── history.php         # Past completed donations
│   │   ├── profile.php         # Edit blood type, phone, weight, location
│   │   └── toggle_availability.php # AJAX availability toggle
│   ├── hospital/
│   │   ├── campaigns.php       # Blood drive event management
│   │   ├── dashboard.php       # Hospital stats, verification status
│   │   ├── profile.php         # Edit hospital details, upload verification doc
│   │   ├── request_matches.php # View scored/ranked donors for a request
│   │   └── requests.php        # Create, edit, close blood requests with time slots
│   ├── matching/
│   │   └── requests.php        # Donor browses open requests filtered by compatibility
│   └── notifications/
│       └── index.php           # Notification centre (view, mark read)
├── public/
│   ├── css/style.css           # Custom theme (#c0392b primary, Plus Jakarta Sans)
│   ├── js/app.js               # Client-side validation, location cascade, password meter
│   └── uploads/                # Hospital verification documents (PDF)
├── sql/
│   └── schema.sql              # Database schema (17 tables)
├── sample_locations.csv        # 267 Nepal locations ready to import
├── contact.php                 # Public contact form with reply threads
├── index.php                   # Landing page (hero, how it works, FAQ, compatibility)
├── privacy.php                 # Privacy policy (13 sections)
├── terms.php                   # Terms and conditions (10 sections)
├── seed_admin.php              # First-run admin setup (delete after use)
└── README.md
```

## Key Features

### Matching Algorithm
Composite scoring (0-100) using four weighted factors:
- Geographic distance via Haversine formula (40 pts)
- Donation recency (25 pts)
- Request urgency boost (20 pts)
- Profile freshness (15 pts)

Weights were adjusted from 50/20/15/15 to 40/25/20/15 during development after testing with geographically dispersed donor data in rural scenarios.

### Progressive Radius Expansion
If insufficient donors are found nearby, the search automatically widens: primary radius then expanded radius then same city then country-wide. Radius thresholds are urgency-adaptive (e.g., critical requests search up to 999 km).

### 90-Day Eligibility Enforcement
Donors are automatically marked ineligible after completing a donation. The system calculates the next eligible date and re-enables eligibility after 90 days.

### Five-State Booking Lifecycle
Bookings follow a defined workflow: pending, confirmed, completed, cancelled, rejected. On completion, an atomic database transaction records the donation, updates eligibility, increments fulfilment, and disables availability. Concurrent booking of the last slot is handled by database-level row locking within the transaction.

### Hospital Verification Pipeline
Hospital accounts require administrator approval before they can post blood requests. Admins review licence numbers and uploaded verification documents (PDF).

### Three-Role RBAC
Server-side enforcement via requireRole() on every page. Donor, Hospital, and Administrator roles with strict separation. Per-admin PIN authentication prevents credential sharing.

### Security Measures
- CSRF tokens on every form (single-use, timing-safe comparison)
- POST-only logout (prevents CSRF-based forced logout)
- Brute force protection (5 attempts, 15-minute lockout)
- Password hashing with bcrypt (cost 12)
- XSS prevention via htmlspecialchars() on all output
- PDO prepared statements for all queries
- Session timeout after 30 minutes of inactivity
- HttpOnly + SameSite Strict session cookies

## Database

17 tables across four functional areas:

- User management: users, admin_credentials, donor_profiles, hospital_profiles
- Coordination: blood_requests, time_slots, bookings, donation_history, campaigns
- Communication: notifications, contact_messages, reports
- Administration: system_logs, login_attempts, countries, cities, areas

Blood type compatibility map supports all 8 types (A+, A-, B+, B-, AB+, AB-, O+, O-) with correct donor-recipient rules defined in config.php.

## Constraints

- Localhost prototype (no live deployment)
- Simulated data (no real hospital or donor records)
- Area-level centroids via CSV import (not precise GPS coordinates)
- Security questions instead of TOTP (no SMTP on localhost)
- In-system notifications only (no email/SMS)

## Author

Niranjan Gc (P2837518)
BSc Computer Science, De Montfort University
Module: CTEC3451N Development Project
Supervisor: Idongesit Williams
