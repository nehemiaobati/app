<?php
// src/controllers/AuthController.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

class AuthController
{
    public static function login(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password are required.']);
            return;
        }

        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                Auth::loginUser($user['id'], $user['username']);
                http_response_code(200);
                echo json_encode(['message' => 'Login successful!', 'username' => $user['username']]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid username or password.']);
            }
        } catch (\PDOException $e) {
            error_log("Login DB error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error during login.']);
        }
    }

    public static function logout(): void
    {
        Auth::logoutUser();
        http_response_code(200);
        echo json_encode(['message' => 'Logged out successfully.']);
    }

    public static function signup(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username, email, and password are required.']);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email format.']);
            return;
        }

        try {
            $pdo = get_pdo();

            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
            $stmt->execute([':username' => $username, ':email' => $email]);
            if ($stmt->fetch()) {
                http_response_code(409); // Conflict
                echo json_encode(['error' => 'Username or email already exists.']);
                return;
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email) VALUES (:username, :password_hash, :email)");
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => $passwordHash,
                ':email' => $email
            ]);

            http_response_code(201); // Created
            echo json_encode(['message' => 'User registered successfully!']);
        } catch (\PDOException $e) {
            error_log("Signup DB error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Database error during signup.']);
        }
    }
}
