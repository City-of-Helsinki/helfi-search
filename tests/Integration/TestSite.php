<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Component\Filesystem\Filesystem;

/**
 * A fake website, served with PHP's built-in web server from a temporary
 * folder. Tests change its pages between crawls.
 */
final class TestSite
{
    public readonly string $url;
    private readonly string $root;
    private readonly string $log;

    /** @var resource */
    private $server;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/helfi-search-site-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        $this->log = $this->root . '.log';

        // Any free port.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new \RuntimeException('Cannot find a free port.');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->url = "http://127.0.0.1:$port";

        $server = proc_open(
            ['php', '-S', "127.0.0.1:$port", '-t', $this->root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']],
            $pipes,
            null,
            // The crawler fetches several pages at once.
            ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
        );
        if ($server === false) {
            throw new \RuntimeException('Cannot start the test site.');
        }
        $this->server = $server;

        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('The test site did not start: ' . file_get_contents($this->log));
    }

    public function stop(): void
    {
        proc_terminate($this->server);
        proc_close($this->server);
        (new Filesystem())->remove([$this->root, $this->log]);
    }

    /**
     * The full URL of a page, e.g. url('/page-a.html').
     */
    public function pageUrl(string $path): string
    {
        return $this->url . $path;
    }

    public function write(string $path, string $html): void
    {
        file_put_contents($this->root . $path, $html);
    }

    public function remove(string $path): void
    {
        unlink($this->root . $path);
    }

    /**
     * A page in the hel.fi layout: header, navigation, main content, sidebar
     * and footer. Only the title, meta tags and main content should be
     * indexed.
     *
     * @param array<string, string> $links Link texts by path.
     */
    public function page(
        string $title,
        string $content,
        string $description = '',
        string $image = '',
        array $links = [],
        string $canonical = '',
    ): string {
        $head = '';
        if ($description !== '') {
            $head .= '<meta name="description" content="' . htmlspecialchars($description) . '">';
        }
        if ($image !== '') {
            $head .= '<meta property="og:image" content="' . htmlspecialchars($image) . '">';
        }
        if ($canonical !== '') {
            $head .= '<link rel="canonical" href="' . htmlspecialchars($canonical) . '">';
        }
        $nav = '';
        foreach ($links as $path => $text) {
            $nav .= '<a href="' . htmlspecialchars($path) . '">' . htmlspecialchars($text) . '</a> ';
        }

        return <<<HTML
            <!DOCTYPE html>
            <html lang="fi">
            <head><meta charset="utf-8"><title>{$title}</title>{$head}</head>
            <body>
              <header>Site header text</header>
              <nav>Navigation text {$nav}</nav>
              <main><h1>{$title}</h1><p>{$content}</p></main>
              <aside>Sidebar text</aside>
              <footer>Site footer text</footer>
            </body>
            </html>
            HTML;
    }
}
