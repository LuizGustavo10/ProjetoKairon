/* PAC Agenda — interface da plataforma. Sem dados pessoais ou credenciais no armazenamento local. */
(() => {
'use strict';
const config = window.KAIRON;
const $ = (selector, root = document) => root.querySelector(selector);
const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const color = value => /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#458c83';
const icons = {
 home:'house',grid:'table-cells-large',calendar:'calendar-days',clock:'clock',people:'users',book:'book-open',classroom:'person-chalkboard',car:'car-side',layers:'layer-group',settings:'gear',bell:'bell',plus:'plus',chevronRight:'chevron-right',chevronLeft:'chevron-left',down:'chevron-down',arrow:'arrow-right',logout:'right-from-bracket',location:'location-dot',building:'building',check:'check',checkCircle:'circle-check',alert:'triangle-exclamation',info:'circle-info',close:'xmark',edit:'pen-to-square',trash:'trash-can',search:'magnifying-glass',menu:'bars',shield:'shield-halved',tablet:'tablet-screen-button',door:'door-open',chart:'chart-column',mail:'envelope',coffee:'mug-hot',refresh:'rotate',external:'arrow-up-right-from-square'
};
const icon = name => `<i class="fa-solid fa-${icons[name] || icons.grid} icon" aria-hidden="true"></i>`;
const roleLabels = {manager:'Gerente',coordinator:'Coordenação pedagógica',instructor:'Instrutor(a)',student:'Aluno(a)'};
const statusLabels = {pending:'Em andamento',approved:'Concluído · aprovado',rejected:'Não aprovado'};
const today = new Intl.DateTimeFormat('en-CA', {timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const managed = ['manager','coordinator'].includes(config.user.role);
const manager = config.user.role === 'manager';
const student = config.user.role === 'student';
const instructor = config.user.role === 'instructor';
const allowedPages = student ? ['agenda'] : managed ? ['dashboard','agenda','course_management','instructor_management','hours','fleet','reservations','catalog'] : ['agenda','dashboard','hours','reservations'];
const currentHash = location.hash.replace('#','');
const state = {page:allowedPages.includes(currentHash) ? currentHash : managed ? 'dashboard' : 'agenda',month:today.slice(0,7),anchor:today,view:'week',data:null,extraLessons:[],classId:'',instructorId:'',courseId:'',selectedInstructorId:'',selectedCourseId:'',query:'',hoursFilter:'all',catalogTab:'courses',notificationsOpen:false,loading:false,map:null,sequence:0,online:true};
const labels = {dashboard:managed ? 'Visão geral' : 'Minhas horas',agenda:student ? 'Agenda da turma' : instructor ? 'Minha agenda' : 'Agenda geral',course_management:'Gestão de cursos',instructor_management:'Gestão de instrutores',hours:'Horas fora de sala',fleet:'Frota e deslocamentos',reservations:'Reservas',catalog:'Cadastros'};
try { if(student) state.classId = localStorage.getItem(`pac-agenda.class.${config.user.id}`) || ''; } catch (_) {}
const root = $('#app');
const dialog = $('#app-dialog');
let dialogSubmit = null;
let loadTimer = null;
let pollBusy = false;
let focusedBeforeDialog = null;

const initials = name => String(name || '').trim().split(/\s+/).slice(0,2).map(part => part[0] || '').join('').toUpperCase();
const avatar = name => `<span class="avatar" aria-hidden="true">${escape(initials(name))}</span>`;
const fmtHours = value => Number(value || 0).toLocaleString('pt-BR', {maximumFractionDigits:1});
const dt = value => new Date(`${String(value).slice(0,10)}T12:00:00`);
const durationHours = (start,end) => Math.max(0,(new Date(String(end).replace(' ','T'))-new Date(String(start).replace(' ','T')))/3600000);
const ymd = date => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
const addDays = (date, amount) => {const d=dt(date);d.setDate(d.getDate()+amount);return ymd(d);};
const shortDate = value => dt(value).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
const fullDate = value => dt(value).toLocaleDateString('pt-BR',{weekday:'long',day:'numeric',month:'long'});
const monthLabel = month => dt(`${month}-01`).toLocaleDateString('pt-BR',{month:'long',year:'numeric'});
const time = value => String(value || '').slice(11,16);
const dateTime = value => `${shortDate(value)} às ${time(value)}`;
const inputTime = value => String(value || '').slice(0,16).replace(' ','T');
const timeRange = lesson => `${time(lesson.starts_at)} – ${time(lesson.ends_at)}`;
const badge = status => `<span class="badge ${escape(status)}"><span class="dot" style="background:currentColor"></span>${escape(statusLabels[status] || {normal:'Dentro do limite',warning:'Atenção',danger:'Limite atingido'}[status] || status)}</span>`;
const weekDays = () => {const date=dt(state.anchor);const shift=(date.getDay()+6)%7;return Array.from({length:7},(_,i)=>addDays(state.anchor,i-shift));};
const byStart = (a,b) => String(a.starts_at).localeCompare(String(b.starts_at));
const allLessons = () => [...new Map([...(state.data?.lessons || []),...state.extraLessons].map(item=>[String(item.id),item])).values()].sort(byStart);
const filteredLessons = () => allLessons().filter(item => (!state.classId || String(item.class_id)===String(state.classId)) && (!state.instructorId || String(item.instructor_id)===String(state.instructorId)) && (!state.courseId || String((state.data.classes || []).find(c=>String(c.id)===String(item.class_id))?.course_id)===String(state.courseId)) && (!state.query || [item.course_name,item.class_name,item.class_code,item.instructor_name,item.room_name,item.location,item.city].join(' ').toLocaleLowerCase('pt-BR').includes(state.query.toLocaleLowerCase('pt-BR'))));
const filteredOutsideHours = () => (state.data?.outside_hours || []).filter(item => (!state.instructorId || String(item.user_id)===String(state.instructorId)) && (!state.query || ['horas fora de sala',item.user_name,item.reason].join(' ').toLocaleLowerCase('pt-BR').includes(state.query.toLocaleLowerCase('pt-BR'))));
const button = (text,action,style='btn',extra='') => `<button type="button" class="${style}" data-action="${action}" ${extra}>${text}</button>`;
const empty = (title,description='',symbol='calendar',action='') => `<div class="empty-state">${icon(symbol)}<h3>${escape(title)}</h3><p>${escape(description)}</p>${action}</div>`;
const opts = (items,selected='',label=item=>item.name,placeholder='Selecione…') => `<option value="">${escape(placeholder)}</option>${items.map(item=>`<option value="${escape(item.id)}" ${String(item.id)===String(selected)?'selected':''}>${escape(label(item))}</option>`).join('')}`;

async function api(path, method='GET', body) {
    const headers = {Accept:'application/json','X-Requested-With':'XMLHttpRequest'};
    if(method !== 'GET') {headers['Content-Type']='application/json';headers[config.csrfHeader || 'X-CSRF-TOKEN']=config.csrfToken;}
    const response = await fetch(`${config.baseUrl}${path}`, {method,headers,credentials:'same-origin',body:body === undefined ? undefined : JSON.stringify(body),cache:'no-store'});
    if(response.status === 401) {location.href=`${config.baseUrl}login`;throw new Error('Sua sessão terminou. Entre novamente.');}
    if(response.status === 428) {location.href=`${config.baseUrl}change-password`;throw new Error('Atualize sua senha para continuar.');}
    let result;
    try {result = await response.json();} catch (_) {throw new Error('Não foi possível concluir a solicitação. Atualize a página e tente novamente.');}
    if(result.csrfToken) config.csrfToken=result.csrfToken;
    if(!response.ok || result.ok===false) throw new Error(result.message || (response.status===403?'Você não tem permissão para esta ação.':'Não foi possível salvar. Confira os campos e tente novamente.'));
    return result.data && result.ok!==undefined ? result.data : result;
}

function toast(message, error=false) {
    const el=document.createElement('div');el.className=`toast${error?' error':''}`;el.innerHTML=`${icon(error?'alert':'checkCircle')}<span>${escape(message)}</span><button aria-label="Fechar aviso">${icon('close')}</button>`;
    el.querySelector('button').addEventListener('click',()=>el.remove());$('#toast-region').append(el);setTimeout(()=>el.remove(),error?8500:5000);
}
async function load(silent=false) {
    const sequence=++state.sequence;
    if(!silent) state.loading=true;
    if(state.data && !silent) {const bar=document.createElement('div');bar.className='loading-bar';bar.id='loading-bar';root.append(bar);}
    try {
        const query=`month=${encodeURIComponent(state.month)}${student&&state.classId?`&class_id=${encodeURIComponent(state.classId)}`:''}`;
        const data=await api(`api/bootstrap?${query}`);
        let extra=[];
        if(state.page==='agenda' && state.view==='week') {
            const months=[...new Set(weekDays().map(day=>day.slice(0,7)))].filter(month=>month!==state.month);
            const results=await Promise.all(months.map(month=>api(`api/bootstrap?month=${month}${student&&state.classId?`&class_id=${encodeURIComponent(state.classId)}`:''}`)));
            extra=results.flatMap(result=>result.lessons || []);
        }
        if(sequence!==state.sequence)return;
        state.data=data;state.extraLessons=extra;state.online=true;
        if(student && state.classId && !(data.classes || []).some(item=>String(item.id)===state.classId)) state.classId='';
        render();
    } catch(error) {
        if(sequence!==state.sequence)return;
        state.online=false;
        if(!state.data) root.innerHTML=`<div class="error-page">${icon('alert')}<h1>Não foi possível carregar a agenda</h1><p>${escape(error.message)}</p>${button(`${icon('refresh')}Tentar novamente`,'retry','btn btn-primary')}</div>`;
        else if(!silent) toast(error.message,true);
    } finally {state.loading=false;$('#loading-bar')?.remove();updateSync();}
}

function sidebar() {
    const entries=[['dashboard','grid'],['agenda','calendar'],['course_management','book'],['instructor_management','classroom'],['hours','clock'],['fleet','car'],['reservations','layers'],['catalog','people']].filter(([page])=>allowedPages.includes(page));
    const pending=(state.data.requests || []).filter(item=>item.status==='pending').length;
    return `<aside class="sidebar" id="sidebar" aria-label="Menu principal"><div class="brand"><span class="brand-mark">${icon('calendar')}</span><div><div class="brand-name">PAC Agenda</div><div class="brand-caption">Agendamento para colaboradores</div></div></div><div class="nav-label">Seu espaço</div><nav class="nav-group">${entries.map(([page,symbol])=>`<button class="nav-link ${state.page===page?'active':''}" data-action="navigate" data-page="${page}" ${state.page===page?'aria-current="page"':''} title="${labels[page]}">${icon(symbol)}<span>${labels[page]}</span>${page==='hours'&&pending?`<span class="nav-count">${pending}</span>`:''}</button>`).join('')}</nav><div class="sidebar-bottom"><div class="sidebar-user">${avatar(config.user.name)}<div class="user-details"><strong>${escape(config.user.name)}</strong><small>${escape(roleLabels[config.user.role])}</small></div><form class="logout-form" method="post" action="${escape(config.baseUrl)}logout"><input type="hidden" name="${escape(config.csrfName)}" value="${escape(config.csrfToken)}"><button class="icon-button" type="button" data-action="logout" title="Sair da conta" aria-label="Sair da conta">${icon('logout')}</button></form></div></div></aside><button class="mobile-scrim" data-action="close-menu" aria-label="Fechar menu"></button>`;
}
function heading(title,subtitle,actions='') {
    return `<div class="page-heading"><div><div class="eyebrow">${managed?'Planejamento pedagógico':student?'Seu próximo passo':'Seu tempo, bem organizado'}</div><h1>${escape(title)}</h1><p>${escape(subtitle)}</p></div><div class="heading-actions">${monthPicker()}${actions}</div></div>`;
}
function monthPicker() {const cycle=state.data?.hour_cycle?.label||'';return `<div class="period-controls">${!student&&cycle?`<span class="hour-cycle-chip" title="O dia 20 inicia um novo ciclo">${icon('clock')}Ciclo de horas <strong>${escape(cycle)}</strong></span>`:''}<div class="month-picker">${button(icon('chevronLeft'),'prev-month','icon-button','aria-label="Mês anterior"')}<label class="sr-only" for="month-select">Mês de referência</label><input type="month" id="month-select" value="${state.month}" aria-label="Mês de referência">${button(icon('chevronRight'),'next-month','icon-button','aria-label="Próximo mês"')}</div></div>`;}
function render() {
    if(!state.data)return;
    const scroll=window.scrollY;
    if(state.map) {state.map.remove();state.map=null;}
    const pageContent={dashboard:dashboard,agenda:agenda,course_management:courseManagementPage,instructor_management:instructorManagementPage,hours:hoursPage,fleet:fleetPage,reservations:reservationsPage,catalog:catalogPage}[state.page]();
    root.innerHTML=`<div class="app-shell">${sidebar()}<header class="topbar"><div class="inline">${button(icon('menu'),'menu','icon-button mobile-toggle','aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"')}<div class="breadcrumbs">${icon('home')}<span>Meu espaço</span>${icon('chevronRight')}<strong>${escape(labels[state.page])}</strong></div></div><div class="topbar-right"><div class="sync-state" id="sync-state" title="A agenda verifica atualizações a cada 2 segundos"><i class="sync-dot"></i><span>Agenda atualizada</span></div><div class="topbar-divider"></div><button class="icon-button" data-action="notifications" aria-label="Notificações${state.data.unread?`, ${state.data.unread} não lidas`:''}" aria-expanded="${state.notificationsOpen}">${icon('bell')}${state.data.unread?'<span class="notification-dot"></span>':''}</button><span class="avatar top-avatar" title="${escape(config.user.name)}">${escape(initials(config.user.name))}</span></div></header><main id="main" class="main" tabindex="-1">${pageContent}<footer class="page-footer"><span>PAC Agenda · Plataforma de Agendamento para Colaboradores.</span><span>${icon('shield')}Acesso de ${escape(roleLabels[config.user.role].toLowerCase())} · ${escape(monthLabel(state.month))}</span></footer></main>${state.notificationsOpen?notifications():''}</div>`;
    applyHourCycleCopy();
    bindPage();
    if(state.page==='fleet') requestAnimationFrame(initMap);
    window.scrollTo({top:scroll,behavior:'instant'});
}
function applyHourCycleCopy() {
    if(student||!state.data?.hour_cycle)return;
    const set=(selector,text)=>{const element=$(selector);if(element)element.textContent=text;};
    set('.dashboard-hours-card .badge','Ciclo 20/20');
    set('.dashboard-alerts-wide .card-head p','Acompanhamento do ciclo 20/20');
    set('.stats-grid .stat-card:nth-child(4) .stat-note','Todas as horas do ciclo');
    document.querySelectorAll('.dashboard-alerts-wide .alert-item.danger .alert-text p').forEach(element=>element.textContent='Limite de 50h atingido no ciclo');
    if(state.page==='hours') {
        set('.stats-grid .stat-card:nth-child(2) .stat-note','Já incluídas no ciclo selecionado');
        set('.stats-grid .stat-card:nth-child(3) .stat-note','Solicitações do ciclo selecionado');
        const notice=$('.notice span');if(notice)notice.innerHTML=`Somente horas <strong>aprovadas</strong> entram no ciclo de <strong>${escape(state.data.hour_cycle.label)}</strong>. O dia 20 inicia um novo ciclo.`;
    }
    if(state.page==='instructor_management')set('.management-stats .stat-card:first-child .stat-note','Carga prevista no ciclo');
}
function stat(label,value,note,symbol,tone='',suffix='h',level='') {
    return `<article class="stat-card ${level}"><div class="stat-top"><span>${escape(label)}</span><span class="stat-symbol ${tone}">${icon(symbol)}</span></div><div class="stat-value">${escape(value)}${suffix?`<span>${escape(suffix)}</span>`:''}</div><div class="stat-note">${escape(note)}</div></article>`;
}
function personalStats() {
    const self=(state.data.summary || []).find(item=>Number(item.user_id)===Number(config.user.id)) || {class_hours:0,completed_class_hours:0,outside_hours:0,total_hours:0,level:'normal'};
    return `<div class="stats-grid">${stat('Em sala · programadas',fmtHours(self.class_hours),'Aulas previstas neste mês','book','blue')}${stat('Realizadas em sala',fmtHours(self.completed_class_hours),'Carga horária decorrida das aulas','checkCircle','')}${stat('Fora de sala · aprovadas',fmtHours(self.outside_hours),self.level==='danger'?'Limite de 50h atingido':self.level==='warning'?`${fmtHours(50-Number(self.outside_hours))}h para atingir o limite de 50h`:'Pedidos aprovados neste mês','clock','orange','h',self.level)}${stat('Total programado',fmtHours(self.total_hours),'Em sala + fora de sala aprovadas','chart','purple')}</div>`;
}
function dashboard() {
    const summary=state.data.summary || [];
    const sum=field=>summary.reduce((total,row)=>total+Number(row[field]||0),0);
    const todayLessons=(state.data.lessons || []).filter(lesson=>lesson.starts_at.slice(0,10)===today).sort(byStart);
    const nextLessons=(state.data.lessons || []).filter(lesson=>lesson.starts_at.slice(0,10)>=today).sort(byStart).slice(0,4);
    const shown=todayLessons.length?todayLessons.slice(0,5):nextLessons;
    const pending=(state.data.requests || []).filter(item=>item.status==='pending');
    const alerts=summary.filter(item=>item.level==='warning'||item.level==='danger').sort((a,b)=>Number(b.outside_hours)-Number(a.outside_hours));
    return heading(managed?`Olá, ${config.user.name.split(' ')[0]}`:'Cada hora faz a diferença.',managed?'Uma visão tranquila do planejamento, para você cuidar do que importa.':'Acompanhe suas aulas, suas solicitações e a distribuição do seu tempo.',!student?button(`${icon('plus')}${managed?'Nova aula':'Solicitar horas'}`,managed?'new-lesson':'new-hours','btn btn-primary'):'')+
    (managed?`<div class="stats-grid">${stat('Instrutores na equipe',String(summary.length),'Pessoas que fazem acontecer','people','','')}${stat('Em sala · programadas',fmtHours(sum('class_hours')),`${fmtHours(sum('completed_class_hours'))}h realizadas até agora`,'book','blue')}${stat('Fora de sala · aprovadas',fmtHours(sum('outside_hours')),'Contam a partir da aprovação','clock','orange')}${stat('Total programado',fmtHours(sum('total_hours')),'Todas as horas do mês','chart','purple')}</div>`:personalStats())+
    `<div class="dashboard-grid dashboard-hours-layout"><section class="card dashboard-hours-card"><div class="card-head"><div><h2>${managed?'Distribuição de horas por instrutor':'Minha carga horária'}</h2><p>Programação em sala e horas aprovadas fora de sala</p></div><span class="badge">Mensal</span></div><div class="chart-legend"><span class="legend-item"><i class="legend-square"></i>Em sala · programadas</span><span class="legend-item"><i class="legend-line"></i>Fora de sala · aprovadas</span></div>${hoursChart(summary)}</section><section class="card alerts-card dashboard-alerts-wide"><div class="card-head"><div><h2>Atenção às horas <span class="count-pill">${alerts.length}</span></h2><p>Acompanhamento do limite mensal</p></div>${icon('alert')}</div>${alerts.length?`<div class="alert-list">${alerts.map(item=>`<div class="alert-item ${item.level}">${icon('alert')}<div class="alert-text"><strong>${escape(item.name)}</strong><p>${item.level==='danger'?'Limite mensal de 50h atingido':`Faltam ${fmtHours(50-Number(item.outside_hours))}h para o limite`}</p></div><div class="alert-hours">${fmtHours(item.outside_hours)}<small> h</small></div></div>`).join('')}</div>`:empty('Tudo dentro do limite','Nenhum instrutor com 40 horas ou mais fora de sala neste mês.','checkCircle')}<p class="info-note">Amarelo a partir de 40h. Vermelho a partir de 50h. Apenas solicitações aprovadas entram na soma.</p></section></div><div class="dashboard-grid section-gap dashboard-activity-layout"><section class="card"><div class="card-head"><div><h2>${todayLessons.length?'Aulas de hoje':'Próximas aulas'} <span class="count-pill">${shown.length}</span></h2><p>${todayLessons.length?fullDate(today):'O que vem pela frente no mês selecionado'}</p></div>${button(`Ver agenda ${icon('arrow')}`,'navigate','btn btn-ghost btn-sm','data-page="agenda"')}</div>${shown.length?`<div class="today-list">${shown.map(lesson=>lessonRow(lesson,!todayLessons.length)).join('')}</div>`:empty('Agenda livre por aqui','As aulas cadastradas para o mês aparecerão neste espaço.','calendar',managed?button(`${icon('plus')}Agendar aula`,'new-lesson','btn btn-primary btn-sm'):'')}</section><section class="card"><div class="card-head"><div><h2>Solicitações em andamento <span class="count-pill">${pending.length}</span></h2><p>${manager?'Pedidos que precisam do seu olhar':'Acompanhe os pedidos de horas'}</p></div></div>${pending.length?`<div class="requests-preview">${pending.slice(0,3).map(item=>`<div class="request-preview">${avatar(item.user_name||config.user.name)}<div><strong>${escape(item.user_name||config.user.name)}</strong><p>${escape(item.reason.length>48?item.reason.slice(0,48)+'…':item.reason)}</p><span class="badge pending">${shortDate(item.work_date)} · Em andamento</span></div><span class="request-amount">${fmtHours(item.hours)}h</span></div>`).join('')}</div><div class="card-footer">${button(`Ver solicitações ${icon('arrow')}`,'navigate','btn btn-ghost','data-page="hours"')}</div>`:empty('Tudo acompanhado','Nenhum pedido aguardando aprovação neste mês.','checkCircle')}</section></div>`;
}
function hoursChart(rows) {
    if(!rows.length)return empty('Ainda sem horas para mostrar','Os instrutores cadastrados e suas horas aparecerão aqui.','chart');
    const maximum=Math.max(100,...rows.map(row=>Math.max(Number(row.class_hours),Number(row.outside_hours))));
    const scaleMax=Math.ceil(maximum/25)*25,percent=value=>Math.min(100,Math.max(0,(Number(value)/scaleMax)*100));
    const scale=[1,.75,.5,.25,0].map(part=>`<span style="bottom:${part*100}%">${fmtHours(scaleMax*part)}h</span>`).join('');
    return `<div class="vertical-hours-wrap" role="img" aria-label="Comparativo vertical das horas em sala e fora de sala por instrutor" style="--threshold:${238*percent(50)/100}px"><div class="vertical-hours-scale" aria-hidden="true">${scale}</div><div class="vertical-hours-chart">${rows.map(row=>`<article class="vertical-hours-person ${escape(row.level)}" aria-label="${escape(`${row.name}: ${fmtHours(row.class_hours)} horas em sala e ${fmtHours(row.outside_hours)} horas fora de sala`)}"><div class="vertical-bars"><span class="vertical-bar class-hours" style="height:${percent(row.class_hours)}%"><b>${fmtHours(row.class_hours)}h</b></span><span class="vertical-bar outside-hours ${escape(row.level)}" style="height:${percent(row.outside_hours)}%"><b>${fmtHours(row.outside_hours)}h</b></span></div><div class="vertical-person-label">${avatar(row.name)}<div><strong>${escape(row.name)}</strong><small>${fmtHours(row.total_hours)}h no total · ${fmtHours(row.completed_class_hours)}h realizadas</small></div></div></article>`).join('')}</div></div><details class="chart-accessible"><summary>Ver os números por instrutor</summary><table class="compact-table"><thead><tr><th>Instrutor</th><th>Em sala</th><th>Realizadas</th><th>Fora de sala</th><th>Total</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${escape(row.name)}</td><td>${fmtHours(row.class_hours)}h</td><td>${fmtHours(row.completed_class_hours)}h</td><td>${fmtHours(row.outside_hours)}h</td><td>${fmtHours(row.total_hours)}h</td></tr>`).join('')}</tbody></table></details>`;
}
function lessonRow(lesson,showDate=false) {
    return `<button class="lesson-row" data-action="lesson-detail" data-id="${lesson.id}"><span class="time-col">${showDate?shortDate(lesson.starts_at):time(lesson.starts_at)}<small>${showDate?time(lesson.starts_at):time(lesson.ends_at)}</small></span><span class="course-stripe" style="--course-color:${color(lesson.course_color)}"></span><span class="lesson-info"><span class="lesson-name">${escape(lesson.course_name)}</span><span class="lesson-meta"><span>${icon('people')}${escape(lesson.class_code||lesson.class_name)}</span><span>${icon('location')}${escape(lesson.room_name||lesson.location||lesson.city||'Local a definir')}</span></span></span>${managed?`<span class="lesson-person">${avatar(lesson.instructor_name)}${escape(lesson.instructor_name?.split(' ')[0])}</span>`:''}<span class="lesson-status">${lesson.starts_at.slice(0,10)===today?'Hoje':shortDate(lesson.starts_at)}</span>${icon('chevronRight')}</button>`;
}
function agenda() {
    const classes=state.data.classes || [];
    const courses=state.data.courses || [];
    const users=(state.data.users || []).filter(item=>item.role==='instructor');
    const lessons=filteredLessons();
    const outsideHours=filteredOutsideHours();
    const days=weekDays();
    const weekTitle=`${shortDate(days[0])} a ${shortDate(days[6])}`;
    const selectedClass=classes.find(item=>String(item.id)===state.classId);
    const studentSelector=student?`<section class="student-selector"><div><h2>Qual é a sua turma?</h2><p>Escolha sua turma para ver cursos, horários e locais de aula.</p></div><label class="sr-only" for="student-class">Sua turma</label><select id="student-class">${opts(classes,state.classId,item=>`${item.code} · ${item.name}`,'Selecione sua turma')}</select></section>`:'';
    return heading(labels.agenda,student?'Tudo o que você precisa para chegar à próxima aula.':instructor?'Seu próximo encontro com a aprendizagem começa aqui.':'Organize os encontros, conecte a equipe e acompanhe cada turma.',managed?button(`${icon('plus')}Nova aula`,'new-lesson','btn btn-primary'):instructor?button(`${icon('plus')}Solicitar horas`,'new-hours','btn btn-primary'):'')+studentSelector+(instructor?personalStats():'')+(student&&!state.classId?`<section class="card">${empty('Sua programação começa aqui','Selecione uma turma acima para visualizar a agenda de aulas.','book')}</section>`:`<section class="card"><div class="filters-bar"><div class="filters">${managed?`<label class="sr-only" for="filter-instructor">Filtrar instrutor</label><select id="filter-instructor">${opts(users,state.instructorId,item=>item.name,'Todos os instrutores')}</select><label class="sr-only" for="filter-class">Filtrar turma</label><select id="filter-class">${opts(classes,state.classId,item=>`${item.code} · ${item.name}`,'Todas as turmas')}</select>`:''}<label class="sr-only" for="filter-course">Filtrar curso</label><select id="filter-course">${opts(courses,state.courseId,item=>item.name,'Todos os cursos')}</select><div class="search-input">${icon('search')}<label class="sr-only" for="agenda-search">Pesquisar na agenda</label><input id="agenda-search" type="search" placeholder="Buscar na agenda" value="${escape(state.query)}"></div></div><div class="segmented" aria-label="Visualização da agenda">${[['week','Semana'],['month','Mês'],['list','Lista']].map(([view,label])=>button(label,'calendar-view',view===state.view?'active':'',`data-view="${view}" aria-pressed="${view===state.view}"`)).join('')}</div></div><div class="calendar-top"><div><h2>${state.view==='week'?weekTitle:escape(monthLabel(state.month))}${student&&selectedClass?` · ${escape(selectedClass.code)}`:''}</h2></div><div class="calendar-navigation">${button('Hoje','calendar-today','btn btn-sm')}${state.view==='week'?button(icon('chevronLeft'),'prev-week','icon-button','aria-label="Semana anterior"')+button(icon('chevronRight'),'next-week','icon-button','aria-label="Próxima semana"'):''}</div></div>${state.view==='week'?calendarWeek(lessons,outsideHours,days):state.view==='month'?calendarMonth(lessons,outsideHours):calendarList(lessons,outsideHours)}<div class="course-legend">${courses.map(item=>`<span class="legend-item"><i class="dot" style="background:${color(item.color)}"></i>${escape(item.name)}</span>`).join('')}${!student?'<span class="legend-item"><i class="dot outside-dot"></i>Horas fora de sala · aprovadas</span>':''}</div></section>`);
}
function eventCard(lesson,compact=false) {
    return `<button class="calendar-event" style="--course-color:${color(lesson.course_color)}" data-action="lesson-detail" data-id="${lesson.id}" title="${escape(`${lesson.course_name} · ${timeRange(lesson)} · ${lesson.instructor_name}`)}"><time>${compact?time(lesson.starts_at):timeRange(lesson)}</time><strong>${escape(lesson.course_name)}</strong>${!compact?`<small>${escape(lesson.class_code||lesson.class_name)}</small>${managed?`<small>${escape(lesson.instructor_name)}</small>`:''}<small class="event-room">${icon('location')}${escape(lesson.room_name||lesson.location||lesson.city||'A definir')}</small>`:''}</button>`;
}
function outsideCard(item,compact=false) {return `<button class="calendar-event outside-event" data-action="outside-detail" data-id="${item.id}" title="${escape(`Horas fora de sala · ${fmtHours(item.hours)}h · ${item.user_name}`)}"><time>${fmtHours(item.hours)}h</time><strong>${compact?'Fora de sala':'Horas fora de sala'}</strong>${!compact?`<small>${escape(item.reason)}</small>${managed?`<small>${escape(item.user_name)}</small>`:''}`:''}</button>`;}
function outsideRow(item) {return `<button class="lesson-row outside-row" data-action="outside-detail" data-id="${item.id}"><span class="time-col">${fmtHours(item.hours)}h<small>Aprovadas</small></span><span class="course-stripe outside-stripe"></span><span class="lesson-info"><span class="lesson-name">Horas fora de sala</span><span class="lesson-meta"><span>${icon('info')}${escape(item.reason)}</span></span></span>${managed?`<span class="lesson-person">${avatar(item.user_name)}${escape(item.user_name?.split(' ')[0])}</span>`:''}<span class="lesson-status outside-status">Aprovado</span>${icon('chevronRight')}</button>`;}
function calendarWeek(lessons,outsideHours,days) {return `<div class="calendar-week">${days.map(day=>{const events=lessons.filter(item=>item.starts_at.slice(0,10)<=day&&item.ends_at.slice(0,10)>=day),outside=outsideHours.filter(item=>item.work_date===day);return `<section class="week-column"><div class="week-date ${day===today?'today':''}">${dt(day).toLocaleDateString('pt-BR',{weekday:'short'}).replace('.','')}<strong>${Number(day.slice(8))}</strong></div>${events.map(lesson=>eventCard(lesson)).join('')}${outside.map(item=>outsideCard(item)).join('')}${!events.length&&!outside.length?`<p class="day-empty">Sem programação</p>`:''}</section>`;}).join('')}</div>`;}
function calendarMonth(lessons,outsideHours) {
    const first=`${state.month}-01`,start=addDays(first,-((dt(first).getDay()+6)%7));
    const last=dt(first);last.setMonth(last.getMonth()+1,0);
    const length=Math.ceil((((dt(first).getDay()+6)%7)+last.getDate())/7)*7;
    return `<div class="calendar-month">${['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'].map(day=>`<div class="month-weekday">${day}</div>`).join('')}${Array.from({length},(_,index)=>{const day=addDays(start,index),items=[...lessons.filter(item=>item.starts_at.slice(0,10)===day).map(item=>['lesson',item]),...outsideHours.filter(item=>item.work_date===day).map(item=>['outside',item])],outside=day.slice(0,7)!==state.month;return `<div class="month-day ${outside?'outside':''}"><button class="day-number ${day===today?'today':''}" data-action="day-list" data-date="${day}" aria-label="Ver programação de ${shortDate(day)}">${Number(day.slice(8))}</button>${items.slice(0,3).map(([type,item])=>type==='lesson'?eventCard(item,true):outsideCard(item,true)).join('')}${items.length>3?button(`+ ${items.length-3} itens`,'day-list','more-events',`data-date="${day}"`):''}</div>`;}).join('')}</div>`;
}
function courseCalendarMonth(lessons) {
    const first=`${state.month}-01`,start=addDays(first,-((dt(first).getDay()+6)%7));
    const last=dt(first);last.setMonth(last.getMonth()+1,0);
    const length=Math.ceil((((dt(first).getDay()+6)%7)+last.getDate())/7)*7;
    return `<div class="calendar-month course-month-calendar">${['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'].map(day=>`<div class="month-weekday">${day}</div>`).join('')}${Array.from({length},(_,index)=>{const day=addDays(start,index),items=lessons.filter(item=>item.starts_at.slice(0,10)===day),outside=day.slice(0,7)!==state.month;return `<div class="month-day ${outside?'outside':''}"><span class="day-number ${day===today?'today':''}">${Number(day.slice(8))}</span>${items.slice(0,3).map(item=>`<button class="course-calendar-event" style="--course-color:${color(item.course_color)}" data-action="lesson-detail" data-id="${item.id}" title="${escape(`${timeRange(item)} · ${item.instructor_name} · ${item.class_code||item.class_name}`)}"><span><time>${time(item.starts_at)}</time><strong>${escape(item.instructor_name)}</strong></span><small>${escape(item.class_code||item.class_name)}</small></button>`).join('')}${items.length>3?`<span class="course-calendar-more">+ ${items.length-3} aulas</span>`:''}</div>`;}).join('')}</div>`;
}
function calendarList(lessons,outsideHours) {
    const items=[...lessons.filter(item=>item.starts_at.slice(0,7)===state.month).map(item=>['lesson',item]),...outsideHours.filter(item=>item.work_date.slice(0,7)===state.month).map(item=>['outside',item])];
    if(!items.length)return empty('Nenhuma programação encontrada','Tente outro mês ou ajuste os filtros. Novas atividades aparecem automaticamente.','calendar',managed?button(`${icon('plus')}Agendar aula`,'new-lesson','btn btn-primary btn-sm'):'');
    const grouped=new Map();items.forEach(([type,item])=>{const day=type==='lesson'?item.starts_at.slice(0,10):item.work_date;if(!grouped.has(day))grouped.set(day,[]);grouped.get(day).push([type,item]);});
    return `<div class="agenda-list">${[...grouped].sort(([a],[b])=>a.localeCompare(b)).map(([day,events])=>`<section id="day-${day}"><h3 class="agenda-date ${day===today?'today':''}">${escape(fullDate(day))}${day===today?'<span class="badge approved">Hoje</span>':''}</h3>${events.map(([type,item])=>type==='lesson'?lessonRow(item):outsideRow(item)).join('')}</section>`).join('')}</div>`;
}
function instructorManagementPage() {
    const instructors=(state.data.users || []).filter(item=>item.role==='instructor'&&Number(item.active)!==0);
    if(!instructors.length)return heading('Gestão de instrutores','Acompanhe individualmente agenda, cursos e carga horária.')+`<section class="card">${empty('Nenhum instrutor cadastrado','Cadastre instrutores para começar a organizar a equipe.','people')}</section>`;
    if(!instructors.some(item=>String(item.id)===String(state.selectedInstructorId)))state.selectedInstructorId=String(instructors[0].id);
    const selected=instructors.find(item=>String(item.id)===String(state.selectedInstructorId));
    const summary=(state.data.summary || []).find(item=>Number(item.user_id)===Number(selected.id)) || {class_hours:0,completed_class_hours:0,outside_hours:0,total_hours:0,level:'normal'};
    const lessons=allLessons().filter(item=>Number(item.instructor_id)===Number(selected.id)).sort(byStart);
    const outside=(state.data.outside_hours || []).filter(item=>Number(item.user_id)===Number(selected.id));
    const courses=[...new Map(lessons.map(item=>[item.course_name,{name:item.course_name,color:item.course_color}])).values()];
    const schedule=[...lessons.map(item=>({type:'lesson',date:item.starts_at,item})),...outside.map(item=>({type:'outside',date:`${item.work_date} 23:59:00`,item}))].sort((a,b)=>a.date.localeCompare(b.date));
    const actions=`${button(`${icon('calendar')}Abrir agenda individual`,'open-instructor-agenda','btn',`data-id="${selected.id}"`)}${button(`${icon('plus')}Agendar para ${escape(selected.name.split(' ')[0])}`,'instructor-new-lesson','btn btn-primary',`data-id="${selected.id}"`)}`;
    return heading('Gestão de instrutores','Selecione um instrutor para acompanhar e configurar sua programação.',actions)+`<div class="management-layout"><aside class="card management-selector"><div class="management-selector-head"><h2>Equipe de instrutores</h2><span class="count-pill">${instructors.length}</span></div><div class="management-selector-list">${instructors.map(item=>{const row=(state.data.summary || []).find(summaryItem=>Number(summaryItem.user_id)===Number(item.id));return `<button class="management-selector-item ${Number(item.id)===Number(selected.id)?'active':''}" data-action="select-instructor" data-id="${item.id}">${avatar(item.name)}<span><strong>${escape(item.name)}</strong><small>${fmtHours(row?.total_hours||0)}h programadas</small></span><i class="status-light ${escape(row?.level||'normal')}"></i>${icon('chevronRight')}</button>`;}).join('')}</div></aside><div class="management-content"><section class="card management-hero"><div class="management-profile">${avatar(selected.name)}<div><span class="eyebrow">Instrutor selecionado</span><h2>${escape(selected.name)}</h2><p>${escape(selected.email||'')} · Crachá ${escape(selected.badge)}</p></div></div>${manager?button(`${icon('edit')}Editar cadastro`,'edit-catalog','btn btn-sm',`data-type="users" data-id="${selected.id}"`):''}</section><div class="stats-grid management-stats">${stat('Em sala · programadas',fmtHours(summary.class_hours),'Carga prevista no mês','book','blue')}${stat('Em sala · realizadas',fmtHours(summary.completed_class_hours),'Aulas já concluídas','checkCircle','')}${stat('Fora de sala',fmtHours(summary.outside_hours),summary.level==='danger'?'Limite atingido':summary.level==='warning'?'Próximo ao limite':'Dentro do limite','clock','orange','h',summary.level)}${stat('Carga total',fmtHours(summary.total_hours),'Sala + atividades externas','chart','purple')}</div><section class="card instructor-courses"><div class="card-head"><div><h2>Cursos que está ministrando <span class="count-pill">${courses.length}</span></h2><p>Com base nas aulas do mês selecionado</p></div></div>${courses.length?`<div class="course-chip-list">${courses.map(course=>`<span class="course-chip"><i style="background:${color(course.color)}"></i>${escape(course.name)}</span>`).join('')}</div>`:empty('Nenhum curso neste mês','As disciplinas aparecem quando uma aula é agendada para o instrutor.','book')}</section><section class="card management-agenda"><div class="card-head"><div><h2>Agenda individual <span class="count-pill">${schedule.length}</span></h2><p>Aulas e horas aprovadas fora de sala</p></div>${button(`${icon('plus')}Nova aula`,'instructor-new-lesson','btn btn-primary btn-sm',`data-id="${selected.id}"`)}</div>${schedule.length?`<div class="management-schedule">${schedule.map(entry=>entry.type==='lesson'?lessonRow(entry.item,true):outsideRow(entry.item)).join('')}</div>`:empty('Agenda livre neste mês','Agende a primeira aula para este instrutor.','calendar',button('Agendar aula','instructor-new-lesson','btn btn-primary btn-sm',`data-id="${selected.id}"`))}</section></div></div>`;
}
function courseManagementPage() {
    const courses=state.data.courses || [];
    if(!courses.length)return heading('Gestão de cursos','Acompanhe turmas, instrutores e programação por curso.',button(`${icon('plus')}Cadastrar curso`,'new-catalog','btn btn-primary','data-type="courses"'))+`<section class="card">${empty('Nenhum curso cadastrado','Cadastre o primeiro curso para iniciar o planejamento.','book')}</section>`;
    if(!courses.some(item=>String(item.id)===String(state.selectedCourseId)))state.selectedCourseId=String(courses[0].id);
    const selected=courses.find(item=>String(item.id)===String(state.selectedCourseId));
    const classes=(state.data.classes || []).filter(item=>Number(item.course_id)===Number(selected.id));
    const classIds=new Set(classes.map(item=>Number(item.id)));
    const lessons=allLessons().filter(item=>classIds.has(Number(item.class_id))).sort(byStart);
    const instructors=[...new Map(lessons.map(item=>[item.instructor_id,{id:item.instructor_id,name:item.instructor_name}])).values()];
    const scheduledHours=lessons.reduce((total,item)=>total+durationHours(item.starts_at,item.ends_at),0);
    const actions=`${button(`${icon('plus')}Cadastrar curso`,'new-catalog','btn',`data-type="courses"`)}${button(`${icon('calendar')}Agendar aula`,'course-new-lesson','btn btn-primary',`data-id="${selected.id}"`)}`;
    return heading('Gestão de cursos','Selecione um curso para acompanhar turmas, instrutores e agenda.',actions)+`<div class="management-layout"><aside class="card management-selector"><div class="management-selector-head"><h2>Cursos cadastrados</h2><span class="count-pill">${courses.length}</span></div><div class="management-selector-list">${courses.map(item=>{const courseClasses=(state.data.classes||[]).filter(row=>Number(row.course_id)===Number(item.id));return `<button class="management-selector-item course-selector ${Number(item.id)===Number(selected.id)?'active':''}" data-action="select-course" data-id="${item.id}"><i class="course-color" style="background:${color(item.color)}"></i><span><strong>${escape(item.name)}</strong><small>${courseClasses.length} ${courseClasses.length===1?'turma':'turmas'}</small></span>${icon('chevronRight')}</button>`;}).join('')}</div></aside><div class="management-content"><section class="card management-hero course-hero" style="--course-color:${color(selected.color)}"><div class="management-profile"><span class="course-hero-icon">${icon('book')}</span><div><span class="eyebrow">Curso selecionado</span><h2>${escape(selected.name)}</h2><p>${classes.length} ${classes.length===1?'turma vinculada':'turmas vinculadas'} neste planejamento</p></div></div>${button(`${icon('edit')}Editar curso`,'edit-catalog','btn btn-sm',`data-type="courses" data-id="${selected.id}"`)}</section><div class="course-overview-grid"><article class="card mini-overview"><span>${icon('layers')}</span><strong>${classes.length}</strong><small>Turmas</small></article><article class="card mini-overview"><span>${icon('people')}</span><strong>${instructors.length}</strong><small>Instrutores</small></article><article class="card mini-overview"><span>${icon('calendar')}</span><strong>${lessons.length}</strong><small>Aulas</small></article><article class="card mini-overview"><span>${icon('clock')}</span><strong>${fmtHours(scheduledHours)}h</strong><small>Programadas</small></article></div><section class="card course-team"><div class="card-head"><div><h2>Turmas e instrutores</h2><p>Estrutura ativa no mês selecionado</p></div></div><div class="course-class-list">${classes.map(item=>{const assigned=[...new Set(lessons.filter(lesson=>Number(lesson.class_id)===Number(item.id)).map(lesson=>lesson.instructor_name))];return `<article><span class="class-code">${escape(item.code)}</span><div><strong>${escape(item.name)}</strong><small>${escape(item.city)} · ${assigned.length?escape(assigned.join(', ')):'Sem instrutor no mês'}</small></div>${button(`${icon('calendar')}Agendar`,'course-class-new-lesson','btn btn-sm',`data-id="${item.id}"`)}</article>`;}).join('')||'<p class="management-empty">Nenhuma turma vinculada a este curso.</p>'}</div></section><section class="card management-agenda"><div class="card-head"><div><h2>Calendário mensal do curso <span class="count-pill">${lessons.length}</span></h2><p>${escape(monthLabel(state.month))} · instrutores e turmas por dia</p></div><span class="badge">Mensal</span></div>${courseCalendarMonth(lessons)}</section></div></div>`;
}
function hoursPage() {
    const requests=(state.data.requests || []).filter(item=>state.hoursFilter==='all'||item.status===state.hoursFilter);
    const pending=(state.data.requests || []).filter(item=>item.status==='pending');
    const approved=(state.data.requests || []).filter(item=>item.status==='approved');
    const own=(state.data.requests || []).filter(item=>Number(item.user_id)===Number(config.user.id));
    return heading('Horas fora de sala',manager?'Cada solicitação, uma decisão clara. Aprove e acompanhe o mês.':'Registre seu planejamento e acompanhe a aprovação do gerente.',button(`${icon('plus')}Solicitar horas`,'new-hours','btn btn-primary'))+
    `<div class="stats-grid">${stat('Pedidos em andamento',String(pending.length),'Aguardando decisão do gerente','clock','orange','')}${stat('Horas aprovadas',fmtHours(approved.reduce((total,item)=>total+Number(item.hours),0)),'Já incluídas no total mensal','checkCircle','')}${stat('Meus pedidos',String(own.length),'Solicitações neste mês','people','blue','')}${stat('Referência de alerta','40 / 50','Atenção a partir de 40h; limite em 50h','alert','purple')}</div><div class="notice">${icon('info')}<span>Somente horas <strong>aprovadas</strong> entram na carga mensal. A referência é a data da atividade. Pedidos em andamento e não aprovados não entram na soma.</span></div><div class="tabs-bar" aria-label="Filtrar status">${[['all','Todos'],['pending','Em andamento'],['approved','Concluídos'],['rejected','Não aprovados']].map(([status,label])=>button(label,'hours-filter',`tab-btn ${state.hoursFilter===status?'active':''}`,`data-filter="${status}" aria-pressed="${state.hoursFilter===status}"`)).join('')}</div>${requests.length?`<div class="list-cards">${requests.map(item=>`<article class="request-card"><div><div class="request-summary"><h3>${escape(item.user_name||config.user.name)}</h3>${badge(item.status)}</div><p>${escape(item.reason)}</p><div class="lesson-meta"><span>${icon('calendar')}${escape(fullDate(item.work_date))}</span>${item.reviewed_at?`<span>Decisão em ${dateTime(item.reviewed_at)}</span>`:''}</div>${item.manager_note?`<div class="manager-note"><strong>Retorno do gerente:</strong> ${escape(item.manager_note)}</div>`:''}${manager&&item.status==='pending'?`<div class="button-row mt-16">${button(`${icon('check')}Aprovar`,'review-hours','btn btn-primary btn-sm',`data-id="${item.id}" data-status="approved"`)}${button('Não aprovar','review-hours','btn btn-sm',`data-id="${item.id}" data-status="rejected"`)}</div>`:''}</div><div class="hours-number">${fmtHours(item.hours)}<small> h</small></div></article>`).join('')}</div>`:`<section class="card">${empty('Nenhuma solicitação por aqui','Os pedidos do mês selecionado aparecerão nesta lista.','clock',button(`${icon('plus')}Solicitar horas`,'new-hours','btn btn-primary btn-sm'))}</section>`}`;
}
function fleetPage() {
    const vehicles=state.data.vehicles || [],trips=(state.data.trips || []).slice().sort(byStart);
    return heading('Frota e deslocamentos','Saiba quem vai, quando vai e qual veículo acompanha cada curso.',button(`${icon('plus')}Programar viagem`,'new-trip','btn btn-primary'))+`<div class="fleet-grid">${vehicles.map(vehicle=>{const schedules=trips.filter(item=>Number(item.vehicle_id)===Number(vehicle.id));const current=schedules.find(item=>item.starts_at.slice(0,10)<=today&&item.ends_at.slice(0,10)>=today);return `<article class="card vehicle-card"><div class="vehicle-top"><span class="vehicle-icon">${icon('car')}</span><span class="badge ${current?'pending':'approved'}">${current?'Programado hoje':'Sem viagem hoje'}</span></div><h3>${escape(vehicle.name)}</h3><p>${escape(vehicle.plate)} · ${vehicle.seats} lugares</p><div class="vehicle-footer">${icon('calendar')}${schedules.length} ${schedules.length===1?'viagem programada':'viagens programadas'} no mês</div></article>`;}).join('')}</div><div class="two-col"><section class="card"><div class="card-head"><div><h2>Onde a aprendizagem acontece</h2><p>Cidades, instrutores e horários previstos</p></div>${icon('location')}</div><div id="fleet-map" class="map-shell" role="region" aria-label="Mapa de destinos programados"><div class="map-fallback">Carregando mapa de destinos…</div></div><p class="map-caption">Localização <strong>programada</strong> nas viagens do mês. O mapa não representa rastreamento em tempo real.</p></section><section class="card"><div class="card-head"><div><h2>Rotas programadas <span class="count-pill">${trips.length}</span></h2><p>${escape(monthLabel(state.month))}</p></div></div>${trips.length?`<div class="trip-list">${trips.map(item=>`<article class="trip-row"><span class="trip-icon">${icon('location')}</span><div class="trip-info"><h3>${escape(item.city)}</h3><p>${escape(item.destination)}</p><p>${escape(item.instructor_name)} · ${escape(item.vehicle_name)}</p><p>${dateTime(item.starts_at)} → ${dateTime(item.ends_at)}</p></div><button class="icon-button" data-action="edit-trip" data-id="${item.id}" title="Editar viagem para ${escape(item.city)}" aria-label="Editar viagem para ${escape(item.city)}">${icon('edit')}</button></article>`).join('')}</div>`:empty('Nenhuma viagem neste mês','Programe um destino e vincule o instrutor e o veículo.','car',button('Programar viagem','new-trip','btn btn-primary btn-sm'))}</section></div>`;
}
function reservationsPage() {
    const reservations=(state.data.reservations || []).slice().sort(byStart),rooms=state.data.rooms || [],tablets=state.data.tablet_pools || [];
    return heading('Tudo pronto para a aula','Reserve ambientes e tablets. O sistema confere os horários para você.',button(`${icon('plus')}Nova reserva`,'new-reservation','btn btn-primary'))+`<div class="resource-cards">${rooms.map(item=>`<article class="card resource-card"><span class="resource-icon">${icon('door')}</span><div><h3>${escape(item.name)}</h3><p>${item.capacity} lugares</p></div></article>`).join('')}${tablets.map(item=>`<article class="card resource-card"><span class="resource-icon">${icon('tablet')}</span><div><h3>${escape(item.name)}</h3><p>${item.quantity} tablets no conjunto</p></div></article>`).join('')}</div><div class="notice">${icon('info')}<span>Salas com aula agendada já estão ocupadas. Reservas de tablets respeitam a quantidade disponível em cada horário.</span></div><section class="card"><div class="card-head"><div><h2>${managed?'Reservas da equipe':'Minhas reservas'} <span class="count-pill">${reservations.length}</span></h2><p>Planejamento para ${escape(monthLabel(state.month))}</p></div></div>${reservations.length?`<div class="table-scroll mt-16"><table class="data-table"><thead><tr><th>Recurso</th><th>Responsável</th><th>Início e término</th><th>Finalidade</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>${reservations.map(item=>`<tr><td><strong>${escape(item.resource_name)}</strong><small>${item.resource_type==='tablet'?`${item.quantity} tablets`:'Sala de aula'}</small></td><td>${escape(item.user_name)}</td><td>${dateTime(item.starts_at)}<small>até ${dateTime(item.ends_at)}</small></td><td class="reason-cell">${escape(item.purpose)}</td><td>${button(icon('trash'),'delete-reservation','icon-button',`data-id="${item.id}" title="Cancelar reserva" aria-label="Cancelar reserva de ${escape(item.resource_name)}"`)}</td></tr>`).join('')}</tbody></table></div>`:empty('Nenhuma reserva para este mês','Escolha uma sala ou um conjunto de tablets e reserve o período.','layers',button('Fazer uma reserva','new-reservation','btn btn-primary btn-sm'))}</section>`;
}
function catalogCreateMenu(tabs) {
    const options={courses:['book','Curso','Conteúdo e cor na agenda'],classes:['classroom','Turma','Código, curso e cidade'],rooms:['door','Sala','Ambiente e capacidade'],tablet_pools:['tablet','Tablets','Conjunto e quantidade'],vehicles:['car','Veículo','Nome, placa e lugares'],users:['people','Pessoa','Acesso e perfil no sistema']};
    return `<details class="catalog-create"><summary class="btn btn-primary">${icon('plus')}Novo cadastro${icon('down')}</summary><div class="catalog-create-menu" role="menu"><div class="catalog-menu-head"><strong>O que deseja cadastrar?</strong><span>Escolha uma opção para continuar</span></div>${tabs.map(([type])=>{const [symbol,label,description]=options[type];return `<button type="button" class="catalog-menu-item" data-action="new-catalog" data-type="${type}" role="menuitem">${icon(symbol)}<span><strong>${label}</strong><small>${description}</small></span>${icon('chevronRight')}</button>`;}).join('')}</div></details>`;
}
function catalogPage() {
    const tabs=[['courses','Cursos'],['classes','Turmas'],['rooms','Salas'],['tablet_pools','Tablets'],['vehicles','Veículos'],...(manager?[['users','Pessoas']]:[])];
    const tab=tabs.find(([key])=>key===state.catalogTab)?state.catalogTab:'courses';
    const definitions={courses:{label:'curso',headers:['Curso','Cor na agenda'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td><span class="legend-item"><i class="dot" style="background:${color(item.color)}"></i>${escape(item.color)}</span></td>`},classes:{label:'turma',headers:['Turma','Curso','Cidade'],row:item=>`<td><strong>${escape(item.name)}</strong><small>${escape(item.code)}</small></td><td>${escape((state.data.courses||[]).find(course=>Number(course.id)===Number(item.course_id))?.name)}</td><td>${escape(item.city)}</td>`},rooms:{label:'sala',headers:['Sala','Capacidade'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td>${item.capacity} lugares</td>`},tablet_pools:{label:'conjunto de tablets',headers:['Conjunto','Quantidade'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td>${item.quantity} tablets</td>`},vehicles:{label:'veículo',headers:['Veículo','Placa','Lugares'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td>${escape(item.plate)}</td><td>${item.seats}</td>`},users:{label:'pessoa',headers:['Pessoa','Crachá','Perfil','Status'],row:item=>`<td><div class="person">${avatar(item.name)}<div><strong>${escape(item.name)}</strong><small>${escape(item.email)}</small></div></div></td><td>${escape(item.badge)}</td><td>${escape(roleLabels[item.role])}</td><td><span class="badge ${Number(item.active)!==0?'approved':'rejected'}">${Number(item.active)!==0?'Ativo':'Inativo'}</span></td>`}};
    const definition=definitions[tab],items=state.data[tab]||[];
    return heading('Uma base bem organizada','Pessoas, cursos e recursos que dão vida à sua programação.',catalogCreateMenu(tabs))+`<div class="tabs-bar">${tabs.map(([key,label])=>button(label,'catalog-tab',`tab-btn ${key===tab?'active':''}`,`data-tab="${key}" aria-pressed="${key===tab}"`)).join('')}</div>${tab==='users'?`<div class="notice">${icon('shield')}<span>Novas pessoas acessam com o <strong>crachá</strong> e a senha inicial <strong>123456</strong>. A troca é obrigatória no primeiro acesso.</span></div>`:''}<section class="card">${items.length?`<div class="table-scroll"><table class="data-table"><thead><tr>${definition.headers.map(label=>`<th>${label}</th>`).join('')}<th><span class="sr-only">Ações</span></th></tr></thead><tbody>${items.map(item=>`<tr>${definition.row(item)}<td>${button(`${icon('edit')}Editar`,'edit-catalog','btn btn-sm',`data-type="${tab}" data-id="${item.id}"`)}</td></tr>`).join('')}</tbody></table></div>`:empty('Vamos começar por aqui',`Cadastre o primeiro item de ${tabs.find(([key])=>key===tab)[1].toLowerCase()}.`,'layers',button('Cadastrar','new-catalog','btn btn-primary btn-sm',`data-type="${tab}"`))}</section>`;
}
function notifications() {
    const notifications=state.data.notifications || [];
    return `<section class="notifications-panel" aria-label="Notificações"><div class="notifications-head"><h2>Suas notificações</h2>${button(icon('close'),'notifications','icon-button','aria-label="Fechar notificações"')}</div><div class="notification-list">${notifications.length?notifications.map(item=>`<article class="notification-item ${item.read_at?'':'unread'}">${icon('bell')}<div><h3>${escape(item.title)}</h3><p>${escape(item.body)}</p><time>${dateTime(item.created_at)}</time></div></article>`).join(''):'<p class="notification-empty">Nenhuma novidade por enquanto.</p>'}</div>${notifications.length?`<div class="notifications-foot">${button(`${icon('check')}Marcar todas como lidas`,'read-notifications','btn btn-ghost btn-sm')}</div>`:''}</section>`;
}
async function initMap() {
    const container=$('#fleet-map');if(!container)return;
    const trips=(state.data.trips || []).filter(item=>item.latitude!==null&&item.longitude!==null&&Number.isFinite(Number(item.latitude))&&Number.isFinite(Number(item.longitude)));
    if(!window.L) {container.innerHTML='<div class="map-fallback">O mapa está indisponível. Os destinos e horários continuam disponíveis na lista de rotas.</div>';return;}
    container.innerHTML='';state.map=L.map(container,{scrollWheelZoom:false,attributionControl:false,minZoom:5,maxZoom:13}).setView([-24.5,-51.5],6);
    L.control.attribution({prefix:false,position:'bottomright'}).addAttribution('<a href="https://www.ibge.gov.br/geociencias/organizacao-do-territorio/malhas-territoriais.html" target="_blank" rel="noopener noreferrer">Base cartográfica: IBGE</a>').addTo(state.map);
    let boundaryBounds=null;
    try {
        const [mapResponse,citiesResponse]=await Promise.all([
            fetch(`${config.baseUrl}assets/vendor/parana.geojson`,{cache:'force-cache'}),
            fetch(`${config.baseUrl}assets/vendor/parana-municipios.json`,{cache:'force-cache'})
        ]);
        if(!mapResponse.ok||!citiesResponse.ok)throw new Error('Base cartográfica indisponível');
        const [geography,cities]=await Promise.all([mapResponse.json(),citiesResponse.json()]);
        const cityNames=new Map(cities.map(city=>[String(city.id),city.nome]));
        const majorCities=new Set(['Curitiba','Londrina','Maringá','Ponta Grossa','Cascavel','Foz do Iguaçu','Guarapuava','Paranaguá']);
        const boundary=L.geoJSON(geography,{interactive:false,style:{color:'#78a9cf',weight:1,opacity:.72,fillColor:'#dceffc',fillOpacity:.55},onEachFeature:(feature,layer)=>{
            const name=cityNames.get(String(feature.properties?.codarea));
            if(name)layer.bindTooltip(escape(name),{permanent:true,direction:'center',className:`municipality-label${majorCities.has(name)?' major':''}`,opacity:1});
        }}).addTo(state.map);
        boundaryBounds=boundary.getBounds();
        const updateLabels=()=>container.classList.toggle('map-show-city-labels',state.map?.getZoom()>=9);
        state.map.on('zoomend',updateLabels);updateLabels();
    } catch (_) {
        container.classList.add('map-without-boundary');
    }
    const points=[],labeledCities=new Set();
    trips.forEach((trip,index)=>{const point=[Number(trip.latitude),Number(trip.longitude)],cityKey=String(trip.city).toLocaleLowerCase('pt-BR');points.push(point);const marker=L.marker(point,{icon:L.divIcon({html:`<div class="map-pin"><span>${index+1}</span></div>`,className:'',iconSize:[31,31],iconAnchor:[15,31]}),title:`${trip.city} · ${trip.instructor_name}`}).addTo(state.map).bindPopup(`<div class="map-popup"><strong>${escape(trip.city)}</strong><p>${escape(trip.destination)}</p><p>${escape(trip.instructor_name)}<br>${escape(trip.vehicle_name)} · ${escape(trip.vehicle_plate)}</p><p>${dateTime(trip.starts_at)}<br>até ${dateTime(trip.ends_at)}</p><a href="https://www.openstreetmap.org/?mlat=${point[0]}&mlon=${point[1]}#map=14/${point[0]}/${point[1]}" target="_blank" rel="noopener noreferrer">Abrir localização ${icon('external')}</a></div>`);if(!labeledCities.has(cityKey)){marker.bindTooltip(escape(trip.city),{permanent:true,direction:'right',offset:[12,-16],className:'route-city-label',opacity:1});labeledCities.add(cityKey);}});
    if(points.length)state.map.fitBounds(points,{padding:[45,45],maxZoom:9});
    else if(boundaryBounds?.isValid())state.map.fitBounds(boundaryBounds,{padding:[18,18]});
}

function field(name,label,value='',type='text',extra='',full=false) {return `<div class="form-field ${full?'full':''}"><label for="field-${name}">${label}</label><input id="field-${name}" name="${name}" type="${type}" value="${escape(value)}" ${extra}></div>`;}
function selectField(name,label,items,selected='',itemLabel=item=>item.name,extra='',full=false) {return `<div class="form-field ${full?'full':''}"><label for="field-${name}">${label}</label><select id="field-${name}" name="${name}" ${extra}>${opts(items,selected,itemLabel)}</select></div>`;}
function textarea(name,label,value='',extra='',full=true) {return `<div class="form-field ${full?'full':''}"><label for="field-${name}">${label}</label><textarea id="field-${name}" name="${name}" ${extra}>${escape(value)}</textarea></div>`;}
function showDialog(title,subtitle,body,footer='',submit=null) {
    focusedBeforeDialog=document.activeElement;
    $('#dialog-content').innerHTML=`<form id="dialog-form"><header class="dialog-header"><div><h2 id="dialog-title">${escape(title)}</h2>${subtitle?`<p>${escape(subtitle)}</p>`:''}</div>${button(icon('close'),'close-dialog','icon-button','aria-label="Fechar janela"')}</header><div class="dialog-body"><div id="form-error" class="form-error" role="alert" tabindex="-1"></div>${body}</div>${footer?`<footer class="dialog-footer">${footer}</footer>`:''}</form>`;
    dialogSubmit=submit;
    if(!dialog.open)dialog.showModal();
    const autofocus=$('[autofocus]',dialog);if(autofocus)autofocus.focus();
}
function closeDialog() {dialog.close();dialogSubmit=null;if(focusedBeforeDialog?.isConnected)focusedBeforeDialog.focus();}
const saveFooter = (label='Salvar',remove='') => `${remove}${button('Cancelar','close-dialog','btn')}<button class="btn btn-primary" type="submit">${icon('check')}${label}</button>`;
async function mutate(path,method,data,message) {await api(path,method,data);closeDialog();toast(message);await load(true);}

function lessonDialog(id=null,seed={}) {
    const lesson=id?allLessons().find(item=>String(item.id)===String(id)):seed;if(!lesson)return;
    const classes=state.data.classes || [],users=(state.data.users || []).filter(item=>item.role==='instructor'&&Number(item.active)!==0);
    const day=state.month===today.slice(0,7)?today:`${state.month}-01`;
    const body=`<div class="form-grid">${selectField('class_id','Turma',classes,lesson.class_id,item=>`${item.code} · ${item.name}`,'required autofocus',true)}${selectField('instructor_id','Instrutor(a)',users,lesson.instructor_id,item=>item.name,'required',true)}${field('starts_at','Início',inputTime(lesson.starts_at||`${day} 08:00`),'datetime-local','required')}${field('ends_at','Término',inputTime(lesson.ends_at||`${day} 12:00`),'datetime-local','required')}${selectField('room_id','Sala <small>ou informe o local externo abaixo</small>',state.data.rooms || [],lesson.room_id,item=>item.name,'',true)}${field('location','Local externo <small>opcional quando houver sala</small>',lesson.location,'text','maxlength="180"',true)}${field('city','Cidade',lesson.city||'','text','maxlength="100"',true)}${textarea('content','Conteúdo previsto <small>o instrutor também pode preencher</small>',lesson.content,'maxlength="6000"')}</div><p class="form-note">${icon('info')}A turma, o instrutor e a sala não podem ter horários sobrepostos. Ao salvar, os instrutores envolvidos serão notificados.</p>`;
    showDialog(id?'Editar aula':'Vamos agendar uma aula',id?'Atualize a programação. Os envolvidos serão informados.':'Escolha a turma, o instrutor e o melhor horário.',body,saveFooter(id?'Salvar alteração':'Agendar aula',id?button(icon('trash'),'delete-lesson','btn btn-danger',`data-id="${id}" aria-label="Excluir aula"`):''),async data=>{['class_id','instructor_id'].forEach(key=>data[key]=Number(data[key]));data.room_id=data.room_id?Number(data.room_id):null;await mutate(id?`api/lessons/${id}`:'api/lessons',id?'PUT':'POST',data,id?'Aula atualizada. A equipe já pode ver a alteração.':'Aula agendada com sucesso.');});
    $('#field-class_id')?.addEventListener('change',event=>{const selected=classes.find(item=>String(item.id)===event.target.value);if(selected&&!$('#field-city').value)$('#field-city').value=selected.city||'';});
}
function lessonDetail(id) {
    const lesson=allLessons().find(item=>String(item.id)===String(id));if(!lesson)return;
    const canContent=!student&&(managed||Number(lesson.instructor_id)===Number(config.user.id));
    const detail=(symbol,label,value)=>`<div class="detail-item">${icon(symbol)}<div><label>${label}</label><strong>${escape(value)}</strong></div></div>`;
    const body=`<div class="detail-course" style="--course-color:${color(lesson.course_color)}"><h3>${escape(lesson.course_name)}</h3><p>${escape(lesson.class_code)} · ${escape(lesson.class_name)}</p></div><div class="detail-grid">${detail('calendar','Data',fullDate(lesson.starts_at))}${detail('clock','Horário',timeRange(lesson))}${detail('people','Instrutor(a)',lesson.instructor_name)}${detail('location','Local',lesson.room_name||lesson.location||'A definir')}${lesson.city?detail('building','Cidade',lesson.city):''}${lesson.ends_at.slice(0,10)!==lesson.starts_at.slice(0,10)?detail('calendar','Término',dateTime(lesson.ends_at)):''}</div><div class="detail-content">${canContent?textarea('content','O que será ensinado nesta aula?',lesson.content,'maxlength="6000" placeholder="Descreva os temas, as atividades e os materiais para este encontro…"'):`<h3>Conteúdo do encontro</h3><p>${escape(lesson.content||'O conteúdo será compartilhado pelo instrutor.')}</p>`}</div>${canContent?`<p class="form-note">${icon('book')}O conteúdo fica disponível na programação da turma.</p>`:''}`;
    const footer=`${managed?button(`${icon('edit')}Editar aula`,'edit-lesson','btn',`data-id="${lesson.id}"`):''}${button('Fechar','close-dialog','btn')}${canContent?'<button type="submit" class="btn btn-primary">Salvar conteúdo</button>':''}`;
    showDialog('Detalhes da aula','Um encontro, todas as informações.',body,footer,canContent?async data=>mutate(`api/lessons/${lesson.id}/content`,'PATCH',{content:data.content},'Conteúdo da aula atualizado.'):null);
}
function outsideDetail(id) {
    const item=(state.data.outside_hours || []).find(row=>String(row.id)===String(id));if(!item)return;
    const detail=(symbol,label,value)=>`<div class="detail-item">${icon(symbol)}<div><label>${label}</label><strong>${escape(value)}</strong></div></div>`;
    const body=`<div class="detail-course outside-detail"><h3>Horas fora de sala</h3><p>Atividade aprovada pelo gerente</p></div><div class="detail-grid">${detail('calendar','Data',fullDate(item.work_date))}${detail('clock','Carga horária',`${fmtHours(item.hours)} horas`)}${detail('people','Colaborador(a)',item.user_name)}${detail('checkCircle','Status','Aprovado')}${item.reviewer_name?detail('shield','Aprovado por',item.reviewer_name):''}${item.reviewed_at?detail('calendar','Decisão',dateTime(item.reviewed_at)):''}</div><div class="detail-content"><h3>Justificativa</h3><p>${escape(item.reason)}</p>${item.manager_note?`<div class="manager-note"><strong>Parecer do gerente:</strong> ${escape(item.manager_note)}</div>`:''}</div>`;
    showDialog('Horas fora de sala','Esta atividade já faz parte da carga horária do ciclo.',body,button('Fechar','close-dialog','btn'));
}
function hoursDialog() {
    const day=state.month===today.slice(0,7)?today:`${state.month}-01`;
    showDialog('Solicitar horas fora de sala','Conte ao gerente o que você vai realizar e quanto tempo precisa.',`<div class="form-grid">${field('work_date','Data da atividade',day,'date','required autofocus')}${field('hours','Quantidade de horas','','number','required min="0.25" max="24" step="0.25" placeholder="Ex.: 4"')}${textarea('reason','Por que você precisa vir à empresa?','','required minlength="10" maxlength="2000" placeholder="Ex.: planejamento das aulas, reunião pedagógica ou preparação de materiais…"')}</div><p class="form-note">${icon('info')}Seu pedido ficará em andamento até a decisão do gerente. As horas só serão somadas depois da aprovação.</p>`,saveFooter('Enviar solicitação'),async data=>{data.hours=Number(data.hours);await mutate('api/hours','POST',data,'Solicitação enviada ao gerente.');});
}
function reviewHours(id,status) {
    const request=(state.data.requests || []).find(item=>String(item.id)===String(id));if(!request)return;
    const approve=status==='approved';
    showDialog(approve?'Aprovar solicitação':'Não aprovar solicitação',`${request.user_name} · ${fmtHours(request.hours)}h · ${shortDate(request.work_date)}`,`<p class="confirm-description">${escape(request.reason)}</p><div class="form-grid mt-16">${textarea('manager_note',`Retorno para o solicitante ${approve?'<small>opcional</small>':''}`,'',`${approve?'':'required minlength="3"'} maxlength="2000" placeholder="Explique sua decisão ou deixe uma orientação."`)}</div>${approve?`<p class="form-note">${icon('info')}As ${fmtHours(request.hours)}h serão contabilizadas imediatamente no mês da atividade.</p>`:''}`,saveFooter(approve?'Confirmar aprovação':'Confirmar decisão'),async data=>mutate(`api/hours/${id}/review`,'PATCH',{status,manager_note:data.manager_note},approve?'Solicitação aprovada. Horas atualizadas.':'Decisão registrada e solicitante notificado.'));
}
function tripDialog(id=null) {
    const trip=id?(state.data.trips || []).find(item=>String(item.id)===String(id)):{};if(!trip)return;
    const day=state.month===today.slice(0,7)?today:`${state.month}-01`,users=(state.data.users || []).filter(item=>item.role==='instructor'&&Number(item.active)!==0);
    showDialog(id?'Editar deslocamento':'Programar um deslocamento','Vincule o veículo, o instrutor e o destino do curso externo.',`<div class="form-grid">${selectField('vehicle_id','Veículo',state.data.vehicles || [],trip.vehicle_id,item=>`${item.name} · ${item.plate}`,'required autofocus',true)}${selectField('instructor_id','Instrutor(a)',users,trip.instructor_id,item=>item.name,'required',true)}${selectField('lesson_id','Aula vinculada <small>opcional</small>',state.data.lessons || [],trip.lesson_id,item=>`${shortDate(item.starts_at)} ${time(item.starts_at)} · ${item.course_name}`,'',true)}${field('city','Cidade de destino',trip.city,'text','required maxlength="100"',true)}${field('destination','Local / endereço',trip.destination,'text','required maxlength="255"',true)}${field('starts_at','Saída',inputTime(trip.starts_at||`${day} 07:00`),'datetime-local','required')}${field('ends_at','Retorno',inputTime(trip.ends_at||`${day} 18:00`),'datetime-local','required')}${field('latitude','Latitude',trip.latitude,'number','required min="-90" max="90" step="any" placeholder="Ex.: -25.4284"')}${field('longitude','Longitude',trip.longitude,'number','required min="-180" max="180" step="any" placeholder="Ex.: -49.2733"')}${textarea('notes','Observações <small>opcional</small>',trip.notes,'maxlength="2000"')}</div><p class="form-note">${icon('location')}As coordenadas identificam o destino no mapa. Não há rastreamento do veículo. O sistema verifica conflitos de viagem.</p>`,saveFooter(id?'Salvar alterações':'Programar viagem',id?button(icon('trash'),'delete-trip','btn btn-danger',`data-id="${id}" aria-label="Excluir viagem"`):''),async data=>{['vehicle_id','instructor_id','latitude','longitude'].forEach(key=>data[key]=Number(data[key]));data.lesson_id=data.lesson_id?Number(data.lesson_id):null;await mutate(id?`api/trips/${id}`:'api/trips',id?'PUT':'POST',data,id?'Deslocamento atualizado.':'Deslocamento programado.');});
    $('#field-lesson_id')?.addEventListener('change',event=>{const lesson=allLessons().find(item=>String(item.id)===event.target.value);if(!lesson)return;$('#field-instructor_id').value=String(lesson.instructor_id);if(lesson.city)$('#field-city').value=lesson.city;if(lesson.location)$('#field-destination').value=lesson.location;if(lesson.latitude)$('#field-latitude').value=lesson.latitude;if(lesson.longitude)$('#field-longitude').value=lesson.longitude;});
}
function reservationDialog() {
    const day=state.month===today.slice(0,7)?today:`${state.month}-01`;
    showDialog('Reservar um recurso','Garanta o espaço e os equipamentos para a sua atividade.',`<div class="form-grid">${selectField('resource_type','O que você precisa?', [{id:'room',name:'Sala de aula'},{id:'tablet',name:'Tablets'}],'room',item=>item.name,'required autofocus',true)}${selectField('resource_id','Recurso',state.data.rooms || [],'',item=>item.name,'required',true)}${field('quantity','Quantidade',1,'number','required min="1" max="1" step="1"')}${field('starts_at','Início',`${day}T08:00`,'datetime-local','required',true)}${field('ends_at','Término',`${day}T12:00`,'datetime-local','required',true)}${textarea('purpose','Finalidade da reserva','','required minlength="3" maxlength="1000" placeholder="Ex.: oficina de práticas, avaliação ou aula de informática."')}</div><p class="form-note">${icon('info')}Salas são reservadas individualmente. Para tablets, informe quantos equipamentos serão usados.</p>`,saveFooter('Confirmar reserva'),async data=>{data.resource_id=Number(data.resource_id);data.quantity=Number(data.quantity);await mutate('api/reservations','POST',data,'Recurso reservado com sucesso.');});
    $('#field-resource_type').addEventListener('change',event=>{const isTablet=event.target.value==='tablet';$('#field-resource_id').innerHTML=opts(isTablet?state.data.tablet_pools:state.data.rooms,'',item=>isTablet?`${item.name} · ${item.quantity} disponíveis no total`:item.name);$('#field-quantity').value=1;$('#field-quantity').max=isTablet?'10000':'1';});
}
function catalogDialog(type,id=null) {
    const item=id?(state.data[type] || []).find(row=>String(row.id)===String(id)):{};if(!item)return;
    const names={courses:'curso',classes:'turma',rooms:'sala',tablet_pools:'conjunto de tablets',vehicles:'veículo',users:'pessoa'};
    let fields='';
    if(type==='courses')fields=field('name','Nome do curso',item.name,'text','required maxlength="150" autofocus',true)+field('color','Cor na agenda',item.color||'#448c80','color','required',true);
    if(type==='classes')fields=field('code','Código da turma',item.code,'text','required maxlength="50" autofocus')+field('name','Nome da turma',item.name,'text','required maxlength="150"')+selectField('course_id','Curso',state.data.courses || [],item.course_id,row=>row.name,'required',true)+field('city','Cidade',item.city,'text','required maxlength="100"',true);
    if(type==='rooms')fields=field('name','Nome da sala',item.name,'text','required maxlength="100" autofocus',true)+field('capacity','Capacidade de pessoas',item.capacity||30,'number','required min="1" max="10000" step="1"',true);
    if(type==='tablet_pools')fields=field('name','Nome do conjunto',item.name,'text','required maxlength="100" autofocus',true)+field('quantity','Quantidade de tablets',item.quantity||10,'number','required min="1" max="10000" step="1"',true);
    if(type==='vehicles')fields=field('name','Identificação do veículo',item.name,'text','required maxlength="100" autofocus',true)+field('plate','Placa',item.plate,'text','required maxlength="10" placeholder="ABC1D23"')+field('seats','Quantidade de lugares',item.seats||5,'number','required min="1" max="100" step="1"');
    if(type==='users')fields=field('name','Nome completo',item.name,'text','required maxlength="150" autofocus',true)+field('badge','Número do crachá',item.badge,'text','required maxlength="30" inputmode="numeric"')+selectField('role','Perfil de acesso',Object.entries(roleLabels).map(([id,name])=>({id,name})),item.role||'instructor',row=>row.name,'required')+field('email','E-mail para avisos',item.email,'email','required maxlength="254"',true)+(id?`<div class="form-field full"><label class="checkbox-label"><input type="checkbox" name="active" value="1" ${Number(item.active)!==0?'checked':''}>Acesso ativo</label></div><div class="form-field full"><label class="checkbox-label"><input type="checkbox" name="reset_password" value="1">Redefinir a senha para 123456 e exigir nova troca</label><p class="form-hint">A redefinição revoga os acessos lembrados desta pessoa.</p></div>`:'');
    showDialog(`${id?'Editar':'Cadastrar'} ${names[type]}`,'Mantenha as informações organizadas e atualizadas.',`<div class="form-grid">${fields}</div>${type==='users'&&!id?`<p class="form-note">${icon('shield')}Senha inicial: 123456. A pessoa precisará definir uma nova senha ao entrar.</p>`:''}`,saveFooter('Salvar cadastro'),async data=>{['course_id','capacity','quantity','seats'].forEach(key=>{if(key in data)data[key]=Number(data[key]);});if(type==='users'){data.active=id?data.active==='1':true;data.reset_password=data.reset_password==='1';}await mutate(type==='users'?(id?`api/users/${id}`:'api/users'):(id?`api/catalog/${type}/${id}`:`api/catalog/${type}`),id?'PUT':'POST',data,'Cadastro salvo com sucesso.');});
}
function confirmDelete(type,id) {
    const labels={lesson:['Excluir esta aula?','A aula será removida da agenda e os instrutores envolvidos serão avisados.','api/lessons','Aula excluída.'],trip:['Excluir este deslocamento?','O veículo e o instrutor ficarão livres neste período de viagem.','api/trips','Deslocamento excluído.'],reservation:['Cancelar esta reserva?','O recurso ficará disponível para outras atividades neste período.','api/reservations','Reserva cancelada.']};
    const [title,description,path,message]=labels[type];
    showDialog(title,'Esta ação não pode ser desfeita.',`<p class="confirm-description">${description}</p>`,`${button('Manter','close-dialog','btn')}<button class="btn btn-danger" type="submit">${icon('trash')}Confirmar exclusão</button>`,async()=>mutate(`${path}/${id}`,'DELETE',undefined,message));
}
function bindPage() {
    $('#month-select')?.addEventListener('change',event=>{if(/^\d{4}-\d{2}$/.test(event.target.value)){state.month=event.target.value;state.anchor=`${state.month}-01`;load();}});
    $('#filter-instructor')?.addEventListener('change',event=>{state.instructorId=event.target.value;render();});
    $('#filter-class')?.addEventListener('change',event=>{state.classId=event.target.value;render();});
    $('#filter-course')?.addEventListener('change',event=>{state.courseId=event.target.value;render();});
    $('#student-class')?.addEventListener('change',event=>{state.classId=event.target.value;try{localStorage.setItem(`pac-agenda.class.${config.user.id}`,state.classId);}catch(_){}load();});
    $('#agenda-search')?.addEventListener('input',event=>{state.query=event.target.value;clearTimeout(loadTimer);loadTimer=setTimeout(()=>{const position=event.target.selectionStart;render();const input=$('#agenda-search');if(input){input.focus();try{input.setSelectionRange(position,position);}catch(_){}}},240);});
}
function changeMonth(amount) {const date=dt(`${state.month}-01`);date.setMonth(date.getMonth()+amount);state.month=ymd(date).slice(0,7);state.anchor=`${state.month}-01`;load();}
async function navigate(page) {if(!allowedPages.includes(page))return;state.page=page;state.notificationsOpen=false;location.hash=page;window.scrollTo({top:0,behavior:'instant'});if(page==='agenda'&&state.view==='week')await load();else render();}
async function action(event) {
    const target=event.target.closest('[data-action]');if(!target)return;
    const name=target.dataset.action,id=target.dataset.id;
    try {
        switch(name) {
        case 'retry':await load();break;
        case 'navigate':await navigate(target.dataset.page);break;
        case 'menu':$('#sidebar')?.classList.add('open');target.setAttribute('aria-expanded','true');break;
        case 'close-menu':$('#sidebar')?.classList.remove('open');$('[data-action="menu"]')?.setAttribute('aria-expanded','false');break;
        case 'notifications':state.notificationsOpen=!state.notificationsOpen;render();break;
        case 'read-notifications':await api('api/notifications/read','POST',{});await load(true);break;
        case 'prev-month':changeMonth(-1);break;
        case 'next-month':changeMonth(1);break;
        case 'calendar-view':state.view=target.dataset.view;if(state.view==='week')await load();else render();break;
        case 'calendar-today':state.month=today.slice(0,7);state.anchor=today;await load();if(state.view==='list')$(`#day-${today}`)?.scrollIntoView({block:'center',behavior:'smooth'});break;
        case 'prev-week':case 'next-week':state.anchor=addDays(state.anchor,name==='prev-week'?-7:7);state.month=state.anchor.slice(0,7);await load();break;
        case 'day-list':state.view='list';state.anchor=target.dataset.date;if(state.month!==state.anchor.slice(0,7)){state.month=state.anchor.slice(0,7);await load();}else render();$(`#day-${state.anchor}`)?.scrollIntoView({block:'center',behavior:'smooth'});break;
        case 'hours-filter':state.hoursFilter=target.dataset.filter;render();break;
        case 'catalog-tab':state.catalogTab=target.dataset.tab;render();break;
        case 'select-instructor':state.selectedInstructorId=String(id);render();break;
        case 'select-course':state.selectedCourseId=String(id);render();break;
        case 'open-instructor-agenda':state.instructorId=String(id);state.classId='';state.courseId='';await navigate('agenda');break;
        case 'instructor-new-lesson':lessonDialog(null,{instructor_id:Number(id)});break;
        case 'course-new-lesson':{
            const firstClass=(state.data.classes||[]).find(item=>Number(item.course_id)===Number(id));
            if(firstClass)lessonDialog(null,{class_id:Number(firstClass.id)});else toast('Cadastre uma turma para este curso antes de agendar a aula.',true);
            break;
        }
        case 'course-class-new-lesson':lessonDialog(null,{class_id:Number(id)});break;
        case 'new-lesson':lessonDialog();break;
        case 'edit-lesson':lessonDialog(id);break;
        case 'lesson-detail':lessonDetail(id);break;
        case 'outside-detail':outsideDetail(id);break;
        case 'delete-lesson':confirmDelete('lesson',id);break;
        case 'new-hours':hoursDialog();break;
        case 'review-hours':reviewHours(id,target.dataset.status);break;
        case 'new-trip':tripDialog();break;
        case 'edit-trip':tripDialog(id);break;
        case 'delete-trip':confirmDelete('trip',id);break;
        case 'new-reservation':reservationDialog();break;
        case 'delete-reservation':confirmDelete('reservation',id);break;
        case 'new-catalog':target.closest('details')?.removeAttribute('open');catalogDialog(target.dataset.type);break;
        case 'edit-catalog':catalogDialog(target.dataset.type,id);break;
        case 'close-dialog':closeDialog();break;
        case 'logout':{
            await fetch(`${config.baseUrl}logout`, {method:'POST',headers:{[config.csrfHeader || 'X-CSRF-TOKEN']:config.csrfToken},credentials:'same-origin'});
            location.href=`${config.baseUrl}login`;
            break;
        }
        }
    } catch(error) {toast(error.message,true);}
}
document.addEventListener('click',action);
dialog.addEventListener('click',event=>{if(event.target===dialog){const bounds=dialog.getBoundingClientRect();if(event.clientX<bounds.left||event.clientX>bounds.right||event.clientY<bounds.top||event.clientY>bounds.bottom)closeDialog();}});
dialog.addEventListener('cancel',()=>{dialogSubmit=null;});
dialog.addEventListener('submit',async event=>{
    event.preventDefault();if(!dialogSubmit)return;
    const form=event.target;if(!form.reportValidity())return;
    const submit=form.querySelector('[type="submit"]');const original=submit?.innerHTML;
    const data=Object.fromEntries(new FormData(form));const errorBox=$('#form-error');errorBox.classList.remove('visible');
    if(submit){submit.disabled=true;submit.innerHTML='Salvando…';}
    try {await dialogSubmit(data);} catch(error){errorBox.textContent=error.message;errorBox.classList.add('visible');errorBox.focus();}
    finally {if(submit?.isConnected){submit.disabled=false;submit.innerHTML=original;}}
});
document.addEventListener('keydown',event=>{if(event.key==='Escape'){state.notificationsOpen=false;$('.notifications-panel')?.remove();$('#sidebar')?.classList.remove('open');}});
document.addEventListener('click',event=>{if(state.notificationsOpen&&!event.target.closest('.notifications-panel')&&!event.target.closest('[data-action="notifications"]')){state.notificationsOpen=false;$('.notifications-panel')?.remove();}});
document.addEventListener('click',event=>{if(!event.target.closest('.catalog-create'))document.querySelectorAll('.catalog-create[open]').forEach(menu=>menu.removeAttribute('open'));});
function updateSync(){const element=$('#sync-state');if(!element)return;element.classList.toggle('offline',!state.online);element.title=state.online?'A agenda verifica atualizações a cada 2 segundos':'Sem conexão. As alterações não serão salvas até a conexão voltar.';const span=element.querySelector('span');if(span)span.textContent=state.online?'Agenda atualizada':'Reconectando…';}
async function poll(){
    if(pollBusy||state.loading||document.hidden||!state.data)return;pollBusy=true;
    try {const update=await api('api/updates');state.online=true;const changed=Number(update.version)!==Number(state.data.version);const unreadChanged=Number(update.unread)!==Number(state.data.unread);if(changed){await load(true);}else if(unreadChanged){state.data.unread=update.unread;state.data.notifications=update.notifications || state.data.notifications;const focused=document.activeElement;if(!focused||!['INPUT','SELECT','TEXTAREA'].includes(focused.tagName))render();}updateSync();} catch(_){state.online=false;updateSync();}finally{pollBusy=false;}
}
window.addEventListener('online',()=>poll());
document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
window.addEventListener('hashchange',()=>{const page=location.hash.slice(1);if(allowedPages.includes(page)&&page!==state.page)navigate(page);});
load();setInterval(poll,2000);
})();
