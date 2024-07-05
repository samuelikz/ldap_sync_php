<?php

require '../vendor/autoload.php';

use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Configurar Logger
$log = new Logger('sync');
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/sync.log', Logger::WARNING));

// Configurações
$ldap_host = $_ENV['LDAP_HOST'];
$ldap_dn = $_ENV['LDAP_DN'];
$ldap_user = $_ENV['LDAP_USER'];
$ldap_password = $_ENV['LDAP_PASSWORD'];
$db_host = $_ENV['DB_HOST'];
$db_user = $_ENV['DB_USER'];
$db_password = $_ENV['DB_PASSWORD'];
$db_name = $_ENV['DB_NAME'];

try {
    // Conectar ao LDAP
    $ldap_conn = ldap_connect($ldap_host);
    if (!$ldap_conn) {
        throw new Exception("Não foi possível conectar ao servidor LDAP.");
    }

    ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    if (!ldap_bind($ldap_conn, $ldap_user, $ldap_password)) {
        throw new Exception("Não foi possível autenticar no servidor LDAP.");
    }

    // Pesquisar displayName, userPrincipalName, mail, department
    $search = ldap_search($ldap_conn, $ldap_dn, "(objectclass=person)", ["displayName", "userPrincipalName", "mail", "department"]);
    if (!$search) {
        throw new Exception("Pesquisa LDAP falhou.");
    }

    $entries = ldap_get_entries($ldap_conn, $search);
    if ($entries === false) {
        throw new Exception("Erro ao obter entradas do LDAP.");
    }

    // Conectar ao banco de dados usando PDO
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $db_user, $db_password, $options);

    // Sincronizar dados
    syncData($entries, $pdo);

    // Fechar conexões
    ldap_unbind($ldap_conn);
} catch (Exception $e) {
    $log->error($e->getMessage());
    die("Ocorreu um erro. Por favor, verifique os logs.");
}

/**
 * Sincroniza os dados do LDAP com o banco de dados.
 *
 * @param array $entries
 * @param PDO $pdo
 * @return void
 */
function syncData(array $entries, PDO $pdo): void
{
    $stmt_select = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE userPrincipalName = :userPrincipalName");
    $stmt_insert = $pdo->prepare("INSERT INTO usuarios (displayName, userPrincipalName, mail, department) VALUES (:displayName, :userPrincipalName, :mail, :department)");
    $stmt_update = $pdo->prepare("UPDATE usuarios SET displayName = :displayName, mail = :mail, department = :department WHERE userPrincipalName = :userPrincipalName");

    for ($i = 0; $i < $entries["count"]; $i++) {
        // Verificar se as chaves displayName, userPrincipalName, mail, department existem
        if (isset($entries[$i]["displayname"][0]) && isset($entries[$i]["userprincipalname"][0]) && isset($entries[$i]["mail"][0]) && isset($entries[$i]["department"][0])) {
            $displayName = $entries[$i]["displayname"][0];
            $userPrincipalNameFull = $entries[$i]["userprincipalname"][0];
            $mail = $entries[$i]["mail"][0];
            $department = $entries[$i]["department"][0];

            // Extrair o nome de usuário sem o domínio
            $userPrincipalName = getUsernameFromPrincipalName($userPrincipalNameFull);

            // Verificar se o usuário já existe no banco de dados
            $stmt_select->execute([':userPrincipalName' => $userPrincipalName]);
            $count = $stmt_select->fetchColumn();

            if ($count == 0) {
                // Inserir novo usuário
                $stmt_insert->execute([
                    ':displayName' => $displayName,
                    ':userPrincipalName' => $userPrincipalName,
                    ':mail' => $mail,
                    ':department' => $department
                ]);
            } else {
                // Atualizar usuário existente
                $stmt_update->execute([
                    ':displayName' => $displayName,
                    ':mail' => $mail,
                    ':department' => $department,
                    ':userPrincipalName' => $userPrincipalName
                ]);
            }
        } else {
            // Log de chave ausente
            error_log("Chave ausente em entradas LDAP na posição $i");
        }
    }
}

/**
 * Função para extrair o nome de usuário sem o domínio.
 *
 * @param string $userPrincipalNameFull
 * @return string
 */
function getUsernameFromPrincipalName(string $userPrincipalNameFull): string
{
    // Encontrar a posição do '@' para extrair o nome de usuário
    $pos = strpos($userPrincipalNameFull, '@');
    if ($pos !== false) {
        return substr($userPrincipalNameFull, 0, $pos);
    }
    return $userPrincipalNameFull; // Caso não encontre '@', retornar o nome completo
}