<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

final class AuthPageController
{
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

        return new Response((string) file_get_contents($path), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
