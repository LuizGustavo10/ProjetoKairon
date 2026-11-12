<?php

namespace App\Services;

use CodeIgniter\HTTP\ResponseInterface;

class AuthService
{
    public const COOKIE = 'kairon_remember';

    public function current(): ?array
    {
        $session = session();
        $db = db_connect();
        $id = $session->get('user_id');
        if ($id) {
            $user = $db->table('users')->where('id', $id)->get()->getRowArray();
            if ($user && (int) $user['active'] === 1 && hash_equals((string) $session->get('auth_stamp'), hash('sha256', $user['password_hash']))) {
                return $this->safe($user);
            }
            $session->remove(['user_id', 'auth_stamp', 'remembered']);
            return null;
        }
        $token = service('request')->getCookie(self::COOKIE);
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $remember = $db->table('remember_tokens')->where('token_hash', hash('sha256', $token))
            ->where('expires_at >', date('Y-m-d H:i:s'))->get()->getRowArray();
        if (!$remember) {
            return null;
        }
        $user = $db->table('users')->where('id', $remember['user_id'])->where('active', 1)->get()->getRowArray();
        if (!$user) {
            return null;
        }
        $session->regenerate(true);
        $session->set(['user_id' => $user['id'], 'auth_stamp' => hash('sha256', $user['password_hash']), 'remembered' => true]);
        return $this->safe($user);
    }

    public function login(string $badge, string $password, bool $remember, ResponseInterface $response): bool
    {
        $user = db_connect()->table('users')->where('badge', $badge)->where('active', 1)->get()->getRowArray();
        // Run the same password algorithm when a badge does not exist.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if (!password_verify($password, $hash) || !$user) {
            return false;
        }
        session()->regenerate(true);
        session()->set(['user_id' => $user['id'], 'auth_stamp' => hash('sha256', $hash), 'remembered' => $remember]);
        $this->revokeCookie($response);
        if ($remember) {
            $this->remember((int) $user['id'], $response);
        }
        return true;
    }

    public function changePassword(int $id, string $password, ResponseInterface $response): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $db = db_connect();
        $db->transStart();
        $db->table('users')->where('id', $id)->update([
            'password_hash' => $hash, 'must_change_password' => 0, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('remember_tokens')->where('user_id', $id)->delete();
        $db->transComplete();
        if (!$db->transStatus()) {
            throw new \RuntimeException('Não foi possível atualizar a senha.');
        }
        session()->regenerate(true);
        session()->set('auth_stamp', hash('sha256', $hash));
        if (session()->get('remembered')) {
            $this->remember($id, $response);
        }
    }

    public function logout(ResponseInterface $response): void
    {
        $this->revokeCookie($response);
        session()->destroy();
    }

    private function remember(int $id, ResponseInterface $response): void
    {
        $token = bin2hex(random_bytes(32));
        $db = db_connect();
        $db->table('remember_tokens')->where('expires_at <', date('Y-m-d H:i:s'))->delete();
        $db->table('remember_tokens')->insert([
            'user_id' => $id, 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 30 * DAY), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $response->setCookie(self::COOKIE, $token, 30 * DAY, '', '/', '', service('request')->isSecure(), true, 'Lax');
    }

    private function revokeCookie(ResponseInterface $response): void
    {
        $token = service('request')->getCookie(self::COOKIE);
        if (is_string($token) && $token !== '') {
            db_connect()->table('remember_tokens')->where('token_hash', hash('sha256', $token))->delete();
        }
        $response->deleteCookie(self::COOKIE, '', '/');
    }

    private function safe(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
}
