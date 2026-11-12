<?php

declare(strict_types=1);

use App\Filters\Authenticated;
use App\Services\AuthService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

/** @internal */
final class AuthServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace = 'App';
    protected $DBGroup = 'tests';

    private AuthService $auth;
    private IncomingRequest $request;
    private Response $response;

    protected function setUp(): void
    {
        self::assertSame('testing', ENVIRONMENT);
        self::assertSame('tests', config('Database')->defaultGroup);
        self::assertSame(':memory:', config('Database')->tests['database']);
        self::assertSame('SQLite3', config('Database')->tests['DBDriver']);
        parent::setUp();
        $this->request = new IncomingRequest(config('App'), new URI('http://localhost/api/bootstrap'), null, new UserAgent());
        $this->request->setGlobal('cookie', []);
        Services::injectMock('request', $this->request);
        $this->response = new Response(config('App'));
        Services::injectMock('response', $this->response);
        $this->auth = new AuthService();
        $this->db->table('users')->insert([
            'id' => 1, 'badge' => '3001', 'name' => 'Instrutor de teste', 'email' => 'test@example.com',
            'role' => 'instructor', 'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
            'must_change_password' => 1, 'active' => 1,
            'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
        ]);
    }

    public function testAnonymousApiRequestIsRejected(): void
    {
        self::assertNull($this->auth->current());
        $response = (new Authenticated())->before($this->request);
        self::assertSame(401, $response->getStatusCode());
    }

    public function testWrongCredentialsDoNotCreateASession(): void
    {
        self::assertFalse($this->auth->login('3001', 'incorrect', true, $this->response));
        self::assertFalse($this->auth->login('nonexistent', '123456', true, $this->response));
        self::assertNull(session()->get('user_id'));
        self::assertSame(0, $this->db->table('remember_tokens')->countAllResults());
    }

    public function testFirstLoginRequiresPasswordChangeBeforeApiAccess(): void
    {
        self::assertTrue($this->auth->login('3001', '123456', false, $this->response));
        self::assertArrayNotHasKey('password_hash', $this->auth->current());
        self::assertSame(403, (new Authenticated())->before($this->request)->getStatusCode());
        self::assertNull((new Authenticated())->before($this->request, ['password']));
        $this->auth->changePassword(1, 'new-password-123', $this->response);
        self::assertEquals(0, $this->auth->current()['must_change_password']);
        self::assertNull((new Authenticated())->before($this->request));
    }

    public function testRememberCookieIsOpaqueHttpOnlyAndOnlyHashIsPersisted(): void
    {
        self::assertTrue($this->auth->login('3001', '123456', true, $this->response));
        $cookie = $this->response->getCookie(AuthService::COOKIE);
        $raw = $cookie->getValue();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $raw);
        self::assertTrue($cookie->isHTTPOnly());
        self::assertSame('Lax', $cookie->getSameSite());
        $stored = $this->db->table('remember_tokens')->get()->getRowArray();
        self::assertSame(hash('sha256', $raw), $stored['token_hash']);
        self::assertNotSame($raw, $stored['token_hash']);

        session()->remove(['user_id', 'auth_stamp', 'remembered']);
        $this->request->setGlobal('cookie', [AuthService::COOKIE => $raw]);
        self::assertEquals(1, $this->auth->current()['id']);
        self::assertEquals(1, session()->get('user_id'));
    }

    public function testExpiredRememberTokenDoesNotRestoreAccess(): void
    {
        $this->auth->login('3001', '123456', true, $this->response);
        $raw = $this->response->getCookie(AuthService::COOKIE)->getValue();
        $this->db->table('remember_tokens')->update(['expires_at' => '2000-01-01 00:00:00']);
        session()->remove(['user_id', 'auth_stamp', 'remembered']);
        $this->request->setGlobal('cookie', [AuthService::COOKIE => $raw]);
        self::assertNull($this->auth->current());
    }

    public function testPasswordChangeRevokesOldRememberTokenAndOtherSessions(): void
    {
        $this->auth->login('3001', '123456', true, $this->response);
        $raw = $this->response->getCookie(AuthService::COOKIE)->getValue();
        $oldStamp = session()->get('auth_stamp');
        $this->auth->changePassword(1, 'changed-password-123', $this->response);
        self::assertSame(0, $this->db->table('remember_tokens')->where('token_hash', hash('sha256', $raw))->countAllResults());
        self::assertEquals(1, $this->auth->current()['id']);

        session()->set('auth_stamp', $oldStamp);
        self::assertNull($this->auth->current());
        self::assertNull(session()->get('user_id'));
        $this->request->setGlobal('cookie', [AuthService::COOKIE => $raw]);
        self::assertNull($this->auth->current());
    }

    public function testDeactivatedUserLosesExistingSessionAndCannotLogin(): void
    {
        $this->auth->login('3001', '123456', false, $this->response);
        $this->db->table('users')->where('id', 1)->update(['active' => 0]);
        self::assertNull($this->auth->current());
        self::assertFalse($this->auth->login('3001', '123456', false, $this->response));
    }
}
