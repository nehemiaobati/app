# P2Profit Dashboard

This repository contains the P2Profit Dashboard application, a web-based interface for managing and monitoring a trading bot. The dashboard provides functionalities such as user authentication, data visualization, and interaction with the bot's operations.

## Features

*   **User Authentication:** Secure login system for accessing the dashboard.
*   **Data Visualization:** Display of trading data and bot performance.
*   **Bot Management:** Interface to control and monitor the associated trading bot.
*   **API Endpoints:** A RESTful API for programmatic interaction with the dashboard and bot data.

## Setup

To set up and run the P2Profit Dashboard, follow these steps:

### 1. Environment Variables

The application relies on environment variables for sensitive configurations. Create a `.env` file in the `dashboard/` directory, based on the `dashboard/.env.example` file.

```
# dashboard/.env
DB_HOST=your_database_host
DB_PORT=your_database_port
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASSWORD=your_database_password
BOT_SCRIPT_PATH=/path/to/your/bot/p2profitdba.php
```

### 2. Database Configuration

The dashboard requires a MySQL/MariaDB database. Use the provided SQL scripts to set up your database:

*   `dashboard/public/schema.sql`: Contains the database schema (table definitions).
*   `dashboard/public/setup_db.sql`: Contains initial data or setup commands.

You can import these files using a MySQL client:

```bash
mysql -h DB_HOST -P DB_PORT -u DB_USER -pDB_PASSWORD DB_NAME < dashboard/public/schema.sql
mysql -h DB_HOST -P DB_PORT -u DB_USER -pDB_PASSWORD DB_NAME < dashboard/public/setup_db.sql
```

### 3. PHP Dependencies

Navigate to the `dashboard/` directory and install the PHP dependencies using Composer:

```bash
cd dashboard/
composer install
```

Do the same for the `dashboard/bot/` directory:

```bash
cd bot/
composer install
```

### 4. Web Server Configuration

Configure your web server (e.g., Apache or Nginx) to point its document root to the `dashboard/public/` directory. Ensure that `index.php` is handled correctly for routing.

For Apache, you might need to ensure `mod_rewrite` is enabled and that your VirtualHost configuration includes:

```apache
<Directory /var/www/html/webapp/app/dashboard/public>
    AllowOverride All
    Require all granted
</Directory>
```

### 5. Bot Script

The `BOT_SCRIPT_PATH` in your `.env` file should point to the `p2profitdba.php` script located in `dashboard/bot/`. This script is the core of your trading bot.

## Usage

### Accessing the Dashboard

Once the web server is configured and running, you can access the dashboard by navigating to its URL in your web browser (e.g., `http://localhost/` or `http://your-domain.com/`).

### API Endpoints

The dashboard exposes API endpoints under the `/api/` path. For example, `http://localhost/api/data` might be an endpoint to retrieve trading data. Refer to `dashboard/src/api.php` and `dashboard/src/controllers/` for detailed API routes and functionalities.

### Bot Operation

The bot script (`dashboard/bot/p2profitdba.php`) is designed to be run as a background process, likely managed by `dashboard/src/process_manager.php`. Ensure the bot script has the necessary permissions to execute.

## File Structure

*   `README.md`: This file.
*   `dashboard/`: Contains the main application code.
    *   `.env`: Environment variables (local configuration).
    *   `.env.example`: Example environment variables.
    *   `composer.json`, `composer.lock`: PHP Composer dependency files.
    *   `bot/`: Contains the trading bot script and its dependencies.
        *   `p2profitdba.php`: The main bot script.
    *   `public/`: Web server document root.
        *   `index.html`: Main dashboard HTML.
        *   `index.php`: PHP entry point and API router.
        *   `api.php`: Public API endpoint.
        *   `schema.sql`, `setup_db.sql`: Database setup scripts.
        *   `assets/`: CSS, images, and other static assets.
        *   `js/`: JavaScript files for frontend functionality.
    *   `src/`: Backend PHP source code.
        *   `api.php`: API logic.
        *   `auth.php`: Authentication logic.
        *   `config.php`: Application configuration.
        *   `db.php`: Database connection and utility functions.
        *   `process_manager.php`: Script for managing background processes (e.g., the bot).
        *   `controllers/`: PHP classes handling request logic.
        *   `models/`: PHP classes representing data models.
        *   `views/`: PHP files for rendering views (if any).
    *   `var/`: Contains application logs (e.g., `bot.log`).

## Contributing

Contributions are welcome! Please follow these steps:

1.  Fork the repository.
2.  Create a new branch (`git checkout -b feature/your-feature-name`).
3.  Make your changes.
4.  Commit your changes (`git commit -m 'Add new feature'`).
5.  Push to the branch (`git push origin feature/your-feature-name`).
6.  Create a new Pull Request.

## License

This project is licensed under the MIT License. See the `LICENSE` file (if present) for details.
