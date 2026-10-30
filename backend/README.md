# Backend – Certificate Generation Queue API

Laravel 13 REST API for Project 21. See the main README in the repository root for setup,
demo accounts, failure injection and tests.

Quick start:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --port=8000      # terminal 1
php artisan queue:work             # terminal 2 (required)
```
