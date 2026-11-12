<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ResetDemoAccessExamples extends Migration
{
    private array $demoBadges = ['1001', '2001', '3001'];

    public function up(): void
    {
        $users = $this->db->table('users')
            ->whereIn('badge', $this->demoBadges)
            ->get()
            ->getResultArray();

        foreach ($users as $user) {
            if (! str_ends_with((string) $user['email'], '@example.com')) {
                continue;
            }

            $this->db->table('users')->where('id', $user['id'])->update([
                'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
                'must_change_password' => 1,
                'active' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($this->db->tableExists('remember_tokens')) {
                $this->db->table('remember_tokens')->where('user_id', $user['id'])->delete();
            }
        }
    }

    public function down(): void
    {
        // Password hashes cannot be restored safely.
    }
}
