<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

final class AuthPageController
{
    public function __construct(private readonly string $publicApiOrigin)
    {
        $url = parse_url($publicApiOrigin);
        if (!is_array($url) || !isset($url['scheme'], $url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            !in_array($url['path'] ?? '', ['', '/'], true) ||
            !($url['scheme'] === 'https' || ($url['scheme'] === 'http' && in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true)))) {
            throw new \InvalidArgumentException('GOFORMX_PUBLIC_API_URL must be an HTTPS origin (HTTP is allowed only on loopback).');
        }
    }

    public function register(): Response
    {
        return $this->page('register');
    }

    public function login(): Response
    {
        return $this->page('login');
    }

    public function verifyEmail(): Response
    {
        return $this->page('verify-email');
    }

    public function forgotPassword(): Response
    {
        return $this->page('forgot-password');
    }

    public function resetPassword(): Response
    {
        return $this->page('reset-password');
    }

    public function dashboard(): Response
    {
        return $this->page('app');
    }

    private function page(string $name): Response
    {
        $path = dirname(__DIR__, 2) . '/templates/' . $name . '.html.twig';

        $html = str_replace('{{ PUBLIC_API_ORIGIN }}', htmlspecialchars(rtrim($this->publicApiOrigin, '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), (string) file_get_contents($path));
        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
