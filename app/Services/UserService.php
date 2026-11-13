<?php
namespace App\Services;

class UserService
{
    public function save(array $actor, array $data, ?int $id): array
    {
        if ($actor['role'] !== 'manager') { throw new \DomainException('Somente o gerente pode gerenciar acessos.'); }
        $db = db_connect();
        $existing = $id ? $db->table('users')->where('id', $id)->get()->getRowArray() : null;
        if ($id && !$existing) { throw new \DomainException('Usuário não encontrado.'); }
        $badge = trim((string) ($data['badge'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $role = (string) ($data['role'] ?? 'instructor');
        $photoUrl = trim((string) ($data['photo_url'] ?? ''));
        $active = isset($data['active']) ? (int) (bool) $data['active'] : 1;
        if (!preg_match('/^[a-zA-Z0-9_-]{2,40}$/D', $badge) || mb_strlen($name) < 3 || mb_strlen($name) > 120) {
            throw new \DomainException('Preencha o nome (3–120 caracteres) e um crachá válido (2–40 letras, números, traços).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) { throw new \DomainException('Informe um e-mail válido.'); }
        if ($photoUrl !== '' && (!filter_var($photoUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $photoUrl) || strlen($photoUrl) > 500)) { throw new \DomainException('Informe uma URL http ou https válida para a foto.'); }
        if (!in_array($role, ['manager', 'coordinator', 'instructor', 'student'], true)) { throw new \DomainException('Perfil inválido.'); }
        if ($id === (int) $actor['id'] && ($role !== 'manager' || !$active)) {
            throw new \DomainException('Você não pode desativar ou retirar o perfil de gerente do próprio acesso.');
        }
        $duplicate = $db->table('users')->groupStart()->where('badge', $badge)->orWhere('email', $email)->groupEnd();
        if ($id) { $duplicate->where('id !=', $id); }
        if ($duplicate->countAllResults()) { throw new \DomainException('Este crachá ou e-mail já está cadastrado.'); }
        if ($existing && $existing['role'] === 'instructor' && $role !== 'instructor' && $db->table('lessons')->where('instructor_id', $id)->countAllResults()) {
            throw new \DomainException('Este instrutor possui aulas vinculadas. Mantenha o perfil ou redistribua as aulas antes.');
        }
        $row = ['badge' => $badge, 'name' => $name, 'email' => $email, 'photo_url' => $photoUrl, 'role' => $role, 'active' => $active, 'updated_at' => date('Y-m-d H:i:s')];
        $reset = !empty($data['reset_password']);
        if (!$id || $reset) { $row['password_hash'] = password_hash('123456', PASSWORD_DEFAULT); $row['must_change_password'] = 1; }
        $db->transStart();
        if ($id) {
            $db->table('users')->where('id', $id)->update($row);
            if ($reset || !$active || $existing['role'] !== $role) { $db->table('remember_tokens')->where('user_id', $id)->delete(); }
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $db->table('users')->insert($row);
            $id = (int) $db->insertID();
        }
        $db->table('changes')->insert(['kind' => 'users', 'created_at' => date('Y-m-d H:i:s')]);
        $db->transComplete();
        if (!$db->transStatus()) { throw new \DomainException('Não foi possível salvar este acesso. Confira se crachá e e-mail já existem.'); }
        return ['id' => $id];
    }
}
