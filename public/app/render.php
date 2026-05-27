<?php
// Small HTML rendering helpers rendered with php-mustache.
use app\Auth;

/**
 * Function render_header
 *
 * @param string $title
 * @return void
 */
function render_header(string $title): void {
    $appname = (string) app_config('app_name');
    $user = Auth::user();
    $hasuser = (bool) $user;

    echo render_app_template('layout/header', [
        'html_lang' => 'pt-BR',
        'page_title' => $title . ' - ' . $appname,
        'app_name' => $appname,
        'body_class' => $hasuser ? 'has-sidebar' : 'auth-page',
        'has_user' => $hasuser,
        'user_name' => (string) ($user['name'] ?? $user['username'] ?? 'Administrador'),
        'navigation' => render_navigation_items(),
    ]);
}

/**
 * Function render_navigation_items
 *
 * @return array<int, array<string, string>>
 */
function render_navigation_items(): array {
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    $items = [
        [
            'url' => '/',
            'label' => 'Sites',
            'icon' => 'S',
            'active_on' => ['index.php', 'details.php'],
        ],
        [
            'url' => '/install.php',
            'label' => 'Instalar Moodle',
            'icon' => '+',
            'active_on' => ['install.php'],
        ],
        [
            'url' => '/jobs.php',
            'label' => 'Fila',
            'icon' => 'F',
            'active_on' => ['jobs.php'],
        ],
        [
            'url' => '/logout.php',
            'label' => 'Sair',
            'icon' => '×',
            'active_on' => [],
            'extra_class' => 'logout-link',
        ],
    ];

    foreach ($items as $index => $item) {
        $classes = ['side-nav-link'];
        if (in_array($current, $item['active_on'], true)) {
            $classes[] = 'is-active';
        }
        if (!empty($item['extra_class'])) {
            $classes[] = (string) $item['extra_class'];
        }

        $items[$index]['class'] = implode(' ', $classes);
        unset($items[$index]['active_on'], $items[$index]['extra_class']);
    }

    return $items;
}

/**
 * Function render_footer
 *
 * @return void
 */
function render_footer(): void {
    if (Auth::user()) {
        echo "</main></div></body></html>";
        return;
    }

    echo "</main></body></html>";
}

/**
 * Function status_badge
 *
 * @param string $status
 * @param $label
 * @return string
 */
function status_badge(string $status, $label = null): string {
    if ($status == 'waiting_dns') {
        $label = 'Aguardando configuração';
    } else if ($status == 'running') {
        $label = 'Executando agora';
    } else if ($status == 'failed') {
        $label = 'Falhou';
    } else if ($status == 'error') {
        $label = 'Erro';
    } else if ($status == 'active') {
        $label = 'Ativo';
    } else if ($status == 'done') {
        $label = 'Finalizado';
    } else if (!$label) {
        $label = $status;
    }

    $class = match ($status) {
        'done', 'active', 'ok' => 'ok',
        'running' => 'running',
        'waiting_dns', 'warning' => 'warning',
        'failed', 'error', 'danger' => 'danger',
        default => 'muted',
    };

    return render_app_template('status-badge', [
        'class' => $class,
        'label' => (string) $label,
    ]);
}

/**
 * Function flash_message
 *
 * @return string|null
 */
function flash_message(): ?string {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return (string) $message;
}

/**
 * Function render_app_template
 *
 * @param string $template
 * @param array $context
 * @return string
 */
function render_app_template(string $template, array $context = []): string {
    return render_mustache_engine()->render($template, $context);
}

/**
 * Function render_mustache_engine
 *
 * @return \Mustache_Engine
 */
function render_mustache_engine(): Mustache_Engine {
    static $engine = null;

    if ($engine instanceof Mustache_Engine) {
        return $engine;
    }

    $engine = new Mustache_Engine([
        'loader' => new Mustache_Loader_FilesystemLoader(__DIR__ . '/templates'),
        'escape' => static function($value): string {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        },
    ]);

    return $engine;
}
