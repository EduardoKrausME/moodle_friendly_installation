#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'

# Moodle Friendly Installation bootstrap.
# Detects the operating system family and delegates the installation to the family installer.
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/install/installation.sh | sudo bash
#
# When this file is executed from a cloned/unzipped repository, it uses the local
# install/installation-*.sh file. When executed through curl, it downloads the
# family installer to a temporary file and executes it from there.

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

script_dir() {
    local src="${BASH_SOURCE[0]:-}"
    if [[ -n "${src}" && -f "${src}" ]]; then
        cd -- "$(dirname -- "${src}")" >/dev/null 2>&1 && pwd -P
        return 0
    fi
    return 1
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

run_installer_file() {
    local installer_path="$1"
    shift || true

    if [[ "${EUID}" -eq 0 ]]; then
        bash "${installer_path}" "$@"
        return
    fi

    command_exists sudo || die "This installer must run as root and sudo was not found. Run it as root or install sudo."
    sudo bash "${installer_path}" "$@"
}

run_installer() {
    local installer="$1"
    shift || true

    local local_dir=""
    if local_dir="$(script_dir 2>/dev/null || true)" && [[ -n "${local_dir}" && -f "${local_dir}/${installer}" ]]; then
        log "Detected installer: ${installer}"
        log "Running local installer: ${local_dir}/${installer}"
        run_installer_file "${local_dir}/${installer}" "$@"
        return
    fi

    local url="${RAW_BASE_URL}/${installer}"
    local tmpfile=""
    tmpfile="$(mktemp)"

    log "Detected installer: ${installer}"
    log "Downloading installer: ${url}"
    curl -fsSL "${url}" -o "${tmpfile}" || {
        rm -f "${tmpfile}"
        die "Failed to download ${url}"
    }
    chmod +x "${tmpfile}"

    local rc=0
    run_installer_file "${tmpfile}" "$@" || rc=$?
    rm -f "${tmpfile}"
    return "${rc}"
}

main() {
    local installer
    installer="$(detect_installer)"
    run_installer "${installer}" "$@"
}

main "$@"
