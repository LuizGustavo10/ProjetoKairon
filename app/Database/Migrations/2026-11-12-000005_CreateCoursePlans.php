<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCoursePlans extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'unsigned' => true, 'auto_increment' => true],
            'class_id' => ['type' => 'INTEGER', 'unsigned' => true],
            'starts_at' => ['type' => 'DATETIME'],
            'ends_at' => ['type' => 'DATETIME'],
            'created_by' => ['type' => 'INTEGER', 'unsigned' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('class_id');
        $this->forge->addKey(['starts_at', 'ends_at']);
        $this->forge->addForeignKey('class_id', 'classes', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('created_by', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('course_plans', true);
    }

    public function down()
    {
        $this->forge->dropTable('course_plans', true);
    }
}
