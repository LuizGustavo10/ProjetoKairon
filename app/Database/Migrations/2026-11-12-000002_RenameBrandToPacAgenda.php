<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameBrandToPacAgenda extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('notifications')) {
            $this->db->table('notifications')
                ->where('title', 'Bem-vindo ao Kairon')
                ->update(['title' => 'Bem-vindo ao PAC Agenda']);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('notifications')) {
            $this->db->table('notifications')
                ->where('title', 'Bem-vindo ao PAC Agenda')
                ->update(['title' => 'Bem-vindo ao Kairon']);
        }
    }
}
