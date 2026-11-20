# Changelog

This changelog records verified changes in published GitHub releases. Each entry compares the release tag with the immediately preceding published release; changes made after the tag are excluded. Earlier releases are available in GitHub's release history.

## 2.6.2 - 2026-11-16

- Added a post-install Moodle CLI configuration script and migration for managed site routing settings.
- Added managed Moodle plugin Git linking, plugin upgrade execution jobs and related checks.
- Extended mobile app management to synchronize LearnLingo branding, generate application icons from the site logo and improve the embedded preview.
- Refined installation templates, application build configuration and administration interfaces.

**Comparison:** [2.6.1...2.6.2](https://github.com/EduardoKrausME/moodle_friendly_installation/compare/2.6.1...2.6.2)

## 2.6.1 - 2026-11-11

- Introduced an upgrade migration for existing Moodle web-server configurations and updated installer NGINX templates.
- Changed the default installation theme to LearnLingo and removed the old manual theme-color selection from the installation interface.
- Kept resource-usage jobs visible in the jobs page only while pending or running.
- Adjusted debug configuration and added migration regression coverage.

**Comparison:** [2.6.0...2.6.1](https://github.com/EduardoKrausME/moodle_friendly_installation/compare/2.6.0...2.6.1)

## 2.6.0 - 2026-10-31

- Installed Moodle Composer dependencies before running installation CLI scripts, logging but not failing installation when Composer fails.
- Added upgrade migration handling for managed Moodle installations and updated PHP-FPM socket detection.
- Added migration and Composer regression tests.

**Comparison:** [2.5.5...2.6.0](https://github.com/EduardoKrausME/moodle_friendly_installation/compare/2.5.5...2.6.0)

## 2.5.5 - 2026-10-30

- Detected active PHP-FPM sockets for managed Apache Moodle virtual hosts, including migration of existing configurations.
- Improved Debian/Ubuntu installer prerequisites, PHP repository handling and Certbot execution paths.
- Generated administrator single sign-on links on demand rather than embedding reusable links in the site details page.
- Added socket-migration tests and corrected installer configuration defaults.

**Comparison:** [2.5.3...2.5.5](https://github.com/EduardoKrausME/moodle_friendly_installation/compare/2.5.3...2.5.5)
