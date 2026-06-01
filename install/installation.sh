#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

# Moodle Friendly Installation bootstrap.
# Clones the full project into /home/admin.moodle and delegates the installation
# to the local Debian/Ubuntu or RedHat/Fedora installer.
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/install/installation.sh | sudo bash

REPO_URL="${REPO_URL:-https://github.com/EduardoKrausME/moodle_friendly_installation.git}"
REPO_BRANCH="${1:-${REPO_BRANCH:-master}}"

log() {
    printf '\n\n\n\033[1;32m==>\033[0m %s\n' "$*"
}

warn() {
    printf '\n\n\n\033[1;33mWARN:\033[0m %s\n' "$*" >&2
}

die() {
    printf '\n\n\n\033[1;31mERROR:\033[0m %s\n' "$*" >&2
    exit 1
}

need_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        die "Run as root: curl -fsSL <url> | sudo bash"
    fi
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

detect_package_manager() {
    if command_exists apt-get; then
        printf 'apt-get'
    elif command_exists dnf; then
        printf 'dnf'
    elif command_exists yum; then
        printf 'yum'
    else
        die "Could not find apt-get, dnf or yum to install bootstrap dependencies."
    fi
}

install_bootstrap_dependencies() {
    local pkg_manager
    pkg_manager="$(detect_package_manager)"

    if command_exists git && command_exists curl; then
        return 0
    fi

    log "Installing bootstrap dependencies"
    case "${pkg_manager}" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get update
            apt-get install -y git curl ca-certificates
            ;;
        dnf)
            dnf install -y git curl ca-certificates
            ;;
        yum)
            yum install -y git curl ca-certificates
            ;;
    esac
}

detect_installer() {
    [[ -r /etc/os-release ]] || die "Could not detect the Linux distribution. /etc/os-release was not found."

    # shellcheck source=/dev/null
    source /etc/os-release

    local os_id="${ID:-}"
    local os_like="${ID_LIKE:-}"

    case "${os_id} ${os_like}" in
        *debian*|*ubuntu*)
            printf 'installation-debian.sh'
            ;;
        *fedora*|*centos*|*rhel*|*rocky*|*almalinux*)
            printf 'installation-redhat.sh'
            ;;
        *)
            die "Unsupported distribution: ID=${os_id}, ID_LIKE=${os_like}. Supported families: Debian/Ubuntu and Fedora/CentOS/RHEL/AlmaLinux/Rocky."
            ;;
    esac
}

clone_project() {
    log "Cloning the project into /home/admin.moodle from ${REPO_URL} (${REPO_BRANCH})"

    local tmpdir=""
    tmpdir="$(mktemp -d)"
    local config_backup="${tmpdir}/config.php"
    local users_backup="${tmpdir}/users.json"

    if [[ -f "/home/admin.moodle/public/config.php" ]]; then
        cp -a "/home/admin.moodle/public/config.php" "${config_backup}"
    fi
    if [[ -f "/home/admin.moodle/data/users.json" ]]; then
        cp -a "/home/admin.moodle/data/users.json" "${users_backup}"
    fi

    if [[ -d "/home/admin.moodle/.git" ]]; then
        log "Updating existing Git checkout in /home/admin.moodle"
        git -C "/home/admin.moodle" remote set-url origin "${REPO_URL}" || true
        git -C "/home/admin.moodle" fetch --depth 1 origin "${REPO_BRANCH}" || die "Failed to fetch ${REPO_BRANCH} from ${REPO_URL}."
        git -C "/home/admin.moodle" checkout -B "${REPO_BRANCH}" FETCH_HEAD || die "Failed to checkout ${REPO_BRANCH}."
        git -C "/home/admin.moodle" reset --hard FETCH_HEAD || die "Failed to reset /home/admin.moodle."
    else
        if [[ -e "/home/admin.moodle" ]]; then
            local backup_dir="/home/admin.moodle.backup.$(date +%Y%m%d%H%M%S)"
            warn "/home/admin.moodle already exists and is not a Git checkout. Moving it to ${backup_dir}."
            mv "/home/admin.moodle" "${backup_dir}"
        fi

        mkdir -p "$(dirname "/home/admin.moodle")"
        git clone --depth 1 --branch "${REPO_BRANCH}" "${REPO_URL}" "/home/admin.moodle" || die "Failed to clone ${REPO_URL}."
    fi

    if [[ -f "${config_backup}" ]]; then
        mkdir -p "/home/admin.moodle/public"
        cp -a "${config_backup}" "/home/admin.moodle/public/config.php"
        log "Preserved existing public/config.php"
    fi
    if [[ -f "${users_backup}" ]]; then
        mkdir -p "/home/admin.moodle/data"
        cp -a "${users_backup}" "/home/admin.moodle/data/users.json"
        log "Preserved existing data/users.json"
    fi

    [[ -f "/home/admin.moodle/public/app/bootstrap.php" ]] || die "Invalid project clone: public/app/bootstrap.php was not found."
    [[ -d "/home/admin.moodle/install" ]] || die "Invalid project clone: install/ folder was not found."
}

run_family_installer() {
    local installer="$1"
    local installer_path="/home/admin.moodle/install/${installer}"

    [[ -f "${installer_path}" ]] || die "Installer file not found: ${installer_path}"
    chmod +x "${installer_path}"

    export REPO_URL REPO_BRANCH
    log "Running ${installer_path}"
    exec bash "${installer_path}" "${REPO_BRANCH}"
}

main() {
    need_root
    install_bootstrap_dependencies

    local installer
    installer="$(detect_installer)"

    clone_project
    run_family_installer "${installer}"
}

main "$@"
