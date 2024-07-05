<?php

session_start();

require '../vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Configurar Logger
$log = new Logger('auth');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/auth.log', Logger::WARNING));

// Configurações do banco de dados
$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_password = $_ENV['DB_PASSWORD'];
$db_name = $_ENV['DB_NAME'];

try {
    // Conectar ao banco de dados usando PDO
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_password, $options);

    // Verificar se o formulário foi submetido
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST['username'];
        $password = $_POST['password'];

        // Consulta para buscar usuário no banco de dados
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE userPrincipalName = :username");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user) {
            // Comparar a senha com a senha armazenada no banco de dados
            // Aqui você pode implementar a lógica para comparar a senha usando funções de hash seguras
            // Exemplo básico (não seguro):
            if ($password === $user['userPassword']) {
                // Autenticação bem-sucedida
                $_SESSION['user'] = $user;
                header("Location: dashboard.php");
                exit();
            } else {
                // Senha incorreta
                throw new Exception("Senha incorreta.");
            }
        } else {
            // Usuário não encontrado
            throw new Exception("Usuário não encontrado.");
        }
    }
} catch (Exception $e) {
    $log->error($e->getMessage());
    die("Ocorreu um erro. Por favor, verifique os logs.");
}