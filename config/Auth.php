<?php
// config/Auth.php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// config/Auth.php

// Chave secreta para assinar e verificar JWTs.
// MUITO IMPORTANTE: Mude esta chave para uma string longa e aleatória em produção!
// Você pode gerar uma string aleatória com PHP: bin2hex(random_bytes(32))
define('JWT_SECRET_KEY', '6b3866fd4232f4430a01b4ae0687eec6366fd8f670289db883339cfda5515275');

// Algoritmo de criptografia para o JWT (HS256 é comum)
define('JWT_ALGORITHM', 'HS256');

// Tempo de expiração do token em segundos (ex: 1 hora = 3600 segundos)
define('JWT_EXPIRATION_TIME', 3600);
/**
 * Retrieves the JWT token from the Authorization header.
 * @return string|null The JWT token or null if not found.
 */
function getAuthToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    return null;
}

/**
 * A helper function to get user data from the JWT token.
 * This is useful for controllers that need user info.
 * @return array|null User data from the token, or null if invalid.
 */
function getAuthenticatedUserData() {
    try {
        $jwt = getAuthToken();
        if ($jwt) {
            $decoded = JWT::decode($jwt, new Key(JWT_SECRET_KEY, JWT_ALGORITHM));
            return (array) $decoded->data;
        }
    } catch (Exception $e) {
        // Log the error but return null to handle gracefully
        error_log("JWT Decode Error: " . $e->getMessage());
    }
    return null;
}


