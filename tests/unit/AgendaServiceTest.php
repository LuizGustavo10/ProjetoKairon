<?php

declare(strict_types=1);

use App\Services\AgendaService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** @internal */
final class AgendaServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'App';
    protected $DBGroup = 'tests';

    private AgendaService $agenda;
    private array $manager;
    private array $coordinator;
    private array $instructor;
    private array $otherInstructor;
    private array $student;

    protected function setUp(): void
    {
        // Guard BEFORE migrations: tests must never touch the application database.
        self::assertSame('testing', ENVIRONMENT);
        $database = config('Database');
        self::assertSame('tests', $database->defaultGroup);
        self::assertSame('SQLite3', $database->tests['DBDriver']);
        self::assertSame(':memory:', $database->tests['database']);

        parent::setUp();

        $this->manager = $this->user(1, 'manager');
        $this->coordinator = $this->user(2, 'coordinator');
        $this->instructor = $this->user(3, 'instructor');
        $this->otherInstructor = $this->user(4, 'instructor');
        $this->student = $this->user(5, 'student');
        $this->db->table('courses')->insert(['id' => 1, 'name' => 'Tecnologia', 'color' => '#2563eb']);
        foreach ([1, 2, 3] as $id) {
            $this->db->table('classes')->insert(['id' => $id, 'code' => 'T' . $id, 'name' => 'Turma ' . $id, 'course_id' => 1, 'city' => 'Curitiba']);
            $this->db->table('rooms')->insert(['id' => $id, 'name' => 'Sala ' . $id, 'capacity' => 30]);
        }
        $this->db->table('tablet_pools')->insert(['id' => 1, 'name' => 'Tablets', 'quantity' => 10]);
        $this->db->table('vehicles')->insert(['id' => 1, 'name' => 'Veículo A', 'plate' => 'AAA1B23', 'seats' => 5]);
        $this->db->table('vehicles')->insert(['id' => 2, 'name' => 'Veículo B', 'plate' => 'BBB2C34', 'seats' => 5]);
        $this->agenda = new AgendaService();
    }

    private function user(int $id, string $role): array
    {
        $user = [
            'id' => $id, 'badge' => (string) (1000 + $id), 'name' => 'Pessoa ' . $id,
            'email' => 'person' . $id . '@example.com', 'role' => $role,
            'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT),
            'must_change_password' => 0, 'active' => 1,
            'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
        ];
        $this->db->table('users')->insert($user);
        return $user;
    }

    private function lesson(array $overrides = []): array
    {
        return array_replace([
            'class_id' => 1, 'instructor_id' => 3, 'room_id' => 1,
            'location' => 'Unidade Centro', 'city' => 'Curitiba',
            'starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 10:00:00',
            'content' => 'Introdução',
        ], $overrides);
    }

    private function reservation(array $overrides = []): array
    {
        return array_replace([
            'resource_type' => 'tablet', 'resource_id' => 1, 'quantity' => 6,
            'starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 09:00:00',
            'purpose' => 'Atividade em sala',
        ], $overrides);
    }

    private function trip(array $overrides = []): array
    {
        return array_replace([
            'vehicle_id' => 1, 'instructor_id' => 3, 'city' => 'Curitiba',
            'destination' => 'Unidade externa', 'latitude' => -25.4284, 'longitude' => -49.2733,
            'starts_at' => '2026-11-10 08:00:00', 'ends_at' => '2026-11-10 12:00:00',
            'notes' => 'Curso externo',
        ], $overrides);
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            self::fail('A operação deveria ser recusada pelo serviço.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function summary(string $month = '2026-11'): array
    {
        $data = $this->agenda->bootstrap($this->instructor, $month);
        self::assertCount(1, $data['summary']);
        return $data['summary'][0];
    }

    public function testInstructorReadsOnlyOwnScheduleAndCannotEditAnotherInstructorsContent(): void
    {
        $own = $this->agenda->saveLesson($this->manager, $this->lesson());
        $other = $this->agenda->saveLesson($this->coordinator, $this->lesson(['class_id' => 2, 'instructor_id' => 4, 'room_id' => 2]));
        $data = $this->agenda->bootstrap($this->instructor, '2026-11');
        self::assertCount(1, $data['lessons']);
        self::assertEquals($own['id'], $data['lessons'][0]['id']);
        self::assertCount(1, $data['users']);
        self::assertEquals(3, $data['users'][0]['id']);

        $this->agenda->saveContent($this->instructor, (int) $own['id'], 'Novo conteúdo planejado');
        self::assertSame('Novo conteúdo planejado', $this->db->table('lessons')->where('id', $own['id'])->get()->getRowArray()['content']);
        $this->reject(fn () => $this->agenda->saveContent($this->instructor, (int) $other['id'], 'Indevido'));
        self::assertSame('Introdução', $this->db->table('lessons')->where('id', $other['id'])->get()->getRowArray()['content']);
    }

    public function testStudentSelectsClassWithoutReceivingStaffPrivateData(): void
    {
        $this->agenda->saveLesson($this->manager, $this->lesson());
        $this->agenda->saveLesson($this->manager, $this->lesson(['class_id' => 2, 'instructor_id' => 4, 'room_id' => 2]));
        $this->agenda->requestHours($this->instructor, ['work_date' => '2026-11-11', 'hours' => 2, 'reason' => 'Planejamento']);
        $data = $this->agenda->bootstrap($this->student, '2026-11', 1);
        self::assertCount(1, $data['lessons']);
        self::assertEquals(1, $data['lessons'][0]['class_id']);
        foreach (['users', 'requests', 'summary', 'trips', 'reservations'] as $key) {
            self::assertSame([], $data[$key], $key . ' must remain private');
        }
        self::assertSame([], $this->agenda->bootstrap($this->student, '2026-11')['lessons']);
    }

    public function testOnlyManagementCanChangeSchedulingAndStudentsCannotRequestHours(): void
    {
        $this->reject(fn () => $this->agenda->saveLesson($this->instructor, $this->lesson()));
        $this->reject(fn () => $this->agenda->saveLesson($this->student, $this->lesson()));
        $this->reject(fn () => $this->agenda->requestHours($this->student, ['work_date' => '2026-11-11', 'hours' => 2, 'reason' => 'Planejamento']));
        self::assertSame(0, $this->db->table('lessons')->countAllResults());
    }

    public function testInstructorClassAndRoomConflictsAreRejectedButAdjacentLessonsAreAllowed(): void
    {
        $this->agenda->saveLesson($this->manager, $this->lesson());
        $overlap = ['starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 11:00:00'];
        foreach ([
            ['class_id' => 2, 'instructor_id' => 3, 'room_id' => 2],
            ['class_id' => 1, 'instructor_id' => 4, 'room_id' => 2],
            ['class_id' => 2, 'instructor_id' => 4, 'room_id' => 1],
        ] as $conflict) {
            $this->reject(fn () => $this->agenda->saveLesson($this->coordinator, $this->lesson(array_merge($overlap, $conflict))));
        }
        self::assertSame(1, $this->db->table('lessons')->countAllResults());
        $this->agenda->saveLesson($this->coordinator, $this->lesson(['starts_at' => '2026-11-10 10:00:00', 'ends_at' => '2026-11-10 12:00:00']));
        self::assertSame(2, $this->db->table('lessons')->countAllResults());
    }

    public function testReschedulingInstructorQueuesNotificationsForBothAffectedPeople(): void
    {
        $lesson = $this->agenda->saveLesson($this->manager, $this->lesson());
        $this->db->table('email_queue')->emptyTable();
        $this->db->table('notifications')->emptyTable();
        $version = $this->agenda->updates($this->instructor)['version'];

        $this->agenda->saveLesson($this->coordinator, $this->lesson(['instructor_id' => 4]), (int) $lesson['id']);
        $recipientIds = array_column($this->db->table('email_queue')->orderBy('user_id')->get()->getResultArray(), 'user_id');
        self::assertEquals([3, 4], $recipientIds);
        self::assertGreaterThan($version, $this->agenda->updates($this->instructor)['version']);
        self::assertGreaterThan(0, $this->agenda->updates($this->instructor)['unread']);
        self::assertGreaterThan(0, $this->agenda->updates($this->otherInstructor)['unread']);
    }

    public function testPendingRequestsDontCountAndOnlyManagerCanApproveExactlyOnce(): void
    {
        $request = $this->agenda->requestHours($this->instructor, ['work_date' => '2026-11-11', 'hours' => 8, 'reason' => 'Planejamento de curso']);
        self::assertEquals(0, $this->summary()['outside_hours']);
        $this->reject(fn () => $this->agenda->reviewHours($this->coordinator, (int) $request['id'], ['status' => 'approved', 'manager_note' => '']));
        $this->reject(fn () => $this->agenda->reviewHours($this->instructor, (int) $request['id'], ['status' => 'approved', 'manager_note' => '']));
        $this->agenda->reviewHours($this->manager, (int) $request['id'], ['status' => 'approved', 'manager_note' => 'Aprovado']);
        self::assertEquals(8, $this->summary()['outside_hours']);
        $this->reject(fn () => $this->agenda->reviewHours($this->manager, (int) $request['id'], ['status' => 'approved', 'manager_note' => '']));
        self::assertEquals(8, $this->summary()['outside_hours']);
    }

    public function testOnlyApprovedOutsideHoursAppearInTheScopedAgenda(): void
    {
        $request = $this->agenda->requestHours($this->instructor, [
            'work_date' => '2026-11-11',
            'hours' => 4,
            'reason' => 'Preparação de materiais e planejamento pedagógico.',
        ]);

        self::assertSame([], $this->agenda->bootstrap($this->coordinator, '2026-11')['outside_hours']);

        $this->agenda->reviewHours($this->manager, (int) $request['id'], [
            'status' => 'approved',
            'manager_note' => 'Atividade autorizada.',
        ]);

        $managementItems = $this->agenda->bootstrap($this->coordinator, '2026-11')['outside_hours'];
        self::assertCount(1, $managementItems);
        self::assertSame('2026-11-11', $managementItems[0]['work_date']);
        self::assertEquals(4, $managementItems[0]['hours']);
        self::assertCount(1, $this->agenda->bootstrap($this->instructor, '2026-11')['outside_hours']);
        self::assertSame([], $this->agenda->bootstrap($this->otherInstructor, '2026-11')['outside_hours']);
        self::assertSame([], $this->agenda->bootstrap($this->student, '2026-11')['outside_hours']);
    }

    public function testMonthlyWarningThresholdsAreInclusiveAt40And50Hours(): void
    {
        foreach ([['2026-11-01', 20], ['2026-11-02', 19.5]] as [$date, $hours]) {
            $this->approve($date, $hours);
        }
        self::assertSame('normal', $this->summary()['level']);
        $this->approve('2026-11-03', .5);
        self::assertEquals(40, $this->summary()['outside_hours']);
        self::assertSame('warning', $this->summary()['level']);
        $this->approve('2026-11-04', 10);
        self::assertEquals(50, $this->summary()['outside_hours']);
        self::assertSame('danger', $this->summary()['level']);
        $this->approve('2026-12-01', 7);
        self::assertEquals(50, $this->summary()['outside_hours']);
        self::assertEquals(7, $this->summary('2026-12')['outside_hours']);
    }

    private function approve(string $date, float $hours): void
    {
        $request = $this->agenda->requestHours($this->instructor, ['work_date' => $date, 'hours' => $hours, 'reason' => 'Planejamento de aulas']);
        $this->agenda->reviewHours($this->manager, (int) $request['id'], ['status' => 'approved', 'manager_note' => '']);
    }

    public function testRejectedHoursDontCountAndInstructorCannotImpersonateRequestOwner(): void
    {
        $request = $this->agenda->requestHours($this->instructor, ['user_id' => 4, 'work_date' => '2026-11-11', 'hours' => 8, 'reason' => 'Planejamento']);
        self::assertEquals(3, $this->db->table('hour_requests')->where('id', $request['id'])->get()->getRowArray()['user_id']);
        $this->agenda->reviewHours($this->manager, (int) $request['id'], ['status' => 'rejected', 'manager_note' => 'Rever a necessidade']);
        self::assertEquals(0, $this->summary()['outside_hours']);
        self::assertSame([], $this->agenda->bootstrap($this->otherInstructor, '2026-11')['requests']);
    }

    public function testLessonsCrossingDay20BoundaryContributeOnlyTheirDurationInEachCycle(): void
    {
        $this->agenda->saveLesson($this->manager, $this->lesson(['starts_at' => '2020-01-19 23:00:00', 'ends_at' => '2020-01-20 02:00:00']));
        self::assertEquals(1, $this->summary('2020-01')['class_hours']);
        self::assertEquals(2, $this->summary('2020-02')['class_hours']);
        self::assertEquals(1, $this->summary('2020-01')['completed_class_hours']);
        self::assertEquals(2, $this->summary('2020-02')['total_hours']);
    }

    public function testHourCycleStartsOnDay20AndEndsBeforeTheNextDay20(): void
    {
        $this->approve('2026-10-19', 3);
        $this->approve('2026-10-20', 4);
        $this->approve('2026-11-19', 5);
        $this->approve('2026-11-20', 6);

        $november = $this->agenda->bootstrap($this->instructor, '2026-11');
        self::assertSame('2026-10-20', $november['hour_cycle']['starts_on']);
        self::assertSame('2026-11-20', $november['hour_cycle']['ends_on']);
        self::assertSame('20/10/2026 a 20/11/2026', $november['hour_cycle']['label']);
        self::assertEquals(9, $november['summary'][0]['outside_hours']);
        self::assertSame(['2026-11-19', '2026-10-20'], array_column($november['requests'], 'work_date'));
        self::assertEquals(6, $this->summary('2026-12')['outside_hours']);
    }

    public function testRoomReservationAndLessonConflictsAreCheckedInBothDirections(): void
    {
        $this->agenda->saveReservation($this->instructor, $this->reservation(['resource_type' => 'room', 'resource_id' => 1, 'quantity' => 1]));
        $this->reject(fn () => $this->agenda->saveLesson($this->manager, $this->lesson()));
        $this->agenda->saveLesson($this->manager, $this->lesson(['room_id' => 2]));
        $this->reject(fn () => $this->agenda->saveReservation($this->instructor, $this->reservation(['resource_type' => 'room', 'resource_id' => 2, 'quantity' => 1])));
    }

    public function testTabletCapacityUsesSimultaneousPeakAndAllowsAdjacentReservations(): void
    {
        $this->agenda->saveReservation($this->instructor, $this->reservation());
        $this->agenda->saveReservation($this->otherInstructor, $this->reservation(['starts_at' => '2026-11-10 09:00:00', 'ends_at' => '2026-11-10 10:00:00']));
        // 6+4 at each instant; adding all intersecting rows would incorrectly reject 16.
        $this->agenda->saveReservation($this->coordinator, $this->reservation(['quantity' => 4, 'ends_at' => '2026-11-10 10:00:00']));
        $this->reject(fn () => $this->agenda->saveReservation($this->manager, $this->reservation(['quantity' => 1, 'starts_at' => '2026-11-10 08:30:00'])));
        self::assertSame(3, $this->db->table('reservations')->countAllResults());
    }

    public function testReservationDeletionCannotBePerformedByAnotherInstructor(): void
    {
        $reservation = $this->agenda->saveReservation($this->instructor, $this->reservation());
        $this->reject(fn () => $this->agenda->deleteReservation($this->otherInstructor, (int) $reservation['id']));
        self::assertSame(1, $this->db->table('reservations')->countAllResults());
        $this->agenda->deleteReservation($this->instructor, (int) $reservation['id']);
        self::assertSame(0, $this->db->table('reservations')->countAllResults());
    }

    public function testVehicleAndInstructorCannotBeDoubleBookedForTrips(): void
    {
        $this->agenda->saveTrip($this->manager, $this->trip());
        $this->reject(fn () => $this->agenda->saveTrip($this->coordinator, $this->trip(['instructor_id' => 4])));
        $this->reject(fn () => $this->agenda->saveTrip($this->coordinator, $this->trip(['vehicle_id' => 2])));
        $this->agenda->saveTrip($this->coordinator, $this->trip(['starts_at' => '2026-11-10 12:00:00', 'ends_at' => '2026-11-10 14:00:00']));
        self::assertSame(2, $this->db->table('trips')->countAllResults());
    }

    public function testInvalidDurationsQuantitiesAndCoordinatesLeaveNoRecords(): void
    {
        $this->reject(fn () => $this->agenda->saveLesson($this->manager, $this->lesson(['ends_at' => '2026-11-10 07:00:00'])));
        $this->reject(fn () => $this->agenda->saveReservation($this->instructor, $this->reservation(['quantity' => 0])));
        $this->reject(fn () => $this->agenda->saveTrip($this->manager, $this->trip(['latitude' => 999])));
        $this->reject(fn () => $this->agenda->requestHours($this->instructor, ['work_date' => '2026-02-30', 'hours' => 2, 'reason' => 'Planejamento']));
        self::assertSame(0, $this->db->table('lessons')->countAllResults());
        self::assertSame(0, $this->db->table('reservations')->countAllResults());
        self::assertSame(0, $this->db->table('trips')->countAllResults());
        self::assertSame(0, $this->db->table('hour_requests')->countAllResults());
    }

    public function testLessonSchedulingIgnoresCoordinatesFromTheRequest(): void
    {
        $lesson = $this->agenda->saveLesson($this->manager, $this->lesson([
            'latitude' => -25.4284,
            'longitude' => -49.2733,
        ]));
        $stored = $this->db->table('lessons')->where('id', $lesson['id'])->get()->getRowArray();

        self::assertNull($stored['latitude']);
        self::assertNull($stored['longitude']);
    }

    public function testReadNotificationsAffectsOnlyTheCurrentUser(): void
    {
        $this->agenda->saveLesson($this->manager, $this->lesson());
        $this->agenda->saveLesson($this->manager, $this->lesson(['class_id' => 2, 'instructor_id' => 4, 'room_id' => 2]));
        $this->agenda->readNotifications($this->instructor);
        self::assertEquals(0, $this->agenda->updates($this->instructor)['unread']);
        self::assertGreaterThan(0, $this->agenda->updates($this->otherInstructor)['unread']);
    }
}
