<?php
// config/Database.php
class Database
{
    private $host = "localhost";
    private $db_name = "sistema_crescer3"; // Nome do banco de dados
    private $username = "martinelli"; // Seu usuário do banco de dados
    private $password = "@Leodan1"; // Sua senha do banco de dados
    public $conn;

    public function getConnection()
    {
        $this->conn = null;
        try {
            // 1. Conecta
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);

            // 2. IMPORTANTE: Habilita os erros do PDO como Exceções
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 3. Configura o fuso horário (Correto)
            $this->conn->exec("SET time_zone = '-03:00'");

            // 4. Configura o charset (Correto)
            $this->conn->exec("set names utf8");

        } catch (PDOException $exception) {
            echo "Erro de conexão: " . $exception->getMessage();
        }
        return $this->conn;
    }
}
?>