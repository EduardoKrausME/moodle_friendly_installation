# Install Software Moodle™ Admin

## Installation

Run the command below in the terminal as `root` or using `sudo`:

```bash
curl -fsSL https://raw.githubusercontent.com/EduardoKrausME/moodle_friendly_installation/refs/heads/master/install/installation.sh -o i.sh && chmod +x i.sh && ./i.sh
```

## The panel

Panel for managing Moodle™ installations on a private server. The idea is to have a central screen to create new Moodle™ instances, monitor the installation , open already installed environments, run a quick diagnosis, and generate the Android APP for each domain once everything is configured.

The project was designed for a server where each Moodle™ is located inside `/home/[domain]`, and the panel does not install anything directly through the web interface. It only creates jobs, while the heavy actions are executed by CRON running as `root`, which avoids leaving dangerous permissions assigned to the Apache/PHP-FPM user.

## What the panel does

- Lists the Moodle™ installations found on the server.
- Reads basic data from each Moodle™ `config.php`.
- Shows diagnostics for DNS, SSL, NGINX/Apache, debug mode, and control flags.
- Creates a new Moodle™ installation through a queue.
- Generates server configuration files using templates.
- Installs the default plugins defined in `templates/install-moodle.sh`.
- Allows administrative access through an SSO file generated inside Moodle™.
- Has a multilingual interface using the files in `public/app/lang/`.

If APP support is available:

- Configures the Android APP for each Moodle™.
- Validates a 1024x1024 PNG icon before saving.
- Generates the Android keystore during the first APP configuration.
- Generates APK and AAB files through the server queue.

## Server requirements

The panel uses PHP and simple JSON files to store users, queue data, and settings. To install Moodle™ and generate the APP, the server also needs the tools used by the scripts.

At minimum, check:

- PHP CLI and PHP-FPM working.
- PHP extensions required by Moodle™.
- Git.
- MySQL/MariaDB with administrative access configured.
- NGINX and/or Apache, depending on the templates used on the server.
- Certbot, if Let’s Encrypt certificates will be issued through the panel.
- Composer already installed or the PHP dependencies already present.
- Node.js, NPM, Cordova, Android SDK, Gradle, and Java 17 to generate APK/AAB.
- ImageMagick with the `magick` command, used to generate the APP icons and screens.

For Cordova Android 15, use Java 17. On recent Fedora versions, it is usually better to use Temurin 17 when the `java-17-openjdk-devel` package is not available.

## Initial configuration

Rename the file `config-example.php` to `config.php` and edit the file. The most important points are:

```php
"base_url" => "https://admin.moodle",
"base_dir" => realpath(__DIR__ . "/.."),
"apache_user" => "apache",
"apache_group" => "apache",
"php_bin" => "/usr/bin/php",
"mysql_admin_host" => "localhost",
"mysql_admin_user" => "root",
"mysql_admin_pass" => "",
````

Adjust these paths according to the server. The panel was written assuming that Moodle™ installations are located in `/home/[domain]/moodle` and Moodledata in `/home/[domain]/moodledata`.

## Creating the first user

The login reads users from `data/users.json`. If the file does not exist yet, create it manually outside `public/`:

```json
[
  {
    "username": "admin",
    "name": "Administrator",
    "password": "change-this-password"
  }
]
```

On the first login, if the password is in plain text, the panel itself replaces it with `password_hash()`. Even so, use a strong password from the beginning and keep the `data/` folder outside the public area. If you lose the password, simply replace the hash with a plain-text password.

## CRON root runner

The panel creates jobs, but the file `bin/cron-root-runner.php` is responsible for executing them. There is also an example in `bin/install-root-cron.example`.

The base line is:

```
* * * * * root /usr/bin/php /home/admin.moodle/bin/cron-root-runner.php >/var/log/moodle-admin-runner.log 2>&1
```

Adjust the project path before placing it in `/etc/cron.d/admin-runner`.

This runner executes one pending job at a time and uses a lock to prevent two simultaneous executions.

## Software Moodle™ installation

On the “Install Moodle” screen, the panel creates a job with the domain, branch, admin user, password, email, and certificate option.

The script that performs the actual installation is `templates/install-moodle.sh`. It clones Moodle, writes the `config.php`, creates the NGINX/Apache files, installs the database through Moodle™ CLI, removes some native plugins that will not be used, and then installs the default plugins for the environment.

Before using this in production, carefully review the list of plugins and removals. It represents one installation opinion, not a universal rule for every Moodle.

## Software Moodle™ diagnostics

The details screen tries to check:

* Moodle™ `config.php`.
* Basic database data.
* Domain DNS.
* SSL certificate.
* NGINX and Apache files.
* Control flags created in `/home/[DOMAIN]`.
* Debug and maintenance mode.

The database password is masked on the screen.

## Android APP

Each Moodle™ can have its own APP configuration. The panel saves the data by domain and uses the `Package UID` to organize resources in:

```
app-MoodleMobile-V2/res/[Package UID]/
```

The icon must be a PNG file with exactly 1024x1024 pixels. After the `Package UID` is saved for the first time, it becomes locked to avoid accidentally changing the application identity.

When there is no keystore yet for that package, the form asks for the Android key password. After the key is created, the password is not requested again.

The Android files are stored in:

```
app-MoodleMobile-V2/res/[Package UID]/key-android/
```

The build generates APK and AAB files through the queue, using Cordova Android 15.

## Languages

The panel texts are stored in:

```
public/app/lang/
```

Each language is a PHP file returning an array. The `meta.flag` key stores the SVG or PNG flag link used by the language selector.

Example:

```php
'meta' => [
    'name' => 'Portuguese (Brazil)',
    'native_name' => 'Português',
    'html_lang' => 'pt-BR',
    'flag' => 'https://flagcdn.com/br.svg',
],
```

The selector appears on the login screen and also in the logged-in user area. The choice is stored in the session and cookie. It can also be changed directly through the URL:

```
?lang=pt_br
?lang=en
?lang=es
```

To add another language, copy an existing file in `public/app/lang/`, translate the texts, and adjust the `meta` block.

## Logs and generated files

During use, the project creates files in folders such as:

```
data/
data/logs/
data/queue/
data/runtime/
```

These folders do not need to be inside `public/`. The panel creates whatever is necessary, as long as the PHP user has write permission where it needs to write, and the root CRON has access to execute the jobs.

## Normal usage flow

After configuring the server, the expected usage is simple: access the panel, create a Moodle™ installation, monitor the job in the queue, open the domain details, and check DNS/SSL/configuration. Once Moodle™ is ready, the APP screen allows you to configure the package, name, color, icon, and generate APK/AAB.

If something fails, first check the queue screen and then the files in `data/logs/`. Almost always, the real error appears there: DNS not yet pointed, folder permission issue, missing package on the server, Certbot failure, or incomplete Android environment.