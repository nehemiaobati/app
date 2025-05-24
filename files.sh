#!/bin/bash

#begin

# --- 1. Create the root directory and navigate into it ---
echo "Creating root directory: p2profit_dashboard/"
mkdir p2profit_dashboard
cd p2profit_dashboard

# --- 2. Create public/ directory and its contents ---
echo "Creating public/ and its subdirectories/files..."
mkdir -p public/assets/{css,img} public/js

echo 'new file' > public/index.html
echo 'new file' > public/login.html
echo 'new file' > public/js/app.js
echo 'new file' > public/js/charts.js
echo 'new file' > public/js/api-client.js
echo 'new file' > public/.htaccess

# --- 3. Create src/ directory and its contents ---
echo "Creating src/ and its subdirectories/files..."
mkdir -p src/{controllers,models,views}

echo 'new file' > src/config.php
echo 'new file' > src/db.php
echo 'new file' > src/auth.php
echo 'new file' > src/api.php
echo 'new file' > src/controllers/AuthController.php
echo 'new file' > src/controllers/BotController.php
echo 'new file' > src/controllers/DataController.php
echo 'new file' > src/models/User.php
echo 'new file' > src/models/BotConfig.php
echo 'new file' > src/models/BotStatus.php
echo 'new file' > src/process_manager.php

# --- 4. Create bot/ directory and its contents ---
echo "Creating bot/ and its subdirectories/files..."
mkdir -p bot/vendor # vendor will be populated by Composer later
echo 'new file' > bot/p2profitdba.php
echo 'new file' > bot/composer.json

# --- 5. Create var/ directory and its contents ---
echo "Creating var/ and its files..."
mkdir var
echo 'new file' > var/bot.log

# --- 6. Create root-level files ---
echo "Creating root-level files..."
echo 'new file' > .env.example
echo 'new file' > .env # This one will be filled with actual credentials later
echo 'new file' > composer.json
echo 'new file' > README.md

echo "Directory structure and files created successfully in $(pwd)"
echo "Remember to populate the files with the actual code and run 'composer install' in the main project directory and the 'bot/' directory."
#end 