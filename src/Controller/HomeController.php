<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

final class HomeController
{
    private const PAGES = [
        'home' => ['/', 'One home for your forms', 'Build your site. Connect a form. Find every response in one place. Developer preview, starting with the verified local Codex workflow.'],
        'getting-started' => ['/getting-started', 'Getting started', 'Connect the experimental local Codex client, prepare a form, explicitly publish it and verify a synthetic submission in your GoFormX inbox.'],
        'compatibility' => ['/compatibility', 'Compatibility', 'Dated evidence for the local Codex workflow and live personal-site collection, with clear limitations and planned assistant support.'],
        'docs' => ['/docs', 'Documentation', 'Guides to GoFormX schemas, origins, versions, retries, permissions, explicit publication and the submissions dashboard.'],
    ];

    public function __construct(private readonly string $canonicalOrigin)
    {
        $url = parse_url($canonicalOrigin);
        if (!is_array($url) || !isset($url['scheme'], $url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            !in_array($url['path'] ?? '', ['', '/'], true) ||
            !($url['scheme'] === 'https' || ($url['scheme'] === 'http' && in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true)))) {
            throw new \InvalidArgumentException('The public website requires an explicit HTTPS origin or loopback HTTP origin.');
        }
    }

    public function index(): Response
    {
        return $this->page('home');
    }

    public function gettingStarted(): Response { return $this->page('getting-started'); }
    public function compatibility(): Response { return $this->page('compatibility'); }
    public function docs(): Response { return $this->page('docs'); }

    private function page(string $name): Response
    {
        [$path, $title, $description] = self::PAGES[$name];
        $root = dirname(__DIR__, 2) . '/templates/';
        $html = strtr((string) file_get_contents($root . 'public-shell.html.twig'), [
            '{{ TITLE }}' => $this->escape($title . ' | GoFormX'),
            '{{ DESCRIPTION }}' => $this->escape($description),
            '{{ CANONICAL }}' => $this->escape(rtrim($this->canonicalOrigin, '/') . $path),
            '{{ ORIGIN }}' => $this->escape(rtrim($this->canonicalOrigin, '/')),
            '{{ CONTENT }}' => (string) file_get_contents($root . $name . '.html.twig'),
            '{{ START_CURRENT }}' => $name === 'getting-started' ? 'aria-current="page"' : '',
            '{{ COMPATIBILITY_CURRENT }}' => $name === 'compatibility' ? 'aria-current="page"' : '',
            '{{ DOCS_CURRENT }}' => $name === 'docs' ? 'aria-current="page"' : '',
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
