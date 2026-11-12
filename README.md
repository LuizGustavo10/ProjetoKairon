# PAC Agenda — Plataforma de Agendamento para Colaboradores

Agenda responsiva em CodeIgniter 4 para gerentes, coordenação pedagógica, instrutores e alunos. O projeto usa o MariaDB do XAMPP e inclui dados fictícios para demonstração.

## Acessar agora

O projeto fica em **http://localhost/ProjetoKairon/**. Inicie Apache e MySQL no painel do XAMPP e aplique migrations pendentes no PowerShell:

```powershell
.\scripts\start.ps1
```

O script aplica as migrations pendentes. O Apache e o MariaDB precisam estar ativos no painel do XAMPP.

Todos os acessos demonstrativos usam a senha inicial `123456` e exigem uma nova senha na primeira entrada:

| Perfil | Crachá |
| --- | --- |
| Gerente | `1001` |
| Coordenação pedagógica | `2001` |
| Instrutores | `3001`, `3002`, `3003` |
| Aluno | `4001` |

## Recursos implementados

- agenda geral para gerente e coordenação, agenda pessoal para instrutores e programação por turma para alunos;
- cursos identificados por cores, detalhes da aula e conteúdo preenchido pelo instrutor;
- pedidos de horas fora de sala, aprovação gerencial e alertas amarelo a partir de 40h e vermelho a partir de 50h;
- dashboard mensal com horas programadas, realizadas, aprovadas fora de sala e total;
- detecção de conflitos de turma, instrutor, sala, veículo e reservas;
- frota e cursos externos com destino, horário e mapa da localização programada;
- reservas de salas e lotes de tablets com controle de disponibilidade simultânea;
- atualização compartilhada a cada dois segundos, notificações e fila de e-mail para mudanças da agenda;
- login por crachá, troca obrigatória da senha inicial e opção segura de manter o acesso por 30 dias.

## Banco de dados

O banco local é `kairon`, configurado em `.env` para o MariaDB padrão do XAMPP (`root`, sem senha). Para recriar a estrutura em uma instalação vazia:

```powershell
php spark migrate --all
php spark db:seed DemoSeeder
```

O seed se recusa a executar quando já existem usuários. Ele contém apenas pessoas, e-mails e programações fictícias.

## E-mails

As alterações de agenda entram em `email_queue`. Configure o SMTP no `.env`, mude `kairon.emailEnabled` para `true` e execute o trabalhador sob um supervisor:

```powershell
php spark agenda:emails --watch
```

Sem SMTP configurado, as notificações dentro do sistema continuam funcionando e nenhum e-mail é tentado.

## Verificação

```powershell
composer test
```

A suíte usa SQLite em memória e não altera o banco `kairon`. Ela cobre permissões, login persistente, escopos por perfil, conflitos, reservas, aprovação de horas, alertas mensais e fila de avisos.

Consulte [docs/architecture.md](docs/architecture.md) para o contrato técnico e [docs/legacy-analysis.md](docs/legacy-analysis.md) para a análise segura do projeto de referência.
