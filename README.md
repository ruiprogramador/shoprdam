Shop Rdam

A modern multi-vendor e-commerce platform built with Laravel 12, inspired by the Udemy course “Laravel 12: Build a Multi-Vendor Ecommerce Website (2025)”.
The UI is powered by the Tabler admin dashboard template for a clean, responsive, and highly customizable experience.

🚀 About the Project

Shop Rdam is a learning-focused project designed to explore and implement a complete multi-vendor e-commerce system using Laravel 12.
The platform includes vendor management, product listings, order processing, role-based authentication, and a fully-featured back office.

This project follows the structure and development workflow taught in the Udemy course while adding personal enhancements and improvements.

🎯 Key Features
🛒 Frontend

-> Product catalog & categories

-> Product details page

-> Cart & checkout flow

-> Customer authentication

-> Order history

🏪 Vendor Features

-> Vendor registration & approval

-> Vendor dashboard

-> Product CRUD

-> Order management

-> Earnings overview

⚙️ Admin Panel (powered by Tabler UI)

-> Manage vendors & customers

-> Approve/ban vendors

-> Global product management

-> Category & attribute management

System settings

🔐 Authentication & Security

-> Laravel Breeze

-> Role-based permissions (Admin, Vendor, Customer)

-> CSRF & input validation

-> Secure file uploads

💰 Business Logic

-> Multi-vendor commissions

-> Order payment workflow

-> Vendor earnings reports

-> Product stock system

🧩 Tech Stack
Framework	      :   Laravel 12 (PHP 8.3+)
Frontend        :   UI	Tabler Admin Template
Database	      :   MySQL
Authentication  :  Laravel Breeze
Package         :  Composer, NPM
Deployment	    :  Laravel Herd
📁 Project Structure
shoprdam/

│── app/

│── bootstrap/

│── config/

│── database/

│── public/

│── resources/

│   ├── views/

│   ├── js/

│   └── css/

│── routes/

│── storage/

└── tests/

🛠️ Installation & Setup
1️⃣ Clone the repository
git clone https://github.com/your-username/shoprdam.git
cd shoprdam

2️⃣ Install dependencies
composer install
npm install

3️⃣ Environment configuration
cp .env.example .env
php artisan key:generate


Update your .env with database credentials and mail settings.

4️⃣ Run migrations & seeders
php artisan migrate --seed

5️⃣ Compile frontend assets
npm run dev

6️⃣ Start the development server
php artisan serve

🎨 UI Template

This project uses the Tabler Dashboard Template, which offers a clean, modern admin interface:
(Template used: https://preview.tabler.io/)

📚 Course Reference

This project follows and expands upon the Udemy course:
“Laravel 12: Build a Multi-Vendor Ecommerce Website (2025)”

🤝 Contributing

Contributions, suggestions, and improvements are welcome.
Feel free to submit a pull request or open an issue.

📄 License

This project is for educational purposes.
