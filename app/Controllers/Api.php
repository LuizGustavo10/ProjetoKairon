<?php
namespace App\Controllers;
use App\Services\AgendaService;
use App\Services\AuthService;
use App\Services\UserService;
use CodeIgniter\HTTP\ResponseInterface;

class Api extends BaseController
{
    private function user(): array { return (new AuthService())->current(); }
    private function data(): array
    {
        try { $data = $this->request->getJSON(true); }
        catch (\Throwable $e) { throw new \DomainException('O formulário enviado é inválido.'); }
        if (!is_array($data)) { throw new \DomainException('Envie os dados do formulário.'); }
        return $data;
    }
    private function run(callable $operation, bool $raw = false): ResponseInterface
    {
        try {
            $data = $operation(new AgendaService());
            return $this->response->setHeader('Cache-Control', 'no-store, private')->setJSON($raw ? $data : ['ok' => true, 'data' => $data]);
        } catch (\DomainException $e) {
            return $this->response->setStatusCode(422)->setJSON(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'Agenda API {class}: {message}', ['class' => get_class($e), 'message' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['ok' => false, 'message' => 'Não foi possível salvar ou carregar os dados. Tente novamente.']);
        }
    }
    public function bootstrap()
    {
        return $this->run(fn ($s) => $s->bootstrap($this->user(), (string) ($this->request->getGet('month') ?? date('Y-m')),
            $this->request->getGet('class_id') ? (int) $this->request->getGet('class_id') : null), true);
    }
    public function updates() { return $this->run(fn ($s) => $s->updates($this->user()), true); }
    public function saveLesson(?int $id = null) { return $this->run(fn ($s) => $s->saveLesson($this->user(), $this->data(), $id)); }
    public function deleteLesson(int $id) { return $this->run(fn ($s) => $s->deleteLesson($this->user(), $id)); }
    public function saveContent(int $id) { return $this->run(fn ($s) => $s->saveContent($this->user(), $id, (string) ($this->data()['content'] ?? ''))); }
    public function createCoursePlans() { return $this->run(fn ($s) => $s->createCoursePlans($this->user(), $this->data())); }
    public function scheduleCourseInstructor() { return $this->run(fn ($s) => $s->scheduleCourseInstructor($this->user(), $this->data())); }
    public function deleteCoursePlan(int $id) { return $this->run(fn ($s) => $s->deleteCoursePlan($this->user(), $id)); }
    public function requestHours() { return $this->run(fn ($s) => $s->requestHours($this->user(), $this->data())); }
    public function reviewHours(int $id) { return $this->run(fn ($s) => $s->reviewHours($this->user(), $id, $this->data())); }
    public function saveScheduleBlock() { return $this->run(fn ($s) => $s->saveScheduleBlock($this->user(), $this->data())); }
    public function reviewScheduleBlock(int $id) { return $this->run(fn ($s) => $s->reviewScheduleBlock($this->user(), $id, $this->data())); }
    public function saveTrip(?int $id = null) { return $this->run(fn ($s) => $s->saveTrip($this->user(), $this->data(), $id)); }
    public function deleteTrip(int $id) { return $this->run(fn ($s) => $s->deleteTrip($this->user(), $id)); }
    public function saveReservation() { return $this->run(fn ($s) => $s->saveReservation($this->user(), $this->data())); }
    public function deleteReservation(int $id) { return $this->run(fn ($s) => $s->deleteReservation($this->user(), $id)); }
    public function saveCatalog(string $type, ?int $id = null) { return $this->run(fn ($s) => $s->saveCatalog($this->user(), $type, $this->data(), $id)); }
    public function saveUser(?int $id = null) { return $this->run(fn () => (new UserService())->save($this->user(), $this->data(), $id)); }
    public function readNotifications() { return $this->run(fn ($s) => $s->readNotifications($this->user())); }
}
