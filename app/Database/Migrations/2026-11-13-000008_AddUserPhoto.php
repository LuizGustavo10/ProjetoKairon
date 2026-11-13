<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserPhoto extends Migration
{
    public function up() { $this->forge->addColumn('users', ['photo_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'default' => '', 'after' => 'email']]); }
    public function down() { $this->forge->dropColumn('users', 'photo_url'); }
}
