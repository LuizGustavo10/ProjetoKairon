<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Throwable;

/** All authorization and availability decisions live on the server. */
class AgendaService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = db_connect();
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('PRAGMA foreign_keys = ON');
            $this->db->query('PRAGMA busy_timeout = 10000');
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
    }

    private function roles(array $user, array $allowed): void
    {
        if (!in_array($user['role'] ?? '', $allowed, true) || empty($user['id'])) {
            throw new DomainException('Você não tem permissão para esta ação.');
        }
    }

    private function management(array $user): void
    {
        $this->roles($user, ['manager', 'coordinator']);
    }

    private function staff(array $user): void
    {
        $this->roles($user, ['manager', 'coordinator', 'instructor']);
    }

    private function text(mixed $value, string $label, int $max = 190, bool $required = true): string
    {
        if (!is_scalar($value) && $value !== null) { throw new DomainException("{$label} inválido."); }
        $value = trim((string) $value);
        if (($required && $value === '') || mb_strlen($value) > $max) {
            throw new DomainException("Informe {$label} com até {$max} caracteres.");
        }
        return $value;
    }

    private function positive(mixed $value, string $label, int $max = 100000): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > $max) {
            throw new DomainException("Informe {$label} válido.");
        }
        return (int) $value;
    }

    private function row(string $table, int $id): array
    {
        $row = $this->db->table($table)->where('id', $id)->get()->getRowArray();
        if (!$row) { throw new DomainException('Registro não encontrado. Atualize a página.'); }
        return $row;
    }

    private function ensureCourseClass(array $course): int
    {
        $existing = $this->db->table('classes')->where('course_id', (int) $course['id'])->orderBy('id', 'ASC')->get()->getRowArray();
        if ($existing) { return (int) $existing['id']; }
        $base = 'CURSO-' . (int) $course['id'];
        $code = $base;
        $suffix = 1;
        while ($this->db->table('classes')->where('code', $code)->countAllResults()) { $code = $base . '-' . $suffix++; }
        $this->db->table('classes')->insert(['code' => $code, 'name' => $course['name'], 'course_id' => (int) $course['id'], 'city' => 'A definir']);
        return (int) $this->db->insertID();
    }

    private function workload(int $userId, string $day): array
    {
        $date = new DateTimeImmutable($day);
        $weekStart = $date->modify('monday this week')->format('Y-m-d');
        $weekEnd = (new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d');
        $totals = ['day' => 0.0, 'week' => 0.0];
        $lessons = $this->db->table('lessons l')->select('l.starts_at,l.ends_at,co.course_type,co.travel_out_minutes,co.travel_back_minutes')->join('classes c', 'c.id=l.class_id')->join('courses co', 'co.id=c.course_id')->where('l.instructor_id', $userId)->where('l.starts_at >=', $weekStart . ' 00:00:00')->where('l.starts_at <', $weekEnd . ' 00:00:00')->get()->getResultArray();
        foreach ($lessons as $lesson) {
            $hours = max(0, strtotime($lesson['ends_at']) - strtotime($lesson['starts_at'])) / 3600;
            if ($lesson['course_type'] === 'external') { $hours += ((int) $lesson['travel_out_minutes'] + (int) $lesson['travel_back_minutes'] + 20) / 60; }
            $totals['week'] += $hours;
            if (substr($lesson['starts_at'], 0, 10) === $day) { $totals['day'] += $hours; }
        }
        foreach ($this->db->table('hour_requests')->select('work_date,hours')->where(['user_id' => $userId, 'status' => 'approved'])->where('work_date >=', $weekStart)->where('work_date <', $weekEnd)->get()->getResultArray() as $outside) {
            $totals['week'] += (float) $outside['hours'];
            if ($outside['work_date'] === $day) { $totals['day'] += (float) $outside['hours']; }
        }
        return $totals;
    }

    private function instructor(mixed $id): array
    {
        $row = $this->row('users', $this->positive($id, 'o instrutor'));
        if ($row['role'] !== 'instructor' || !(int) $row['active']) { throw new DomainException('Selecione um instrutor ativo.'); }
        return $row;
    }

    private function interval(array $data): array
    {
        $values = [];
        foreach (['starts_at', 'ends_at'] as $key) {
            $raw = str_replace('T', ' ', $this->text($data[$key] ?? '', 'a data e o horário', 19));
            if (strlen($raw) === 16) { $raw .= ':00'; }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new DateTimeZone('America/Sao_Paulo'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $raw) { throw new DomainException('Data ou horário inválido.'); }
            $values[] = $raw;
        }
        if ($values[1] <= $values[0]) { throw new DomainException('O término precisa ser depois do início.'); }
        if (strtotime($values[1]) - strtotime($values[0]) > 31 * 86400) { throw new DomainException('O agendamento deve durar no máximo 31 dias.'); }
        return $values;
    }

    private function coordinates(array $data): array
    {
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        if (($lat === '' || $lat === null) && ($lng === '' || $lng === null)) { return [null, null]; }
        if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat < -90 || (float) $lat > 90 || (float) $lng < -180 || (float) $lng > 180) {
            throw new DomainException('Informe latitude e longitude válidas para o mapa.');
        }
        return [(float) $lat, (float) $lng];
    }

    private function conflict(string $table, array $where, string $start, string $end, ?int $except = null): bool
    {
        $query = $this->db->table($table)->where($where)->where('starts_at <', $end)->where('ends_at >', $start);
        if ($except) { $query->where('id !=', $except); }
        return $query->countAllResults() > 0;
    }

    /** Serialize availability checks with writes, including requests from other PHP workers. */
    private function write(callable $callback): mixed
    {
        $file = null;
        $mysql = $this->db->DBDriver === 'MySQLi';
        $lockName = 'kairon_' . substr(hash('sha256', (string) $this->db->database), 0, 32);
        if ($mysql) {
            $locked = $this->db->query('SELECT GET_LOCK(?, 10) AS acquired', [$lockName])->getRowArray();
            if ((int) ($locked['acquired'] ?? 0) !== 1) { throw new DomainException('Outro agendamento está sendo salvo. Tente novamente.'); }
        } else {
            if (!is_dir(WRITEPATH . 'locks')) { mkdir(WRITEPATH . 'locks', 0775, true); }
            $file = fopen(WRITEPATH . 'locks/agenda.lock', 'c');
            if (!$file || !flock($file, LOCK_EX)) { throw new DomainException('Não foi possível bloquear a agenda. Tente novamente.'); }
        }
        try {
            $this->db->transBegin();
            $result = $callback();
            if ($this->db->transStatus() === false) { throw new \RuntimeException('Falha ao gravar a transação.'); }
            $this->db->transCommit();
            return $result;
        } catch (Throwable $error) {
            $this->db->transRollback();
            throw $error;
        } finally {
            if ($mysql) { $this->db->query('SELECT RELEASE_LOCK(?)', [$lockName]); }
            if (is_resource($file)) { flock($file, LOCK_UN); fclose($file); }
        }
    }

    private function changed(string $kind): void
    {
        $this->db->table('changes')->insert(['kind' => $kind, 'created_at' => $this->now()]);
    }

    private function notify(int $userId, string $title, string $body, bool $email = false): void
    {
        $this->db->table('notifications')->insert(['user_id' => $userId, 'title' => $title, 'body' => $body, 'read_at' => null, 'created_at' => $this->now()]);
        if ($email) {
            $recipient = $this->row('users', $userId);
            $this->db->table('email_queue')->insert(['user_id' => $userId, 'recipient' => $recipient['email'], 'subject' => $title, 'body' => $body, 'status' => 'pending', 'attempts' => 0, 'last_error' => '', 'created_at' => $this->now(), 'sent_at' => null]);
        }
    }

    private function lessonMessage(array $lesson): string
    {
        $class = $this->row('classes', (int) $lesson['class_id']);
        $course = $this->row('courses', (int) $class['course_id']);
        $place = $lesson['location'];
        if (!empty($lesson['room_id'])) { $place = $this->row('rooms', (int) $lesson['room_id'])['name']; }
        return $course['name'] . ' · ' . $class['code'] . "\n" . date('d/m/Y H:i', strtotime($lesson['starts_at'])) . ' até ' . date('d/m/Y H:i', strtotime($lesson['ends_at'])) . "\nLocal: " . $place . ($lesson['city'] ? ' — ' . $lesson['city'] : '') . "\nConteúdo: " . ($lesson['content'] ?: 'A definir') . "\nConsulte o PAC Agenda para os detalhes atualizados.";
    }

    public function saveLesson(array $user, array $data, ?int $id = null): array
    {
        $this->management($user);
        return $this->write(function () use ($user, $data, $id) {
            $old = $id ? $this->row('lessons', $id) : null;
            [$start, $end] = $this->interval($data);
            $instructor = $this->instructor($data['instructor_id'] ?? null);
            $class = $this->row('classes', $this->positive($data['class_id'] ?? null, 'a turma'));
            $roomId = empty($data['room_id']) ? null : $this->positive($data['room_id'], 'a sala');
            if ($roomId) { $this->row('rooms', $roomId); }
            $location = $this->text($data['location'] ?? '', 'o local da aula', 190, !$roomId);
            $city = $this->text($data['city'] ?? $class['city'], 'a cidade', 120, false);
            foreach (['class_id' => ['id' => $class['id'], 'message' => 'Esta turma já tem um instrutor neste horário.'], 'instructor_id' => ['id' => $instructor['id'], 'message' => 'Este instrutor já tem uma aula neste horário.']] as $field => $check) {
                if ($this->conflict('lessons', [$field => $check['id']], $start, $end, $id)) { throw new DomainException($check['message']); }
            }
            if ($roomId && ($this->conflict('lessons', ['room_id' => $roomId], $start, $end, $id) || $this->conflict('reservations', ['resource_type' => 'room', 'resource_id' => $roomId], $start, $end))) {
                throw new DomainException('Esta sala já está ocupada ou reservada neste horário.');
            }
            $tripQuery = $this->db->table('trips')->where('instructor_id', $instructor['id'])->where('starts_at <', $end)->where('ends_at >', $start);
            foreach ($tripQuery->get()->getResultArray() as $trip) {
                if (!$id || (int) $trip['lesson_id'] !== $id) { throw new DomainException('O instrutor está programado para outra viagem neste horário.'); }
            }
            if ($id) {
                foreach ($this->db->table('trips')->where('lesson_id', $id)->get()->getResultArray() as $trip) {
                    if ((int) $trip['instructor_id'] !== (int) $instructor['id'] || $trip['starts_at'] > $start || $trip['ends_at'] < $end) {
                        throw new DomainException('A aula tem uma viagem vinculada. Ajuste ou remova a viagem antes de mudar o instrutor ou sair do período reservado.');
                    }
                }
            }
            $record = ['class_id' => (int) $class['id'], 'instructor_id' => (int) $instructor['id'], 'room_id' => $roomId, 'location' => $location, 'city' => $city, 'starts_at' => $start, 'ends_at' => $end, 'content' => $this->text($data['content'] ?? '', 'o conteúdo', 10000, false), 'created_by' => $old['created_by'] ?? $user['id'], 'updated_at' => $this->now()];
            if ($id) { $this->db->table('lessons')->where('id', $id)->update($record); }
            else { $this->db->table('lessons')->insert($record); $id = (int) $this->db->insertID(); }
            $title = $old ? 'Sua agenda foi atualizada' : 'Nova aula na sua agenda';
            $body = ($old ? "Uma aula foi alterada.\nAntes:\n" . $this->lessonMessage($old) . "\n\nAgora:\n" : '') . $this->lessonMessage($record);
            $this->notify((int) $instructor['id'], $title, $body, true);
            if ($old && (int) $old['instructor_id'] !== (int) $instructor['id']) { $this->notify((int) $old['instructor_id'], 'Aula retirada da sua agenda', $this->lessonMessage($old), true); }
            $this->changed('lesson');
            return $record + ['id' => $id];
        });
    }

    public function createCoursePlans(array $user, array $data): array
    {
        $this->management($user);
        $classId = isset($data['class_id']) && (int) $data['class_id'] > 0 ? (int) $data['class_id'] : null;
        if ($classId) {
            $class = $this->row('classes', $classId);
            $course = $this->row('courses', (int) $class['course_id']);
        } else {
            $course = $this->row('courses', $this->positive($data['course_id'] ?? null, 'o curso'));
        }
        $turn = $this->text($data['turn'] ?? '', 'o turno', 20);
        $times = ['morning' => ['08:00:00', '12:00:00'], 'afternoon' => ['13:30:00', '17:30:00'], 'night' => ['19:00:00', '22:00:00']];
        if (!isset($times[$turn])) { throw new DomainException('Selecione manhã, tarde ou noite.'); }
        if (!$course['starts_on'] || !$course['ends_on'] || !$course['turn'] || $course['weekdays'] === null || $course['weekdays'] === '') { throw new DomainException('Configure o período, turno e dias da semana do curso antes de planejar.'); }
        $allowOutsideSchedule = filter_var($data['allow_outside_schedule'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$allowOutsideSchedule && $turn !== $course['turn']) { throw new DomainException('O planejamento deve usar o turno configurado para o curso.'); }
        $weekdays = array_map('intval', explode(',', (string) $course['weekdays']));
        $dates = $data['dates'] ?? null;
        if (!is_array($dates) || !$dates || count($dates) > 62) { throw new DomainException('Selecione de 1 a 62 dias para o curso.'); }
        $dates = array_values(array_unique($dates));
        return $this->write(function () use ($user, $classId, $turn, $times, $dates, $course, $weekdays, $allowOutsideSchedule) {
            $classId ??= $this->ensureCourseClass($course);
            $created = [];
            foreach ($dates as $day) {
                $date = is_string($day) ? DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
                if (!$date || $date->format('Y-m-d') !== $day) { throw new DomainException('Uma das datas selecionadas é inválida.'); }
                if (!$allowOutsideSchedule && ($day < $course['starts_on'] || $day > $course['ends_on'] || !in_array((int) $date->format('w'), $weekdays, true))) {
                    throw new DomainException('Este dia não está no plano deste curso. Confirme a exceção para continuar.');
                }
                $start = $day . ' ' . $times[$turn][0];
                $minutes = max(30, (int) round((float) ($course['daily_hours'] ?? 4) * 60));
                $end = (new DateTimeImmutable($start))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
                if ($this->conflict('lessons', ['class_id' => $classId], $start, $end) || $this->conflict('course_plans', ['class_id' => $classId], $start, $end)) {
                    throw new DomainException('A turma já possui programação em um dos dias selecionados.');
                }
                $record = ['class_id' => $classId, 'starts_at' => $start, 'ends_at' => $end, 'created_by' => $user['id'], 'created_at' => $this->now()];
                $this->db->table('course_plans')->insert($record);
                $created[] = $record + ['id' => (int) $this->db->insertID()];
            }
            $this->changed('course_plan');
            return $created;
        });
    }

    public function deleteCoursePlan(array $user, int $id): void
    {
        $this->management($user);
        $this->write(function () use ($id) {
            $this->row('course_plans', $id);
            $this->db->table('course_plans')->where('id', $id)->delete();
            $this->changed('course_plan');
        });
    }

    public function scheduleCourseInstructor(array $user, array $data): array
    {
        $this->management($user);
        $course = $this->row('courses', $this->positive($data['course_id'] ?? null, 'o curso'));
        $classId = isset($data['class_id']) && (int) $data['class_id'] > 0 ? (int) $data['class_id'] : null;
        if ($classId) {
            $class = $this->row('classes', $classId);
            if ((int) $class['course_id'] !== (int) $course['id']) { throw new DomainException('A turma selecionada não pertence a este curso.'); }
        }
        $instructor = $this->instructor($data['instructor_id'] ?? null);
        $turn = $this->text($data['turn'] ?? '', 'o turno', 20);
        $times = ['morning' => ['08:00:00', '12:00:00'], 'afternoon' => ['13:30:00', '17:30:00'], 'night' => ['19:00:00', '22:00:00']];
        if (!isset($times[$turn])) { throw new DomainException('Selecione manhã, tarde ou noite.'); }
        $allowOutsideSchedule = filter_var($data['allow_outside_schedule'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$allowOutsideSchedule && $turn !== $course['turn']) { throw new DomainException('O planejamento deve usar o turno configurado para o curso.'); }
        $weekdays = array_map('intval', explode(',', (string) $course['weekdays']));
        $dates = $data['dates'] ?? null;
        if (!is_array($dates) || !$dates || count($dates) > 62) { throw new DomainException('Selecione de 1 a 62 dias para o curso.'); }
        $dates = array_values(array_unique($dates));
        $content = $this->text($data['content'] ?? '', 'o conteúdo', 6000, false);
        return $this->write(function () use ($user, $course, $classId, $instructor, $turn, $times, $allowOutsideSchedule, $weekdays, $dates, $content) {
            $classId ??= $this->ensureCourseClass($course);
            $class = $this->row('classes', $classId);
            $created = [];
            foreach ($dates as $day) {
                $date = is_string($day) ? DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
                if (!$date || $date->format('Y-m-d') !== $day) { throw new DomainException('Uma das datas selecionadas é inválida.'); }
                if (!$allowOutsideSchedule && ($day < $course['starts_on'] || $day > $course['ends_on'] || !in_array((int) $date->format('w'), $weekdays, true))) { throw new DomainException('Este dia não está no plano deste curso.'); }
                $start = $day . ' ' . $times[$turn][0];
                $minutes = max(30, (int) round((float) ($course['daily_hours'] ?? 4) * 60));
                $end = (new DateTimeImmutable($start))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
                $block = $this->db->table('schedule_blocks')->where(['status' => 'approved', 'block_date' => $day])->groupStart()->where('user_id', null)->orWhere('user_id', (int) $instructor['id'])->groupEnd()->groupStart()->where('turn', 'all')->orWhere('turn', $turn)->groupEnd()->countAllResults();
                if ($block) { throw new DomainException('A agenda do instrutor está fechada em um dos dias selecionados.'); }
                if ($this->conflict('lessons', ['instructor_id' => (int) $instructor['id']], $start, $end)) { throw new DomainException('O instrutor já está ocupado em um dos dias selecionados.'); }
                if ($this->conflict('trips', ['instructor_id' => (int) $instructor['id']], $start, $end)) { throw new DomainException('O instrutor possui deslocamento em um dos dias selecionados.'); }
                if ($this->conflict('lessons', ['class_id' => $classId], $start, $end) || $this->conflict('course_plans', ['class_id' => $classId], $start, $end)) { throw new DomainException('A turma já possui programação em um dos dias selecionados.'); }
                $newHours = max(0, strtotime($end) - strtotime($start)) / 3600;
                if ($course['course_type'] === 'external') { $newHours += ((int) $course['travel_out_minutes'] + (int) $course['travel_back_minutes'] + 20) / 60; }
                $load = $this->workload((int) $instructor['id'], $day);
                if ($load['day'] + $newHours > 10.00001) { throw new DomainException('Este planejamento ultrapassa o limite de 10 horas de trabalho no dia.'); }
                if ($load['week'] + $newHours > 44.00001) { throw new DomainException('Este planejamento ultrapassa o limite de 44 horas de trabalho na semana.'); }
                $place = $course['location'] ?: 'A definir';
                if (!empty($course['address'])) { $place .= ' · ' . $course['address']; }
                $record = ['class_id' => $classId, 'instructor_id' => (int) $instructor['id'], 'room_id' => null, 'location' => $place, 'city' => $class['city'] === 'A definir' ? '' : $class['city'], 'starts_at' => $start, 'ends_at' => $end, 'content' => $content ?: (string) ($course['notes'] ?? ''), 'created_by' => (int) $user['id'], 'updated_at' => $this->now()];
                $this->db->table('lessons')->insert($record);
                $created[] = $record + ['id' => (int) $this->db->insertID()];
            }
            $lines = array_map(fn ($lesson) => date('d/m/Y H:i', strtotime($lesson['starts_at'])) . ' até ' . date('H:i', strtotime($lesson['ends_at'])), $created);
            $travelNotice = $course['course_type'] === 'external' ? "\nCurso externo: considere " . (int) $course['travel_out_minutes'] . ' min de ida, ' . (int) $course['travel_back_minutes'] . " min de volta e chegada 20 minutos antes da saída." : '';
            $this->notify((int) $instructor['id'], 'Novas aulas na sua agenda', $course['name'] . "\n" . implode("\n", $lines) . $travelNotice . "\nConsulte o PAC Agenda para os detalhes atualizados.", true);
            $this->changed('lesson');
            return $created;
        });
    }

    public function deleteLesson(array $user, int $id): void
    {
        $this->management($user);
        $this->write(function () use ($id) {
            $old = $this->row('lessons', $id);
            if ($this->db->table('trips')->where('lesson_id', $id)->countAllResults()) { throw new DomainException('Remova ou desvincule a viagem antes de excluir esta aula.'); }
            $this->db->table('lessons')->where('id', $id)->delete();
            $this->notify((int) $old['instructor_id'], 'Aula cancelada', $this->lessonMessage($old), true);
            $this->changed('lesson');
        });
    }

    public function saveContent(array $user, int $id, string $content): void
    {
        $this->staff($user);
        $this->write(function () use ($user, $id, $content) {
            $lesson = $this->row('lessons', $id);
            if ($user['role'] === 'instructor' && (int) $lesson['instructor_id'] !== (int) $user['id']) { throw new DomainException('Você só pode editar o conteúdo das suas aulas.'); }
            $content = $this->text($content, 'o conteúdo', 10000, false);
            $this->db->table('lessons')->where('id', $id)->update(['content' => $content, 'updated_at' => $this->now()]);
            $lesson['content'] = $content;
            $this->notify((int) $lesson['instructor_id'], 'Conteúdo da aula atualizado', $this->lessonMessage($lesson), true);
            $this->changed('content');
        });
    }

    public function requestHours(array $user, array $data): array
    {
        $this->staff($user);
        return $this->write(function () use ($user, $data) {
            $day = $this->text($data['work_date'] ?? '', 'a data', 10);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (!$date || $date->format('Y-m-d') !== $day) { throw new DomainException('Informe uma data válida.'); }
            $hours = $data['hours'] ?? null;
            if (!is_numeric($hours) || !is_finite((float) $hours) || (float) $hours <= 0 || (float) $hours > 10 || abs(round((float) $hours, 2) - (float) $hours) > 0.00001) { throw new DomainException('Informe de 0,01 a 10 horas, com até duas casas decimais.'); }
            $hours = round((float) $hours, 2);
            $dateForWeek = new DateTimeImmutable($day);
            $weekStart = $dateForWeek->modify('monday this week')->format('Y-m-d');
            $weekEnd = (new DateTimeImmutable($weekStart))->modify('+7 days')->format('Y-m-d');
            $pendingDay = (float) ($this->db->table('hour_requests')->selectSum('hours', 'total')->where(['user_id' => $user['id'], 'work_date' => $day, 'status' => 'pending'])->get()->getRowArray()['total'] ?? 0);
            $pendingWeek = (float) ($this->db->table('hour_requests')->selectSum('hours', 'total')->where(['user_id' => $user['id'], 'status' => 'pending'])->where('work_date >=', $weekStart)->where('work_date <', $weekEnd)->get()->getRowArray()['total'] ?? 0);
            $load = $this->workload((int) $user['id'], $day);
            if ($load['day'] + $pendingDay + $hours > 10.00001) { throw new DomainException('A solicitação ultrapassa o limite de 10 horas de trabalho no dia.'); }
            if ($load['week'] + $pendingWeek + $hours > 44.00001) { throw new DomainException('A solicitação ultrapassa o limite de 44 horas de trabalho na semana.'); }
            $turn = $this->text($data['turn'] ?? 'morning', 'o turno', 20);
            if (!in_array($turn, ['morning', 'afternoon', 'night'], true)) { throw new DomainException('Selecione o turno das horas fora de sala.'); }
            $record = ['user_id' => (int) $user['id'], 'work_date' => $day, 'turn' => $turn, 'hours' => $hours, 'reason' => $this->text($data['reason'] ?? '', 'a justificativa', 3000), 'status' => 'pending', 'manager_note' => '', 'reviewed_by' => null, 'reviewed_at' => null, 'created_at' => $this->now()];
            $this->db->table('hour_requests')->insert($record);
            $id = (int) $this->db->insertID();
            foreach ($this->db->table('users')->whereIn('role', ['manager', 'coordinator'])->where('active', 1)->get()->getResultArray() as $reviewer) { $this->notify((int) $reviewer['id'], 'Novo pedido de horas', $user['name'] . ' solicitou ' . $hours . 'h fora de sala para ' . date('d/m/Y', strtotime($day)) . '.'); }
            $this->changed('hours');
            return $record + ['id' => $id];
        });
    }

    public function reviewHours(array $user, int $id, array $data): void
    {
        $this->roles($user, ['manager', 'coordinator']);
        $this->write(function () use ($user, $id, $data) {
            $request = $this->row('hour_requests', $id);
            if ($request['status'] !== 'pending') { throw new DomainException('Este pedido já foi analisado.'); }
            $status = $data['status'] ?? '';
            if (!in_array($status, ['approved', 'rejected'], true)) { throw new DomainException('Selecione aprovar ou recusar.'); }
            $note = $this->text($data['manager_note'] ?? '', 'o parecer', 2000, $status === 'rejected');
            $this->db->table('hour_requests')->where('id', $id)->update(['status' => $status, 'manager_note' => $note, 'reviewed_by' => $user['id'], 'reviewed_at' => $this->now()]);
            $this->notify((int) $request['user_id'], $status === 'approved' ? 'Pedido de horas aprovado' : 'Pedido de horas recusado', $request['hours'] . 'h em ' . date('d/m/Y', strtotime($request['work_date'])) . ($note ? '. Parecer: ' . $note : '. As horas já estão no seu total do mês.'));
            $this->changed('hours');
        });
    }

    public function saveScheduleBlock(array $user, array $data): array
    {
        $this->staff($user);
        return $this->write(function () use ($user, $data) {
            $day = $this->text($data['block_date'] ?? '', 'a data', 10);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (!$date || $date->format('Y-m-d') !== $day) { throw new DomainException('Informe uma data válida.'); }
            $turn = $this->text($data['turn'] ?? '', 'o turno', 20);
            if (!in_array($turn, ['all', 'morning', 'afternoon', 'night'], true)) { throw new DomainException('Selecione um turno válido.'); }
            $type = $this->text($data['type'] ?? '', 'o tipo de fechamento', 30);
            if (!in_array($type, ['holiday', 'formative', 'call', 'travel', 'medical', 'personal', 'other'], true)) { throw new DomainException('Selecione o motivo do fechamento.'); }
            $management = in_array($user['role'], ['manager', 'coordinator'], true);
            $global = $management && filter_var($data['global'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $record = ['user_id' => $global ? null : (int) $user['id'], 'block_date' => $day, 'turn' => $turn, 'type' => $type, 'reason' => $this->text($data['reason'] ?? '', 'a justificativa', 3000), 'status' => $management ? 'approved' : 'pending', 'requested_by' => (int) $user['id'], 'reviewed_by' => $management ? (int) $user['id'] : null, 'review_note' => '', 'reviewed_at' => $management ? $this->now() : null, 'created_at' => $this->now()];
            $this->db->table('schedule_blocks')->insert($record);
            $id = (int) $this->db->insertID();
            if (!$management) {
                foreach ($this->db->table('users')->whereIn('role', ['manager', 'coordinator'])->where('active', 1)->get()->getResultArray() as $reviewer) { $this->notify((int) $reviewer['id'], 'Novo pedido de fechamento de agenda', $user['name'] . ' pediu bloqueio em ' . date('d/m/Y', strtotime($day)) . '.'); }
            }
            $this->changed('schedule_block');
            return $record + ['id' => $id];
        });
    }

    public function reviewScheduleBlock(array $user, int $id, array $data): void
    {
        $this->management($user);
        $this->write(function () use ($user, $id, $data) {
            $block = $this->row('schedule_blocks', $id);
            if ($block['status'] !== 'pending') { throw new DomainException('Este pedido já foi analisado.'); }
            $status = (string) ($data['status'] ?? '');
            if (!in_array($status, ['approved', 'rejected'], true)) { throw new DomainException('Selecione aprovar ou recusar.'); }
            $note = $this->text($data['review_note'] ?? '', 'o parecer', 2000, $status === 'rejected');
            $this->db->table('schedule_blocks')->where('id', $id)->update(['status' => $status, 'reviewed_by' => (int) $user['id'], 'review_note' => $note, 'reviewed_at' => $this->now()]);
            $this->notify((int) $block['requested_by'], $status === 'approved' ? 'Fechamento de agenda aprovado' : 'Fechamento de agenda recusado', date('d/m/Y', strtotime($block['block_date'])) . ($note ? '. Parecer: ' . $note : '.'));
            $this->changed('schedule_block');
        });
    }

    public function saveTrip(array $user, array $data, ?int $id = null): array
    {
        $this->management($user);
        return $this->write(function () use ($user, $data, $id) {
            $old = $id ? $this->row('trips', $id) : null;
            [$start, $end] = $this->interval($data);
            $vehicle = $this->row('vehicles', $this->positive($data['vehicle_id'] ?? null, 'o veículo'));
            $instructor = $this->instructor($data['instructor_id'] ?? null);
            $lessonId = empty($data['lesson_id']) ? null : $this->positive($data['lesson_id'], 'a aula');
            if ($lessonId) {
                $lesson = $this->row('lessons', $lessonId);
                if ((int) $lesson['instructor_id'] !== (int) $instructor['id'] || $start > $lesson['starts_at'] || $end < $lesson['ends_at']) { throw new DomainException('A viagem deve abranger o horário da aula e ter o mesmo instrutor.'); }
            }
            if ($this->conflict('trips', ['vehicle_id' => $vehicle['id']], $start, $end, $id)) { throw new DomainException('Este veículo já tem uma viagem neste horário.'); }
            if ($this->conflict('trips', ['instructor_id' => $instructor['id']], $start, $end, $id)) { throw new DomainException('Este instrutor já está em outra viagem neste horário.'); }
            if ($this->conflict('lessons', ['instructor_id' => $instructor['id']], $start, $end, $lessonId)) { throw new DomainException('O instrutor tem outra aula dentro do período da viagem.'); }
            [$lat, $lng] = $this->coordinates($data);
            $record = ['vehicle_id' => (int) $vehicle['id'], 'instructor_id' => (int) $instructor['id'], 'lesson_id' => $lessonId, 'city' => $this->text($data['city'] ?? '', 'a cidade', 120), 'destination' => $this->text($data['destination'] ?? '', 'o destino', 190), 'latitude' => $lat, 'longitude' => $lng, 'starts_at' => $start, 'ends_at' => $end, 'notes' => $this->text($data['notes'] ?? '', 'as observações', 3000, false), 'created_by' => $old['created_by'] ?? $user['id']];
            if ($id) { $this->db->table('trips')->where('id', $id)->update($record); }
            else { $this->db->table('trips')->insert($record); $id = (int) $this->db->insertID(); }
            $this->notify((int) $instructor['id'], $old ? 'Viagem atualizada' : 'Nova viagem programada', $record['city'] . ' · ' . $record['destination'] . ' · ' . date('d/m/Y H:i', strtotime($start)) . ' · ' . $vehicle['name'], true);
            if ($old && (int) $old['instructor_id'] !== (int) $instructor['id']) { $this->notify((int) $old['instructor_id'], 'Viagem retirada da sua programação', $old['city'] . ' · ' . date('d/m/Y H:i', strtotime($old['starts_at'])), true); }
            $this->changed('trip');
            return $record + ['id' => $id];
        });
    }

    public function deleteTrip(array $user, int $id): void
    {
        $this->management($user);
        $this->write(function () use ($id) {
            $trip = $this->row('trips', $id);
            $this->db->table('trips')->where('id', $id)->delete();
            $this->notify((int) $trip['instructor_id'], 'Viagem cancelada', $trip['city'] . ' · ' . date('d/m/Y H:i', strtotime($trip['starts_at'])), true);
            $this->changed('trip');
        });
    }

    public function saveReservation(array $user, array $data): array
    {
        $this->staff($user);
        return $this->write(function () use ($user, $data) {
            [$start, $end] = $this->interval($data);
            $type = $data['resource_type'] ?? '';
            if (!in_array($type, ['room', 'tablet'], true)) { throw new DomainException('Selecione sala ou tablets.'); }
            $resource = $this->row($type === 'room' ? 'rooms' : 'tablet_pools', $this->positive($data['resource_id'] ?? null, 'o recurso'));
            $quantity = $type === 'room' ? 1 : $this->positive($data['quantity'] ?? null, 'a quantidade de tablets');
            if ($type === 'room') {
                if ($this->conflict('lessons', ['room_id' => $resource['id']], $start, $end) || $this->conflict('reservations', ['resource_type' => 'room', 'resource_id' => $resource['id']], $start, $end)) { throw new DomainException('Esta sala já está ocupada ou reservada neste horário.'); }
            } else {
                $overlaps = $this->db->table('reservations')->where(['resource_type' => 'tablet', 'resource_id' => $resource['id']])->where('starts_at <', $end)->where('ends_at >', $start)->get()->getResultArray();
                $points = [[$start, $quantity], [$end, -$quantity]];
                foreach ($overlaps as $reservation) { $points[] = [max($start, $reservation['starts_at']), (int) $reservation['quantity']]; $points[] = [min($end, $reservation['ends_at']), -(int) $reservation['quantity']]; }
                usort($points, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
                $used = 0;
                foreach ($points as [, $delta]) { $used += $delta; if ($used > (int) $resource['quantity']) { throw new DomainException('Não há tablets suficientes nesse período. Capacidade do lote: ' . $resource['quantity'] . '.'); } }
            }
            $record = ['resource_type' => $type, 'resource_id' => (int) $resource['id'], 'quantity' => $quantity, 'user_id' => (int) $user['id'], 'starts_at' => $start, 'ends_at' => $end, 'purpose' => $this->text($data['purpose'] ?? '', 'a finalidade', 2000)];
            $this->db->table('reservations')->insert($record);
            $id = (int) $this->db->insertID();
            $this->changed('reservation');
            return $record + ['id' => $id];
        });
    }

    public function deleteReservation(array $user, int $id): void
    {
        $this->staff($user);
        $this->write(function () use ($user, $id) {
            $reservation = $this->row('reservations', $id);
            if ($user['role'] === 'instructor' && (int) $reservation['user_id'] !== (int) $user['id']) { throw new DomainException('Você só pode cancelar as suas reservas.'); }
            $this->db->table('reservations')->where('id', $id)->delete();
            $this->changed('reservation');
        });
    }

    public function saveCatalog(array $user, string $type, array $data, ?int $id = null): array
    {
        $this->management($user);
        if (!in_array($type, ['courses', 'classes', 'rooms', 'tablet_pools', 'vehicles'], true)) { throw new DomainException('Cadastro inválido.'); }
        return $this->write(function () use ($type, $data, $id) {
            if ($id) { $this->row($type, $id); }
            $record = ['name' => $this->text($data['name'] ?? '', 'o nome', 120)];
            if ($type === 'courses') {
                $color = (string) ($data['color'] ?? '');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { throw new DomainException('Selecione uma cor válida para o curso.'); }
                $record['color'] = $color;
                $startsOn = (string) ($data['starts_on'] ?? '');
                $endsOn = (string) ($data['ends_on'] ?? '');
                $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
                $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
                if (!$startDate || $startDate->format('Y-m-d') !== $startsOn || !$endDate || $endDate->format('Y-m-d') !== $endsOn) { throw new DomainException('Informe um período válido para o curso.'); }
                if ($endsOn < $startsOn) { throw new DomainException('A finalização do curso deve ser posterior ao início.'); }
                $turn = (string) ($data['turn'] ?? '');
                if (!in_array($turn, ['morning', 'afternoon', 'night'], true)) { throw new DomainException('Selecione o turno do curso.'); }
                $weekdays = $data['weekdays'] ?? null;
                if (!is_array($weekdays)) { throw new DomainException('Selecione pelo menos um dia da semana.'); }
                $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
                sort($weekdays);
                if (!$weekdays || count($weekdays) > 7 || array_filter($weekdays, static fn ($day) => $day < 0 || $day > 6)) { throw new DomainException('Selecione dias da semana válidos.'); }
                $record += ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'turn' => $turn, 'weekdays' => implode(',', $weekdays)];
                $courseType = (string) ($data['course_type'] ?? 'internal');
                if (!in_array($courseType, ['internal', 'external'], true)) { throw new DomainException('Informe se o curso é interno ou externo.'); }
                $dailyHours = (float) ($data['daily_hours'] ?? 4);
                $totalHours = (float) ($data['total_hours'] ?? 40);
                if ($dailyHours <= 0 || $dailyHours > 10 || $totalHours <= 0 || $totalHours > 5000) { throw new DomainException('Informe a carga diária e a carga horária total do curso.'); }
                $travelOut = $courseType === 'external' ? $this->positive($data['travel_out_minutes'] ?? null, 'o tempo de ida', 1440) : 0;
                $travelBack = $courseType === 'external' ? $this->positive($data['travel_back_minutes'] ?? null, 'o tempo de volta', 1440) : 0;
                $record += ['course_type' => $courseType, 'location' => $this->text($data['location'] ?? 'Unidade Senac', 'o local', 180), 'address' => $this->text($data['address'] ?? '', 'o endereço', 255, false), 'notes' => $this->text($data['notes'] ?? '', 'as observações', 3000, false), 'daily_hours' => round($dailyHours, 2), 'total_hours' => round($totalHours, 2), 'travel_out_minutes' => $travelOut, 'travel_back_minutes' => $travelBack];
                $holidays = array_flip(array_column($this->db->table('schedule_blocks')->select('block_date')->where(['user_id' => null, 'type' => 'holiday', 'status' => 'approved'])->get()->getResultArray(), 'block_date'));
                $remaining = (int) ceil($totalHours / $dailyHours);
                $cursor = $startDate;
                $guard = 0;
                while ($remaining > 0 && $guard++ < 1900) {
                    $cursorDay = $cursor->format('Y-m-d');
                    if (in_array((int) $cursor->format('w'), $weekdays, true) && !isset($holidays[$cursorDay])) { $remaining--; }
                    if ($remaining > 0) { $cursor = $cursor->modify('+1 day'); }
                }
                if ($remaining > 0) { throw new DomainException('Não foi possível calcular a previsão de término do curso.'); }
                $record['ends_on'] = $cursor->format('Y-m-d');
            }
            if ($type === 'classes') {
                $record['code'] = $this->text($data['code'] ?? '', 'o código da turma', 40);
                $record['course_id'] = $this->positive($data['course_id'] ?? null, 'o curso');
                $this->row('courses', $record['course_id']);
                $record['city'] = $this->text($data['city'] ?? '', 'a cidade', 120);
                $duplicate = $this->db->table($type)->where('code', $record['code']);
                if ($id) { $duplicate->where('id !=', $id); }
                if ($duplicate->countAllResults()) { throw new DomainException('Já existe uma turma com este código.'); }
            }
            if ($type === 'rooms') { $record['capacity'] = $this->positive($data['capacity'] ?? null, 'a capacidade', 5000); }
            if ($type === 'tablet_pools') {
                $record['quantity'] = $this->positive($data['quantity'] ?? null, 'a quantidade', 5000);
                if ($id) {
                    $points = [];
                    foreach ($this->db->table('reservations')->where(['resource_type' => 'tablet', 'resource_id' => $id])->where('ends_at >', $this->now())->get()->getResultArray() as $reservation) { $points[] = [$reservation['starts_at'], (int) $reservation['quantity']]; $points[] = [$reservation['ends_at'], -(int) $reservation['quantity']]; }
                    usort($points, static fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
                    $used = 0;
                    foreach ($points as [, $delta]) { $used += $delta; if ($used > $record['quantity']) { throw new DomainException('Existem reservas que excedem a nova quantidade de tablets.'); } }
                }
            }
            if ($type === 'vehicles') {
                $record['plate'] = strtoupper(str_replace([' ', '-'], '', $this->text($data['plate'] ?? '', 'a placa', 10)));
                if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $record['plate'])) { throw new DomainException('Informe uma placa brasileira válida.'); }
                $record['seats'] = $this->positive($data['seats'] ?? null, 'o número de lugares', 100);
                $duplicate = $this->db->table($type)->where('plate', $record['plate']);
                if ($id) { $duplicate->where('id !=', $id); }
                if ($duplicate->countAllResults()) { throw new DomainException('Esta placa já está cadastrada.'); }
            }
            $isNew = !$id;
            if ($id) { $this->db->table($type)->where('id', $id)->update($record); }
            else { $this->db->table($type)->insert($record); $id = (int) $this->db->insertID(); }
            if ($type === 'courses' && $isNew) { $this->ensureCourseClass($record + ['id' => $id]); }
            $this->changed('catalog');
            return $record + ['id' => $id];
        });
    }

    public function updates(array $user): array
    {
        $this->roles($user, ['manager', 'coordinator', 'instructor', 'student']);
        return ['version' => (int) ($this->db->table('changes')->selectMax('id', 'version')->get()->getRowArray()['version'] ?? 0), 'unread' => $this->db->table('notifications')->where('user_id', $user['id'])->where('read_at', null)->countAllResults(), 'notifications' => $this->db->table('notifications')->select('id,title,body,read_at,created_at')->where('user_id', $user['id'])->orderBy('id', 'DESC')->limit(30)->get()->getResultArray()];
    }

    public function readNotifications(array $user): void
    {
        $this->roles($user, ['manager', 'coordinator', 'instructor', 'student']);
        $this->db->table('notifications')->where('user_id', $user['id'])->where('read_at', null)->update(['read_at' => $this->now()]);
    }

    public function bootstrap(array $user, string $month, ?int $classId = null): array
    {
        $this->roles($user, ['manager', 'coordinator', 'instructor', 'student']);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || substr($month, 0, 4) < '2000' || substr($month, 0, 4) > '2100') { throw new DomainException('Selecione um mês válido.'); }
        $start = $month . '-01 00:00:00';
        $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d H:i:s');
        $cycleEndDate = new DateTimeImmutable($month . '-21 00:00:00');
        $cycleStartDate = $cycleEndDate->modify('-1 month');
        $cycleStart = $cycleStartDate->format('Y-m-d H:i:s');
        $cycleEnd = $cycleEndDate->format('Y-m-d H:i:s');
        $hourCycle = [
            'starts_on' => $cycleStartDate->format('Y-m-d'),
            'ends_on' => $cycleEndDate->format('Y-m-d'),
            'label' => $cycleStartDate->format('d/m/Y') . ' a ' . $cycleEndDate->modify('-1 day')->format('d/m/Y'),
        ];
        $isManager = $user['role'] === 'manager';
        $management = in_array($user['role'], ['manager', 'coordinator'], true);
        $student = $user['role'] === 'student';
        // Read revision first: a concurrent write during the snapshot triggers another refresh.
        $updates = $this->updates($user);
        $safeUser = array_intersect_key($user, array_flip(['id', 'badge', 'name', 'email', 'photo_url', 'role', 'must_change_password']));
        $lessons = $this->db->table('lessons l')->select('l.*, c.name AS class_name, c.code AS class_code, co.name AS course_name, co.color AS course_color, u.name AS instructor_name, u.photo_url AS instructor_photo, r.name AS room_name')->join('classes c', 'c.id=l.class_id')->join('courses co', 'co.id=c.course_id')->join('users u', 'u.id=l.instructor_id')->join('rooms r', 'r.id=l.room_id', 'left')->where('l.starts_at <', $end)->where('l.ends_at >', $start);
        if ($user['role'] === 'instructor') { $lessons->where('l.instructor_id', $user['id']); }
        if ($student) { $lessons->where('l.class_id', $classId ?: 0); }
        elseif ($classId) { $lessons->where('l.class_id', $classId); }
        $lessonRows = $lessons->orderBy('l.starts_at')->get()->getResultArray();
        $coursePlans = [];
        if ($management) {
            $coursePlans = $this->db->table('course_plans p')
                ->select('p.*, c.name AS class_name, c.code AS class_code, c.city, co.id AS course_id, co.name AS course_name, co.color AS course_color')
                ->join('classes c', 'c.id=p.class_id')->join('courses co', 'co.id=c.course_id')
                ->where('p.starts_at <', $end)->where('p.ends_at >', $start)
                ->orderBy('p.starts_at')->get()->getResultArray();
        }
        if ($student) {
            foreach ($lessonRows as &$lesson) { unset($lesson['created_by'], $lesson['latitude'], $lesson['longitude'], $lesson['instructor_id']); }
            unset($lesson);
        }
        $users = [];
        if ($isManager) { $users = $this->db->table('users')->select('id,badge,name,email,photo_url,role,active,must_change_password')->orderBy('name')->get()->getResultArray(); }
        elseif ($management) { $users = $this->db->table('users')->select('id,badge,name,email,photo_url,role,active')->where('role', 'instructor')->where('active', 1)->orderBy('name')->get()->getResultArray(); }
        elseif (!$student) { $users = [$safeUser]; }
        $requests = [];
        if (!$student) {
            $query = $this->db->table('hour_requests h')->select('h.*, u.name AS user_name, r.name AS reviewer_name')->join('users u', 'u.id=h.user_id')->join('users r', 'r.id=h.reviewed_by', 'left')->where('h.work_date >=', substr($cycleStart, 0, 10))->where('h.work_date <', substr($cycleEnd, 0, 10));
            if (!$management) { $query->where('h.user_id', $user['id']); }
            $requests = $query->orderBy('h.created_at', 'DESC')->orderBy('h.id', 'DESC')->get()->getResultArray();
        }
        $outsideHours = [];
        if (!$student) {
            $outsideQuery = $this->db->table('hour_requests h')
                ->select('h.id,h.user_id,h.work_date,h.turn,h.hours,h.reason,h.manager_note,h.reviewed_at,u.name AS user_name,r.name AS reviewer_name')
                ->join('users u', 'u.id=h.user_id')
                ->join('users r', 'r.id=h.reviewed_by', 'left')
                ->where('h.status', 'approved')
                ->where('h.work_date >=', substr($start, 0, 10))
                ->where('h.work_date <', substr($end, 0, 10));
            if (!$management) { $outsideQuery->where('h.user_id', $user['id']); }
            $outsideHours = $outsideQuery->orderBy('h.work_date')->orderBy('u.name')->orderBy('h.id')->get()->getResultArray();
        }
        $trips = [];
        if ($management) { $trips = $this->db->table('trips t')->select('t.*, v.name AS vehicle_name, v.plate AS vehicle_plate, u.name AS instructor_name')->join('vehicles v', 'v.id=t.vehicle_id')->join('users u', 'u.id=t.instructor_id')->where('t.starts_at <', $end)->where('t.ends_at >', $start)->orderBy('t.starts_at')->get()->getResultArray(); }
        $reservations = [];
        if (!$student) {
            $query = $this->db->table('reservations r')->select('r.*, u.name AS user_name')->join('users u', 'u.id=r.user_id')->where('r.starts_at <', $end)->where('r.ends_at >', $start);
            if (!$management) { $query->where('r.user_id', $user['id']); }
            foreach ($query->orderBy('r.starts_at')->get()->getResultArray() as $reservation) {
                $resource = $this->row($reservation['resource_type'] === 'room' ? 'rooms' : 'tablet_pools', (int) $reservation['resource_id']);
                $reservation['resource_name'] = $resource['name'];
                $reservations[] = $reservation;
            }
        }
        $blocksQuery = $this->db->table('schedule_blocks b')->select('b.*, u.name AS user_name, q.name AS requester_name, r.name AS reviewer_name')->join('users u', 'u.id=b.user_id', 'left')->join('users q', 'q.id=b.requested_by')->join('users r', 'r.id=b.reviewed_by', 'left')->where('b.block_date >=', substr($start, 0, 10))->where('b.block_date <', substr($end, 0, 10));
        if (!$management) { $blocksQuery->groupStart()->where('b.user_id', null)->orWhere('b.user_id', (int) $user['id'])->groupEnd(); }
        $scheduleBlocks = $student ? [] : $blocksQuery->orderBy('b.block_date')->orderBy('b.id')->get()->getResultArray();
        $summary = [];
        if (!$student) {
            $peopleQuery = $this->db->table('users')->select('id,name');
            if ($management) { $peopleQuery->where('role', 'instructor'); }
            else { $peopleQuery->where('id', $user['id']); }
            foreach ($peopleQuery->orderBy('name')->get()->getResultArray() as $person) {
                $scheduled = 0; $completed = 0;
                foreach ($this->db->table('lessons')->where('instructor_id', $person['id'])->where('starts_at <', $cycleEnd)->where('ends_at >', $cycleStart)->get()->getResultArray() as $lesson) {
                    $from = max(strtotime($cycleStart), strtotime($lesson['starts_at']));
                    $to = min(strtotime($cycleEnd), strtotime($lesson['ends_at']));
                    $scheduled += max(0, $to - $from) / 3600;
                    $completed += max(0, min($to, strtotime($this->now())) - $from) / 3600;
                }
                $outside = (float) ($this->db->table('hour_requests')->selectSum('hours', 'total')->where(['user_id' => $person['id'], 'status' => 'approved'])->where('work_date >=', substr($cycleStart, 0, 10))->where('work_date <', substr($cycleEnd, 0, 10))->get()->getRowArray()['total'] ?? 0);
                $summary[] = ['user_id' => (int) $person['id'], 'name' => $person['name'], 'class_hours' => round($scheduled, 2), 'completed_class_hours' => round($completed, 2), 'outside_hours' => round($outside, 2), 'total_hours' => round($scheduled + $outside, 2), 'level' => $outside >= 50 ? 'danger' : ($outside >= 40 ? 'warning' : 'normal')];
            }
        }
        return ['user' => $safeUser, 'month' => $month, 'hour_cycle' => $hourCycle, 'users' => $users, 'courses' => $this->db->table('courses')->orderBy('name')->get()->getResultArray(), 'classes' => $this->db->table('classes c')->select('c.*, co.name AS course_name, co.color AS course_color')->join('courses co', 'co.id=c.course_id')->orderBy('c.name')->get()->getResultArray(), 'rooms' => $student ? [] : $this->db->table('rooms')->orderBy('name')->get()->getResultArray(), 'tablet_pools' => $student ? [] : $this->db->table('tablet_pools')->orderBy('name')->get()->getResultArray(), 'vehicles' => $management ? $this->db->table('vehicles')->orderBy('name')->get()->getResultArray() : [], 'lessons' => $lessonRows, 'course_plans' => $coursePlans, 'requests' => $requests, 'outside_hours' => $outsideHours, 'schedule_blocks' => $scheduleBlocks, 'trips' => $trips, 'reservations' => $reservations, 'summary' => $summary] + $updates;
    }
}
