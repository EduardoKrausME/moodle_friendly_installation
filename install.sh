#!/bin/bash

# curl -s https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/install.sh | bash
# dpkg --configure -a && apt -y -qq -o=Dpkg::Use-Pty=0 remove mysql* php* apache* && apt -y autoremove


# Check if the script is being run as root
if [ "$EUID" -ne 0 ]; then
  echo "This script must be run as root. Please re-run using sudo or as the root user."
  exit 1
fi


# Update the packages
apt update -y -qq -o=Dpkg::Use-Pty=0


# Check if MySql is installed
if ! command -v mysql &> /dev/null; then
    echo "MySql is not installed. Installing now..."

    apt install -y -qq -o=Dpkg::Use-Pty=0 mysql-server

    # Start Service
    systemctl start mysql.service

    # Confirm the installation
    if command -v mysql &> /dev/null; then
        echo "MySql was successfully installed!"
    else
        echo "There was an error installing MySql."
    fi
fi

# Check if PHP is installed
if ! command -v php &> /dev/null; then
    echo "PHP is not installed. Installing now..."

    # Install PHP
    apt install -y php -qq -o=Dpkg::Use-Pty=0 php-{cli,curl,gd,xml,mbstring,mysqli,intl,soap,xmlrpc,zip,bcmath} apache2 libapache2-mod-php

    # To allow for HTTPS traffic, allow the "Apache Full" profile:
    ufw allow 'Apache Full'

    # Then delete the redundant “Apache” profile:
    ufw delete allow 'Apache'

    rm -f /var/www/html/index.html
    rm -f /etc/apache2/sites-enabled/000-default.conf
    rm -f /etc/apache2/mods-enabled/autoindex*

    echo -e "\n\n# Security Settings\nServerTokens Prod\nServerSignature Off\n" | tee -a /etc/apache2/apache2.conf

    # Confirm the installation
    if command -v php &> /dev/null; then
        echo "PHP was successfully installed!"
    else
        echo "There was an error installing PHP."
    fi
fi

# Check if GIT is installed
if ! command -v git &> /dev/null; then
    apt install -y -qq -o=Dpkg::Use-Pty=0 git
fi

# Check if DIALOG is installed
if ! command -v dialog &> /dev/null; then
    apt install -y -qq -o=Dpkg::Use-Pty=0 dialog
fi


# Check if DIALOG is installed
if ! command -v certbot &> /dev/null; then
    apt install -y -qq -o=Dpkg::Use-Pty=0 certbot python3-certbot-apache
fi


curl https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/install-info.php?v2 -o install-info.php
php install-info.php
rm -f install-info.php
