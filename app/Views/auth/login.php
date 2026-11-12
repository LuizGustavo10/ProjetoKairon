<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#edf8ff"><title>Entrar · PAC Agenda</title>
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
        <h1>Bem-vindo</h1><p class="intro">Entre com seu crachá para acessar aulas, reservas e programações.</p>
        <?php if ($error): ?><div class="error" role="alert"><?= esc($error) ?></div><?php endif ?>
        <form action="<?= site_url('login') ?>" method="post">
            <?= csrf_field() ?>
            <label for="badge">Número do crachá</label><div class="input-shell"><i class="fa-solid fa-id-badge" aria-hidden="true"></i><input id="badge" name="badge" type="text" inputmode="text" autocomplete="username" placeholder="Digite seu crachá" value="<?= esc($badge, 'attr') ?>" maxlength="40" required autofocus></div>
            <label for="password">Senha</label><div class="input-shell password-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Digite sua senha" maxlength="128" required><button class="toggle-password" type="button" data-password="password" aria-label="Mostrar senha"><i class="fa-solid fa-eye" aria-hidden="true"></i><span>Mostrar</span></button></div>
            <label class="remember"><input type="checkbox" name="remember" value="1" checked><span>Manter conectado por 30 dias</span></label>
            <button class="submit" type="submit">Entrar na plataforma <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </form>
        <div class="first-access"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><p><strong>Primeiro acesso?</strong> Use seu crachá e a senha inicial <b>123456</b>. Você criará uma nova senha ao entrar.</p></div>
        <p class="help">Precisa de acesso ou esqueceu sua senha?<br>Fale com o gerente da sua unidade.</p>
        <footer>PAC Agenda <span><?= date('Y') ?></span></footer>
        </div>
    </section>
</main>
<script src="<?= base_url('assets/auth.js') ?>" defer></script>
</body></html>
