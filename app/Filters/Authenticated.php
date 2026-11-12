<?php

namespace App\Filters;

use App\Services\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class Authenticated implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = (new AuthService())->current();
        $api = str_contains($request->getUri()->getPath(), '/api/');
        if (!$user) {
            return $api
                ? service('response')->setStatusCode(401)->setJSON(['ok' => false, 'message' => 'Sua sessão expirou. Entre novamente.'])
                : redirect()->to(rtrim(config('App')->baseURL, '/') . '/login');
        }
        if ((int) $user['must_change_password'] && !in_array('password', $arguments ?? [], true)) {
            return $api
                ? service('response')->setStatusCode(403)->setJSON(['ok' => false, 'message' => 'Altere sua senha inicial antes de continuar.', 'redirect' => rtrim(config('App')->baseURL, '/') . '/change-password'])
                : redirect()->to(rtrim(config('App')->baseURL, '/') . '/change-password');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, private');
    }
}
