#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

# Moodle Friendly Installation bootstrap.
# Detects the operating system and delegates the installation to the specific installer.
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/installation.sh | sudo bash

RAW_BASE_URL="https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/install"

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

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

detect_installer() {
    [[ -r /etc/os-release ]] || die "Could not detect the Linux distribution. /etc/os-release was not found."

    # shellcheck source=/dev/null
    source /etc/os-release

    local os_id="${ID:-}"
    local os_like="${ID_LIKE:-}"

    case "${os_id} ${os_like}" in
        *ubuntu*)
            printf 'installation-ubuntu.sh'
            ;;
        *fedora*)
            printf 'installation-fedora.sh'
            ;;
        *centos*|*rhel*|*rocky*|*almalinux*)
            printf 'installation-centos.sh'
            ;;
        *)
            die "Unsupported distribution: ID=${os_id}, ID_LIKE=${os_like}. Supported: Ubuntu, Fedora, CentOS/RHEL/AlmaLinux/Rocky."
            ;;
    esac
}

run_remote_installer() {
    local installer="$1"
    shift || true

    local url="${RAW_BASE_URL}/${installer}"
    log "Detected installer: ${installer}"
    log "Running: curl -fsSL ${url} | sudo bash"

    if [[ "${EUID}" -eq 0 ]]; then
        curl -fsSL "${url}" | bash -s -- "$@"
        return
    fi

    command_exists sudo || die "This installer must run as root and sudo was not found. Run it as root or install sudo."
    curl -fsSL "${url}" | sudo bash -s -- "$@"
}

main() {
    local installer
    installer="$(detect_installer)"
    run_remote_installer "${installer}" "$@"
}

main "$@"
