<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCourseOperationsAndScheduleBlocks extends Migration
{
    public function up()
    {
        $this->forge->addColumn('courses', [
            'course_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'internal'],
            'location' => ['type' => 'VARCHAR', 'constraint' => 180, 'default' => ''],
            'address' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'daily_hours' => ['type' => 'DECIMAL', 'constraint' => '6,2', 'default' => 4],
            'total_hours' => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 0],
            'travel_out_minutes' => ['type' => 'INTEGER', 'default' => 0],
            'travel_back_minutes' => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $this->forge->addColumn('hour_requests', [
            'turn' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'morning', 'after' => 'work_date'],
        ]);
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INTEGER', 'unsigned' => true, 'null' => true],
            'block_date' => ['type' => 'DATE'],
            'turn' => ['type' => 'VARCHAR', 'constraint' => 20],
            'type' => ['type' => 'VARCHAR', 'constraint' => 30],
            'reason' => ['type' => 'TEXT'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20],
            'requested_by' => ['type' => 'INTEGER', 'unsigned' => true],
            'reviewed_by' => ['type' => 'INTEGER', 'unsigned' => true, 'null' => true],
            'review_note' => ['type' => 'TEXT', 'null' => true],
            'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['block_date', 'status']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('requested_by', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('reviewed_by', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('schedule_blocks', true);
    }

    public function down()
    {
        $this->forge->dropTable('schedule_blocks', true);
        $this->forge->dropColumn('hour_requests', 'turn');
        $this->forge->dropColumn('courses', ['course_type', 'location', 'address', 'notes', 'daily_hours', 'total_hours', 'travel_out_minutes', 'travel_back_minutes']);
    }
}
