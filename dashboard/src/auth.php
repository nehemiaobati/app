<?php
// src/auth.php

class Auth
{
    public static function requireLogin(): void
    {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
            session_unset();
            session_destroy();
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized: Please log in.']);
            exit();
        }
        $_SESSION['last_activity'] = time(); // Update last activity time
    }

    public static function loginUser(int $userId, string $username): void
    {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['last_activity'] = time();
    }

    public static function logoutUser(): void
    {
        session_unset();
        session_destroy();
        // Clear session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }

    public static function getLoggedInUser(): ?array
    {
        if (isset($_SESSION['user_id'])) {
            return ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']];
        }
        return null;
    }
}