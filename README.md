# 🛍️ Shop Rdam

A multi-vendor e-commerce platform built with **Laravel 12** — vendor onboarding
and approval, product catalog, Stripe checkout, commission handling, queued jobs
and real-time events, with an automated test suite.

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![Inertia](https://img.shields.io/badge/Inertia.js-v2-9553E9)
![Pest](https://img.shields.io/badge/Tested%20with-Pest%204-46B555)
![License](https://img.shields.io/badge/License-MIT-blue)

---

## 🚀 About

Shop Rdam is a full multi-vendor marketplace: vendors register and get approved,
list their own products, manage their orders and track their earnings, while the
platform takes a commission on every sale.

I built it to work end to end with a modern Laravel stack — Inertia for the
SPA layer, Redis-backed queues via Horizon, WebSockets via Reverb, Stripe for
payments, and a Pest test suite running in CI.

---

## 🧩 Tech Stack

### ⚙️ Backend
| | |
|---|---|
| **Framework** | Laravel 12 (PHP 8.2+) |
| **SPA layer** | Inertia.js v2 + Ziggy + Laravel Wayfinder |
| **Auth** | Laravel Breeze · Laravel Sanctum (API tokens) |
| **Queues** | Laravel Horizon (Redis) |
| **Real-time** | Laravel Reverb (WebSockets) |
| **Database** | MySQL |
| **Architecture** | `lorisleiva/laravel-actions` · `spatie/laravel-query-builder` |

### 🎨 Frontend
| | |
|---|---|
| **Framework** | Vue 3 + TypeScript <!-- ⚠️ CONFIRMA: composer.json diz laravel/blank-vue-starter-kit --> |
| **Styling** | Tailwind CSS |
| **Build** | Vite |
| **Admin UI** | Tabler dashboard template |

### 🔌 Integrations
| | |
|---|---|
| **Payments** | Stripe (`stripe-php` v21) |
| **Images** | Intervention Image |
| **Reports** | Laravel Excel (`maatwebsite/excel`) |
| **i18n** | `spatie/laravel-translation-loader` · Google Translate PHP |
| **Geo data** | `nnjeim/world` |

### 🧪 Quality & Tooling
| | |
|---|---|
| **Testing** | Pest 4 + `pest-plugin-laravel` · Mockery · Faker |
| **Code style** | Laravel Pint (PSR-12) · ESLint · Prettier |
| **CI** | GitHub Actions <!-- ⚠️ CONFIRMA o que corre em .github/workflows --> |
| **Local env** | Laravel Sail (Docker) · Laravel Herd |
| **Debugging** | Laravel Pail · Log Viewer |

---

## 🎯 Features

### 🛒 Storefront
- Product catalog with categories and attributes
- Product detail pages
- Cart and Stripe checkout flow
- Customer authentication and account area
- Order history

### 🏪 Vendor
- Registration and admin approval flow
- Vendor dashboard
- Product CRUD with secure image uploads
- Order management
- Earnings overview and reports

### 🛠️ Admin
- Manage vendors and customers
- Approve / ban vendors
- Global product management
- Category and attribute management
- System settings

### 💰 Business logic
- Per-vendor commission model
- Order payment workflow
- Vendor earnings reports (exportable)
- Stock control

### 🔐 Security
- Role-based permissions (Admin · Vendor · Customer)
- Sanctum tokens for API access
- CSRF protection and request validation
- Secure file uploads

---

## 📁 Project Structure

```
shoprdam/
├── .github/workflows/   # CI pipeline
├── app/                 # Actions, models, HTTP layer
├── bootstrap/
├── config/
├── database/            # Migrations, factories, seeders
├── docs/                # Feature documentation
├── public/
├── resources/
│   ├── js/              # Vue 3 + TypeScript
│   ├── css/             # Tailwind
│   └── views/
├── routes/
├── storage/
└── tests/               # Pest suite
```

---

## 🛠️ Getting Started

### 1️⃣ Clone

```bash
git clone https://github.com/ruiprogramador/shoprdam.git
cd shoprdam
```

### 2️⃣ Set up

```bash
composer setup
```

Runs `composer install`, creates `.env`, generates the app key, migrates the
database and builds the frontend assets in one step.

Update `.env` with your database, mail, Stripe and Redis credentials.

### 3️⃣ Seed sample data

```bash
php artisan migrate --seed
```

### 4️⃣ Run

```bash
composer dev
```

Starts the dev server, the queue worker and Vite together.

For SSR: `composer dev:ssr`

---

## 🧪 Tests

```bash
composer test
```

Runs the Pest suite. Check code style with:

```bash
./vendor/bin/pint --test
```

---

## 📈 Status

🟢 **Active development.**
---

## 📄 License

MIT
