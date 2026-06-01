#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

# Moodle Friendly Installation panel installer.
# Supported targets: Ubuntu/Debian, Fedora, CentOS/RHEL/Alma/Rocky 8+.
# Usage: sudo bash installation.sh [repo-branch]

ENV_URL="https://raw.githubusercontent.com/moodle/moodle/main/public/admin/environment.xml"
INSTALL_DIR="${INSTALL_DIR:-/home/admin.moodle}"
REPO_URL="${REPO_URL:-https://github.com/EduardoKrausME/moodle_friendly_installation.git}"
REPO_BRANCH="${1:-${REPO_BRANCH:-master}}"
NONINTERACTIVE="${NONINTERACTIVE:-0}"

OS_ID=""
OS_VERSION_ID=""
OS_LIKE=""
OS_FAMILY=""
PKG_MANAGER=""
WEB_USER=""
WEB_GROUP=""
APACHE_SERVICE=""
APACHE_SITES_DIR=""
NGINX_SITES_DIR="/etc/nginx/sites-enabled"
PHP_SERIES=""
PHP_BIN="/usr/bin/php"
PHP_FPM_SERVICE=""
PHP_FPM_SOCKET=""
DB_ENGINE=""
DB_SERVICE=""
DB_ROOT_PASS=""
MOODLE_STABLE_BRANCH=""
PANEL_DOMAIN=""
PUBLIC_IP=""
BASE_URL=""
SERVER_NAME=""
LE_EMAIL=""
MOODLE_VERSION=""
MOODLE_REQUIRES=""
PHP_REQUIRED=""
MARIADB_REQUIRED=""
MYSQL_REQUIRED=""

log() {
    printf '\033[1;32m==>\033[0m %s\n' "$*"
}

warn() {
    printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2
}

die() {
    printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2
    exit 1
}

need_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        die "Execute como root: sudo bash $0"
    fi
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

version_ge() {
    # Returns true when $1 >= $2.
    [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" == "$2" ]]
}

safe_php_string() {
    python3 - "$1" <<'PY'
import sys
s = sys.argv[1]
s = s.replace('\\', '\\\\').replace("'", "\\'")
print("'" + s + "'")
PY
}

prompt_text() {
    local var_name="$1"
    local label="$2"
    local default_value="${3:-}"
    local value=""

    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        value="${default_value}"
    else
        if [[ -n "${default_value}" ]]; then
            read -r -p "${label} [${default_value}]: " value
            value="${value:-${default_value}}"
        else
            read -r -p "${label}: " value
        fi
    fi

    printf -v "${var_name}" '%s' "${value}"
}

prompt_secret() {
    local var_name="$1"
    local label="$2"
    local value=""

    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        value="${!var_name:-}"
        [[ -n "${value}" ]] || die "Variável ${var_name} precisa estar definida em NONINTERACTIVE=1."
    else
        read -r -s -p "${label}: " value
        printf '\n'
    fi

    printf -v "${var_name}" '%s' "${value}"
}

detect_os() {
    [[ -r /etc/os-release ]] || die "Não foi possível detectar a distribuição Linux."
    # shellcheck source=/dev/null
    source /etc/os-release
    OS_ID="${ID:-}"
    OS_VERSION_ID="${VERSION_ID:-}"
    OS_LIKE="${ID_LIKE:-}"

    case "${OS_ID} ${OS_LIKE}" in
        *ubuntu*|*debian*)
            OS_FAMILY="debian"
            PKG_MANAGER="apt"
            WEB_USER="www-data"
            WEB_GROUP="www-data"
            APACHE_SERVICE="apache2"
            APACHE_SITES_DIR="/etc/apache2/sites-enabled"
            ;;
        *fedora*)
            OS_FAMILY="fedora"
            PKG_MANAGER="dnf"
            WEB_USER="apache"
            WEB_GROUP="apache"
            APACHE_SERVICE="httpd"
            APACHE_SITES_DIR="/etc/httpd/sites-enabled"
            ;;
        *centos*|*rhel*|*rocky*|*almalinux*)
            OS_FAMILY="rhel"
            if command_exists dnf; then
                PKG_MANAGER="dnf"
            elif command_exists yum; then
                PKG_MANAGER="yum"
            else
                die "CentOS/RHEL encontrado, mas nem dnf nem yum estão disponíveis."
            fi
            WEB_USER="apache"
            WEB_GROUP="apache"
            APACHE_SERVICE="httpd"
            APACHE_SITES_DIR="/etc/httpd/sites-enabled"
            ;;
        *)
            die "Distribuição não suportada: ID=${OS_ID}, ID_LIKE=${OS_LIKE}."
            ;;
    esac

    log "Sistema detectado: ${OS_ID} ${OS_VERSION_ID} (${OS_FAMILY})"
}

pkg_update() {
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -y
    else
        ${PKG_MANAGER} makecache -y || true
    fi
}

pkg_install() {
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get install -y "$@"
    else
        ${PKG_MANAGER} install -y "$@"
    fi
}

install_base_packages() {
    log "Instalando pacotes base"
    pkg_update
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        pkg_install ca-certificates curl wget gnupg lsb-release software-properties-common unzip tar git dnsutils cron openssl python3 sed grep awk coreutils
    else
        pkg_install ca-certificates curl wget gnupg2 unzip tar git bind-utils cronie openssl python3 sed grep gawk coreutils policycoreutils-python-utils || pkg_install ca-certificates curl wget gnupg2 unzip tar git bind-utils cronie openssl python3 sed grep gawk coreutils
        systemctl enable --now crond >/dev/null 2>&1 || true
    fi
}

fetch_moodle_environment() {
    local tmpxml="/tmp/moodle-environment.xml"
    local outenv="/tmp/moodle-env-requirements.env"

    log "Lendo requirements atuais do Moodle em environment.xml"
    curl -fsSL "${ENV_URL}" -o "${tmpxml}" || die "Falha ao baixar ${ENV_URL}"

    python3 - "${tmpxml}" > "${outenv}" <<'PY'
import shlex
import sys
import xml.etree.ElementTree as ET

xml_path = sys.argv[1]
root = ET.parse(xml_path).getroot()
moodles = list(root.iter('MOODLE'))
if not moodles:
    raise SystemExit('Nenhum bloco MOODLE encontrado no environment.xml')

moodle = moodles[-1]

def emit(key, value):
    print(f'{key}={shlex.quote(value or "")}')

php_required = ''
for item in moodle.iter('PHP'):
    if item.attrib.get('level') == 'required':
        php_required = item.attrib.get('version', '').strip()

vendors = {}
for vendor in moodle.iter('VENDOR'):
    name = vendor.attrib.get('name', '').strip().lower()
    if name in {'mariadb', 'mysql'}:
        vendors[name] = vendor.attrib.get('version', '').strip()

emit('MOODLE_VERSION', moodle.attrib.get('version', '').strip())
emit('MOODLE_REQUIRES', moodle.attrib.get('requires', '').strip())
emit('PHP_REQUIRED', php_required)
emit('MARIADB_REQUIRED', vendors.get('mariadb', ''))
emit('MYSQL_REQUIRED', vendors.get('mysql', ''))
PY

    # shellcheck source=/tmp/moodle-env-requirements.env
    source "${outenv}"

    [[ -n "${MOODLE_VERSION}" ]] || die "Não foi possível detectar a versão do Moodle."
    [[ -n "${PHP_REQUIRED}" ]] || die "Não foi possível detectar a versão requerida do PHP."
    [[ -n "${MARIADB_REQUIRED}" ]] || warn "Não foi possível detectar MariaDB required no XML."
    [[ -n "${MYSQL_REQUIRED}" ]] || warn "Não foi possível detectar MySQL required no XML."

    PHP_SERIES="$(printf '%s' "${PHP_REQUIRED}" | awk -F. '{print $1"."$2}')"
    local moodle_major moodle_minor
    moodle_major="${MOODLE_VERSION%%.*}"
    moodle_minor="${MOODLE_VERSION#*.}"
    moodle_minor="${moodle_minor%%.*}"
    if [[ "${moodle_major}" =~ ^[0-9]+$ ]] && [[ "${moodle_minor}" =~ ^[0-9]+$ ]]; then
        MOODLE_STABLE_BRANCH="$(printf 'MOODLE_%d%02d_STABLE' "${moodle_major}" "${moodle_minor}")"
    else
        MOODLE_STABLE_BRANCH="MOODLE_${MOODLE_VERSION/./}_STABLE"
    fi
    log "Moodle ${MOODLE_VERSION} requer PHP >= ${PHP_REQUIRED}, MariaDB >= ${MARIADB_REQUIRED:-?}, MySQL >= ${MYSQL_REQUIRED:-?}"
}

ask_install_options() {
    local db_default="mariadb"
    local choice=""

    while true; do
        prompt_text choice "Banco de dados para instalar (mariadb|mysql)" "${db_default}"
        choice="$(printf '%s' "${choice}" | tr '[:upper:]' '[:lower:]')"
        case "${choice}" in
            mariadb|mysql)
                DB_ENGINE="${choice}"
                break
                ;;
            *)
                warn "Escolha inválida. Use mariadb ou mysql."
                ;;
        esac
    done

    prompt_text PANEL_DOMAIN "Domínio do painel, ou ENTER para usar o IP público" ""
    PANEL_DOMAIN="$(printf '%s' "${PANEL_DOMAIN}" | tr '[:upper:]' '[:lower:]' | sed -E 's#^https?://##; s#/.*$##; s/:.*$//')"

    prompt_secret DB_ROOT_PASS "Senha root do banco de dados que será gravada no config.php"
    [[ ${#DB_ROOT_PASS} -ge 8 ]] || die "A senha root do banco precisa ter ao menos 8 caracteres."
}

detect_public_ip() {
    PUBLIC_IP="$(curl -fsSL --max-time 8 https://api.ipify.org 2>/dev/null || true)"
    if [[ -z "${PUBLIC_IP}" ]]; then
        PUBLIC_IP="$(curl -fsSL --max-time 8 https://ifconfig.me/ip 2>/dev/null || true)"
    fi
    if [[ -z "${PUBLIC_IP}" ]]; then
        PUBLIC_IP="$(hostname -I | awk '{print $1}')"
    fi
    [[ -n "${PUBLIC_IP}" ]] || die "Não foi possível detectar o IP público."
    log "IP público detectado: ${PUBLIC_IP}"
}

check_dns_before_continue() {
    if [[ -z "${PANEL_DOMAIN}" ]]; then
        SERVER_NAME="_"
        BASE_URL="http://${PUBLIC_IP}"
        log "Instalação do painel será liberada por IP: ${BASE_URL}"
        return
    fi

    local resolved=""
    resolved="$(dig +short A "${PANEL_DOMAIN}" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u | tr '\n' ' ' | sed 's/[[:space:]]*$//')"
    if [[ -z "${resolved}" ]]; then
        die "O domínio ${PANEL_DOMAIN} ainda não possui registro A publicado. Aponte o DNS para ${PUBLIC_IP} e execute novamente."
    fi

    if ! printf ' %s ' "${resolved}" | grep -q " ${PUBLIC_IP} "; then
        die "DNS não conferido para ${PANEL_DOMAIN}. Resolvido: ${resolved}. Esperado: ${PUBLIC_IP}. Corrija o DNS e execute novamente."
    fi

    SERVER_NAME="${PANEL_DOMAIN}"
    BASE_URL="https://${PANEL_DOMAIN}"
    log "DNS conferido: ${PANEL_DOMAIN} aponta para ${PUBLIC_IP}"
}

install_php() {
    log "Instalando PHP ${PHP_SERIES} e extensões usadas pelo Moodle/painel"

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        if ! apt-cache show "php${PHP_SERIES}-fpm" >/dev/null 2>&1; then
            log "Repositório padrão não encontrou php${PHP_SERIES}; adicionando PPA ondrej/php"
            LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php
            apt-get update -y
        fi

        pkg_install \
            "php${PHP_SERIES}" "php${PHP_SERIES}-cli" "php${PHP_SERIES}-fpm" \
            "php${PHP_SERIES}-mysql" "php${PHP_SERIES}-curl" "php${PHP_SERIES}-xml" \
            "php${PHP_SERIES}-mbstring" "php${PHP_SERIES}-zip" "php${PHP_SERIES}-gd" \
            "php${PHP_SERIES}-intl" "php${PHP_SERIES}-soap" "php${PHP_SERIES}-opcache" \
            "php${PHP_SERIES}-bcmath" "php${PHP_SERIES}-ldap"

        PHP_BIN="/usr/bin/php${PHP_SERIES}"
        PHP_FPM_SERVICE="php${PHP_SERIES}-fpm"
        PHP_FPM_SOCKET="/run/php/php${PHP_SERIES}-fpm.sock"
    else
        if [[ "${OS_FAMILY}" == "rhel" ]]; then
            local major="${OS_VERSION_ID%%.*}"
            if [[ "${major}" =~ ^[0-9]+$ ]] && [[ "${major}" -lt 8 ]]; then
                die "CentOS/RHEL ${OS_VERSION_ID} não é suportado para PHP moderno. Use CentOS Stream/RHEL/Alma/Rocky 8+ ou Fedora."
            fi

            pkg_install epel-release || true
            if ! rpm -q remi-release >/dev/null 2>&1; then
                rpm -Uvh "https://rpms.remirepo.net/enterprise/remi-release-${major}.rpm" || warn "Não foi possível instalar remi-release automaticamente. Tentando repositório padrão."
            fi
            ${PKG_MANAGER} module reset -y php || true
            ${PKG_MANAGER} module enable -y "php:remi-${PHP_SERIES}" || warn "Não foi possível habilitar php:remi-${PHP_SERIES}; tentando pacote padrão."
        fi

        pkg_install php php-cli php-fpm php-mysqlnd php-curl php-xml php-mbstring php-zip php-gd php-intl php-soap php-opcache php-bcmath php-ldap
        PHP_BIN="/usr/bin/php"
        PHP_FPM_SERVICE="php-fpm"
        PHP_FPM_SOCKET="/run/php-fpm/www.sock"
    fi

    command_exists "${PHP_BIN}" || die "PHP não encontrado em ${PHP_BIN}."
    local php_current=""
    php_current="$(${PHP_BIN} -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    [[ -n "${php_current}" ]] || die "Não foi possível ler a versão do PHP instalado."
    version_ge "${php_current}" "${PHP_REQUIRED}" || die "PHP instalado (${php_current}) é menor que o requerido pelo Moodle (${PHP_REQUIRED})."

    tune_php_ini
    systemctl enable --now "${PHP_FPM_SERVICE}"
    systemctl restart "${PHP_FPM_SERVICE}"
    log "PHP ativo: ${php_current} (${PHP_FPM_SERVICE})"
}

tune_php_ini() {
    local files=()
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        files=("/etc/php/${PHP_SERIES}/cli/php.ini" "/etc/php/${PHP_SERIES}/fpm/php.ini")
    else
        files=("/etc/php.ini")
        if [[ -f /etc/php-fpm.d/www.conf ]]; then
            sed -i "s/^user = .*/user = ${WEB_USER}/" /etc/php-fpm.d/www.conf || true
            sed -i "s/^group = .*/group = ${WEB_GROUP}/" /etc/php-fpm.d/www.conf || true
            sed -i "s#^listen = .*#listen = ${PHP_FPM_SOCKET}#" /etc/php-fpm.d/www.conf || true
            sed -i "s/^;\?listen.owner = .*/listen.owner = ${WEB_USER}/" /etc/php-fpm.d/www.conf || true
            sed -i "s/^;\?listen.group = .*/listen.group = ${WEB_GROUP}/" /etc/php-fpm.d/www.conf || true
            sed -i "s/^;\?listen.mode = .*/listen.mode = 0660/" /etc/php-fpm.d/www.conf || true
        fi
    fi

    for file in "${files[@]}"; do
        [[ -f "${file}" ]] || continue
        sed -i \
            -e 's/^memory_limit = .*/memory_limit = 512M/' \
            -e 's/^max_execution_time = .*/max_execution_time = 300/' \
            -e 's/^post_max_size = .*/post_max_size = 128M/' \
            -e 's/^upload_max_filesize = .*/upload_max_filesize = 128M/' \
            -e 's/^max_input_vars = .*/max_input_vars = 5000/' \
            -e 's/^;max_input_vars = .*/max_input_vars = 5000/' \
            -e 's/^date.timezone = .*/date.timezone = America\/Sao_Paulo/' \
            -e 's/^;date.timezone =.*/date.timezone = America\/Sao_Paulo/' \
            "${file}" || true
    done
}

install_database() {
    if [[ "${DB_ENGINE}" == "mariadb" ]]; then
        install_mariadb
    else
        install_mysql
    fi
    configure_database_root
    validate_database_version
}

install_mariadb() {
    log "Instalando MariaDB >= ${MARIADB_REQUIRED:-10.11}"
    local wanted="${MARIADB_REQUIRED:-10.11.0}"
    local series="$(printf '%s' "${wanted}" | awk -F. '{print $1"."$2}')"

    if curl -fsSL https://r.mariadb.com/downloads/mariadb_repo_setup -o /tmp/mariadb_repo_setup; then
        chmod +x /tmp/mariadb_repo_setup
        bash /tmp/mariadb_repo_setup --mariadb-server-version="mariadb-${series}" || warn "Falha ao configurar repositório MariaDB ${series}; tentando repositório da distribuição."
        pkg_update
    else
        warn "Não foi possível baixar mariadb_repo_setup; tentando repositório da distribuição."
    fi

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        pkg_install mariadb-server mariadb-client
    else
        pkg_install MariaDB-server MariaDB-client || pkg_install mariadb-server mariadb
    fi

    DB_SERVICE="mariadb"
    systemctl enable --now mariadb || systemctl enable --now mysql
}

install_mysql() {
    log "Instalando MySQL >= ${MYSQL_REQUIRED:-8.4}"

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        # The official MySQL APT package is not stable by filename. Prefer distro package and validate version after installation.
        pkg_install mysql-server mysql-client || pkg_install default-mysql-server default-mysql-client
        DB_SERVICE="mysql"
        systemctl enable --now mysql || systemctl enable --now mysqld
    else
        pkg_install mysql-server mysql || pkg_install community-mysql-server community-mysql
        DB_SERVICE="mysqld"
        systemctl enable --now mysqld || systemctl enable --now mysql
    fi
}

mysql_client() {
    if command_exists mysql; then
        command -v mysql
    elif command_exists mariadb; then
        command -v mariadb
    else
        return 1
    fi
}

run_mysql_socket() {
    local client
    client="$(mysql_client)" || return 1
    "${client}" -uroot "$@"
}

run_mysql_password() {
    local client
    client="$(mysql_client)" || return 1
    MYSQL_PWD="${DB_ROOT_PASS}" "${client}" -uroot "$@"
}

configure_database_root() {
    log "Configurando senha root do banco"
    local pass_sql
    pass_sql="$(printf '%s' "${DB_ROOT_PASS}" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g")"

    local sql="
ALTER USER 'root'@'localhost' IDENTIFIED BY '${pass_sql}';
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
"

    if run_mysql_socket -e "SELECT 1" >/dev/null 2>&1; then
        run_mysql_socket -e "${sql}" || warn "Não foi possível aplicar hardening completo via socket."
    elif run_mysql_password -e "SELECT 1" >/dev/null 2>&1; then
        run_mysql_password -e "${sql}" || warn "Não foi possível aplicar hardening completo via senha."
    else
        local current=""
        if [[ "${NONINTERACTIVE}" == "1" ]]; then
            die "Não foi possível autenticar no banco como root para aplicar a senha."
        fi
        read -r -s -p "Senha root atual do banco, caso já exista: " current
        printf '\n'
        local client
        client="$(mysql_client)" || die "Cliente MySQL/MariaDB não encontrado."
        MYSQL_PWD="${current}" "${client}" -uroot -e "${sql}" || die "Falha ao configurar a senha root do banco."
    fi
}

validate_database_version() {
    local client version required label
    client="$(mysql_client)" || die "Cliente MySQL/MariaDB não encontrado."
    version="$(${client} --version | grep -oE '[0-9]+(\.[0-9]+){1,2}' | head -n1 || true)"
    [[ -n "${version}" ]] || die "Não foi possível validar a versão do banco."

    if [[ "${DB_ENGINE}" == "mariadb" ]]; then
        required="${MARIADB_REQUIRED:-10.11.0}"
        label="MariaDB"
    else
        required="${MYSQL_REQUIRED:-8.4}"
        label="MySQL"
    fi

    if ! version_ge "${version}" "${required}"; then
        die "${label} instalado (${version}) é menor que o requerido pelo Moodle (${required}). Instale um repositório mais novo e execute novamente."
    fi
    log "${label} ativo: ${version}"
}

install_web_servers() {
    log "Instalando Apache, NGINX e Certbot"
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        pkg_install apache2 nginx certbot python3-certbot-nginx
        a2enmod proxy proxy_fcgi setenvif rewrite headers ssl >/dev/null 2>&1 || true
    else
        pkg_install httpd nginx certbot python3-certbot-nginx || pkg_install httpd nginx certbot
    fi
}

ensure_sites_enabled_includes() {
    mkdir -p "${APACHE_SITES_DIR}" "${NGINX_SITES_DIR}"

    if [[ "${OS_FAMILY}" != "debian" ]]; then
        if ! grep -Rq "sites-enabled/\*\.conf" /etc/httpd/conf /etc/httpd/conf.d 2>/dev/null; then
            printf '\nIncludeOptional sites-enabled/*.conf\n' >> /etc/httpd/conf/httpd.conf
        fi
    fi

    if ! grep -Rq "sites-enabled/\*\.conf" /etc/nginx/nginx.conf /etc/nginx/conf.d 2>/dev/null; then
        printf 'include /etc/nginx/sites-enabled/*.conf;\n' > /etc/nginx/conf.d/00-sites-enabled.conf
    fi
}

configure_apache_port() {
    log "Configurando Apache para escutar somente em 127.0.0.1:8080"
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        sed -i -E 's/^[[:space:]]*Listen[[:space:]]+80$/# Listen 80 disabled by Moodle Friendly Installation installer/' /etc/apache2/ports.conf || true
        grep -q '^Listen 127.0.0.1:8080$' /etc/apache2/ports.conf || printf '\nListen 127.0.0.1:8080\n' >> /etc/apache2/ports.conf
        a2dissite 000-default >/dev/null 2>&1 || true
    else
        sed -i -E 's/^[[:space:]]*Listen[[:space:]]+80$/# Listen 80 disabled by Moodle Friendly Installation installer/' /etc/httpd/conf/httpd.conf || true
        grep -q '^Listen 127.0.0.1:8080$' /etc/httpd/conf/httpd.conf || printf '\nListen 127.0.0.1:8080\n' >> /etc/httpd/conf/httpd.conf
        if command_exists semanage; then
            semanage port -a -t http_port_t -p tcp 8080 >/dev/null 2>&1 || semanage port -m -t http_port_t -p tcp 8080 >/dev/null 2>&1 || true
        fi
        setsebool -P httpd_can_network_connect 1 >/dev/null 2>&1 || true
    fi
}

configure_firewall() {
    if command_exists ufw && ufw status | grep -q active; then
        ufw allow 'Nginx Full' || true
    fi
    if systemctl is-active --quiet firewalld; then
        firewall-cmd --permanent --add-service=http || true
        firewall-cmd --permanent --add-service=https || true
        firewall-cmd --reload || true
    fi
}

fetch_panel() {
    log "Instalando painel em ${INSTALL_DIR} a partir de ${REPO_URL} (${REPO_BRANCH})"
    local tmpdir
    tmpdir="$(mktemp -d)"

    if [[ -e "${INSTALL_DIR}" ]]; then
        if [[ "${NONINTERACTIVE}" == "1" || "${FORCE_INSTALL:-0}" == "1" ]]; then
            rm -rf "${INSTALL_DIR}"
        else
            local overwrite=""
            read -r -p "${INSTALL_DIR} já existe. Apagar e reinstalar? (yes/no) [no]: " overwrite
            [[ "${overwrite}" == "yes" ]] || die "Instalação cancelada para não sobrescrever ${INSTALL_DIR}."
            rm -rf "${INSTALL_DIR}"
        fi
    fi

    if git ls-remote --heads "${REPO_URL}" "${REPO_BRANCH}" >/dev/null 2>&1; then
        git clone --depth 1 --branch "${REPO_BRANCH}" "${REPO_URL}" "${tmpdir}/repo" || die "Falha ao clonar ${REPO_URL}."
    else
        warn "Não consegui validar branch via git ls-remote. Tentando baixar tarball pelo GitHub."
        curl -fsSL "https://codeload.github.com/EduardoKrausME/moodle_friendly_installation/tar.gz/refs/heads/${REPO_BRANCH}" -o "${tmpdir}/repo.tar.gz" || die "Falha ao baixar tarball do projeto."
        mkdir -p "${tmpdir}/repo"
        tar -xzf "${tmpdir}/repo.tar.gz" -C "${tmpdir}/repo" --strip-components=1 || die "Falha ao extrair tarball do projeto."
    fi

    [[ -d "${tmpdir}/repo/public" ]] || die "Projeto inválido: não encontrei a pasta public/ na raiz."
    [[ -d "${tmpdir}/repo/bin" ]] || die "Projeto inválido: não encontrei a pasta bin/ na raiz."
    [[ -d "${tmpdir}/repo/templates" ]] || warn "Pasta templates/ não encontrada. O painel pode não conseguir instalar Moodles."

    mkdir -p "${INSTALL_DIR}"
    cp -a "${tmpdir}/repo/." "${INSTALL_DIR}/"
    mkdir -p "${INSTALL_DIR}/data" "${INSTALL_DIR}/runtime" "${INSTALL_DIR}/logs" "${INSTALL_DIR}/queue"
    chmod +x "${INSTALL_DIR}/bin/cron-root-runner.php" "${INSTALL_DIR}/bin/cron-install_moodle.php" "${INSTALL_DIR}/bin/cron-app_build.php" 2>/dev/null || true

    ensure_php_dependencies
}

ensure_php_dependencies() {
    if [[ -f "${INSTALL_DIR}/public/app/vendor/autoload.php" ]]; then
        return 0
    fi

    if [[ ! -f "${INSTALL_DIR}/public/app/composer.json" ]]; then
        warn "Vendor do PHP não encontrado e composer.json também não existe."
        return 0
    fi

    log "Instalando dependências PHP do painel via Composer"
    if ! command_exists composer; then
        if [[ "${OS_FAMILY}" == "debian" ]]; then
            pkg_install composer || true
        else
            pkg_install composer || true
        fi
    fi

    command_exists composer || die "Composer não está instalado e public/app/vendor/autoload.php não existe. Instale composer ou versione vendor/."
    (cd "${INSTALL_DIR}/public/app" && composer install --no-dev --optimize-autoloader --no-interaction) || die "composer install falhou."
}

create_panel_config() {
    log "Gerando public/config.php do painel"
    local app_name base_url mysql_pass phpbin apache_user apache_group apache_sites nginx_sites public_ip default_email
    app_name="$(safe_php_string "Moodle Friendly Installation")"
    base_url="$(safe_php_string "${BASE_URL}")"
    mysql_pass="$(safe_php_string "${DB_ROOT_PASS}")"
    phpbin="$(safe_php_string "${PHP_BIN}")"
    apache_user="$(safe_php_string "${WEB_USER}")"
    apache_group="$(safe_php_string "${WEB_GROUP}")"
    apache_sites="$(safe_php_string "${APACHE_SITES_DIR}")"
    nginx_sites="$(safe_php_string "${NGINX_SITES_DIR}")"
    public_ip="$(safe_php_string "${PUBLIC_IP}")"
    if [[ -n "${PANEL_DOMAIN}" ]]; then
        default_email="admin@${PANEL_DOMAIN}"
    else
        default_email="admin@example.com"
    fi
    default_email="$(safe_php_string "${default_email}")"

    cat > "${INSTALL_DIR}/public/config.php" <<PHP
<?php

// Configuration generated by installation.sh.
return [
    'app_name' => ${app_name},
    'base_url' => ${base_url},
    'base_dir' => realpath(__DIR__ . '/..'),

    'moodle_git_url' => 'https://github.com/moodle/moodle.git',
    'default_moodle_branch' => '${MOODLE_STABLE_BRANCH}',
    'home_base_dir' => '/home',
    'apache_user' => ${apache_user},
    'apache_group' => ${apache_group},
    'php_bin' => ${phpbin},

    'mysql_admin_host' => 'localhost',
    'mysql_admin_port' => 3306,
    'mysql_admin_socket' => null,
    'mysql_admin_user' => 'root',
    'mysql_admin_pass' => ${mysql_pass},

    'apache_sites_enabled' => ${apache_sites},
    'nginx_sites_enabled' => ${nginx_sites},
    'server_public_ips' => [${public_ip}],

    'default_site_fullname_prefix' => 'moodle',
    'default_admin_user' => 'admin',
    'default_admin_email' => ${default_email},

    'reserved_domains' => [
        'admin.moodle.moodle',
    ],
];
PHP
}

create_panel_admin_user() {
    local panel_user panel_pass panel_hash now
    prompt_text panel_user "Usuário admin do painel" "admin"
    prompt_secret panel_pass "Senha admin do painel"
    [[ ${#panel_pass} -ge 8 ]] || die "A senha admin do painel precisa ter ao menos 8 caracteres."

    panel_hash="$(${PHP_BIN} -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "${panel_pass}")"
    now="$(date -Iseconds)"

    python3 - "${INSTALL_DIR}/data/users.json" "${panel_user}" "${panel_hash}" "${now}" <<'PY'
import json
import sys
path, username, password_hash, now = sys.argv[1:]
users = [{
    'username': username,
    'name': 'Administrator',
    'password': password_hash,
    'created_at': now,
}]
with open(path, 'w', encoding='utf-8') as f:
    json.dump(users, f, ensure_ascii=False, indent=2)
    f.write('\n')
PY
}

write_apache_vhost() {
    local file="${APACHE_SITES_DIR}/${SERVER_NAME}.conf"
    local server_for_apache="${PANEL_DOMAIN:-${PUBLIC_IP}}"
    local apache_log_dir="/var/log/httpd"
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        apache_log_dir="/var/log/apache2"
    fi
    cat > "${file}" <<APACHE
<VirtualHost 127.0.0.1:8080>
    ServerName ${server_for_apache}
    DocumentRoot ${INSTALL_DIR}/public

    <Directory ${INSTALL_DIR}>
        Require all denied
    </Directory>

    <Directory ${INSTALL_DIR}/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:${PHP_FPM_SOCKET}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog ${apache_log_dir}/moodle-friendly-installation-error.log
    CustomLog ${apache_log_dir}/moodle-friendly-installation-access.log combined
</VirtualHost>
APACHE
}

write_nginx_vhost() {
    local file="${NGINX_SITES_DIR}/${SERVER_NAME}.conf"
    local listen="listen 80;"
    local server_name="${PANEL_DOMAIN:-_}"
    if [[ -z "${PANEL_DOMAIN}" ]]; then
        listen="listen 80 default_server;"
    fi

    rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf 2>/dev/null || true

    cat > "${file}" <<NGINX
server {
    ${listen}
    server_name ${server_name};

    client_max_body_size 128m;

    location / {
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_read_timeout 300;
        proxy_connect_timeout 300;
        proxy_pass http://127.0.0.1:8080;
    }
}
NGINX
}

set_permissions() {
    log "Ajustando permissões"
    chown -R root:"${WEB_GROUP}" "${INSTALL_DIR}"
    find "${INSTALL_DIR}" -type d -exec chmod 0750 {} \;
    find "${INSTALL_DIR}" -type f -exec chmod 0640 {} \;
    chmod +x "${INSTALL_DIR}/bin/cron-root-runner.php" "${INSTALL_DIR}/bin/cron-install_moodle.php" "${INSTALL_DIR}/bin/cron-app_build.php" 2>/dev/null || true
    chmod 0770 "${INSTALL_DIR}/data" "${INSTALL_DIR}/runtime" "${INSTALL_DIR}/logs" "${INSTALL_DIR}/queue"
    chmod 0640 "${INSTALL_DIR}/public/config.php" "${INSTALL_DIR}/data/users.json"

    if [[ "${OS_FAMILY}" != "debian" ]] && command_exists restorecon; then
        if command_exists semanage; then
            semanage fcontext -a -t httpd_sys_content_t "${INSTALL_DIR}/public(/.*)?" >/dev/null 2>&1 || true
            semanage fcontext -a -t httpd_sys_rw_content_t "${INSTALL_DIR}/(data|runtime|logs|queue)(/.*)?" >/dev/null 2>&1 || true
        fi
        restorecon -R "${INSTALL_DIR}" >/dev/null 2>&1 || true
    fi
}

configure_cron() {
    log "Configurando cron root do painel"
    cat > /etc/cron.d/moodle-friendly-installation-runner <<CRON
* * * * * root ${PHP_BIN} ${INSTALL_DIR}/bin/cron-root-runner.php >>/var/log/moodle-friendly-installation-runner.log 2>&1
CRON
    chmod 0644 /etc/cron.d/moodle-friendly-installation-runner
}

restart_services() {
    log "Validando e reiniciando serviços"
    systemctl enable --now "${PHP_FPM_SERVICE}"
    systemctl enable --now "${APACHE_SERVICE}"
    systemctl enable --now nginx

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        apache2ctl configtest
    else
        httpd -t
    fi
    nginx -t

    systemctl restart "${PHP_FPM_SERVICE}"
    systemctl restart "${APACHE_SERVICE}"
    systemctl restart nginx
}

issue_lets_encrypt() {
    [[ -n "${PANEL_DOMAIN}" ]] || return 0

    prompt_text LE_EMAIL "E-mail para Let's Encrypt" "admin@${PANEL_DOMAIN}"
    [[ "${LE_EMAIL}" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "E-mail inválido para Let's Encrypt."

    log "Emitindo certificado Let's Encrypt para ${PANEL_DOMAIN}"
    certbot --nginx -d "${PANEL_DOMAIN}" --non-interactive --agree-tos -m "${LE_EMAIL}" --redirect || die "Falha ao emitir certificado Let's Encrypt. Confira DNS, porta 80/443 e firewall."
    systemctl reload nginx
}

final_check() {
    local url="${BASE_URL}"
    log "Teste final: ${url}"
    if curl -k -fsSI --max-time 15 "${url}" >/dev/null 2>&1; then
        log "Painel respondendo em ${url}"
    else
        warn "Não consegui validar via curl. Confira: systemctl status nginx ${APACHE_SERVICE} ${PHP_FPM_SERVICE}"
    fi

    cat <<EOF

Instalação concluída.
URL do painel: ${url}
Diretório: ${INSTALL_DIR}
Config: ${INSTALL_DIR}/public/config.php
Cron: /etc/cron.d/moodle-friendly-installation-runner
Log do runner: /var/log/moodle-friendly-installation-runner.log

EOF
}

main() {
    need_root
    detect_os
    install_base_packages
    fetch_moodle_environment
    ask_install_options
    detect_public_ip
    check_dns_before_continue
    install_php
    install_database
    install_web_servers
    ensure_sites_enabled_includes
    configure_apache_port
    configure_firewall
    fetch_panel
    create_panel_config
    create_panel_admin_user
    write_apache_vhost
    write_nginx_vhost
    set_permissions
    configure_cron
    restart_services
    issue_lets_encrypt
    final_check
}

main "$@"
