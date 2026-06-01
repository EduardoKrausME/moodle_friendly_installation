#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

# Moodle Friendly Installation panel installer for Fedora.
# This file is normally called by installation.sh.
# Usage: sudo bash installation-fedora.sh [repo-branch]

ENV_URL="https://raw.githubusercontent.com/moodle/moodle/main/public/admin/environment.xml"
INSTALL_DIR="${INSTALL_DIR:-/home/admin.moodle}"
REPO_URL="${REPO_URL:-https://github.com/EduardoKrausME/moodle_friendly_installation.git}"
REPO_BRANCH="${1:-${REPO_BRANCH:-master}}"
NONINTERACTIVE="${NONINTERACTIVE:-0}"
TTY_IN_FD_OPENED=0

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
        die "Run as root: sudo bash $0"
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

open_interactive_terminal() {
    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        return 0
    fi

    if [[ ! -e /dev/tty ]]; then
        die "Interactive installation requires a TTY. Run from SSH/terminal, or use NONINTERACTIVE=1 with DB_ENGINE and PANEL_DOMAIN."
    fi

    # Keep a stable terminal handle. This avoids prompts disappearing or read blocking
    # when the installer itself was started through curl | sudo bash.
    exec 3</dev/tty || die "Could not open /dev/tty for reading."
    exec 4>/dev/tty || die "Could not open /dev/tty for writing."
    TTY_IN_FD_OPENED=1
}

prompt_text() {
    local var_name="$1"
    local label="$2"
    local default_value="${3:-}"
    local value=""

    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        value="${!var_name:-${default_value}}"
    else
        [[ "${TTY_IN_FD_OPENED}" == "1" ]] || open_interactive_terminal

        if [[ -n "${default_value}" ]]; then
            printf '%s [%s]: ' "${label}" "${default_value}" >&4
            if ! IFS= read -r value <&3; then
                die "Could not read input from the terminal."
            fi
            value="${value:-${default_value}}"
        else
            printf '%s: ' "${label}" >&4
            if ! IFS= read -r value <&3; then
                die "Could not read input from the terminal."
            fi
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
        [[ -n "${value}" ]] || die "Variable ${var_name} must be defined when NONINTERACTIVE=1."
    else
        [[ "${TTY_IN_FD_OPENED}" == "1" ]] || open_interactive_terminal
        printf '%s: ' "${label}" >&4
        if ! IFS= read -r -s value <&3; then
            printf '
' >&4
            die "Could not read password from the terminal."
        fi
        printf '
' >&4
    fi

    printf -v "${var_name}" '%s' "${value}"
}

ui_available() {
    [[ "${NONINTERACTIVE}" != "1" ]] && [[ -e /dev/tty ]] && command_exists whiptail
}

prompt_database_select() {
    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        DB_ENGINE="${DB_ENGINE:-mariadb}"
        DB_ENGINE="$(printf '%s' "${DB_ENGINE}" | tr '[:upper:]' '[:lower:]')"
    elif ui_available; then
        local choice=""
        choice="$(whiptail             --title "Moodle Friendly Installation"             --menu "Database to install"             14 78 2             "mariadb" "MariaDB ${MARIADB_REQUIRED:+>= ${MARIADB_REQUIRED}}"             "mysql" "MySQL ${MYSQL_REQUIRED:+>= ${MYSQL_REQUIRED}}"             3>&1 1>&2 2>&3 < /dev/tty)" || die "Database selection cancelled."
        DB_ENGINE="${choice}"
    else
        [[ "${TTY_IN_FD_OPENED}" == "1" ]] || open_interactive_terminal
        local options=("mariadb" "mysql")
        local choice=""
        printf '
Database to install:
' >&4
        PS3="Select an option [1-2]: "
        select choice in "${options[@]}"; do
            case "${choice}" in
                mariadb|mysql)
                    DB_ENGINE="${choice}"
                    break
                    ;;
                *)
                    printf 'Invalid option. Select 1 or 2.
' >&4
                    ;;
            esac
        done <&3 >&4 2>&4
    fi

    case "${DB_ENGINE}" in
        mariadb|mysql) ;;
        *) die "Invalid database option: ${DB_ENGINE}. Use mariadb or mysql." ;;
    esac
}

prompt_domain_box() {
    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        PANEL_DOMAIN="${PANEL_DOMAIN:-}"
    elif ui_available; then
        local value=""
        value="$(whiptail             --title "Moodle Friendly Installation"             --inputbox "Panel domain, or leave blank to use the public IP."             10 78 "${PANEL_DOMAIN:-}"             3>&1 1>&2 2>&3 < /dev/tty)" || die "Domain input cancelled."
        PANEL_DOMAIN="${value}"
    else
        prompt_text PANEL_DOMAIN "Panel domain, or press ENTER to use the public IP" ""
    fi

    PANEL_DOMAIN="$(printf '%s' "${PANEL_DOMAIN}" | tr '[:upper:]' '[:lower:]' | sed -E 's#^https?://##; s#/.*$##; s/:.*$//')"
}

generate_strong_password() {
    python3 - <<'GENPASS'
import random
import secrets

lower = "abcdefghijkmnopqrstuvwxyz"
upper = "ABCDEFGHJKLMNPQRSTUVWXYZ"
digits = "23456789"
symbols = "@#%_-+="
alphabet = lower + upper + digits + symbols
password = [
    secrets.choice(lower),
    secrets.choice(upper),
    secrets.choice(digits),
    secrets.choice(symbols),
]
password.extend(secrets.choice(alphabet) for _ in range(28))
random.SystemRandom().shuffle(password)
print("".join(password))
GENPASS
}

show_password_generated_message() {
    local message="A strong database root password was generated automatically and will be saved in /public/config.php."

    if [[ "${NONINTERACTIVE}" == "1" ]]; then
        log "${message}"
    elif ui_available; then
        whiptail             --title "Moodle Friendly Installation"             --msgbox "${message}"             9 78 < /dev/tty || true
    else
        printf '
%s
' "${message}" >&4
    fi
}

detect_os() {
    [[ -r /etc/os-release ]] || die "Could not detect the Linux distribution."
    # shellcheck source=/dev/null
    source /etc/os-release
    OS_ID="${ID:-}"
    OS_VERSION_ID="${VERSION_ID:-}"
    OS_LIKE="${ID_LIKE:-}"

    if [[ "${OS_ID}" != "fedora" ]]; then
        die "This installer supports Fedora only. Detected: ID=${OS_ID}, ID_LIKE=${OS_LIKE}. Use install-moodle-friendly-installation.sh to auto-select the correct installer."
    fi

    OS_FAMILY="fedora"
    PKG_MANAGER="dnf"
    WEB_USER="apache"
    WEB_GROUP="apache"
    APACHE_SERVICE="httpd"
    APACHE_SITES_DIR="/etc/httpd/sites-enabled"

    log "Detected system: ${OS_ID} ${OS_VERSION_ID} (${OS_FAMILY})"
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
    log "Installing base packages"
    pkg_update
    if [[ "${OS_FAMILY}" == "debian" ]]; then
        pkg_install ca-certificates curl wget gnupg lsb-release software-properties-common unzip tar git dnsutils cron openssl python3 sed grep gawk coreutils debconf-utils whiptail
    else
        pkg_install ca-certificates curl wget gnupg2 unzip tar git bind-utils cronie openssl python3 sed grep gawk coreutils newt policycoreutils-python-utils || pkg_install ca-certificates curl wget gnupg2 unzip tar git bind-utils cronie openssl python3 sed grep gawk coreutils newt
        systemctl enable --now crond >/dev/null 2>&1 || true
    fi
}

fetch_moodle_environment() {
    local tmpxml="/tmp/moodle-environment.xml"
    local outenv="/tmp/moodle-env-requirements.env"

    log "Reading current Moodle requirements from environment.xml"
    curl -fsSL "${ENV_URL}" -o "${tmpxml}" || die "Failed to download ${ENV_URL}"

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

    [[ -n "${MOODLE_VERSION}" ]] || die "Could not detect the Moodle version."
    [[ -n "${PHP_REQUIRED}" ]] || die "Could not detect the required PHP version."
    [[ -n "${MARIADB_REQUIRED}" ]] || warn "Could not detect the required MariaDB version in XML."
    [[ -n "${MYSQL_REQUIRED}" ]] || warn "Could not detect the required MySQL version in XML."

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
    log "Moodle ${MOODLE_VERSION} requires PHP >= ${PHP_REQUIRED}, MariaDB >= ${MARIADB_REQUIRED:-?}, MySQL >= ${MYSQL_REQUIRED:-?}"
}

ask_install_options() {
    log "Starting interactive installer questions"

    prompt_database_select
    prompt_domain_box

    if [[ -n "${DB_ROOT_PASS:-}" ]]; then
        warn "DB_ROOT_PASS was already defined; using the provided value instead of generating a new one."
    else
        DB_ROOT_PASS="$(generate_strong_password)"
    fi

    [[ ${#DB_ROOT_PASS} -ge 16 ]] || die "The generated database root password is unexpectedly short."
    [[ "${DB_ROOT_PASS}" =~ [[:upper:]] ]] || die "The generated database root password must contain at least one uppercase letter."
    [[ "${DB_ROOT_PASS}" =~ [[:lower:]] ]] || die "The generated database root password must contain at least one lowercase letter."
    [[ "${DB_ROOT_PASS}" =~ [[:digit:]] ]] || die "The generated database root password must contain at least one number."
    [[ "${DB_ROOT_PASS}" =~ [^[:alnum:]] ]] || die "The generated database root password must contain at least one special character."

    show_password_generated_message
}

detect_public_ip() {
    PUBLIC_IP="$(curl -fsSL --max-time 8 https://api.ipify.org 2>/dev/null || true)"
    if [[ -z "${PUBLIC_IP}" ]]; then
        PUBLIC_IP="$(curl -fsSL --max-time 8 https://ifconfig.me/ip 2>/dev/null || true)"
    fi
    if [[ -z "${PUBLIC_IP}" ]]; then
        PUBLIC_IP="$(hostname -I | awk '{print $1}')"
    fi
    [[ -n "${PUBLIC_IP}" ]] || die "Could not detect the public IP."
    log "Detected public IP: ${PUBLIC_IP}"
}

check_dns_before_continue() {
    if [[ -z "${PANEL_DOMAIN}" ]]; then
        SERVER_NAME="_"
        BASE_URL="http://${PUBLIC_IP}"
        log "The panel installation will be available by IP: ${BASE_URL}"
        return
    fi

    local resolved=""
    resolved="$(dig +short A "${PANEL_DOMAIN}" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | sort -u | tr '\n' ' ' | sed 's/[[:space:]]*$//')"
    if [[ -z "${resolved}" ]]; then
        die "The domain ${PANEL_DOMAIN} does not have a published A record yet. Point DNS to ${PUBLIC_IP} and run this installer again."
    fi

    if ! printf ' %s ' "${resolved}" | grep -q " ${PUBLIC_IP} "; then
        die "DNS mismatch for ${PANEL_DOMAIN}. Resolved: ${resolved}. Expected: ${PUBLIC_IP}. Fix DNS and run this installer again."
    fi

    SERVER_NAME="${PANEL_DOMAIN}"
    BASE_URL="https://${PANEL_DOMAIN}"
    log "DNS verified: ${PANEL_DOMAIN} points to ${PUBLIC_IP}"
}

install_php() {
    log "Installing PHP ${PHP_SERIES} and extensions used by Moodle/the panel"

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        if ! apt-cache show "php${PHP_SERIES}-fpm" >/dev/null 2>&1; then
            log "The default repository did not find php${PHP_SERIES}; adding ondrej/php PPA"
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
                die "CentOS/RHEL ${OS_VERSION_ID} is not supported for modern PHP. Use CentOS Stream/RHEL/Alma/Rocky 8+ or Fedora."
            fi

            pkg_install epel-release || true
            if ! rpm -q remi-release >/dev/null 2>&1; then
                rpm -Uvh "https://rpms.remirepo.net/enterprise/remi-release-${major}.rpm" || warn "Could not install remi-release automatically. Trying the default repository."
            fi
            ${PKG_MANAGER} module reset -y php || true
            ${PKG_MANAGER} module enable -y "php:remi-${PHP_SERIES}" || warn "Could not enable php:remi-${PHP_SERIES}; trying the default package."
        fi

        pkg_install php php-cli php-fpm php-mysqlnd php-curl php-xml php-mbstring php-zip php-gd php-intl php-soap php-opcache php-bcmath php-ldap
        PHP_BIN="/usr/bin/php"
        PHP_FPM_SERVICE="php-fpm"
        PHP_FPM_SOCKET="/run/php-fpm/www.sock"
    fi

    command_exists "${PHP_BIN}" || die "PHP was not found at ${PHP_BIN}."
    local php_current=""
    php_current="$(${PHP_BIN} -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    [[ -n "${php_current}" ]] || die "Could not read the installed PHP version."
    version_ge "${php_current}" "${PHP_REQUIRED}" || die "Installed PHP (${php_current}) is lower than the Moodle requirement (${PHP_REQUIRED})."

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
    log "Installing MariaDB >= ${MARIADB_REQUIRED:-10.11}"
    local wanted="${MARIADB_REQUIRED:-10.11.0}"
    local series="$(printf '%s' "${wanted}" | awk -F. '{print $1"."$2}')"

    if curl -fsSL https://r.mariadb.com/downloads/mariadb_repo_setup -o /tmp/mariadb_repo_setup; then
        chmod +x /tmp/mariadb_repo_setup
        bash /tmp/mariadb_repo_setup --mariadb-server-version="mariadb-${series}" || warn "Failed to configure MariaDB ${series} repository; trying the distribution repository."
        pkg_update
    else
        warn "Could not download mariadb_repo_setup; trying the distribution repository."
    fi

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        pkg_install mariadb-server mariadb-client
    else
        pkg_install MariaDB-server MariaDB-client || pkg_install mariadb-server mariadb
    fi

    DB_SERVICE="mariadb"
    systemctl enable --now mariadb || systemctl enable --now mysql
}

setup_mysql_apt_repository() {
    # Moodle 5.2+ currently requires MySQL 8.4. Ubuntu LTS repositories may still provide MySQL 8.0,
    # so use Oracle's APT repository when the user chooses MySQL.
    [[ "${OS_FAMILY}" == "debian" ]] || return 0

    local codename=""
    codename="$(lsb_release -sc 2>/dev/null || true)"
    if [[ -z "${codename}" && -r /etc/os-release ]]; then
        # shellcheck source=/dev/null
        source /etc/os-release
        codename="${VERSION_CODENAME:-}"
    fi
    [[ -n "${codename}" ]] || die "Could not detect the Ubuntu codename for the MySQL APT repository."

    log "Configuring the official MySQL APT repository for MySQL ${MYSQL_REQUIRED:-8.4}"
    mkdir -p /usr/share/keyrings
    local key_tmp="/tmp/mysql-gpg-key.asc"
    if ! curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 -o "${key_tmp}"; then
        curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2022 -o "${key_tmp}" || die "Could not download the MySQL repository GPG key."
    fi
    gpg --dearmor < "${key_tmp}" > /usr/share/keyrings/mysql.gpg
    chmod 0644 /usr/share/keyrings/mysql.gpg

    cat > /etc/apt/sources.list.d/mysql-community.list <<MYSQLAPT
# Added by Moodle Friendly Installation installer.
deb [signed-by=/usr/share/keyrings/mysql.gpg] http://repo.mysql.com/apt/ubuntu/ ${codename} mysql-8.4-lts mysql-tools
MYSQLAPT
    apt-get update -y || die "Failed to update APT metadata after enabling the MySQL repository."
}

setup_mysql_yum_repository() {
    [[ "${OS_FAMILY}" == "rhel" || "${OS_FAMILY}" == "fedora" ]] || return 0

    local major="${OS_VERSION_ID%%.*}"
    local platform=""
    if [[ "${OS_FAMILY}" == "fedora" ]]; then
        platform="fc${major}"
    else
        platform="el${major}"
        ${PKG_MANAGER} -y module disable mysql >/dev/null 2>&1 || true
    fi

    log "Configuring the official MySQL Yum/DNF repository for MySQL ${MYSQL_REQUIRED:-8.4}"
    pkg_install "https://repo.mysql.com/mysql84-community-release-${platform}-1.noarch.rpm" || warn "Could not install the MySQL repository RPM for ${platform}; trying the distribution repository."
}

install_mysql() {
    log "Installing MySQL >= ${MYSQL_REQUIRED:-8.4}"

    if [[ "${OS_FAMILY}" == "debian" ]]; then
        setup_mysql_apt_repository
        if command_exists debconf-set-selections; then
            printf 'mysql-community-server mysql-community-server/root-pass password %s\n' "${DB_ROOT_PASS}" | debconf-set-selections || true
            printf 'mysql-community-server mysql-community-server/re-root-pass password %s\n' "${DB_ROOT_PASS}" | debconf-set-selections || true
        fi
        pkg_install mysql-server mysql-client || pkg_install default-mysql-server default-mysql-client
        DB_SERVICE="mysql"
        systemctl enable --now mysql || systemctl enable --now mysqld
    else
        setup_mysql_yum_repository
        pkg_install mysql-community-server mysql-community-client || pkg_install mysql-server mysql || pkg_install community-mysql-server community-mysql
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
    log "Configuring the database root password"
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
        run_mysql_socket -e "${sql}" || warn "Could not apply full hardening through the socket connection."
    elif run_mysql_password -e "SELECT 1" >/dev/null 2>&1; then
        run_mysql_password -e "${sql}" || warn "Could not apply full hardening through the password connection."
    else
        local current=""
        local client
        client="$(mysql_client)" || die "MySQL/MariaDB client was not found."

        if [[ "${DB_ENGINE}" == "mysql" && -f /var/log/mysqld.log ]]; then
            current="$(grep -i 'temporary password' /var/log/mysqld.log 2>/dev/null | tail -n1 | awk '{print $NF}' || true)"
            if [[ -n "${current}" ]]; then
                if MYSQL_PWD="${current}" "${client}" --connect-expired-password -uroot -e "${sql}" >/dev/null 2>&1; then
                    return 0
                fi
            fi
        fi

        if [[ "${NONINTERACTIVE}" == "1" ]]; then
            die "Could not authenticate to the database as root to apply the password."
        fi
        [[ -r /dev/tty ]] || die "Could not authenticate to the database and there is no terminal to ask the current root password."
        printf 'Current database root password, if one already exists: ' > /dev/tty
        if ! IFS= read -r -s current < /dev/tty; then
            printf '\n' > /dev/tty
            die "Could not read the current database root password."
        fi
        printf '\n' > /dev/tty
        MYSQL_PWD="${current}" "${client}" --connect-expired-password -uroot -e "${sql}" || die "Failed to configure the database root password."
    fi
}

validate_database_version() {
    local client version required label raw_version
    client="$(mysql_client)" || die "MySQL/MariaDB client was not found."
    raw_version="$(${client} --version || true)"

    if [[ "${DB_ENGINE}" == "mariadb" ]]; then
        required="${MARIADB_REQUIRED:-10.11.0}"
        label="MariaDB"
        version="$(printf '%s' "${raw_version}" | grep -oE 'Distrib[[:space:]]+[0-9]+(\.[0-9]+){1,2}' | grep -oE '[0-9]+(\.[0-9]+){1,2}' | head -n1 || true)"
        if [[ -z "${version}" ]]; then
            version="$(printf '%s' "${raw_version}" | grep -oE '[0-9]+(\.[0-9]+){1,2}[^[:space:]]*-MariaDB' | grep -oE '^[0-9]+(\.[0-9]+){1,2}' | head -n1 || true)"
        fi
    else
        required="${MYSQL_REQUIRED:-8.4}"
        label="MySQL"
        version="$(printf '%s' "${raw_version}" | grep -oE '[0-9]+(\.[0-9]+){1,2}' | head -n1 || true)"
    fi

    [[ -n "${version}" ]] || die "Could not validate the database version. Raw output: ${raw_version}"
    if ! version_ge "${version}" "${required}"; then
        die "Installed ${label} (${version}) is lower than the Moodle requirement (${required}). Install a newer repository and run this installer again."
    fi
    log "${label} active: ${version}"
}

install_web_servers() {
    log "Installing Apache, NGINX and Certbot"
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
    log "Configuring Apache to listen only on 127.0.0.1:8080"
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
    log "Installing the panel into ${INSTALL_DIR} from ${REPO_URL} (${REPO_BRANCH})"
    local tmpdir
    tmpdir="$(mktemp -d)"

    if [[ -e "${INSTALL_DIR}" ]]; then
        if [[ "${NONINTERACTIVE}" == "1" || "${FORCE_INSTALL:-0}" == "1" ]]; then
            rm -rf "${INSTALL_DIR}"
        else
            local overwrite=""
            prompt_text overwrite "${INSTALL_DIR} already exists. Delete and reinstall? (yes/no)" "no"
            [[ "${overwrite}" == "yes" ]] || die "Installation canceled to avoid overwriting ${INSTALL_DIR}."
            rm -rf "${INSTALL_DIR}"
        fi
    fi

    if git ls-remote --heads "${REPO_URL}" "${REPO_BRANCH}" >/dev/null 2>&1; then
        git clone --depth 1 --branch "${REPO_BRANCH}" "${REPO_URL}" "${tmpdir}/repo" || die "Failed to clone ${REPO_URL}."
    else
        warn "Could not validate the branch through git ls-remote. Trying to download the GitHub tarball."
        curl -fsSL "https://codeload.github.com/EduardoKrausME/moodle_friendly_installation/tar.gz/refs/heads/${REPO_BRANCH}" -o "${tmpdir}/repo.tar.gz" || die "Failed to download the project tarball."
        mkdir -p "${tmpdir}/repo"
        tar -xzf "${tmpdir}/repo.tar.gz" -C "${tmpdir}/repo" --strip-components=1 || die "Failed to extract the project tarball."
    fi

    [[ -d "${tmpdir}/repo/public" ]] || die "Invalid project: public/ folder was not found at the repository root."
    [[ -d "${tmpdir}/repo/bin" ]] || die "Invalid project: bin/ folder was not found at the repository root."
    [[ -d "${tmpdir}/repo/templates" ]] || warn "templates/ folder was not found. The panel may not be able to install Moodle sites."

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
        warn "PHP vendor was not found and composer.json does not exist either."
        return 0
    fi

    log "Installing panel PHP dependencies through Composer"
    if ! command_exists composer; then
        if [[ "${OS_FAMILY}" == "debian" ]]; then
            pkg_install composer || true
        else
            pkg_install composer || true
        fi
    fi

    command_exists composer || die "Composer is not installed and public/app/vendor/autoload.php does not exist. Install Composer or commit the vendor/ directory."
    (cd "${INSTALL_DIR}/public/app" && composer install --no-dev --optimize-autoloader --no-interaction) || die "composer install failed."
}

create_panel_config() {
    log "Generating panel public/config.php"
    if [[ -f "${INSTALL_DIR}/public/config-example.php" && ! -f "${INSTALL_DIR}/public/config.php" ]]; then
        mv "${INSTALL_DIR}/public/config-example.php" "${INSTALL_DIR}/public/config.php"
    fi

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

// Configuration generated by Moodle Friendly Installation installer.
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
    prompt_text panel_user "Panel admin username" "admin"
    prompt_secret panel_pass "Panel admin password"
    [[ ${#panel_pass} -ge 8 ]] || die "The panel admin password must have at least 8 characters."

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
    log "Adjusting permissions"
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
    log "Configuring the panel root cron"
    cat > /etc/cron.d/moodle-friendly-installation-runner <<CRON
* * * * * root ${PHP_BIN} ${INSTALL_DIR}/bin/cron-root-runner.php >>/var/log/moodle-friendly-installation-runner.log 2>&1
CRON
    chmod 0644 /etc/cron.d/moodle-friendly-installation-runner
}

restart_services() {
    log "Validating and restarting services"
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
    [[ "${LE_EMAIL}" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "Invalid Let's Encrypt email."

    log "Issuing Let's Encrypt certificate for ${PANEL_DOMAIN}"
    certbot --nginx -d "${PANEL_DOMAIN}" --non-interactive --agree-tos -m "${LE_EMAIL}" --redirect || die "Failed to issue the Let's Encrypt certificate. Check DNS, ports 80/443 and firewall."
    systemctl reload nginx
}

final_check() {
    local url="${BASE_URL}"
    log "Final test: ${url}"
    if curl -k -fsSI --max-time 15 "${url}" >/dev/null 2>&1; then
        log "Panel is responding at ${url}"
    else
        warn "Could not validate through curl. Check: systemctl status nginx ${APACHE_SERVICE} ${PHP_FPM_SERVICE}"
    fi

    cat <<EOF

Installation completed.
Panel URL: ${url}
Directory: ${INSTALL_DIR}
Config: ${INSTALL_DIR}/public/config.php
Cron: /etc/cron.d/moodle-friendly-installation-runner
Runner log: /var/log/moodle-friendly-installation-runner.log

EOF
}

main() {
    need_root
    detect_os
    open_interactive_terminal
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
