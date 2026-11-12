<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#edf8ff">
    <meta name="csrf-token" content="<?= esc($csrfToken, 'attr') ?>">
    <title>PAC Agenda · Plataforma de Agendamento para Colaboradores</title>
    <link rel="icon" href="<?= esc($baseUrl, 'attr') ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= esc($baseUrl, 'attr') ?>assets/vendor/leaflet.css">
    <link rel="stylesheet" href="<?= esc($baseUrl, 'attr') ?>assets/vendor/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="<?= esc($baseUrl, 'attr') ?>assets/vendor/fontawesome/css/solid.min.css">
    <link rel="stylesheet" href="<?= esc($baseUrl, 'attr') ?>assets/app.css">
    <link rel="stylesheet" href="<?= esc($baseUrl, 'attr') ?>assets/pac-theme.css">
</head>
<body>
<a class="skip-link" href="#main">Ir para o conteúdo</a>
<div id="app"><div class="initial-loading" role="status"><span class="brand-mark"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span><strong>PAC Agenda</strong><p>Organizando sua agenda…</p></div></div>
<div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true"></div>
<dialog id="app-dialog" aria-labelledby="dialog-title"><div id="dialog-content"></div></dialog>
<script>
window.KAIRON = <?= json_encode(['baseUrl' => $baseUrl, 'user' => $user, 'csrfToken' => $csrfToken, 'csrfHeader' => $csrfHeader, 'csrfName' => $csrfName], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= esc($baseUrl, 'attr') ?>assets/vendor/leaflet.js" defer></script>
<script src="<?= esc($baseUrl, 'attr') ?>assets/app.js" defer></script>
</body>
</html>
