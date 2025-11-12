<?php
// config/loadEnv.php

$envPath = __DIR__ . '/../.env';

if (!file_exists($envPath)) {
    // Se o .env não existe, não há o que carregar
    return;
}

if (!is_readable($envPath)) {
    // Se não consegue ler o .env, é um erro de permissão
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erro: Não foi possível ler o arquivo .env. Verifique as permissões."
    ]);
    exit;
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    // Ignora linhas de comentário
    if (strpos(trim($line), '#') === 0) {
        continue;
    }

    // AQUI ESTÁ A MÁGICA: Separa a linha no primeiro '='
    list($name, $value) = explode('=', $line, 2);
    $name = trim($name);

    // Remove aspas (se existirem) do valor
    $value = trim($value);
    if (strlen($value) > 1 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
        $value = substr($value, 1, -1);
    }
    if (strlen($value) > 1 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
        $value = substr($value, 1, -1);
    }

    // Coloca a variável em todos os lugares para garantir
    putenv("$name=$value");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}