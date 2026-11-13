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
        $this->db->table('courses')->insert(['id' => 1, 'name' => 'Tecnologia', 'color' => '#2563eb', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'turn' => 'morning', 'weekdays' => '2,4']);
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

    public function testPendingRequestsDontCountAndManagementCanApproveExactlyOnce(): void
    {
        $request = $this->agenda->requestHours($this->instructor, ['work_date' => '2026-11-11', 'hours' => 8, 'reason' => 'Planejamento de curso']);
        self::assertEquals(0, $this->summary()['outside_hours']);
        $this->reject(fn () => $this->agenda->reviewHours($this->instructor, (int) $request['id'], ['status' => 'approved', 'manager_note' => '']));
        $this->agenda->reviewHours($this->coordinator, (int) $request['id'], ['status' => 'approved', 'manager_note' => 'Aprovado']);
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
        foreach ([['2026-10-22', 10], ['2026-10-29', 10], ['2026-11-05', 10], ['2026-11-12', 9.5]] as [$date, $hours]) {
            $this->approve($date, $hours);
        }
        self::assertSame('normal', $this->summary()['level']);
        $this->approve('2026-11-13', .5);
        self::assertEquals(40, $this->summary()['outside_hours']);
        self::assertSame('warning', $this->summary()['level']);
        $this->approve('2026-11-19', 10);
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
        $this->agenda->saveLesson($this->manager, $this->lesson(['starts_at' => '2020-01-20 23:00:00', 'ends_at' => '2020-01-21 02:00:00']));
        self::assertEquals(1, $this->summary('2020-01')['class_hours']);
        self::assertEquals(2, $this->summary('2020-02')['class_hours']);
        self::assertEquals(1, $this->summary('2020-01')['completed_class_hours']);
        self::assertEquals(2, $this->summary('2020-02')['total_hours']);
    }

    public function testHourCycleStartsOnDay21AndEndsAfterDay20(): void
    {
        $this->approve('2026-10-20', 3);
        $this->approve('2026-10-21', 4);
        $this->approve('2026-11-20', 5);
        $this->approve('2026-11-21', 6);

        $november = $this->agenda->bootstrap($this->instructor, '2026-11');
        self::assertSame('2026-10-21', $november['hour_cycle']['starts_on']);
        self::assertSame('2026-11-21', $november['hour_cycle']['ends_on']);
        self::assertSame('21/10/2026 a 20/11/2026', $november['hour_cycle']['label']);
        self::assertEquals(9, $november['summary'][0]['outside_hours']);
        self::assertSame(['2026-11-20', '2026-10-21'], array_column($november['requests'], 'work_date'));
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

    public function testCourseDaysCanBePlannedBeforeInstructorAllocation(): void
    {
        $plans = $this->agenda->createCoursePlans($this->coordinator, [
            'class_id' => 1,
            'turn' => 'morning',
            'dates' => ['2026-11-03', '2026-11-05'],
        ]);

        self::assertCount(2, $plans);
        self::assertSame('2026-11-03 08:00:00', $plans[0]['starts_at']);
        self::assertSame('2026-11-03 12:00:00', $plans[0]['ends_at']);
        self::assertCount(2, $this->agenda->bootstrap($this->manager, '2026-11')['course_plans']);
        self::assertCount(0, $this->agenda->bootstrap($this->instructor, '2026-11')['course_plans']);

        $this->reject(fn () => $this->agenda->createCoursePlans($this->instructor, ['class_id' => 1, 'turn' => 'morning', 'dates' => ['2026-11-07']]));
        $this->reject(fn () => $this->agenda->createCoursePlans($this->manager, ['class_id' => 1, 'turn' => 'morning', 'dates' => ['2026-11-03']]));
        $this->reject(fn () => $this->agenda->createCoursePlans($this->manager, ['class_id' => 1, 'turn' => 'afternoon', 'dates' => ['2026-11-10']]));
        $this->reject(fn () => $this->agenda->createCoursePlans($this->manager, ['class_id' => 1, 'turn' => 'morning', 'dates' => ['2026-11-11']]));
        $this->reject(fn () => $this->agenda->createCoursePlans($this->manager, ['class_id' => 1, 'turn' => 'morning', 'dates' => ['2026-12-01']]));

        $this->agenda->deleteCoursePlan($this->manager, (int) $plans[0]['id']);
        self::assertSame(1, $this->db->table('course_plans')->countAllResults());
    }

    public function testCourseCatalogStoresItsPlanningGrid(): void
    {
        $course = $this->agenda->saveCatalog($this->coordinator, 'courses', [
            'name' => 'Gestão Comercial',
            'color' => '#15803d',
            'starts_on' => '2026-11-15',
            'ends_on' => '2027-02-28',
            'turn' => 'night',
            'weekdays' => [6, 2, 4, 2],
        ]);

        self::assertSame('2,4,6', $course['weekdays']);
        self::assertSame('night', $course['turn']);
        self::assertSame('2026-11-15', $course['starts_on']);
        self::assertSame('2026-12-08', $course['ends_on']);
        self::assertSame(1, $this->db->table('classes')->where('course_id', $course['id'])->countAllResults());
        $this->reject(fn () => $this->agenda->saveCatalog($this->manager, 'courses', [
            'name' => 'Curso inválido', 'color' => '#15803d', 'starts_on' => '2027-02-01',
            'ends_on' => '2027-01-01', 'turn' => 'morning', 'weekdays' => [1],
        ]));
    }

    public function testAfternoonCourseUsesTheStandardHalfHourSchedule(): void
    {
        $this->db->table('courses')->where('id', 1)->update(['turn' => 'afternoon']);
        $plans = $this->agenda->createCoursePlans($this->manager, [
            'class_id' => 1, 'turn' => 'afternoon', 'dates' => ['2026-11-03'],
        ]);

        self::assertSame('2026-11-03 13:30:00', $plans[0]['starts_at']);
        self::assertSame('2026-11-03 17:30:00', $plans[0]['ends_at']);
    }

    public function testPlanningExceptionCanCreateTheDefaultClassForAnExistingCourse(): void
    {
        $this->db->table('courses')->insert([
            'id' => 9, 'name' => 'Curso sem turma', 'color' => '#7c3aed',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
            'turn' => 'morning', 'weekdays' => '2,4',
        ]);

        $plans = $this->agenda->createCoursePlans($this->coordinator, [
            'course_id' => 9, 'turn' => 'afternoon', 'dates' => ['2026-11-11'],
            'allow_outside_schedule' => true,
        ]);

        self::assertSame('2026-11-11 13:30:00', $plans[0]['starts_at']);
        $class = $this->db->table('classes')->where('course_id', 9)->get()->getRowArray();
        self::assertNotNull($class);
        self::assertSame((int) $class['id'], (int) $plans[0]['class_id']);
    }

    public function testCoursePlanningAssignsAvailableDaysDirectlyToTheInstructor(): void
    {
        $lessons = $this->agenda->scheduleCourseInstructor($this->coordinator, [
            'course_id' => 1, 'class_id' => 1, 'instructor_id' => 3,
            'turn' => 'morning', 'dates' => ['2026-11-03', '2026-11-05'],
        ]);

        self::assertCount(2, $lessons);
        self::assertSame('2026-11-03 08:00:00', $lessons[0]['starts_at']);
        self::assertSame(2, $this->db->table('lessons')->where('instructor_id', 3)->countAllResults());
        self::assertSame(1, $this->db->table('notifications')->where('user_id', 3)->countAllResults());
        self::assertSame(1, $this->db->table('email_queue')->where('user_id', 3)->countAllResults());
        $this->reject(fn () => $this->agenda->scheduleCourseInstructor($this->manager, [
            'course_id' => 1, 'class_id' => 2, 'instructor_id' => 3,
            'turn' => 'morning', 'dates' => ['2026-11-03'],
        ]));
    }

    public function testInstructorCanRequestAgendaClosureAndCoordinatorCanApproveIt(): void
    {
        $block = $this->agenda->saveScheduleBlock($this->instructor, [
            'block_date' => '2026-11-03', 'turn' => 'morning',
            'type' => 'medical', 'reason' => 'Consulta médica agendada',
        ]);
        self::assertSame('pending', $block['status']);
        $this->agenda->reviewScheduleBlock($this->coordinator, (int) $block['id'], ['status' => 'approved', 'review_note' => 'Aprovado']);
        self::assertSame('approved', $this->db->table('schedule_blocks')->where('id', $block['id'])->get()->getRowArray()['status']);
        $this->reject(fn () => $this->agenda->scheduleCourseInstructor($this->manager, [
            'course_id' => 1, 'class_id' => 1, 'instructor_id' => 3,
            'turn' => 'morning', 'dates' => ['2026-11-03'],
        ]));
    }

    public function testExternalTravelCountsTowardTheDailyWorkLimit(): void
    {
        $course = $this->agenda->saveCatalog($this->manager, 'courses', [
            'name' => 'Curso externo', 'color' => '#dc2626', 'course_type' => 'external',
            'location' => 'Unidade externa', 'address' => 'Avenida Central, 100', 'notes' => '',
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'turn' => 'morning',
            'weekdays' => [2], 'daily_hours' => 4, 'total_hours' => 40,
            'travel_out_minutes' => 30, 'travel_back_minutes' => 30,
        ]);
        $request = $this->agenda->requestHours($this->instructor, ['work_date' => '2026-11-03', 'turn' => 'afternoon', 'hours' => 6, 'reason' => 'Preparação de material didático']);
        $this->agenda->reviewHours($this->coordinator, (int) $request['id'], ['status' => 'approved', 'manager_note' => '']);
        $this->reject(fn () => $this->agenda->scheduleCourseInstructor($this->manager, [
            'course_id' => $course['id'], 'instructor_id' => 3,
            'turn' => 'morning', 'dates' => ['2026-11-03'],
        ]));
    }
}
