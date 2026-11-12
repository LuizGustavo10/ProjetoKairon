<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#edf8ff"><title>Entrar &middot; PAC Agenda</title>
    <link rel="icon" href="<?= base_url('assets/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/fontawesome/css/fontawesome.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/vendor/fontawesome/css/solid.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/auth.css') ?>">
</head>
<body>
<div class="code-overlay" aria-hidden="true"><span>agenda.distribuir(aulas);</span><span>colaborador.consultar(programacao);</span><span>salas.verificarDisponibilidade();</span><span>frota.programar(destino, horario);</span><span>if (horas &gt;= 50) alerta.critico();</span><span>notificacoes.atualizarEquipe();</span></div>
<div class="pointer-glow" aria-hidden="true"></div>
<main class="auth-shell">
    <section class="auth-panel" aria-label="Acesso ao PAC Agenda">
        <div class="login-card">
        <a class="brand" href="<?= site_url('login') ?>"><span class="brand-mark"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><span>PAC Agenda<small>PLATAFORMA DE AGENDAMENTO PARA COLABORADORES</small></span></a>
        <div class="system-tag"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> ACESSO SEGURO</div>
        <h1>Bem-vindo</h1><p class="intro">Entre com seu crach&aacute; para acessar aulas, reservas e programa&ccedil;&otilde;es.</p>
        <?php if ($error): ?><div class="error" role="alert"><?= esc($error) ?></div><?php endif ?>
        <form action="<?= site_url('login') ?>" method="post">
            <?= csrf_field() ?>
            <label for="badge">N&uacute;mero do crach&aacute;</label><div class="input-shell"><i class="fa-solid fa-id-badge" aria-hidden="true"></i><input id="badge" name="badge" type="text" inputmode="text" autocomplete="username" placeholder="Digite seu crach&aacute;" value="<?= esc($badge, 'attr') ?>" maxlength="40" required autofocus></div>
            <label for="password">Senha</label><div class="input-shell password-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" maxlength="128" required><button class="toggle-password" type="button" data-password="password" aria-label="Mostrar senha"><i class="fa-solid fa-eye" aria-hidden="true"></i><span>Mostrar</span></button></div>
            <label class="remember"><input type="checkbox" name="remember" value="1" checked><span>Manter conectado por 30 dias</span></label>
            <button class="submit" type="submit">Entrar na plataforma <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </form>
        <section class="demo-access" aria-labelledby="demo-access-title">
            <div class="demo-access-heading">
                <div><span><i class="fa-solid fa-bolt" aria-hidden="true"></i><strong id="demo-access-title">Acesso r&aacute;pido</strong></span><small>Escolha um perfil para preencher o login</small></div>
                <span class="demo-password"><i class="fa-solid fa-key" aria-hidden="true"></i> Senha <b>123456</b></span>
            </div>
            <div class="demo-access-list">
                <button type="button" class="demo-access-button" data-demo-badge="1001" data-demo-password="123456" aria-pressed="false" aria-label="Preencher acesso de gerente, crach&aacute; 1001">
                    <span class="demo-role-icon"><i class="fa-solid fa-user-tie" aria-hidden="true"></i></span><span class="demo-role"><strong>Gerente</strong><small>Crach&aacute;</small><b>1001</b></span><i class="fa-solid fa-chevron-right demo-role-arrow" aria-hidden="true"></i>
                </button>
                <button type="button" class="demo-access-button" data-demo-badge="2001" data-demo-password="123456" aria-pressed="false" aria-label="Preencher acesso de coordenador, crach&aacute; 2001">
                    <span class="demo-role-icon"><i class="fa-solid fa-user-gear" aria-hidden="true"></i></span><span class="demo-role"><strong>Coordenador</strong><small>Crach&aacute;</small><b>2001</b></span><i class="fa-solid fa-chevron-right demo-role-arrow" aria-hidden="true"></i>
                </button>
                <button type="button" class="demo-access-button" data-demo-badge="3001" data-demo-password="123456" aria-pressed="false" aria-label="Preencher acesso de instrutor, crach&aacute; 3001">
                    <span class="demo-role-icon"><i class="fa-solid fa-person-chalkboard" aria-hidden="true"></i></span><span class="demo-role"><strong>Instrutor</strong><small>Crach&aacute;</small><b>3001</b></span><i class="fa-solid fa-chevron-right demo-role-arrow" aria-hidden="true"></i>
                </button>
            </div>
        </section>
        <div class="first-access"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><p><strong>Primeiro acesso?</strong> Depois de entrar com a senha inicial, voc&ecirc; criar&aacute; uma nova senha pessoal.</p></div>
        <p class="help">Precisa de acesso ou esqueceu sua senha?<br>Fale com o gerente da sua unidade.</p>
        <footer>PAC Agenda <span><?= date('Y') ?></span></footer>
        </div>
    </section>
</main>
<script src="<?= base_url('assets/auth.js') ?>" defer></script>
</body></html>
