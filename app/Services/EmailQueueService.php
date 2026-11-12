<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Transactional outbox sender. Run `php spark agenda:emails --watch` under a supervisor. */
class EmailQueueService
{
    public function enabled(): bool
    {
        return ENVIRONMENT !== 'testing' && filter_var(env('kairon.emailEnabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function process(int $limit = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'disabled' => !$this->enabled(), 'busy' => false];
        if (!$this->enabled()) { return $result; }
        $config = config('Email');
        if ($config->protocol !== 'smtp' || !$config->SMTPHost || !filter_var($config->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Configure email.protocol=smtp, email.SMTPHost e email.fromEmail antes de ativar o envio.');
        }
        $db = db_connect();
        $mysql = $db->DBDriver === 'MySQLi';
        $lockName = 'kairon_mail_' . substr(hash('sha256', (string) $db->database), 0, 32);
        $file = null;
        if ($mysql) {
            if ((int) ($db->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->getRowArray()['acquired'] ?? 0) !== 1) { $result['busy'] = true; return $result; }
        } else {
            if (!is_dir(WRITEPATH . 'locks')) { mkdir(WRITEPATH . 'locks', 0775, true); }
            $file = fopen(WRITEPATH . 'locks/email.lock', 'c');
            if (!$file || !flock($file, LOCK_EX | LOCK_NB)) { if (is_resource($file)) { fclose($file); } $result['busy'] = true; return $result; }
        }
        try {
            $jobs = $db->table('email_queue')->whereIn('status', ['pending', 'failed'])->where('attempts <', 5)->orderBy('id')->limit(max(1, min(100, $limit)))->get()->getResultArray();
            foreach ($jobs as $job) {
                $attempts = (int) $job['attempts'] + 1;
                try {
                    $email = service('email', null, false);
                    $email->initialize($config);
                    $email->setFrom($config->fromEmail, $config->fromName ?: 'PAC Agenda');
                    $email->setTo($job['recipient']);
                    $email->setSubject($job['subject']);
                    $email->setMailType('text');
                    $email->setMessage($job['body']);
                    if (!$email->send()) { throw new \RuntimeException('O servidor SMTP não confirmou o envio. Verifique a configuração e os registros do servidor de e-mail.'); }
                    $db->table('email_queue')->where('id', $job['id'])->update(['status' => 'sent', 'attempts' => $attempts, 'last_error' => '', 'sent_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s')]);
                    $result['sent']++;
                } catch (Throwable $error) {
                    // Never persist SMTP debug strings: they can contain connection credentials.
                    $db->table('email_queue')->where('id', $job['id'])->update(['status' => 'failed', 'attempts' => $attempts, 'last_error' => 'Falha no envio SMTP. Revise a configuração de e-mail.']);
                    log_message('error', 'Falha no envio do e-mail da fila PAC Agenda, ID {id}.', ['id' => $job['id']]);
                    $result['failed']++;
                }
            }
        } finally {
            if ($mysql) { $db->query('SELECT RELEASE_LOCK(?)', [$lockName]); }
            if (is_resource($file)) { flock($file, LOCK_UN); fclose($file); }
        }
        return $result;
    }
}
