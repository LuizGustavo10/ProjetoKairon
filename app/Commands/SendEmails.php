<?php

namespace App\Commands;

use App\Services\EmailQueueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class SendEmails extends BaseCommand
{
    protected $group = 'PAC Agenda';
    protected $name = 'agenda:emails';
    protected $description = 'Envia os avisos pendentes da agenda via SMTP. Seguro para executar periodicamente.';
    protected $options = ['--watch' => 'Mantém o processo ativo, verificando a fila a cada 15 segundos.', '--retry-failed' => 'Reabre mensagens com falha para novas tentativas após corrigir o SMTP.'];

    public function run(array $params)
    {
        $queue = new EmailQueueService();
        if (!$queue->enabled()) { CLI::write('Envio desativado. Configure SMTP no .env e kairon.emailEnabled = true.', 'yellow'); return; }
        if (CLI::getOption('retry-failed') !== null) {
            db_connect()->table('email_queue')->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'last_error' => '']);
            CLI::write('Mensagens com falha foram reabertas para envio.');
        }
        $watch = CLI::getOption('watch') !== null;
        do {
            $result = $queue->process();
            if (!$watch || $result['sent'] || $result['failed']) { CLI::write('Enviados: ' . $result['sent'] . '. Falhas: ' . $result['failed'] . ($result['busy'] ? '. Outra instância está processando.' : '.')); }
            if ($watch) { sleep(15); }
        } while ($watch);
    }
}
