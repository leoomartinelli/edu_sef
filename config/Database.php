<?php
// config/Database.php
class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function getConnection()
    {
        $this->host = getenv('DB_HOST');
        $this->db_name = getenv('DB_NAME');
        $this->username = getenv('DB_USER');
        $this->password = getenv('DB_PASS');

        $this->conn = null;

        if ($this->host === false || $this->db_name === false || $this->username === false) {
            http_response_code(500);
            // Use json_encode para formatar a saída como API
            echo json_encode([
                "success" => false,
                "message" => "Erro crítico: Variáveis de ambiente (DB_HOST, DB_NAME, DB_USER) não estão definidas. Verifique seu .env e o loadEnv.php."
            ]);
            exit(); // Pare a execução
        }

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("SET time_zone = '-03:00'");
            $this->conn->exec("set names utf8");

        } catch (PDOException $exception) {
            // --- ESTA É A MUDANÇA PRINCIPAL ---
            // Em vez de só 'echo', pare tudo e retorne um JSON de erro.
            http_response_code(500); // Erro Interno do Servidor
            echo json_encode([
                "success" => false,
                "message" => "Erro de Conexão com Banco de Dados: " . $exception->getMessage()
            ]);
            exit(); // Pare a execução imediatamente
            // --- FIM DA MUDANÇA ---
        }
        return $this->conn;
    }
}
?>