#!/usr/bin/env bash
set -Eeuo pipefail

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

log "Creating base directories"
mkdir -p "{{BASE_DIR}}" "{{BASE_DIR}}/moodledata"
chmod -R 777 "{{BASE_DIR}}"

if [ -e "{{BASE_DIR}}/moodle" ] && [ ! -d "{{BASE_DIR}}/moodle/.git" ]; then
    echo "{{BASE_DIR}}/moodle exists but is not a git checkout" >&2
    exit 20
fi

if [ ! -d "{{BASE_DIR}}/moodle/.git" ]; then
    log "Cloning Software Moodle™ {{MOODLE_BRANCH}}"
    git clone --quiet --depth 1 -b "{{MOODLE_BRANCH}}" https://github.com/moodle/moodle.git "{{BASE_DIR}}/moodle"
else
    log "Moodle git checkout already exists. Fetching branch"
    git -C "{{BASE_DIR}}/moodle" fetch --all --prune
    git -C "{{BASE_DIR}}/moodle" checkout "{{MOODLE_BRANCH}}"
    git -C "{{BASE_DIR}}/moodle" pull --ff-only
fi

if [ ! -d "{{BASE_DIR}}/moodle/public" ]; then
    echo "Moodle public directory not found: {{BASE_DIR}}/moodle/public" >&2
    exit 21
fi

log "Writing Moodle config.php"
cat > "{{CONFIG_FILE}}" <<PHP
{{CONFIG_FILE_TEMPLATE}}
PHP

log "Writing web server configs"
mkdir -p "$(dirname "{{APACHE_CONF}}")" "$(dirname "{{NGINX_CONF}}")"
cat > "{{APACHE_CONF}}" <<'APACHECONF'
{{APACHE_TEMPLATE}}
APACHECONF
cat > "{{NGINX_CONF}}" <<'NGINXCONF'
{{NGINX_TEMPLATE}}
NGINXCONF


cd {{BASE_DIR}}/moodle/public
rm -rf mod/bigbluebuttonbn
rm -rf blocks/news_items blocks/rss_client blocks/search_forums blocks/selfcompletion blocks/tag_youtube blocks/accessreview blocks/admin_bookmarks blocks/blog_menu blocks/blog_recent blocks/course_summary blocks/feedback blocks/globalsearch blocks/glossary_random blocks/login blocks/mnet_hosts blocks/myprofile blocks/private_files blocks/social_activities blocks/tag_flickr blocks/tags
rm -rf lib/editor/tiny/plugins/premium lib/editor/tiny/plugins/recordrtc lib/mlbackend/python lib/editor/atto
rm -rf enrol/ldap enrol/lti enrol/meta enrol/mnet enrol/paypal enrol/imsenterprise enrol/database
rm -rf auth/cas auth/oauth2 auth/ldap auth/lti auth/shibboleth
rm -rf media/player/vimeo media/player/youtube media/player/videojs
rm -rf repository/dropbox repository/equella repository/filesystem repository/flickr repository/flickr_public repository/googledocs repository/merlot repository/nextcloud repository/onedrive repository/s3 repository/webdav repository/wikimedia repository/youtube
rm -rf portfolio/flickr portfolio/mahara portfolio/googledocs
rm -rf ai/provider/azureai ai/provider/ollama ai/provider/deepseek
rm -rf sms/gateway/modica
rm -rf quiz/accessrule/seb
rm -rf admin/tool/moodlenet message/output/airnotifier search/engine/solr files/converter/googledrive payment/gateway/paypal

if [ "{{INSTALL_MODE}}" = "install" ]; then
    log "Installing Moodle database"
    if [ ! -f "{{BASE_DIR}}/moodledata/.moodle_friendly_installation-installed" ]; then
        sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" "{{BASE_DIR}}/moodle/admin/cli/install_database.php" \
            --agree-license \
            --fullname="{{SITE_FULLNAME}}" \
            --lang={{MOODLE_LANG}} \
            --shortname="{{DOMAIN}}" \
            --summary="Moodle™ Admin" \
            --adminuser="{{ADMIN_USER}}" \
            --adminpass={{ADMIN_PASS_SH}} \
            --adminemail="{{ADMIN_EMAIL}}"
        touch "{{BASE_DIR}}/moodledata/.moodle_friendly_installation-installed"
    fi
else
    log "Restore mode: skipping Moodle install_database.php"
fi

log "Install Plugins"

git clone --depth 1 https://github.com/EduardoKrausME/moodle-theme_eadtraining              {{BASE_DIR}}/moodle/public/theme/eadtraining
git clone --depth 1 https://github.com/EduardoKrausME/moodle-message_kopereemail            {{BASE_DIR}}/moodle/public/message/output/kopereemail

git clone --depth 1 https://github.com/EduardoKrausME/moodle-local-kopere_dashboard         {{BASE_DIR}}/moodle/public/local/kopere_dashboard
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_kopere_bi                {{BASE_DIR}}/moodle/public/local/kopere_bi
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_boost_dark               {{BASE_DIR}}/moodle/public/local/boost_dark
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_kopere_mobile            {{BASE_DIR}}/moodle/public/local/kopere_mobile
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_copy                     {{BASE_DIR}}/moodle/public/local/copy
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_backupftp                {{BASE_DIR}}/moodle/public/local/backupftp
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_slow_queries             {{BASE_DIR}}/moodle/public/local/slow_queries
git clone --depth 1 https://github.com/EduardoKrausME/moodle-local_alternative_file_system  {{BASE_DIR}}/moodle/public/local/alternative_file_system

git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_supervideo                 {{BASE_DIR}}/moodle/public/mod/supervideo
git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_certificatebeautiful       {{BASE_DIR}}/moodle/public/mod/certificatebeautiful
git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_pdfprotect                 {{BASE_DIR}}/moodle/public/mod/pdfprotect
git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_childcourse                {{BASE_DIR}}/moodle/public/mod/childcourse
git clone --depth 1 https://github.com/EduardoKrausME/moodle-mod_scicalc                    {{BASE_DIR}}/moodle/public/mod/scicalc

if [ "{{INSTALL_MODE}}" = "install" ]; then
    log "Set default info Plugins"
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/upgrade.php                       --non-interactive
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=theme              --set=eadtraining
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=enabledashboard    --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=defaulthomepage    --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=enablemyhome       --set=1
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=navshowallcourses  --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=allowframembedding --set=1
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=autolang           --set=1
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=lang               --set={{MOODLE_LANG}}
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=langmenu           --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=autologinguests    --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=guestloginbutton   --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=passwordpolicy     --set=0
    CRON_REMOTE_PASSWORD=$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32)
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=cronremotepassword --set="$CRON_REMOTE_PASSWORD"
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=cronclionly        --set=0
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=siteadmins         --set=3,2

    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=loglifetime --set=3 --component=backup

    # Hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_policyagreed    --set=1 --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_language        --set={{MOODLE_HUB_LANG}} --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_countrycode     --set=BR --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_privacy         --set=notdisplayed --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_contactemail    --set=kraus@eduardokraus.com --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_contactable     --set=0 --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_emailalert      --set=0 --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_commnews        --set=0 --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_name            --set=. --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_description     --set=. --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_imageurl        --set=. --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_contactphone    --set=. --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_regioncode      --set=- --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_geolocation     --set=. --component=hub
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" {{BASE_DIR}}/moodle/admin/cli/cfg.php --name=site_street          --set=. --component=hub
else
    log "Restore mode: skipping Moodle upgrade/config before database import"
fi

log "SSO file"
cp "{{TEMPLATES_DIR}}/moodle-logar-admin.php"  "{{BASE_DIR}}/moodle/public/"

log "Fixing owner and permissions"
chown -R "{{APACHE_USER}}:{{APACHE_GROUP}}" "{{BASE_DIR}}"
chmod -R 777 "{{BASE_DIR}}"

log "Creating Moodle cron"
cat > "{{CRON_FILE}}" <<EOF
* * * * * {{APACHE_USER}} {{PHP_BIN}} {{BASE_DIR}}/moodle/public/admin/cli/cron.php >/dev/null 2>&1
EOF

log "Testing and reloading services"

if [ -f /etc/debian_version ]; then
    PHP_FPM_SERVICE="php$({{PHP_BIN}} -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')-fpm"
    systemctl reload "$PHP_FPM_SERVICE" || systemctl restart "$PHP_FPM_SERVICE"
    systemctl reload apache2            || systemctl restart apache2
    systemctl reload nginx              || systemctl restart nginx
else
    httpd -t
    nginx -t

    systemctl reload php-fpm || systemctl restart php-fpm
    systemctl reload httpd   || systemctl restart httpd
    systemctl reload nginx   || systemctl restart nginx
fi

if [ "{{ISSUE_CERT}}" = "1" ]; then
    log "Issuing Let's Encrypt certificate"
    certbot --nginx \
        --non-interactive \
        --agree-tos \
        --redirect \
        --email "{{ADMIN_EMAIL}}" \
        -d "{{DOMAIN}}"
fi

if [ "{{INSTALL_MODE}}" = "install" ]; then
    log "Purging Moodle caches"
    sudo -u "{{APACHE_USER}}" "{{PHP_BIN}}" "{{BASE_DIR}}/moodle/admin/cli/purge_caches.php" || true
else
    log "Restore mode: Moodle caches will be purged after database import"
fi

log "Done"
