<?php

namespace App\Controllers;

use App\Services\AuthService;

class Auth extends BaseController
{
    protected $helpers = ['form', 'url'];

    public function login()
    {
        $auth = new AuthService();
        if ($auth->current()) {
            return redirect()->to(site_url());
        }
        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('auth/login', [
            'error' => session()->getFlashdata('error'), 'badge' => session()->getFlashdata('badge') ?? '',
        ]));
    }

    public function authenticate()
    {
        $badge = trim((string) $this->request->getPost('badge'));
        $password = (string) $this->request->getPost('password');
        $throttler = service('throttler');
        $ipKey = 'login-ip-' . hash('sha256', $this->request->getIPAddress());
        $badgeKey = 'login-badge-' . hash('sha256', $badge);
        if (!$throttler->check($ipKey, 30, MINUTE) || !$throttler->check($badgeKey, 8, MINUTE)) {
            return redirect()->to(site_url('login'))->with('error', 'Muitas tentativas. Aguarde um minuto e tente novamente.')->with('badge', $badge);
        }
        $response = redirect()->to(site_url());
        if (strlen($badge) > 40 || strlen($password) > 128 || !(new AuthService())->login($badge, $password, (bool) $this->request->getPost('remember'), $response)) {
            return redirect()->to(site_url('login'))->with('error', 'Crachá ou senha incorretos. Confira e tente novamente.')->with('badge', $badge);
        }
        return $response;
    }

    public function password()
    {
        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(view('auth/password', [
            'user' => (new AuthService())->current(), 'error' => session()->getFlashdata('error'),
        ]));
    }

    public function updatePassword()
    {
        $auth = new AuthService();
        $user = $auth->current();
        $password = (string) $this->request->getPost('password');
        $confirm = (string) $this->request->getPost('password_confirm');
        if (strlen($password) < 8 || strlen($password) > 128 || $password === '123456' || $password !== $confirm) {
            return redirect()->to(site_url('change-password'))->with('error', 'Use de 8 a 128 caracteres e repita a mesma senha nos dois campos.');
        }
        $response = redirect()->to(site_url());
        $auth->changePassword((int) $user['id'], $password, $response);
        return $response;
    }

    public function logout()
    {
        $response = redirect()->to(site_url('login'));
        (new AuthService())->logout($response);
        return $response;
    }
}
