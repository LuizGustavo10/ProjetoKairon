<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateDemoOrganization extends Migration
{
    private array $destinations = [
        ['Planaltina do Paraná', -23.0200, -52.9200],
        ['Santa Cruz de Monte Castelo', -22.9586, -53.2972],
        ['Loanda', -22.9228, -53.1369],
    ];

    public function up(): void
    {
        $adriana = $this->db->table('users')->where('badge', '2001')->get()->getRowArray();
        if ($adriana !== null) {
            $this->db->table('users')->where('id', $adriana['id'])->update([
                'name' => 'Adriana',
                'email' => 'adriana@example.com',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($this->db->table('users')->where('badge', '2002')->countAllResults() === 0) {
                $stamp = date('Y-m-d H:i:s');
                $this->db->table('users')->insert([
                    'badge' => '2002',
                    'name' => 'Giselle',
                    'email' => 'giselle@example.com',
                    'role' => 'coordinator',
                    'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
                    'must_change_password' => 1,
                    'active' => 1,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ]);
            }
        }

        $vehicleNames = ['Fiat Cronos' => 'Voyage1', 'Chevrolet Spin' => 'Voyage2', 'Renault Master' => 'Argo'];
        foreach ($vehicleNames as $oldName => $newName) {
            $this->db->table('vehicles')->where('name', $oldName)->update(['name' => $newName, 'seats' => 5]);
        }

        $vehicles = $this->db->table('vehicles')->orderBy('id')->get()->getResultArray();
        $trips = $this->db->table('trips')->orderBy('id')->get()->getResultArray();
        foreach ($trips as $index => $trip) {
            [$city, $latitude, $longitude] = $this->destinations[$index % count($this->destinations)];
            $tripData = [
                'city' => $city,
                'destination' => 'Unidade parceira · ' . $city,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
            if ($vehicles !== []) {
                $tripData['vehicle_id'] = $vehicles[$index % count($vehicles)]['id'];
            }
            $this->db->table('trips')->where('id', $trip['id'])->update($tripData);
            if (! empty($trip['lesson_id'])) {
                $this->db->table('lessons')->where('id', $trip['lesson_id'])->update([
                    'city' => $city,
                    'location' => 'Unidade parceira · ' . $city,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]);
            }
        }

        if ($adriana !== null && $this->db->tableExists('changes')) {
            $this->db->table('changes')->insert(['kind' => 'organization', 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    public function down(): void
    {
        $this->db->table('users')->where('badge', '2001')->update([
            'name' => 'Beatriz Almeida',
            'email' => 'beatriz@example.com',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        foreach (['Voyage1' => ['Fiat Cronos', 5], 'Voyage2' => ['Chevrolet Spin', 7], 'Argo' => ['Renault Master', 16]] as $current => [$previous, $seats]) {
            $this->db->table('vehicles')->where('name', $current)->update(['name' => $previous, 'seats' => $seats]);
        }
    }
}
