<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use DateTimeImmutable;
use DateTimeZone;

/** Explicit, non-destructive demonstration data. Never imports production users. */
class DemoSeeder extends Seeder
{
    public function run()
    {
        if ($this->db->table('users')->countAllResults() > 0) {
            echo "Demonstração não inserida: o banco já contém usuários.\n";
            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $stamp = $now->format('Y-m-d H:i:s');
        $first = $now->modify('first day of this month')->setTime(0, 0);
        $this->db->transStart();
        $password = password_hash('123456', PASSWORD_DEFAULT);
        $people = [
            ['1001', 'Marina Costa', 'marina@example.com', 'manager'],
            ['2001', 'Adriana', 'adriana@example.com', 'coordinator'],
            ['2002', 'Giselle', 'giselle@example.com', 'coordinator'],
            ['3001', 'Ana Martins', 'ana@example.com', 'instructor'],
            ['3002', 'Lucas Ferreira', 'lucas@example.com', 'instructor'],
            ['3003', 'Rafael Oliveira', 'rafael@example.com', 'instructor'],
            ['4001', 'Julia Santos', 'julia@example.com', 'student'],
        ];
        $ids = [];
        foreach ($people as [$badge, $name, $email, $role]) {
            $this->db->table('users')->insert(['badge' => $badge, 'name' => $name, 'email' => $email, 'role' => $role, 'password_hash' => $password, 'must_change_password' => 1, 'active' => 1, 'created_at' => $stamp, 'updated_at' => $stamp]);
            $ids[$badge] = (int) $this->db->insertID();
        }
        $courseIds = [];
        foreach ([['Técnico em Administração', '#4778D8'], ['Assistente de Marketing', '#A36BCC'], ['Técnico em Enfermagem', '#269C89'], ['Cuidador de Idosos', '#E3A04B'], ['Programação Web', '#5A6FD1'], ['Design Gráfico', '#D77585']] as [$name, $color]) {
            $this->db->table('courses')->insert(['name' => $name, 'color' => $color]);
            $courseIds[] = (int) $this->db->insertID();
        }
        $classIds = [];
        foreach ($courseIds as $index => $courseId) {
            $this->db->table('classes')->insert(['code' => 'T' . (101 + $index), 'name' => ['Administração · Manhã', 'Marketing · Manhã', 'Enfermagem · Manhã', 'Cuidador · Manhã', 'Web · Noite', 'Design · Noite'][$index], 'course_id' => $courseId, 'city' => 'Londrina']);
            $classIds[] = (int) $this->db->insertID();
        }
        $roomIds = [];
        foreach ([['Sala 01 · Gestão', 35], ['Laboratório de Saúde', 24], ['Laboratório de Informática', 30], ['Sala Multiuso', 40]] as [$name, $capacity]) {
            $this->db->table('rooms')->insert(['name' => $name, 'capacity' => $capacity]);
            $roomIds[] = (int) $this->db->insertID();
        }
        $this->db->table('tablet_pools')->insert(['name' => 'Tablets · Unidade Londrina', 'quantity' => 30]);
        $tabletId = (int) $this->db->insertID();
        $vehicleIds = [];
        foreach ([['Voyage1', 'SNC1A01', 5], ['Voyage2', 'SNC2B02', 5], ['Argo', 'SNC3C03', 5]] as [$name, $plate, $seats]) {
            $this->db->table('vehicles')->insert(['name' => $name, 'plate' => $plate, 'seats' => $seats]);
            $vehicleIds[] = (int) $this->db->insertID();
        }
        $externalDestinations = [
            ['Planaltina do Paraná', -23.0200, -52.9200],
            ['Santa Cruz de Monte Castelo', -22.9586, -53.2972],
            ['Loanda', -22.9228, -53.1369],
        ];
        $externalTripIndex = 0;
        // Full current month, with distinct rooms/classes and no overlapping instructor assignments.
        for ($day = $first; $day->format('Y-m') === $first->format('Y-m'); $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') > 5) { continue; }
            $date = $day->format('Y-m-d');
            foreach (['3001', '3002', '3003'] as $index => $badge) {
                $classIndex = $index * 2 + ((int) $day->format('j') % 2);
                $external = ($index === 1 && $day->format('N') === '2') || ($index === 2 && $day->format('N') === '4');
                $start = $index === 2 ? '18:00:00' : '08:00:00';
                $end = $index === 2 ? '22:00:00' : '12:00:00';
                if ($external) {
                    [$city, $lat, $lng] = $externalDestinations[$externalTripIndex % count($externalDestinations)];
                    $tripVehicleId = $vehicleIds[$externalTripIndex % count($vehicleIds)];
                    $externalTripIndex++;
                } else {
                    [$city, $lat, $lng] = ['Londrina', -23.3045, -51.1696];
                    $tripVehicleId = null;
                }
                $location = $external ? 'Unidade parceira · ' . $city : 'Senac Londrina';
                $this->db->table('lessons')->insert(['class_id' => $classIds[$classIndex], 'instructor_id' => $ids[$badge], 'room_id' => $external ? null : $roomIds[$index], 'location' => $location, 'city' => $city, 'latitude' => $lat, 'longitude' => $lng, 'starts_at' => $date . ' ' . $start, 'ends_at' => $date . ' ' . $end, 'content' => ['Planejamento e atividade prática em equipe.', 'Estudo de caso e atendimento humanizado.', 'Oficina prática: desenvolvimento de um projeto.'][$index], 'created_by' => $ids['2001'], 'updated_at' => $stamp]);
                $lessonId = (int) $this->db->insertID();
                if ($external) {
                    $this->db->table('trips')->insert(['vehicle_id' => $tripVehicleId, 'instructor_id' => $ids[$badge], 'lesson_id' => $lessonId, 'city' => $city, 'destination' => $location, 'latitude' => $lat, 'longitude' => $lng, 'starts_at' => $date . ($index === 1 ? ' 07:00:00' : ' 17:00:00'), 'ends_at' => $date . ($index === 1 ? ' 13:00:00' : ' 23:00:00'), 'notes' => 'Programação demonstrativa de curso externo.', 'created_by' => $ids['2001']]);
                }
            }
        }
        foreach (['3001' => 24, '3002' => 44, '3003' => 52] as $badge => $total) {
            $part = 0;
            while ($total > 0) {
                $hours = min(8, $total);
                $day = $first->modify('+' . $part . ' days')->format('Y-m-d');
                $this->db->table('hour_requests')->insert(['user_id' => $ids[$badge], 'work_date' => $day, 'hours' => $hours, 'reason' => ['Planejamento pedagógico e preparação de material.', 'Reunião de alinhamento da equipe pedagógica.', 'Organização de atividades e avaliação de projetos.'][$part % 3], 'status' => 'approved', 'manager_note' => 'Aprovado para demonstração.', 'reviewed_by' => $ids['1001'], 'reviewed_at' => $stamp, 'created_at' => $stamp]);
                $total -= $hours;
                $part++;
            }
        }
        foreach (['3001', '3002', '2001'] as $index => $badge) {
            $this->db->table('hour_requests')->insert(['user_id' => $ids[$badge], 'work_date' => $now->format('Y-m-d'), 'hours' => [4, 2, 3][$index], 'reason' => ['Preparar materiais para a próxima turma e revisar as avaliações.', 'Participar do encontro pedagógico e planejar a atividade externa.', 'Organizar a distribuição das aulas do próximo mês.'][$index], 'status' => 'pending', 'manager_note' => '', 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => $stamp]);
        }
        $reserveDate = $now->format('Y-m-d');
        $this->db->table('reservations')->insert(['resource_type' => 'tablet', 'resource_id' => $tabletId, 'quantity' => 15, 'user_id' => $ids['3001'], 'starts_at' => $reserveDate . ' 08:00:00', 'ends_at' => $reserveDate . ' 12:00:00', 'purpose' => 'Pesquisa e atividade prática em equipes.']);
        $this->db->table('reservations')->insert(['resource_type' => 'room', 'resource_id' => $roomIds[3], 'quantity' => 1, 'user_id' => $ids['2001'], 'starts_at' => $reserveDate . ' 14:00:00', 'ends_at' => $reserveDate . ' 16:00:00', 'purpose' => 'Reunião de planejamento pedagógico.']);
        foreach ($ids as $badge => $id) {
            $this->db->table('notifications')->insert(['user_id' => $id, 'title' => 'Bem-vindo ao PAC Agenda', 'body' => 'Sua programação fica organizada em um só lugar. Estes são dados fictícios de demonstração.', 'read_at' => null, 'created_at' => $stamp]);
        }
        $this->db->table('notifications')->insert(['user_id' => $ids['1001'], 'title' => 'Há pedidos aguardando sua análise', 'body' => 'Abra Solicitações para aprovar ou recusar as horas fora de sala.', 'read_at' => null, 'created_at' => $stamp]);
        $this->db->table('changes')->insert(['kind' => 'demo', 'created_at' => $stamp]);
        $this->db->transComplete();
        if ($this->db->transStatus() === false) { throw new \RuntimeException('Não foi possível criar os dados de demonstração.'); }
        echo "Demonstração criada. Crachás 1001 / 2001 / 2002 / 3001 / 3002 / 3003 / 4001. Senha inicial: 123456 (troca obrigatória).\n";
    }
}
