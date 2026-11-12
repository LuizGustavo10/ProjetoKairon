# PAC Agenda — contrato de implementação

Aplicação local CodeIgniter 4, PHP 8.2+, SQLite por padrão (migrations compatíveis com MySQL), interface PT-BR clara e responsiva. Fuso America/Sao_Paulo. Datas persistidas locais ISO `Y-m-d H:i:s`. Horas e alertas calculados por mês civil. Intervalos semiabertos [início,fim): horários adjacentes permitidos.

## Papéis
`manager`, `coordinator`, `instructor`, `student`. Gerente aprova horas, gerencia usuários. Gerente/coordenador gerenciam aulas, cadastros e logística. Instrutor só lê suas aulas e altera conteúdo das próprias. Aluno escolhe turma e só lê programação sem dados privados. Todos os colaboradores podem pedir horas (aluno não é colaborador).

## Tabelas (nomes de campos)
- users: id,badge,name,email,role,password_hash,must_change_password,active,created_at,updated_at
- remember_tokens: id,user_id,token_hash,expires_at,created_at
- courses: id,name,color
- classes: id,code,name,course_id,city
- rooms: id,name,capacity
- tablet_pools: id,name,quantity
- vehicles: id,name,plate,seats
- lessons: id,class_id,instructor_id,room_id(nullable),location,city,latitude(nullable),longitude(nullable),starts_at,ends_at,content,created_by,updated_at
- hour_requests: id,user_id,work_date,hours,reason,status(pending/approved/rejected),manager_note,reviewed_by(nullable),reviewed_at(nullable),created_at
- trips: id,vehicle_id,instructor_id,lesson_id(nullable),city,destination,latitude,longitude,starts_at,ends_at,notes,created_by
- reservations: id,resource_type(room/tablet),resource_id,quantity,user_id,starts_at,ends_at,purpose
- notifications: id,user_id,title,body,read_at(nullable),created_at
- email_queue: id,user_id,recipient,subject,body,status(pending/sent/failed),attempts,last_error,created_at,sent_at(nullable)
- changes: id,kind,created_at

## Serviço de domínio `App\Services\AgendaService`
Construtor sem argumentos, `db_connect()`. Métodos públicos:
- `bootstrap(array $user, string $month, ?int $classId = null): array`
- `saveLesson(array $user, array $data, ?int $id = null): array`
- `deleteLesson(array $user, int $id): void`
- `saveContent(array $user, int $id, string $content): void`
- `requestHours(array $user, array $data): array`
- `reviewHours(array $user, int $id, array $data): void`
- `saveTrip(array $user, array $data, ?int $id = null): array`
- `deleteTrip(array $user, int $id): void`
- `saveReservation(array $user, array $data): array`
- `deleteReservation(array $user, int $id): void`
- `saveCatalog(array $user, string $type, array $data, ?int $id = null): array` (courses/classes/rooms/tablet_pools/vehicles)
- `updates(array $user): array` (`version`, `unread`, `notifications`)
- `readNotifications(array $user): void`
Erros de negócio lançam `\DomainException` (mensagem PT-BR); controller retorna 422. Autorizar dentro dos métodos; nunca confiar role/id vindo do body. Usar transactions, verificar overlap instrutor/turma/sala e sala entre aulas/reservas; capacidade de tablets por faixa horária; conflitos de veículo e instrutor em viagens. `saveLesson` gera notificação/email para instrutor anterior e atual na mesma transação; alterações de conteúdo também geram change. Não enviar email durante testes ou seed.

## JSON bootstrap
`user` (id,badge,name,email,role,must_change_password), `month`, `version`, `users` (colaboradores: id,name,role,badge,email; só gestão; instrutor só self; aluno vazio), `courses`, `classes`, `rooms`, `tablet_pools`, `vehicles`, `lessons` (join: course_name,course_color,class_name,class_code,instructor_name,room_name), `requests` (join user_name; gerente todos, outros só próprios; aluno vazio), `trips` (join vehicle_name,vehicle_plate,instructor_name; apenas gestão), `reservations` (join resource_name,user_name; colaboradores), `summary` (lista {user_id,name,class_hours,completed_class_hours,outside_hours,total_hours,level:normal/warning/danger}; gestão todos instrutores, instrutor próprio, aluno vazio), `notifications` (próprias, últimos 30), `unread`.

## Rotas HTTP
GET /login; POST /login (badge,password,remember); POST /logout; GET/POST /change-password (password,password_confirm).
GET / (view app), GET /api/bootstrap?month=YYYY-MM&class_id=; GET /api/updates.
POST /api/lessons; PUT /api/lessons/{id}; DELETE /api/lessons/{id}; PATCH /api/lessons/{id}/content {content}.
POST /api/hours {work_date,hours,reason}; PATCH /api/hours/{id}/review {status:approved/rejected,manager_note}.
POST /api/trips; PUT /api/trips/{id}; DELETE /api/trips/{id}.
POST /api/reservations; DELETE /api/reservations/{id}.
POST /api/catalog/{type}; PUT /api/catalog/{type}/{id}.
POST /api/users; PUT /api/users/{id} (gerente; nome/email/badge/role/active; create senha inicial 123456 forçada; reset_password true revoga sessões lembradas).
POST /api/notifications/read.
Respostas mutação {ok:true,data:...}; erros {ok:false,message:...}. CSRF cookie/session e cabeçalho `X-CSRF-TOKEN`, token em meta csrf-token. JS usa baseUrl fornecida (com trailing slash). Filtrar por usuário em servidor. Poll /api/updates a cada 2s quando aba visível; recarregar bootstrap se version mudar, preservar formulários abertos.

## Interface
View `app/Views/agenda.php` usa `$user`, `$baseUrl`, `$csrfToken`, `$csrfHeader`. Assets `public/assets/app.css`, `public/assets/pac-theme.css`, `public/assets/app.js` e Font Awesome hospedado localmente. Root faz auth views separadas. JS boot `window.KAIRON = {baseUrl,user,csrfToken,csrfHeader}` (identificador interno legado inserido pela view). O tema claro preserva a identidade visual original: fundo azul-claro, gradientes azul/violeta, superfícies translúcidas, Cabinet Grotesk/Satoshi e navegação lateral clara. Página inicial dashboard com KPIs, gráfico nativo por instrutor e agenda semanal/mensal/lista responsiva; a apuração de horas usa ciclos do dia 20 até o dia 20 seguinte, com limite final exclusivo para impedir duplicidade, enquanto a agenda permanece no mês civil. Solicitações aprovadas aparecem na agenda como blocos cinza de horas fora de sala, respeitando o escopo do perfil. A gestão possui páginas isoladas de cursos e instrutores, com seleção lateral, indicadores por ciclo, agenda específica e atalhos de agendamento previamente vinculados. Inclui ainda frota/mapa (Leaflet e malha municipal simplificada do Paraná armazenada localmente, fonte IBGE), reservas e cadastros. Menu por papel. Diálogo de aula sem coordenadas, com conteúdo do instrutor e edição da gestão; latitude e longitude ficam restritas aos deslocamentos. Formulários com feedback de validação acessível. Sem conteúdo HTML não escapado de usuário.

## Demonstração
Seed dados fictícios para o mês atual no fuso São Paulo. Crachás: 1001 gerente, 2001 Adriana e 2002 Giselle na coordenação, 3001/3002/3003 instrutores, 4001 aluno. Senha inicial 123456, troca obrigatória para todos. A frota demonstrativa contém Voyage1, Voyage2 e Argo; os deslocamentos externos alternam entre Planaltina do Paraná, Santa Cruz de Monte Castelo e Loanda. Emails example.com, envio desabilitado até configurar SMTP. Horários sem conflito, mostrar 44h e 52h aprovadas em seed com solicitações em dias anteriores. Dados não importados de produção.
