/* PAC Agenda — interface da plataforma. Sem dados pessoais ou credenciais no armazenamento local. */
(() => {
'use strict';
const config = window.KAIRON;
const $ = (selector, root = document) => root.querySelector(selector);
const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const color = value => /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#458c83';
const icons = {
 home:'house',grid:'table-cells-large',calendar:'calendar-days',clock:'clock',people:'users',book:'book-open',car:'car-side',layers:'layer-group',settings:'gear',bell:'bell',plus:'plus',chevronRight:'chevron-right',chevronLeft:'chevron-left',down:'chevron-down',arrow:'arrow-right',logout:'right-from-bracket',location:'location-dot',building:'building',check:'check',checkCircle:'circle-check',alert:'triangle-exclamation',info:'circle-info',close:'xmark',edit:'pen-to-square',trash:'trash-can',search:'magnifying-glass',menu:'bars',shield:'shield-halved',tablet:'tablet-screen-button',door:'door-open',chart:'chart-column',mail:'envelope',coffee:'mug-hot',refresh:'rotate',external:'arrow-up-right-from-square'
};
const icon = name => `<i class="fa-solid fa-${icons[name] || icons.grid} icon" aria-hidden="true"></i>`;
const roleLabels = {manager:'Gerente',coordinator:'Coordenação pedagógica',instructor:'Instrutor(a)',student:'Aluno(a)'};
const statusLabels = {pending:'Em andamento',approved:'Concluído · aprovado',rejected:'Não aprovado'};
const today = new Intl.DateTimeFormat('en-CA', {timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const managed = ['manager','coordinator'].includes(config.user.role);
const manager = config.user.role === 'manager';
const student = config.user.role === 'student';
const instructor = config.user.role === 'instructor';
const allowedPages = student ? ['agenda'] : managed ? ['dashboard','agenda','hours','fleet','reservations','catalog'] : ['agenda','dashboard','hours','reservations'];
const currentHash = location.hash.replace('#','');
const state = {page:allowedPages.includes(currentHash) ? currentHash : managed ? 'dashboard' : 'agenda',month:today.slice(0,7),anchor:today,view:innerWidth < 650 ? 'list' : 'week',data:null,extraLessons:[],classId:'',instructorId:'',courseId:'',query:'',hoursFilter:'all',catalogTab:'courses',notificationsOpen:false,loading:false,map:null,sequence:0,online:true};
const labels = {dashboard:managed ? 'Visão geral' : 'Minhas horas',agenda:student ? 'Agenda da turma' : instructor ? 'Minha agenda' : 'Agenda geral',hours:'Horas fora de sala',fleet:'Frota e deslocamentos',reservations:'Reservas',catalog:'Cadastros'};
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
    const entries=[['dashboard','grid'],['agenda','calendar'],['hours','clock'],['fleet','car'],['reservations','layers'],['catalog','people']].filter(([page])=>allowedPages.includes(page));
    const pending=(state.data.requests || []).filter(item=>item.status==='pending').length;
    return `<aside class="sidebar" id="sidebar" aria-label="Menu principal"><div class="brand"><span class="brand-mark">${icon('calendar')}</span><div><div class="brand-name">PAC Agenda</div><div class="brand-caption">Agendamento para colaboradores</div></div></div><div class="workspace-tag">${icon('building')}<div><strong>Planejamento integrado</strong><small>Aulas, equipes e recursos</small></div></div><div class="nav-label">Seu espaço</div><nav class="nav-group">${entries.map(([page,symbol])=>`<button class="nav-link ${state.page===page?'active':''}" data-action="navigate" data-page="${page}" ${state.page===page?'aria-current="page"':''} title="${labels[page]}">${icon(symbol)}<span>${labels[page]}</span>${page==='hours'&&pending?`<span class="nav-count">${pending}</span>`:''}</button>`).join('')}</nav><div class="sidebar-bottom"><div class="help-note">${icon('coffee')}<strong>Mais tempo para ensinar.</strong>Seu planejamento, suas aulas e sua equipe no mesmo lugar.</div><div class="sidebar-user">${avatar(config.user.name)}<div class="user-details"><strong>${escape(config.user.name)}</strong><small>${escape(roleLabels[config.user.role])}</small></div><form class="logout-form" method="post" action="${escape(config.baseUrl)}logout"><input type="hidden" name="${escape(config.csrfName)}" value="${escape(config.csrfToken)}"><button class="icon-button" type="button" data-action="logout" title="Sair da conta" aria-label="Sair da conta">${icon('logout')}</button></form></div></div></aside><button class="mobile-scrim" data-action="close-menu" aria-label="Fechar menu"></button>`;
}
function heading(title,subtitle,actions='') {
    return `<div class="page-heading"><div><div class="eyebrow">${managed?'Planejamento pedagógico':student?'Seu próximo passo':'Seu tempo, bem organizado'}</div><h1>${escape(title)}</h1><p>${escape(subtitle)}</p></div><div class="heading-actions">${monthPicker()}${actions}</div></div>`;
}
function monthPicker() {return `<div class="month-picker">${button(icon('chevronLeft'),'prev-month','icon-button','aria-label="Mês anterior"')}<label class="sr-only" for="month-select">Mês de referência</label><input type="month" id="month-select" value="${state.month}" aria-label="Mês de referência">${button(icon('chevronRight'),'next-month','icon-button','aria-label="Próximo mês"')}</div>`;}
function render() {
    if(!state.data)return;
    const scroll=window.scrollY;
    if(state.map) {state.map.remove();state.map=null;}
    const pageContent={dashboard:dashboard,agenda:agenda,hours:hoursPage,fleet:fleetPage,reservations:reservationsPage,catalog:catalogPage}[state.page]();
    root.innerHTML=`<div class="app-shell">${sidebar()}<header class="topbar"><div class="inline">${button(icon('menu'),'menu','icon-button mobile-toggle','aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"')}<div class="breadcrumbs">${icon('home')}<span>Meu espaço</span>${icon('chevronRight')}<strong>${escape(labels[state.page])}</strong></div></div><div class="topbar-right"><div class="sync-state" id="sync-state" title="A agenda verifica atualizações a cada 2 segundos"><i class="sync-dot"></i><span>Agenda atualizada</span></div><div class="topbar-divider"></div><button class="icon-button" data-action="notifications" aria-label="Notificações${state.data.unread?`, ${state.data.unread} não lidas`:''}" aria-expanded="${state.notificationsOpen}">${icon('bell')}${state.data.unread?'<span class="notification-dot"></span>':''}</button><span class="avatar top-avatar" title="${escape(config.user.name)}">${escape(initials(config.user.name))}</span></div></header><main id="main" class="main" tabindex="-1">${pageContent}<footer class="page-footer"><span>PAC Agenda · Plataforma de Agendamento para Colaboradores.</span><span>${icon('shield')}Acesso de ${escape(roleLabels[config.user.role].toLowerCase())} · ${escape(monthLabel(state.month))}</span></footer></main>${state.notificationsOpen?notifications():''}</div>`;
    bindPage();
    if(state.page==='fleet') requestAnimationFrame(initMap);
    window.scrollTo({top:scroll,behavior:'instant'});
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
    `<div class="dashboard-grid"><section class="card"><div class="card-head"><div><h2>${managed?'Distribuição de horas por instrutor':'Minha carga horária'}</h2><p>Programação em sala e horas aprovadas fora de sala</p></div><span class="badge">Mensal</span></div><div class="chart-legend"><span class="legend-item"><i class="legend-square"></i>Em sala · programadas</span><span class="legend-item"><i class="legend-line"></i>Fora de sala · aprovadas</span></div>${hoursChart(summary)}</section><section class="card alerts-card"><div class="card-head"><div><h2>Atenção às horas <span class="count-pill">${alerts.length}</span></h2><p>Acompanhamento do limite mensal</p></div>${icon('alert')}</div>${alerts.length?`<div class="alert-list">${alerts.map(item=>`<div class="alert-item ${item.level}">${icon('alert')}<div class="alert-text"><strong>${escape(item.name)}</strong><p>${item.level==='danger'?'Limite mensal de 50h atingido':`Faltam ${fmtHours(50-Number(item.outside_hours))}h para o limite`}</p></div><div class="alert-hours">${fmtHours(item.outside_hours)}<small> h</small></div></div>`).join('')}</div>`:empty('Tudo dentro do limite','Nenhum instrutor com 40 horas ou mais fora de sala neste mês.','checkCircle')}<p class="info-note">Amarelo a partir de 40h. Vermelho a partir de 50h. Apenas solicitações aprovadas entram na soma.</p></section></div><div class="dashboard-grid section-gap"><section class="card"><div class="card-head"><div><h2>${todayLessons.length?'Aulas de hoje':'Próximas aulas'} <span class="count-pill">${shown.length}</span></h2><p>${todayLessons.length?fullDate(today):'O que vem pela frente no mês selecionado'}</p></div>${button(`Ver agenda ${icon('arrow')}`,'navigate','btn btn-ghost btn-sm','data-page="agenda"')}</div>${shown.length?`<div class="today-list">${shown.map(lesson=>lessonRow(lesson,!todayLessons.length)).join('')}</div>`:empty('Agenda livre por aqui','As aulas cadastradas para o mês aparecerão neste espaço.','calendar',managed?button(`${icon('plus')}Agendar aula`,'new-lesson','btn btn-primary btn-sm'):'')}</section><section class="card"><div class="card-head"><div><h2>Solicitações em andamento <span class="count-pill">${pending.length}</span></h2><p>${manager?'Pedidos que precisam do seu olhar':'Acompanhe os pedidos de horas'}</p></div></div>${pending.length?`<div class="requests-preview">${pending.slice(0,3).map(item=>`<div class="request-preview">${avatar(item.user_name||config.user.name)}<div><strong>${escape(item.user_name||config.user.name)}</strong><p>${escape(item.reason.length>48?item.reason.slice(0,48)+'…':item.reason)}</p><span class="badge pending">${shortDate(item.work_date)} · Em andamento</span></div><span class="request-amount">${fmtHours(item.hours)}h</span></div>`).join('')}</div><div class="card-footer">${button(`Ver solicitações ${icon('arrow')}`,'navigate','btn btn-ghost','data-page="hours"')}</div>`:empty('Tudo acompanhado','Nenhum pedido aguardando aprovação neste mês.','checkCircle')}</section></div>`;
}
function hoursChart(rows) {
    if(!rows.length)return empty('Ainda sem horas para mostrar','Os instrutores cadastrados e suas horas aparecerão aqui.','chart');
    const width=Math.max(540,rows.length*110),height=248,left=48,right=25,top=30,bottom=57,chartH=height-top-bottom,chartW=width-left-right;
    const maximum=Math.max(60,...rows.map(row=>Math.max(Number(row.class_hours),Number(row.outside_hours))));
    const max=Math.ceil(maximum/20)*20,step=chartW/rows.length,barW=Math.min(40,step*.4);
    const y=value=>top+chartH-(Number(value)/max)*chartH;
    const points=rows.map((row,index)=>`${left+step*(index+.5)},${y(row.outside_hours)}`).join(' ');
    let svg=`<svg class="hours-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Gráfico de barras para horas programadas em sala e linha para horas aprovadas fora de sala. Dados disponíveis na tabela abaixo.">`;
    for(let i=0;i<=4;i++){const value=max*i/4;svg+=`<line x1="${left}" y1="${y(value)}" x2="${width-right}" y2="${y(value)}" stroke="#edf1f4" ${i?'stroke-dasharray="3 4"':''}/><text x="${left-12}" y="${y(value)+3}" text-anchor="end" fill="#97a5ae" font-size="9" font-family="system-ui">${value}h</text>`;}
    svg+=`<line x1="${left}" y1="${y(50)}" x2="${width-right}" y2="${y(50)}" stroke="#d598a0" stroke-dasharray="4 5" opacity=".7"/><text x="${width-right}" y="${y(50)-5}" text-anchor="end" fill="#bd8590" font-size="8" font-family="system-ui">limite externo · 50h</text>`;
    rows.forEach((row,index)=>{const x=left+step*(index+.5);svg+=`<rect x="${x-barW/2}" y="${y(row.class_hours)}" width="${barW}" height="${chartH*(Number(row.class_hours)/max)}" fill="#93beb6" rx="4"><title>${escape(row.name)}: ${fmtHours(row.class_hours)}h em sala</title></rect><text x="${x}" y="${height-29}" text-anchor="middle" fill="#71848e" font-size="9" font-family="system-ui">${escape(row.name.split(' ').slice(0,2).join(' '))}</text>`;});
    svg+=`<polyline points="${points}" fill="none" stroke="#e6a166" stroke-width="2.2" stroke-linejoin="round"/>`;
    rows.forEach((row,index)=>{const x=left+step*(index+.5);svg+=`<circle cx="${x}" cy="${y(row.outside_hours)}" r="4" stroke="white" stroke-width="2" fill="${row.level==='danger'?'#cf6270':'#e6a166'}"><title>${escape(row.name)}: ${fmtHours(row.outside_hours)}h fora de sala</title></circle>`;});
    svg+='</svg>';
    return `<div class="chart-wrap">${svg}</div><details class="chart-accessible"><summary>Ver os números por instrutor</summary><table class="compact-table"><thead><tr><th>Instrutor</th><th>Em sala</th><th>Realizadas</th><th>Fora de sala</th><th>Total</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${escape(row.name)}</td><td>${fmtHours(row.class_hours)}h</td><td>${fmtHours(row.completed_class_hours)}h</td><td>${fmtHours(row.outside_hours)}h</td><td>${fmtHours(row.total_hours)}h</td></tr>`).join('')}</tbody></table></details>`;
}
function lessonRow(lesson,showDate=false) {
    return `<button class="lesson-row" data-action="lesson-detail" data-id="${lesson.id}"><span class="time-col">${showDate?shortDate(lesson.starts_at):time(lesson.starts_at)}<small>${showDate?time(lesson.starts_at):time(lesson.ends_at)}</small></span><span class="course-stripe" style="--course-color:${color(lesson.course_color)}"></span><span class="lesson-info"><span class="lesson-name">${escape(lesson.course_name)}</span><span class="lesson-meta"><span>${icon('people')}${escape(lesson.class_code||lesson.class_name)}</span><span>${icon('location')}${escape(lesson.room_name||lesson.location||lesson.city||'Local a definir')}</span></span></span>${managed?`<span class="lesson-person">${avatar(lesson.instructor_name)}${escape(lesson.instructor_name?.split(' ')[0])}</span>`:''}<span class="lesson-status">${lesson.starts_at.slice(0,10)===today?'Hoje':shortDate(lesson.starts_at)}</span>${icon('chevronRight')}</button>`;
}
function agenda() {
    const classes=state.data.classes || [];
    const courses=state.data.courses || [];
    const users=(state.data.users || []).filter(item=>item.role==='instructor');
    const lessons=filteredLessons();
    const days=weekDays();
    const weekTitle=`${shortDate(days[0])} a ${shortDate(days[6])}`;
    const selectedClass=classes.find(item=>String(item.id)===state.classId);
    const studentSelector=student?`<section class="student-selector"><div><h2>Qual é a sua turma?</h2><p>Escolha sua turma para ver cursos, horários e locais de aula.</p></div><label class="sr-only" for="student-class">Sua turma</label><select id="student-class">${opts(classes,state.classId,item=>`${item.code} · ${item.name}`,'Selecione sua turma')}</select></section>`:'';
    return heading(labels.agenda,student?'Tudo o que você precisa para chegar à próxima aula.':instructor?'Seu próximo encontro com a aprendizagem começa aqui.':'Organize os encontros, conecte a equipe e acompanhe cada turma.',managed?button(`${icon('plus')}Nova aula`,'new-lesson','btn btn-primary'):instructor?button(`${icon('plus')}Solicitar horas`,'new-hours','btn btn-primary'):'')+studentSelector+(instructor?personalStats():'')+(student&&!state.classId?`<section class="card">${empty('Sua programação começa aqui','Selecione uma turma acima para visualizar a agenda de aulas.','book')}</section>`:`<section class="card"><div class="filters-bar"><div class="filters">${managed?`<label class="sr-only" for="filter-instructor">Filtrar instrutor</label><select id="filter-instructor">${opts(users,state.instructorId,item=>item.name,'Todos os instrutores')}</select><label class="sr-only" for="filter-class">Filtrar turma</label><select id="filter-class">${opts(classes,state.classId,item=>`${item.code} · ${item.name}`,'Todas as turmas')}</select>`:''}<label class="sr-only" for="filter-course">Filtrar curso</label><select id="filter-course">${opts(courses,state.courseId,item=>item.name,'Todos os cursos')}</select><div class="search-input">${icon('search')}<label class="sr-only" for="agenda-search">Pesquisar na agenda</label><input id="agenda-search" type="search" placeholder="Buscar na agenda" value="${escape(state.query)}"></div></div><div class="segmented" aria-label="Visualização da agenda">${[['week','Semana'],['month','Mês'],['list','Lista']].map(([view,label])=>button(label,'calendar-view',view===state.view?'active':'',`data-view="${view}" aria-pressed="${view===state.view}"`)).join('')}</div></div><div class="calendar-top"><div><h2>${state.view==='week'?weekTitle:escape(monthLabel(state.month))}${student&&selectedClass?` · ${escape(selectedClass.code)}`:''}</h2></div><div class="calendar-navigation">${button('Hoje','calendar-today','btn btn-sm')}${state.view==='week'?button(icon('chevronLeft'),'prev-week','icon-button','aria-label="Semana anterior"')+button(icon('chevronRight'),'next-week','icon-button','aria-label="Próxima semana"'):''}</div></div>${state.view==='week'?calendarWeek(lessons,days):state.view==='month'?calendarMonth(lessons):calendarList(lessons)}<div class="course-legend">${courses.map(item=>`<span class="legend-item"><i class="dot" style="background:${color(item.color)}"></i>${escape(item.name)}</span>`).join('')}</div></section>`);
}
function eventCard(lesson,compact=false) {
    return `<button class="calendar-event" style="--course-color:${color(lesson.course_color)}" data-action="lesson-detail" data-id="${lesson.id}" title="${escape(`${lesson.course_name} · ${timeRange(lesson)} · ${lesson.instructor_name}`)}"><time>${compact?time(lesson.starts_at):timeRange(lesson)}</time><strong>${escape(lesson.course_name)}</strong>${!compact?`<small>${escape(lesson.class_code||lesson.class_name)}</small>${managed?`<small>${escape(lesson.instructor_name)}</small>`:''}<small class="event-room">${icon('location')}${escape(lesson.room_name||lesson.location||lesson.city||'A definir')}</small>`:''}</button>`;
}
function calendarWeek(lessons,days) {return `<div class="calendar-week">${days.map(day=>{const events=lessons.filter(item=>item.starts_at.slice(0,10)<=day&&item.ends_at.slice(0,10)>=day);return `<section class="week-column"><div class="week-date ${day===today?'today':''}">${dt(day).toLocaleDateString('pt-BR',{weekday:'short'}).replace('.','')}<strong>${Number(day.slice(8))}</strong></div>${events.length?events.map(lesson=>eventCard(lesson)).join(''):`<p class="day-empty">Sem aulas</p>`}</section>`;}).join('')}</div>`;}
function calendarMonth(lessons) {
    const first=`${state.month}-01`,start=addDays(first,-((dt(first).getDay()+6)%7));
    const last=dt(first);last.setMonth(last.getMonth()+1,0);
    const length=Math.ceil((((dt(first).getDay()+6)%7)+last.getDate())/7)*7;
    return `<div class="calendar-month">${['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'].map(day=>`<div class="month-weekday">${day}</div>`).join('')}${Array.from({length},(_,index)=>{const day=addDays(start,index),events=lessons.filter(item=>item.starts_at.slice(0,10)===day),outside=day.slice(0,7)!==state.month;return `<div class="month-day ${outside?'outside':''}"><button class="day-number ${day===today?'today':''}" data-action="day-list" data-date="${day}" aria-label="Ver aulas de ${shortDate(day)}">${Number(day.slice(8))}</button>${events.slice(0,3).map(lesson=>eventCard(lesson,true)).join('')}${events.length>3?button(`+ ${events.length-3} aulas`,'day-list','more-events',`data-date="${day}"`):''}</div>`;}).join('')}</div>`;
}
function calendarList(lessons) {
    const items=lessons.filter(item=>item.starts_at.slice(0,7)===state.month);
    if(!items.length)return empty('Nenhuma aula encontrada','Tente outro mês ou ajuste os filtros. Novas aulas aparecem automaticamente.','calendar',managed?button(`${icon('plus')}Agendar aula`,'new-lesson','btn btn-primary btn-sm'):'');
    const grouped=new Map();items.forEach(item=>{const day=item.starts_at.slice(0,10);if(!grouped.has(day))grouped.set(day,[]);grouped.get(day).push(item);});
    return `<div class="agenda-list">${[...grouped].map(([day,events])=>`<section id="day-${day}"><h3 class="agenda-date ${day===today?'today':''}">${escape(fullDate(day))}${day===today?'<span class="badge approved">Hoje</span>':''}</h3>${events.map(lesson=>lessonRow(lesson)).join('')}</section>`).join('')}</div>`;
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
function catalogPage() {
    const tabs=[['courses','Cursos'],['classes','Turmas'],['rooms','Salas'],['tablet_pools','Tablets'],['vehicles','Veículos'],...(manager?[['users','Pessoas']]:[])];
    const tab=tabs.find(([key])=>key===state.catalogTab)?state.catalogTab:'courses';
    const definitions={courses:{label:'curso',headers:['Curso','Cor na agenda'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td><span class="legend-item"><i class="dot" style="background:${color(item.color)}"></i>${escape(item.color)}</span></td>`},classes:{label:'turma',headers:['Turma','Curso','Cidade'],row:item=>`<td><strong>${escape(item.name)}</strong><small>${escape(item.code)}</small></td><td>${escape((state.data.courses||[]).find(course=>Number(course.id)===Number(item.course_id))?.name)}</td><td>${escape(item.city)}</td>`},rooms:{label:'sala',headers:['Sala','Capacidade'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td>${item.capacity} lugares</td>`},tablet_pools:{label:'conjunto de tablets',headers:['Conjunto','Quantidade'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td>${item.quantity} tablets</td>`},vehicles:{label:'veículo',headers:['Veículo','Placa','Lugares'],row:item=>`<td><strong>${escape(item.name)}</strong></td><td>${escape(item.plate)}</td><td>${item.seats}</td>`},users:{label:'pessoa',headers:['Pessoa','Crachá','Perfil','Status'],row:item=>`<td><div class="person">${avatar(item.name)}<div><strong>${escape(item.name)}</strong><small>${escape(item.email)}</small></div></div></td><td>${escape(item.badge)}</td><td>${escape(roleLabels[item.role])}</td><td><span class="badge ${Number(item.active)!==0?'approved':'rejected'}">${Number(item.active)!==0?'Ativo':'Inativo'}</span></td>`}};
    const definition=definitions[tab],items=state.data[tab]||[];
    return heading('Uma base bem organizada','Pessoas, cursos e recursos que dão vida à sua programação.',button(`${icon('plus')}Cadastrar ${definition.label}`,'new-catalog','btn btn-primary',`data-type="${tab}"`))+`<div class="tabs-bar">${tabs.map(([key,label])=>button(label,'catalog-tab',`tab-btn ${key===tab?'active':''}`,`data-tab="${key}" aria-pressed="${key===tab}"`)).join('')}</div>${tab==='users'?`<div class="notice">${icon('shield')}<span>Novas pessoas acessam com o <strong>crachá</strong> e a senha inicial <strong>123456</strong>. A troca é obrigatória no primeiro acesso.</span></div>`:''}<section class="card">${items.length?`<div class="table-scroll"><table class="data-table"><thead><tr>${definition.headers.map(label=>`<th>${label}</th>`).join('')}<th><span class="sr-only">Ações</span></th></tr></thead><tbody>${items.map(item=>`<tr>${definition.row(item)}<td>${button(`${icon('edit')}Editar`,'edit-catalog','btn btn-sm',`data-type="${tab}" data-id="${item.id}"`)}</td></tr>`).join('')}</tbody></table></div>`:empty('Vamos começar por aqui',`Cadastre o primeiro item de ${tabs.find(([key])=>key===tab)[1].toLowerCase()}.`,'layers',button('Cadastrar','new-catalog','btn btn-primary btn-sm',`data-type="${tab}"`))}</section>`;
}
function notifications() {
    const notifications=state.data.notifications || [];
    return `<section class="notifications-panel" aria-label="Notificações"><div class="notifications-head"><h2>Suas notificações</h2>${button(icon('close'),'notifications','icon-button','aria-label="Fechar notificações"')}</div><div class="notification-list">${notifications.length?notifications.map(item=>`<article class="notification-item ${item.read_at?'':'unread'}">${icon('bell')}<div><h3>${escape(item.title)}</h3><p>${escape(item.body)}</p><time>${dateTime(item.created_at)}</time></div></article>`).join(''):'<p class="notification-empty">Nenhuma novidade por enquanto.</p>'}</div>${notifications.length?`<div class="notifications-foot">${button(`${icon('check')}Marcar todas como lidas`,'read-notifications','btn btn-ghost btn-sm')}</div>`:''}</section>`;
}
function initMap() {
    const container=$('#fleet-map');if(!container)return;
    const trips=(state.data.trips || []).filter(item=>item.latitude!==null&&item.longitude!==null&&Number.isFinite(Number(item.latitude))&&Number.isFinite(Number(item.longitude)));
    if(!window.L) {container.innerHTML='<div class="map-fallback">O mapa está indisponível. Os destinos e horários continuam disponíveis na lista de rotas.</div>';return;}
    container.innerHTML='';state.map=L.map(container,{scrollWheelZoom:false}).setView([-24.5,-51.5],6);
    const layer=L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>'}).addTo(state.map);
    let notified=false;layer.on('tileerror',()=>{if(!notified){notified=true;const caption=$('.map-caption');if(caption)caption.innerHTML+=' <strong>Sem conexão com o mapa.</strong> Consulte os destinos na lista.';}});
    const points=[];
    trips.forEach((trip,index)=>{const point=[Number(trip.latitude),Number(trip.longitude)];points.push(point);L.marker(point,{icon:L.divIcon({html:`<div class="map-pin"><span>${index+1}</span></div>`,className:'',iconSize:[31,31],iconAnchor:[15,31]}),title:`${trip.city} · ${trip.instructor_name}`}).addTo(state.map).bindPopup(`<div class="map-popup"><strong>${escape(trip.city)}</strong><p>${escape(trip.destination)}</p><p>${escape(trip.instructor_name)}<br>${escape(trip.vehicle_name)} · ${escape(trip.vehicle_plate)}</p><p>${dateTime(trip.starts_at)}<br>até ${dateTime(trip.ends_at)}</p><a href="https://www.openstreetmap.org/?mlat=${point[0]}&mlon=${point[1]}#map=14/${point[0]}/${point[1]}" target="_blank" rel="noopener noreferrer">Abrir localização ↗</a></div>`);});
    if(points.length)state.map.fitBounds(points,{padding:[35,35],maxZoom:11});
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
    const body=`<div class="form-grid">${selectField('class_id','Turma',classes,lesson.class_id,item=>`${item.code} · ${item.name}`,'required autofocus',true)}${selectField('instructor_id','Instrutor(a)',users,lesson.instructor_id,item=>item.name,'required',true)}${field('starts_at','Início',inputTime(lesson.starts_at||`${day} 08:00`),'datetime-local','required')}${field('ends_at','Término',inputTime(lesson.ends_at||`${day} 12:00`),'datetime-local','required')}${selectField('room_id','Sala <small>ou informe o local externo abaixo</small>',state.data.rooms || [],lesson.room_id,item=>item.name,'',true)}${field('location','Local externo <small>opcional quando houver sala</small>',lesson.location,'text','maxlength="180"',true)}${field('city','Cidade',lesson.city||'','text','maxlength="100"',true)}${textarea('content','Conteúdo previsto <small>o instrutor também pode preencher</small>',lesson.content,'maxlength="6000"')}${field('latitude','Latitude <small>opcional</small>',lesson.latitude,'number','step="any" min="-90" max="90"')}${field('longitude','Longitude <small>opcional</small>',lesson.longitude,'number','step="any" min="-180" max="180"')}</div><p class="form-note">${icon('info')}A turma, o instrutor e a sala não podem ter horários sobrepostos. Ao salvar, os instrutores envolvidos serão notificados.</p>`;
    showDialog(id?'Editar aula':'Vamos agendar uma aula',id?'Atualize a programação. Os envolvidos serão informados.':'Escolha a turma, o instrutor e o melhor horário.',body,saveFooter(id?'Salvar alteração':'Agendar aula',id?button(icon('trash'),'delete-lesson','btn btn-danger',`data-id="${id}" aria-label="Excluir aula"`):''),async data=>{['class_id','instructor_id'].forEach(key=>data[key]=Number(data[key]));data.room_id=data.room_id?Number(data.room_id):null;data.latitude=data.latitude===''?null:Number(data.latitude);data.longitude=data.longitude===''?null:Number(data.longitude);await mutate(id?`api/lessons/${id}`:'api/lessons',id?'PUT':'POST',data,id?'Aula atualizada. A equipe já pode ver a alteração.':'Aula agendada com sucesso.');});
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
        case 'new-lesson':lessonDialog();break;
        case 'edit-lesson':lessonDialog(id);break;
        case 'lesson-detail':lessonDetail(id);break;
        case 'delete-lesson':confirmDelete('lesson',id);break;
        case 'new-hours':hoursDialog();break;
        case 'review-hours':reviewHours(id,target.dataset.status);break;
        case 'new-trip':tripDialog();break;
        case 'edit-trip':tripDialog(id);break;
        case 'delete-trip':confirmDelete('trip',id);break;
        case 'new-reservation':reservationDialog();break;
        case 'delete-reservation':confirmDelete('reservation',id);break;
        case 'new-catalog':catalogDialog(target.dataset.type);break;
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
