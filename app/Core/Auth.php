<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Auth
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function register(string $name, string $email, string $password): void
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a name and a valid email address.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must contain at least 8 characters.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
        );
        try {
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('An account already exists for that email.');
            }
            throw $exception;
        }

        $this->login($email, $password);
    }

    public function login(string $email, string $password): void
    {
        $statement = $this->db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new RuntimeException('Email or password is incorrect.');
        }

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ];
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
