<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCourseSchedule extends Migration
{
    public function up()
    {
        $this->forge->addColumn('courses', [
            'starts_on' => ['type' => 'DATE', 'null' => true, 'after' => 'color'],
            'ends_on' => ['type' => 'DATE', 'null' => true, 'after' => 'starts_on'],
            'turn' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'ends_on'],
            'weekdays' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'turn'],
        ]);

        // Preserve the existing demo courses with an immediately usable schedule.
        $this->db->table('courses')->where('starts_on', null)->update([
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'turn' => 'morning',
            'weekdays' => '1,2,3,4,5',
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('courses', ['starts_on', 'ends_on', 'turn', 'weekdays']);
    }
}
