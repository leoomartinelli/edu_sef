<?php
// config/Database.php
class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;

    // Variável estática para guardar a conexão única
    public static $connection = null;

    public function getConnection()
    {
        // Se já existe uma conexão aberta, retorna ela mesma (Reutilização)
        if (self::$connection !== null) {
            return self::$connection;
        }

        // Carrega variáveis
        $this->host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? false);
        $this->db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? false);
        $this->username = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? false);
        $this->password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? false);

        if ($this->host === false || $this->db_name === false || $this->username === false) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Erro crítico: Variáveis de ambiente não definidas."
            ]);
            exit();
        }

        try {
            $conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->exec("SET time_zone = '-03:00'");
            $conn->exec("set names utf8");

            // Salva na variável estática para ser reutilizada
            self::$connection = $conn;

            return self::$connection;

        } catch (PDOException $exception) {
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Erro de Conexão com Banco de Dados: " . $exception->getMessage()
            ]);
            exit();
        }
    }
}
?>