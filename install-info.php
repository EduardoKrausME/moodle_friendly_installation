<?php

test__max_input_vars();

$senha = "";
do {
    // Check the connection with MySQL
    try {
        $conn = new mysqli("localhost", "root", $senha);
    } catch (Exception $e) {
        Echo "Erro a conectar ao MySql. Erro foi: {$e->getMessage()}";
        echo "\n\n\nPrecione qualquer tecla para prosseguir...\n";
        fgets(STDIN);
    }
    if (!$conn || $conn->connect_error) {
        $title = "Senha do MySql";
        $subtitle = "Infelizmente não consegui conectar no MySql com a senha de ROOT. Digite a senha para fazer login.";
        $senha = dialogGetData($title, $subtitle);
    } else {
        break;
    }
} while (true);

shell_exec("clear");
echoColor("Welcome to the Moodle installer and configurator. Press Enter to start:", "blue");
fgets(STDIN);


// Moodle Lang
$command = "dialog --menu 'Which language do you want to install?' 20 50 10  \
                           es    'Español - España (es)'               \
                           en    'English - United States (en)'        \
                           de    'Deutsch - Deutschland (de)'          \
                           es_mx 'Español - México (es_mx)'            \
                           pt_br 'Português - Brasil (pt_br)'          \
                           id    'Bahasa Indonesia - Indonesia (id)'   \
                           ru    'Русский - Российская Федерация (ru)' \
                           fr    'Français - France (fr)'              \
                           es_co 'Español - Colombia (es_co)'          \
                           it    'Italiano - Italia (it)' 3>&1 1>&2 2>&3";
$selectedLang = shell_exec($command);
$strings = [];
changueLang($selectedLang);


// Domain
do {
    $selectedDomain = dialogGetData(
        $strings["selectedDomain_title"],
        $strings["selectedDomain_subtitle"]);

    $regex = '/^(https?:\/\/[a-zA-Z0-9-]+\.[a-zA-Z0-9-]+(?:\.[a-zA-Z]{2,})?)(\/[^\s]*)?$/';

    $status = true;
    $urlparse = parse_url($selectedDomain);

    if (!isset($urlparse["scheme"]) || !in_array($urlparse["scheme"], ["http", "https"])) {
        echoColor($strings["urlerror_scheme"], "red");
        $status = false;
    }

    if (!isset($urlparse["host"])) {
        echoColor($strings["urlerror_host"], "red");
        $status = false;
    }

    if (isset($urlparse["path"])) {
        $path = $urlparse["path"];
        if (substr($path, -1) === "/") {
            echoColor($strings["urlerror_path"], "red");
            $status = false;
        }
    }

    if ($status) {
        break;
    } else {
        echoColor($strings["urlerror_again"], "red");
        fgets(STDIN);
    }
} while (true);

$host = parse_url($selectedDomain, PHP_URL_HOST);
$path = parse_url($selectedDomain, PHP_URL_PATH);

// E-mail
$selectedEmail = dialogGetData($strings["selectedEmail_title"]);


// Apache config
$local = apacheConfiguration();


// Moodle Version
$command = "dialog --menu '{$strings["selectedVersion_title"]}' 15 50 5 \
                           MOODLE_500_STABLE 'Moodle 5.0' \
                           MOODLE_405_STABLE 'Moodle 4.5' \
                           main              'Moodle 5.1dev' 3>&1 1>&2 2>&3";
$selectedVersion = shell_exec($command);

echoColor($strings["moodle_install"], "green");

shell_exec("git clone --depth 1 https://github.com/moodle/moodle/ -b {$selectedVersion} {$local}");
shell_exec("mkdir -p /var/www/moodledata/{$host}{$path}");
shell_exec("chown -R www-data:www-data /var/www/moodledata/{$host}{$path}");
shell_exec("chmod -R 755               /var/www/moodledata/{$host}{$path}");

shell_exec("chown -R www-data:www-data {$local}");
shell_exec("chmod -R 755               {$local}");

echoColor(str_replace("{local}", $local, $strings["install_end"]), "blue");

// Sanitize the host to create a database name
$dbName = preg_replace('/[^a-z0-9]/i', "", $host);
if (isset($path[1])) {
    $dbName = "{$dbName}_" . preg_replace('/[^a-z0-9]/i', "", $path); // Add sanitized path to the database name if it exists
}

// Create a MySQL user and password
$mysql_info = createMySqlPassword("moodle_{$dbName}");
if ($mysql_info === false) {
    echoColor($strings["database_error"], "red");
    exit; // Abort execution if user creation fails
}


file_put_contents("{$local}/config.php", getConfigPhp());


echoColor($strings["install_moodle"], "green");
system("php {$local}/admin/cli/install_database.php --lang={$selectedLang} --adminuser={$selectedEmail} --adminpass=Password@123# --adminemail={$selectedEmail} --fullname=Moodle --shortname=Moodle --agree-license");


system("php {$local}/admin/cli/cfg.php --name=texteditors        --set=tiny,textarea");
system("php {$local}/admin/cli/cfg.php --name=enablegravatar     --set=1");
system("php {$local}/admin/cli/cfg.php --name=gravatardefaulturl --set=robohash");

// Force changue Password
try {
    $sql_forcepasswordchange = "INSERT INTO {$mysql_info["dbname"]}.mdl_user_preferences (userid, name, value) VALUES (2, 'auth_forcepasswordchange', 1)";
    $conn->query($sql_forcepasswordchange);
} catch (Exception $e) {
    echoColor($e->getMessage(), "red");
}


changueTheme();
installKrausPlugins();


// End install
echoColor("{$strings["install_moodle_end"]}
    Host:     {$selectedDomain}
    Login:    {$selectedEmail}
    Password: Password@123#", "blue");

// Close connection
$conn->close();

/**
 * Function test__max_input_vars
 *
 */
function test__max_input_vars()
{
    global $strings;
    // Locate the php.ini file
    $phpIniFile = php_ini_loaded_file();

    // Check the current value of max_input_vars
    $currentValue = ini_get("max_input_vars");

    if ($currentValue !== false && $currentValue < 5000) {
        echoColor(str_replace("{currentValue}", $currentValue, $strings["test__max_input_vars_error"]), "red");

        if ($phpIniFile === false) {
            echoColor($strings["test__max_input_vars_phpini"], "red");
            exit;
        }

        // Append the changes to the file
        file_put_contents($phpIniFile, "\n\nmax_input_vars = 5000\n", FILE_APPEND);
    }

    // Checks if it is in CLI and attempts to update the Apache2 configurations.
    if (strpos($phpIniFile, "/cli/") > 1) {
        $phpIniFile = str_replace("cli", "apache2", $phpIniFile);

        // Append the changes to the file
        if (file_exists($phpIniFile)) {
            file_put_contents($phpIniFile, "\n\nmax_input_vars = 5000\n", FILE_APPEND);
        }
    }
}

/**
 * Function apacheConfiguration
 *
 * @return bool|string
 */
function apacheConfiguration()
{
    global $host, $path, $selectedDomain, $selectedEmail, $strings;

    $vhostFile = "/etc/apache2/sites-available/{$host}.conf";
    $local = false;
    if (file_exists($vhostFile)) {
        echoColor($strings["apacheConfiguration_ok"], "blue");
        $vhostContent = file_get_contents($vhostFile);

        preg_match('/DocumentRoot(.*)\n/', $vhostContent, $conf);
        $local = trim($conf[1]);

        echoColor(str_replace("{local}", $local, $strings["apacheConfiguration_root"]), "green");
    }

    if (!$local) {
        echoColor(
            str_replace("{host}", $host, $strings["apacheConfiguration_instruction"]),
            "green");
        echoColor($strings["apacheConfiguration_continue"], "black");
        fgets(STDIN);

        $command = "dialog --menu '{$strings["apacheConfiguration_menu_title"]}' 15 70 2 \
                           root   '/var/www/html{$path}' \
                           domain '/var/www/html/{$host}{$path}' \
                           3>&1 1>&2 2>&3";
        if (shell_exec($command) == "root") {
            $local = "/var/www/html{$path}";
            $documentRoot = "/var/www/html";
        } else {
            $local = "/var/www/html/{$host}{$path}";
            $documentRoot = "/var/www/html/{$host}";
        }

        $vhostContent = "
<VirtualHost *:80>
    ServerAdmin  {$selectedEmail}
    ServerName   {$host}
    DocumentRoot {$documentRoot}

    ErrorLog  /var/www/{$host}-error.log
    CustomLog /var/www/{$host}-access.log combined

    <Directory /var/www/html/{$host}>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>";

        file_put_contents($vhostFile, $vhostContent);
        shell_exec("ln -s {$vhostFile} /etc/apache2/sites-enabled/");

        // Reinicia o apache
        shell_exec("service apache2 restart");
    }

    if (file_exists("{$local}")) {
        echoColor(str_replace("{local}", $local, $strings["apacheConfiguration_aborted"]), "red");
        exit;
    }


    // SSL
    if (strpos($selectedDomain, "https") === 0) {
        $enableSsl = true;
    } else {
        $command = "dialog --menu '{$strings["apacheConfiguration_enable_ssl"]}' 15 50 2 \
                              YES {$strings["YES"]} \
                              NO  {$strings["NO"]}  \
                              3>&1 1>&2 2>&3";
        if (shell_exec($command) == "YES") {
            $enableSsl = true;
            $selectedDomain = "https" . substr($selectedDomain, 4);
        } else {
            $enableSsl = false;
        }
    }
    if ($enableSsl) {
        echo "\n\n\n\n\n\n\n";
        shell_exec("certbot --apache -m {$selectedEmail} -d {$host} --agree-tos --no-eff-email");
        shell_exec("(crontab -l; echo \"0 0 1 */2 * /usr/bin/certbot renew\") | crontab -");
    }

    return $local;
}

/**
 * Function createMySqlPassword
 *
 * @param $dbName
 *
 * @return array|bool
 */
function createMySqlPassword($dbName)
{
    global $conn, $strings;

    // Generate a random password for the user
    $caracteres = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $passwordAleatoria = "";
    for ($i = 0; $i < 9; $i++) {
        $passwordAleatoria .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    $passwordAleatoria .= "#$&";

    if ($conn->connect_error) {
        echoColor($strings["createMySqlPassword_error_connect"], "red");
        fgets(STDIN);

        $dbName = dialogGetData($strings["createMySqlPassword_db_name"]);
        $dbUser = dialogGetData($strings["createMySqlPassword_user_name"]);
        $password = dialogGetData($strings["createMySqlPassword_password"]);

        $command = "dialog --menu '{$strings["createMySqlPassword_type"]}' 15 50 2 \
                          'mariadb' 'MariaDB' \
                          'mysqli'  'MySql' 3>&1 1>&2 2>&3";
        $dbType = shell_exec($command);

        return [
            "dbtype" => $dbType,
            "dbname" => $dbName,
            "dbuser" => $dbUser,
            "password" => $password,
        ];
    }

    $dbUser = $dbName;
    do {
        $dbUser = dialogGetData($strings["createMySqlPassword_user_name"], "", $dbUser);
        try {
            $sql_criar_usuario = "CREATE USER '{$dbUser}'@'localhost' IDENTIFIED BY '{$passwordAleatoria}'";
            $conn->query($sql_criar_usuario);

            $sql_grant_usage = "GRANT USAGE ON *.* TO '{$dbUser}'@'localhost'";
            $conn->query($sql_grant_usage);

            $sql_conceder_permissao = "ALTER USER '{$dbUser}'@'localhost' REQUIRE NONE WITH MAX_QUERIES_PER_HOUR 0 MAX_CONNECTIONS_PER_HOUR 0 MAX_UPDATES_PER_HOUR 0 MAX_USER_CONNECTIONS 0";
            $conn->query($sql_conceder_permissao);
        } catch (Exception $e) {
            echoColor($e->getMessage(), "red");

            echoColor($strings["createMySqlPassword_again"], "red");
            fgets(STDIN);
            continue;
        }

        break;
    } while (true);


    do {
        $dbName = dialogGetData($strings["createMySqlPassword_db_name"], "", $dbName);

        try {
            $sql_create_database = "CREATE DATABASE `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";
            if (!$conn->query($sql_create_database) === TRUE) {
                return false;
            }

            $sql_grant_privileges = "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost'";
            if (!$conn->query($sql_grant_privileges) === TRUE) {
                return false;
            }
        } catch (Exception $e) {
            echoColor($e->getMessage(), "red");

            echoColor($strings["createMySqlPassword_again"], "red");
            fgets(STDIN);
            continue;
        }

        break;
    } while (true);

    $serverInfo = $conn->server_info;

    if (stripos($serverInfo, "MariaDB") !== false) {
        $dbType = "mariadb";
    } else {
        $dbType = "mysqli";
    }

    return [
        "dbtype" => $dbType,
        "dbname" => $dbName,
        "dbuser" => $dbUser,
        "password" => $passwordAleatoria,
    ];
}

/**
 * Function echoColor
 *   30: Black
 *   31: Red
 *   32: Green
 *   33: Yellow
 *   34: Blue
 *   35: Magenta
 *   36: Cyan
 *   37: White
 *
 * @param $text
 * @param $color
 *
 * @return string
 */
function echoColor($text, $color)
{
    switch (strtolower($color)) {
        case "black":
            echo "\033[30m";
            break;
        case "red":
            echo "\033[31m";
            break;
        case "green":
            echo "\033[32m";
            break;
        case "yellow":
            echo "\033[33m";
            break;
        case "blue":
            echo "\033[34m";
            break;
        case "magenta":
            echo "\033[35m";
            break;
        case "cyan":
            echo "\033[36m";
            break;
        case "white":
            echo "\033[37m";
            break;
        default:
            echo "\033[0m"; // Reset color
    }

    echo "\n\n\n";
    echo $text;
    echo "\n";

    echo "\033[0m"; // Reset color
}

/**
 * Function dialogGetData
 *
 * @param string $title
 * @param string $subtitle
 * @param string $default
 *
 * @return string
 */
function dialogGetData($title, $subtitle = "", $default = "")
{
    $command = "dialog --title '{$title}' --inputbox '{$subtitle}' 11 80 '{$default}' 3>&1 1>&2 2>&3";
    $data = shell_exec($command);
    return $data;
}

/**
 * Function getConfigPhp
 *
 * @return string
 */
function getConfigPhp()
{
    global $mysql_info, $selectedDomain, $host, $path;

    $config = "<?php // Moodle configuration file

unset( \$CFG );
global \$CFG;
\$CFG = new stdClass();

\$CFG->dbtype    = '{$mysql_info['dbtype']}';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = 'localhost';
\$CFG->dbname    = '{$mysql_info['dbname']}';
\$CFG->dbuser    = '{$mysql_info['dbuser']}';
\$CFG->dbpass    = '{$mysql_info['password']}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = array(
    'dbpersist'   => 0,
    'dbport'      => '',
    'dbsocket'    => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

\$CFG->wwwroot   = '{$selectedDomain}';
\$CFG->dataroot  = '/var/www/moodledata/{$host}{$path}';
\$CFG->admin     = 'admin';
// \$CFG->sslproxy = true;

\$CFG->directorypermissions = 0777;

\$CFG->showcampaigncontent           = false;
\$CFG->showservicesandsupportcontent = false;
\$CFG->enableuserfeedback            = \" \";
// \$CFG->registrationpending           = false;
// \$CFG->site_is_public                = false;
// \$CFG->disableupdatenotifications    = true;

require_once( __DIR__ . '/lib/setup.php' );";

    return $config;
}

function changueTheme()
{
    global $local, $strings;

    // Theme
    $command = "dialog --menu '{$strings["changueTheme_title"]}' 10 50 2 \
                       YES {$strings["YES"]} \
                       NO  {$strings["NO"]}  \
                       3>&1 1>&2 2>&3";
    if (shell_exec($command) == "YES") {
        shell_exec("git clone --depth 1 https://github.com/EduardoKrausME/moodle-theme_boost_magnific {$local}/theme/boost_magnific");
        system("php {$local}/admin/cli/upgrade.php --non-interactive");
        system("php {$local}/admin/cli/cfg.php --name=theme --set=boost_magnific");
    }
}

function installKrausPlugins()
{
    global $local, $strings;

    // Theme
    $command = "dialog --menu '{$strings["installKrausPlugins_title"]}' 10 50 2 \
                       YES {$strings["YES"]} \
                       NO  {$strings["NO"]}  \
                       3>&1 1>&2 2>&3";
    if (shell_exec($command) == "YES") {
        shell_exec("git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_supervideo {$local}/mod/supervideo");
        shell_exec("git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_certificatebeautiful {$local}/mod/certificatebeautiful");

        shell_exec("git clone --depth 1 https://github.com/EduardoKrausME/moodle-local-kopere_dashboard {$local}/local/kopere_dashboard");
        shell_exec("git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_kopere_bi {$local}/local/kopere_bi");

        system("php {$local}/admin/cli/upgrade.php --non-interactive");
    }
}

/**
 * Function changueLang
 *
 * @param $selectedLang
 */
function changueLang($selectedLang)
{
    global $strings;

    switch ($selectedLang) {
        case "pt_br":
            $strings = [
                "selectedDomain_title" => "Por favor, insira agora a URL do seu Moodle",
                "selectedDomain_subtitle" => "Ela não deve terminar com / \nExemplo: http://seumoodle.seusite.com\nExemplo: http://seumoodle.seusite.com/moodle2",
                "urlerror_scheme" => "A URL deve começar com HTTP ou HTTPS",
                "urlerror_host" => "URL inválida",
                "urlerror_path" => "A URL não pode terminar com /",
                "urlerror_again" => "A URL não é válida. Pressione Enter para tentar novamente!",

                "selectedEmail_title" => "Qual e-mail deve ser usado para o registro do Moodle?",

                "selectedVersion_title" => "Escolha a versão do Moodle",
                "moodle_install" => "Agora vou baixar o código do Moodle. Fique atento, logo trarei mais opções para você:",
                "install_end" => "Os códigos estão na pasta {local} e agora vamos configurar o banco de dados:",

                "install_moodle" => "Agora vou instalar o Moodle",
                "install_moodle_end" => "Instalação concluída. Agora acesse pelo navegador:",

                "test__max_input_vars_error" => "O valor atual de max_input_vars é {currentValue}. Tentando atualizar...",
                "test__max_input_vars_phpini" => "Arquivo php.ini não encontrado. Altere e tente novamente.",

                "apacheConfiguration_ok" => "O domínio já está configurado.",
                "apacheConfiguration_root" => "A pasta raiz do domínio é: {local}",
                "apacheConfiguration_instruction" => "A seguir, será perguntado se você deseja instalar o Moodle em `/var/www/html` ou em `/var/www/html/{host}`.\n\n" .
                    "A opção `/var/www/html/{host}` é recomendada se você planeja hospedar múltiplos sites Moodle em domínios diferentes. " .
                    "Por outro lado, a opção `/var/www/html/` é ideal se você deseja apenas um domínio ou uma única instalação do Moodle neste servidor.",
                "apacheConfiguration_continue" => "Pressione Enter para continuar!",
                "apacheConfiguration_menu_title" => "Como você deseja realizar a instalação?",
                "apacheConfiguration_aborted" => "A pasta {local} já existe, e a instalação foi abortada.",
                "apacheConfiguration_enable_ssl" => "Deseja habilitar o HTTPS?",

                "database_error" => "Não foi possível criar um usuário no banco de dados, abortando",
                "createMySqlPassword_error_connect" => "Erro ao tentar conectar ao banco de dados MySQL. Se o problema persistir, consulte o administrador do sistema para garantir que o servidor MySQL está ativo e acessível.",
                "createMySqlPassword_db_name" => "Por favor, insira o novo nome do banco de dados MySQL:",
                "createMySqlPassword_user_name" => "Por favor, insira o novo nome de usuário do MySQL:",
                "createMySqlPassword_password" => "Por favor, insira a nova senha do MySQL:",
                "createMySqlPassword_type" => "Escolha o tipo de servidor de banco de dados:",
                "createMySqlPassword_again" => "Pressione Enter para tentar novamente!",

                "changueTheme_title" => "Gostaria de instalar um tema moderno com suporte a Modo Escuro?",
                "installKrausPlugins_title" => "Que tal instalar os incríveis plugins desenvolvidos pelo Kraus?",

                "YES" => "Sim",
                "NO" => "Não",
            ];
            break;
        case "es":
        case "es_mx":
        case "es_co":
            $strings = [
                "selectedDomain_title" => "Por favor, ingrese ahora la URL de su Moodle",
                "selectedDomain_subtitle" => "No debe terminar con  / \nEjemplo: http://yourmoodle.mysite.com\nEjemplo: http://yourmoodle.mysite.com/moodle2",
                "urlerror_scheme" => "La URL debe comenzar con HTTP o HTTPS",
                "urlerror_host" => "URL inválida",
                "urlerror_path" => "La URL no puede terminar con /",
                "urlerror_again" => "La URL no es válida. Presione enter para intentar nuevamente!",

                "selectedEmail_title" => "¿Qué correo electrónico se debe usar para el registro de Moodle?",

                "selectedVersion_title" => "Elija la versión de Moodle",
                "moodle_install" => "Ahora voy a descargar el código de Moodle. Mantente atento, pronto volveré con más opciones para ti:",
                "install_end" => "Los códigos están en la carpeta {local} y ahora pasemos a la base de datos:",

                "install_moodle" => "Ahora voy a instalar Moodle",
                "install_moodle_end" => "Instalación completada. Ahora accede a través del navegador:",

                "test__max_input_vars_error" => "El valor actual de max_input_vars es {currentValue}. Intentando actualizar...",
                "test__max_input_vars_phpini" => "Archivo php.ini no encontrado. Cámbialo e inténtalo nuevamente.",

                "apacheConfiguration_ok" => "El dominio ya está configurado.",
                "apacheConfiguration_root" => "La carpeta raíz del dominio es: {local}",
                "apacheConfiguration_instruction" => "A continuación, se te preguntará si deseas instalar Moodle en `/var/www/html` o en `/var/www/html/{host}`.\n\n" .
                    "La opción `/var/www/html/{host}` es recomendada si planeas alojar múltiples sitios de Moodle en diferentes dominios. " .
                    "Por otro lado, la opción `/var/www/html/` es ideal si solo deseas un único dominio o una única instalación de Moodle en este servidor.",
                "apacheConfiguration_continue" => "¡Presiona enter para continuar!",
                "apacheConfiguration_menu_title" => "¿Cómo quieres la instalación?",
                "apacheConfiguration_aborted" => "La carpeta {local} ya existe y la instalación se ha abortado.",
                "apacheConfiguration_enable_ssl" => "¿Quieres habilitar HTTPS?",

                "database_error" => "No se pudo crear un usuario en la base de datos, abortando",
                "createMySqlPassword_error_connect" => "Error al intentar conectarse a la base de datos MySQL. Si el problema persiste, consulta al administrador del sistema para asegurarte de que el servidor MySQL esté activo y accesible.",
                "createMySqlPassword_db_name" => "Por favor, ingrese el nuevo nombre de la base de datos MySQL:",
                "createMySqlPassword_user_name" => "Por favor, ingrese el nuevo nombre de usuario de MySQL:",
                "createMySqlPassword_password" => "Por favor, ingrese la nueva contraseña de MySQL:",
                "createMySqlPassword_type" => "Elija el tipo de servidor de base de datos:",
                "createMySqlPassword_again" => "¡Presiona enter para intentar nuevamente!",

                "changueTheme_title" => "¿Te gustaría instalar un tema moderno con soporte para Modo Oscuro?",
                "installKrausPlugins_title" => "¿Qué te parece instalar los increíbles complementos desarrollados por Kraus?",

                "YES" => "Sí",
                "NO" => "No",
            ];
            break;
        case "de":
            $strings = [
                "selectedDomain_title" => "Bitte geben Sie jetzt die URL Ihres Moodles ein",
                "selectedDomain_subtitle" => "Sie darf nicht mit  /  enden\nBeispiel: http://yourmoodle.mysite.com\nBeispiel: http://yourmoodle.mysite.com/moodle2",
                "urlerror_scheme" => "URL muss mit HTTP oder HTTPS beginnen",
                "urlerror_host" => "Ungültige URL",
                "urlerror_path" => "URL darf nicht mit / enden",
                "urlerror_again" => "URL ist ungültig. Drücken Sie die Eingabetaste, um es erneut zu versuchen!",

                "selectedEmail_title" => "Welche E-Mail soll für die Moodle-Registrierung verwendet werden?",

                "selectedVersion_title" => "Wählen Sie die Moodle-Version",
                "moodle_install" => "Jetzt lade ich den Moodle-Code herunter. Bleiben Sie dran, ich werde bald mit weiteren Optionen für Sie zurück sein:",
                "install_end" => "Die Codes befinden sich im Ordner {local} und jetzt geht es weiter mit der Datenbank:",

                "install_moodle" => "Jetzt installiere ich Moodle",
                "install_moodle_end" => "Installation abgeschlossen. Jetzt können Sie über den Browser darauf zugreifen:",

                "test__max_input_vars_error" => "Der aktuelle Wert von max_input_vars ist {currentValue}. Versuch der Aktualisierung...",
                "test__max_input_vars_phpini" => "php.ini-Datei nicht gefunden. Ändern Sie die Datei und versuchen Sie es erneut.",

                "apacheConfiguration_ok" => "Die Domain ist bereits konfiguriert.",
                "apacheConfiguration_root" => "Das Stammverzeichnis der Domain ist: {local}",
                "apacheConfiguration_instruction" => "Als Nächstes werden Sie gefragt, ob Sie Moodle in `/var/www/html` oder in `/var/www/html/{host}` installieren möchten.\n\n" .
                    "Die Option `/var/www/html/{host}` wird empfohlen, wenn Sie planen, mehrere Moodle-Sites auf verschiedenen Domains zu hosten. " .
                    "Die Option `/var/www/html/` ist ideal, wenn Sie nur eine einzelne Domain oder eine einzige Moodle-Installation auf diesem Server wünschen.",
                "apacheConfiguration_continue" => "Drücken Sie die Eingabetaste, um fortzufahren!",
                "apacheConfiguration_menu_title" => "Wie möchten Sie die Installation durchführen?",
                "apacheConfiguration_aborted" => "Der Ordner {local} existiert bereits, und die Installation wurde abgebrochen.",
                "apacheConfiguration_enable_ssl" => "Möchten Sie HTTPS aktivieren?",

                "database_error" => "Es war nicht möglich, einen Benutzer in der Datenbank zu erstellen, Abbruch",
                "createMySqlPassword_error_connect" => "Fehler beim Versuch, eine Verbindung zur MySQL-Datenbank herzustellen. Wenn das Problem weiterhin besteht, wenden Sie sich bitte an den Systemadministrator, um sicherzustellen, dass der MySQL-Server aktiv und zugänglich ist.",
                "createMySqlPassword_db_name" => "Bitte geben Sie den neuen MySQL-Datenbanknamen ein:",
                "createMySqlPassword_user_name" => "Bitte geben Sie den neuen MySQL-Benutzernamen ein:",
                "createMySqlPassword_password" => "Bitte geben Sie das neue MySQL-Passwort ein:",
                "createMySqlPassword_type" => "Wählen Sie den Datenbankservertyp:",
                "createMySqlPassword_again" => "Drücken Sie die Eingabetaste, um es erneut zu versuchen!",

                "changueTheme_title" => "Möchten Sie ein modernes Design mit Unterstützung für den Dunkelmodus installieren?",
                "installKrausPlugins_title" => "Wie wäre es, die unglaublichen Plugins zu installieren, die von Kraus entwickelt wurden?",

                "YES" => "Ja",
                "NO" => "Nein",
            ];
            break;
        case "id":
            $strings = [
                "selectedDomain_title" => "Silakan masukkan URL Moodle Anda sekarang",
                "selectedDomain_subtitle" => "URL tidak boleh diakhiri dengan  / \nContoh: http://yourmoodle.mysite.com\nContoh: http://yourmoodle.mysite.com/moodle2",
                "urlerror_scheme" => "URL harus dimulai dengan HTTP atau HTTPS",
                "urlerror_host" => "URL tidak valid",
                "urlerror_path" => "URL tidak boleh diakhiri dengan /",
                "urlerror_again" => "URL tidak valid. Tekan enter untuk mencoba lagi!",

                "selectedEmail_title" => "Email apa yang akan digunakan untuk pendaftaran Moodle?",

                "selectedVersion_title" => "Pilih versi Moodle",
                "moodle_install" => "Sekarang saya akan mengunduh kode Moodle. Tetaplah di sini, saya akan segera kembali dengan lebih banyak opsi untuk Anda:",
                "install_end" => "Kode-kodenya ada di folder {local} dan sekarang mari beralih ke basis data:",

                "install_moodle" => "Sekarang saya akan menginstal Moodle",
                "install_moodle_end" => "Instalasi selesai. Sekarang akses melalui browser:",

                "test__max_input_vars_error" => "Nilai saat ini dari max_input_vars adalah {currentValue}. Mencoba memperbarui...",
                "test__max_input_vars_phpini" => "File php.ini tidak ditemukan. Ubah dan coba lagi.",

                "apacheConfiguration_ok" => "Domain sudah dikonfigurasi.",
                "apacheConfiguration_root" => "Folder root domain adalah: {local}",
                "apacheConfiguration_instruction" => "Selanjutnya, Anda akan ditanya apakah Anda ingin menginstal Moodle di `/var/www/html` atau di `/var/www/html/{host}`.\n\n" .
                    "Opsi `/var/www/html/{host}` direkomendasikan jika Anda berencana untuk menghosting beberapa situs Moodle pada domain yang berbeda. " .
                    "Di sisi lain, opsi `/var/www/html/` ideal jika Anda hanya ingin satu domain atau satu instalasi Moodle di server ini.",
                "apacheConfiguration_continue" => "Tekan enter untuk melanjutkan!",
                "apacheConfiguration_menu_title" => "Bagaimana Anda ingin instalasinya?",
                "apacheConfiguration_aborted" => "Folder {local} sudah ada, dan instalasi telah dibatalkan.",
                "apacheConfiguration_enable_ssl" => "Apakah Anda ingin mengaktifkan HTTPS?",

                "database_error" => "Tidak dapat membuat pengguna di basis data, membatalkan",
                "createMySqlPassword_error_connect" => "Kesalahan saat mencoba menghubungkan ke basis data MySQL. Jika masalah terus berlanjut, silakan konsultasikan dengan administrator sistem untuk memastikan bahwa server MySQL aktif dan dapat diakses.",
                "createMySqlPassword_db_name" => "Masukkan nama basis data MySql baru:",
                "createMySqlPassword_user_name" => "Masukkan nama pengguna MySql baru:",
                "createMySqlPassword_password" => "Masukkan kata sandi MySql baru:",
                "createMySqlPassword_type" => "Pilih jenis server basis data:",
                "createMySqlPassword_again" => "Tekan enter untuk mencoba lagi!",

                "changueTheme_title" => "Apakah Anda ingin menginstal tema modern dengan dukungan Mode Gelap?",
                "installKrausPlugins_title" => "Bagaimana kalau menginstal plugin luar biasa yang dikembangkan oleh Kraus?",

                "YES" => "YA",
                "NO" => "TIDAK"
            ];
            break;
        case "ru":
            $strings = [
                "selectedDomain_title" => "Пожалуйста, введите URL вашего Moodle",
                "selectedDomain_subtitle" => "URL не должен заканчиваться на  / \nПример: http://yourmoodle.mysite.com\nПример: http://yourmoodle.mysite.com/moodle2",
                "urlerror_scheme" => "URL должен начинаться с HTTP или HTTPS",
                "urlerror_host" => "Недействительный URL",
                "urlerror_path" => "URL не может заканчиваться на /",
                "urlerror_again" => "URL недействителен. Нажмите Enter, чтобы попробовать снова!",

                "selectedEmail_title" => "Какой email следует использовать для регистрации Moodle?",

                "selectedVersion_title" => "Выберите версию Moodle",
                "moodle_install" => "Сейчас я загружу код Moodle. Подождите немного, я скоро вернусь с дополнительными опциями для вас:",
                "install_end" => "Коды находятся в папке {local}, теперь перейдем к базе данных:",

                "install_moodle" => "Сейчас я установлю Moodle",
                "install_moodle_end" => "Установка завершена. Теперь зайдите на сайт через браузер:",

                "test__max_input_vars_error" => "Текущее значение max_input_vars равно {currentValue}. Пытаюсь обновить...",
                "test__max_input_vars_phpini" => "Файл php.ini не найден. Измените его и попробуйте снова.",

                "apacheConfiguration_ok" => "Домен уже настроен.",
                "apacheConfiguration_root" => "Корневая папка домена: {local}",
                "apacheConfiguration_instruction" => "Далее вам будет предложено установить Moodle в `/var/www/html` или в `/var/www/html/{host}`.\n\n" .
                    "Опция `/var/www/html/{host}` рекомендуется, если вы планируете размещать несколько сайтов Moodle на разных доменах. " .
                    "С другой стороны, опция `/var/www/html/` идеально подходит, если вы хотите только один домен или одну установку Moodle на этом сервере.",
                "apacheConfiguration_continue" => "Нажмите Enter, чтобы продолжить!",
                "apacheConfiguration_menu_title" => "Как вы хотите выполнить установку?",
                "apacheConfiguration_aborted" => "Папка {local} уже существует, установка прервана.",
                "apacheConfiguration_enable_ssl" => "Хотите включить HTTPS?",

                "database_error" => "Не удалось создать пользователя в базе данных, прерывание",
                "createMySqlPassword_error_connect" => "Ошибка подключения к базе данных MySQL. Если проблема сохраняется, обратитесь к системному администратору, чтобы убедиться, что сервер MySQL активен и доступен.",
                "createMySqlPassword_db_name" => "Введите имя новой базы данных MySQL:",
                "createMySqlPassword_user_name" => "Введите имя нового пользователя MySQL:",
                "createMySqlPassword_password" => "Введите новый пароль MySQL:",
                "createMySqlPassword_type" => "Выберите тип сервера базы данных:",
                "createMySqlPassword_again" => "Нажмите Enter, чтобы попробовать снова!",

                "changueTheme_title" => "Хотите установить современную тему с поддержкой темного режима?",
                "installKrausPlugins_title" => "Как насчет того, чтобы установить невероятные плагины, разработанные Краусом?",

                "YES" => "ДА",
                "NO" => "НЕТ"
            ];
            break;
        case "fr":
            $strings = [
                "selectedDomain_title" => "Veuillez saisir l’URL de votre Moodle maintenant",
                "selectedDomain_subtitle" => "Elle ne doit pas se terminer par  / \nExemple : http://votremoodle.monsite.com\nExemple : http://votremoodle.monsite.com/moodle2",
                "urlerror_scheme" => "L'URL doit commencer par HTTP ou HTTPS",
                "urlerror_host" => "URL invalide",
                "urlerror_path" => "L'URL ne peut pas se terminer par /",
                "urlerror_again" => "L'URL n'est pas valide. Appuyez sur Entrée pour réessayer!",

                "selectedEmail_title" => "Quel email doit être utilisé pour l'enregistrement de Moodle?",

                "selectedVersion_title" => "Choisissez la version de Moodle",
                "moodle_install" => "Je vais maintenant télécharger le code de Moodle. Restez attentif, je reviendrai bientôt avec plus d'options pour vous :",
                "install_end" => "Les codes sont dans le dossier {local} et nous allons maintenant passer à la base de données :",

                "install_moodle" => "Je vais maintenant installer Moodle",
                "install_moodle_end" => "Installation terminée. Accédez-y maintenant via le navigateur :",

                "test__max_input_vars_error" => "La valeur actuelle de max_input_vars est {currentValue}. Tentative de mise à jour...",
                "test__max_input_vars_phpini" => "Fichier php.ini introuvable. Modifiez-le et réessayez.",

                "apacheConfiguration_ok" => "Le domaine est déjà configuré.",
                "apacheConfiguration_root" => "Le dossier racine du domaine est : {local}",
                "apacheConfiguration_instruction" => "Ensuite, il vous sera demandé si vous souhaitez installer Moodle dans `/var/www/html` ou dans `/var/www/html/{host}`.\n\n" .
                    "L'option `/var/www/html/{host}` est recommandée si vous prévoyez d'héberger plusieurs sites Moodle sur des domaines différents. " .
                    "En revanche, l'option `/var/www/html/` est idéale si vous ne voulez qu'un seul domaine ou une seule installation Moodle sur ce serveur.",
                "apacheConfiguration_continue" => "Appuyez sur Entrée pour continuer!",
                "apacheConfiguration_menu_title" => "Comment voulez-vous procéder à l'installation?",
                "apacheConfiguration_aborted" => "Le dossier {local} existe déjà et l'installation a été annulée.",
                "apacheConfiguration_enable_ssl" => "Voulez-vous activer HTTPS ?",

                "database_error" => "Impossible de créer un utilisateur dans la base de données, abandon.",
                "createMySqlPassword_error_connect" => "Erreur lors de la tentative de connexion à la base de données MySQL. Si le problème persiste, veuillez consulter l'administrateur système pour vérifier que le serveur MySQL est actif et accessible.",
                "createMySqlPassword_db_name" => "Veuillez saisir le nom de la nouvelle base de données MySQL :",
                "createMySqlPassword_user_name" => "Veuillez saisir le nom du nouvel utilisateur MySQL :",
                "createMySqlPassword_password" => "Veuillez saisir le nouveau mot de passe MySQL :",
                "createMySqlPassword_type" => "Choisissez le type de serveur de base de données :",
                "createMySqlPassword_again" => "Appuyez sur Entrée pour réessayer!",

                "changueTheme_title" => "Souhaitez-vous installer un thème moderne avec prise en charge du mode sombre?",
                "installKrausPlugins_title" => "Que diriez-vous d'installer les incroyables plugins développés par Kraus?",

                "YES" => "Oui",
                "NO" => "Non",
            ];
            break;
        case "it":
            $strings = [
                "selectedDomain_title" => "Per favore, inserisci ora l'URL del tuo Moodle",
                "selectedDomain_subtitle" => "Non deve terminare con  /  \nEsempio: http://tuomoodle.miosito.com\nEsempio: http://tuomoodle.miosito.com/moodle2",
                "urlerror_scheme" => "L'URL deve iniziare con HTTP o HTTPS",
                "urlerror_host" => "URL non valido",
                "urlerror_path" => "L'URL non può terminare con /",
                "urlerror_again" => "L'URL non è valido. Premi invio per riprovare!",

                "selectedEmail_title" => "Quale email dovrebbe essere utilizzata per la registrazione a Moodle?",

                "selectedVersion_title" => "Scegli la versione di Moodle",
                "moodle_install" => "Ora scaricherò il codice di Moodle. Rimani sintonizzato, tornerò presto con altre opzioni per te:",
                "install_end" => "I codici sono nella cartella {local} e ora passiamo al database:",

                "install_moodle" => "Ora installerò Moodle",
                "install_moodle_end" => "Installazione completata. Ora accedi tramite il browser:",

                "test__max_input_vars_error" => "Il valore attuale di max_input_vars è {currentValue}. Tentativo di aggiornamento in corso...",
                "test__max_input_vars_phpini" => "File php.ini non trovato. Modifica e riprova.",

                "apacheConfiguration_ok" => "Il dominio è già configurato.",
                "apacheConfiguration_root" => "La cartella root del dominio è: {local}",
                "apacheConfiguration_instruction" => "Successivamente, ti verrà chiesto se desideri installare Moodle in `/var/www/html` o in `/var/www/html/{host}`.\n\n" .
                    "L'opzione `/var/www/html/{host}` è consigliata se prevedi di ospitare più siti Moodle su domini diversi. " .
                    "D'altra parte, l'opzione `/var/www/html/` è ideale se desideri un solo dominio o una singola installazione di Moodle su questo server.",
                "apacheConfiguration_continue" => "Premi invio per continuare!",
                "apacheConfiguration_menu_title" => "Come desideri effettuare l'installazione?",
                "apacheConfiguration_aborted" => "La cartella {local} esiste già e l'installazione è stata annullata.",
                "apacheConfiguration_enable_ssl" => "Vuoi abilitare HTTPS?",

                "database_error" => "Impossibile creare un utente nel database, interruzione in corso",
                "createMySqlPassword_error_connect" => "Errore durante il tentativo di connessione al database MySQL. Se il problema persiste, contatta l'amministratore di sistema per assicurarti che il server MySQL sia attivo e accessibile.",
                "createMySqlPassword_db_name" => "Inserisci il nuovo nome del database MySQL:",
                "createMySqlPassword_user_name" => "Inserisci il nuovo nome utente MySQL:",
                "createMySqlPassword_password" => "Inserisci la nuova PASSWORD MySQL:",
                "createMySqlPassword_type" => "Scegli il tipo di server database:",
                "createMySqlPassword_again" => "Premi invio per riprovare!",

                "changueTheme_title" => "Vuoi installare un tema moderno con supporto alla modalità scura?",
                "installKrausPlugins_title" => "Che vuoi installare i fantastici plugin sviluppati da Kraus?",

                "YES" => "Sì",
                "NO" => "No",
            ];
            break;

        default:
            $strings = [
                "selectedDomain_title" => "Please enter the URL of your Moodle now",
                "selectedDomain_subtitle" => "It must not end with  / \nExample: http://yourmoodle.mysite.com\nExample: http://yourmoodle.mysite.com/moodle2",
                "urlerror_scheme" => "URL must start with HTTP or HTTPS",
                "urlerror_host" => "Invalid URL",
                "urlerror_path" => "URL cannot end with /",
                "urlerror_again" => "URL is not valid. Press enter to try again!",

                "selectedEmail_title" => "What email should be used for the Moodle registration?",

                "selectedVersion_title" => "Choose the Moodle version",
                "moodle_install" => "Now I will download the Moodle code. Stay tuned, I'll be back with more options for you shortly:",
                "install_end" => "The codes are in the folder {local} and now let's move to the database:",

                "install_moodle" => "Now I will install Moodle",
                "install_moodle_end" => "Installation completed. Now access it through the browser:",

                "test__max_input_vars_error" => "O valor atual de max_input_vars é {currentValue}. Tentando atualizar...",
                "test__max_input_vars_phpini" => "Arquivo php.ini não encontrado. Altere e tente novamente.",

                "apacheConfiguration_ok" => "The domain is already configured.",
                "apacheConfiguration_root" => "The domain's root folder is: {local}",
                "apacheConfiguration_instruction" => "Next, you will be asked whether you want to install Moodle in `/var/www/html` or in `/var/www/html/{host}`.\n\n" .
                    "The option `/var/www/html/{host}` is recommended if you plan to host multiple Moodle sites on different domains. " .
                    "On the other hand, the `/var/www/html/` option is ideal if you only want a single domain or a single Moodle installation on this server.",
                "apacheConfiguration_continue" => "Press enter to continue!",
                "apacheConfiguration_menu_title" => "How do you want the installation?",
                "apacheConfiguration_aborted" => "The folder {local} already exists, and the installation has been aborted.",
                "apacheConfiguration_enable_ssl" => "Do you want to enable HTTPS?",

                "database_error" => "Unable to create a user in the database, aborting",
                "createMySqlPassword_error_connect" => "Error trying to connect to the MySQL database. If the problem persists, please consult the system administrator to ensure that the MySQL server is active and accessible.",
                "createMySqlPassword_db_name" => "Please enter new MySql DB name:",
                "createMySqlPassword_user_name" => "Please enter new MySql USER name:",
                "createMySqlPassword_password" => "Please enter new MySql PASSWORD:",
                "createMySqlPassword_type" => "Choose the database server type:",
                "createMySqlPassword_again" => "Press enter to try again!",

                "changueTheme_title" => "Would you like to install a modern theme with Dark Mode support?",
                "installKrausPlugins_title" => "How about installing the amazing plugins developed by Kraus?",

                "YES" => "Yes",
                "NO" => "No",
            ];
            break;
    }
}
