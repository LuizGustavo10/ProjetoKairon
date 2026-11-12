<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAgenda extends Migration
{
    public function up()
    {
        $id = ['type' => 'INTEGER', 'unsigned' => true, 'auto_increment' => true];
        $int = ['type' => 'INTEGER', 'unsigned' => true];
        $nullableInt = $int + ['null' => true];
        $text = ['type' => 'TEXT'];
        $str = static fn (int $size = 255) => ['type' => 'VARCHAR', 'constraint' => $size];
        $date = ['type' => 'DATETIME'];
        $nullableDate = $date + ['null' => true];
        $tables = [
            'users' => ['id' => $id, 'badge' => $str(40), 'name' => $str(120), 'email' => $str(190), 'role' => $str(20), 'password_hash' => $str(255), 'must_change_password' => ['type' => 'INTEGER', 'default' => 1], 'active' => ['type' => 'INTEGER', 'default' => 1], 'created_at' => $date, 'updated_at' => $date],
            'remember_tokens' => ['id' => $id, 'user_id' => $int, 'token_hash' => $str(64), 'expires_at' => $date, 'created_at' => $date],
            'courses' => ['id' => $id, 'name' => $str(120), 'color' => $str(7)],
            'classes' => ['id' => $id, 'code' => $str(40), 'name' => $str(120), 'course_id' => $int, 'city' => $str(120)],
            'rooms' => ['id' => $id, 'name' => $str(120), 'capacity' => $int],
            'tablet_pools' => ['id' => $id, 'name' => $str(120), 'quantity' => $int],
            'vehicles' => ['id' => $id, 'name' => $str(120), 'plate' => $str(10), 'seats' => $int],
            'lessons' => ['id' => $id, 'class_id' => $int, 'instructor_id' => $int, 'room_id' => $nullableInt, 'location' => $str(190), 'city' => $str(120), 'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true], 'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true], 'starts_at' => $date, 'ends_at' => $date, 'content' => $text, 'created_by' => $int, 'updated_at' => $date],
            'hour_requests' => ['id' => $id, 'user_id' => $int, 'work_date' => ['type' => 'DATE'], 'hours' => ['type' => 'DECIMAL', 'constraint' => '6,2'], 'reason' => $text, 'status' => $str(20), 'manager_note' => $text, 'reviewed_by' => $nullableInt, 'reviewed_at' => $nullableDate, 'created_at' => $date],
            'trips' => ['id' => $id, 'vehicle_id' => $int, 'instructor_id' => $int, 'lesson_id' => $nullableInt, 'city' => $str(120), 'destination' => $str(190), 'latitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true], 'longitude' => ['type' => 'DECIMAL', 'constraint' => '10,7', 'null' => true], 'starts_at' => $date, 'ends_at' => $date, 'notes' => $text, 'created_by' => $int],
            'reservations' => ['id' => $id, 'resource_type' => $str(10), 'resource_id' => $int, 'quantity' => $int, 'user_id' => $int, 'starts_at' => $date, 'ends_at' => $date, 'purpose' => $text],
            'notifications' => ['id' => $id, 'user_id' => $int, 'title' => $str(190), 'body' => $text, 'read_at' => $nullableDate, 'created_at' => $date],
            'email_queue' => ['id' => $id, 'user_id' => $int, 'recipient' => $str(190), 'subject' => $str(190), 'body' => $text, 'status' => $str(20), 'attempts' => ['type' => 'INTEGER', 'default' => 0], 'last_error' => $text, 'created_at' => $date, 'sent_at' => $nullableDate],
            'changes' => ['id' => $id, 'kind' => $str(40), 'created_at' => $date],
        ];
        $references = [
            'remember_tokens' => ['user_id' => 'users'],
            'classes' => ['course_id' => 'courses'],
            'lessons' => ['class_id' => 'classes', 'instructor_id' => 'users', 'room_id' => 'rooms', 'created_by' => 'users'],
            'hour_requests' => ['user_id' => 'users', 'reviewed_by' => 'users'],
            'trips' => ['vehicle_id' => 'vehicles', 'instructor_id' => 'users', 'lesson_id' => 'lessons', 'created_by' => 'users'],
            'reservations' => ['user_id' => 'users'],
            'notifications' => ['user_id' => 'users'],
            'email_queue' => ['user_id' => 'users'],
        ];
        foreach ($tables as $name => $fields) {
            $this->forge->addField($fields);
            $this->forge->addKey('id', true);
            foreach ($references[$name] ?? [] as $field => $target) {
                $this->forge->addKey($field);
                $this->forge->addForeignKey($field, $target, 'id', 'CASCADE', 'RESTRICT');
            }
            if ($name === 'users') { $this->forge->addUniqueKey('badge'); $this->forge->addUniqueKey('email'); }
            if ($name === 'classes') { $this->forge->addUniqueKey('code'); }
            if ($name === 'vehicles') { $this->forge->addUniqueKey('plate'); }
            if ($name === 'remember_tokens') { $this->forge->addUniqueKey('token_hash'); }
            if (in_array($name, ['lessons', 'trips', 'reservations'], true)) { $this->forge->addKey(['starts_at', 'ends_at']); }
            if ($name === 'hour_requests') { $this->forge->addKey(['work_date', 'status']); }
            if ($name === 'email_queue') { $this->forge->addKey(['status', 'attempts']); }
            $this->forge->createTable($name, true);
        }
    }

    public function down()
    {
        foreach (['changes', 'email_queue', 'notifications', 'reservations', 'trips', 'hour_requests', 'lessons', 'vehicles', 'tablet_pools', 'rooms', 'classes', 'courses', 'remember_tokens', 'users'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
