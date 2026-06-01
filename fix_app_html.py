import sys
import re

new_js = '''// ── TURNOS ──
let _turnos = [];
async function carregarTurnos() {
  const el = document.getElementById('turnosTable');
  if (!el) return;
  el.innerHTML = '<div class="empty"><span class="spinner"></span></div>';
  try {
    const res = await api('GET', '/api/turnos');
    _turnos = res.dados || [];
    const rows = _turnos.map(t => `<tr>
      <td><strong>${t.nome}</strong></td>
      <td><span class="badge badge-blue">${t.tipo}</span></td>
      <td>${t.hora_entrada || '—'} → ${t.hora_saida || '—'}</td>
      <td>${t.horas_efectivas}h</td>
      <td><span class="badge badge-${t.classificacao_legal==='nocturno'?'warn':'gray'}">${t.classificacao_legal}</span></td>
      <td>
        <button class="btn btn-sm" onclick="abrirModalTurno(${t.id})">Editar</button>
        <button class="btn btn-sm btn-danger" onclick="eliminarTurno(${t.id})">Desactivar</button>
      </td>
    </tr>`).join('');
    el.innerHTML = `<table class="tbl">
      <thead><tr><th>Nome</th><th>Tipo</th><th>Horário</th><th>Efectivas</th><th>Legal</th><th>Acções</th></tr></thead>
      <tbody>${rows||'<tr><td colspan="6" class="empty">Sem turnos configurados.</td></tr>'}</tbody>
    </table>`;
  } catch(e) { el.innerHTML = '<div class="empty">Erro ao carregar turnos.</div>'; }
}

function abrirModalTurno(id = null) {
  const t = id ? _turnos.find(x => x.id === id) : null;
  document.getElementById('modalTurnoTitle').textContent = id ? 'Editar turno' : 'Novo turno';
  document.getElementById('tId').value = id || '';
  document.getElementById('tNome').value = t ? t.nome : '';
  document.getElementById('tTipo').value = t ? t.tipo : 'trabalho';
  document.getElementById('tEntrada').value = t ? (t.hora_entrada || '') : '';
  document.getElementById('tSaida').value = t ? (t.hora_saida || '') : '';
  document.getElementById('tIniInt').value = t ? (t.hora_inicio_intervalo || '') : '';
  document.getElementById('tFimInt').value = t ? (t.hora_fim_intervalo || '') : '';
  toggleCamposTurno();
  document.getElementById('modalTurno').classList.add('open');
}

function toggleCamposTurno() {
  const tipo = document.getElementById('tTipo').value;
  document.getElementById('camposHorarioTurno').style.display = tipo === 'trabalho' ? 'block' : 'none';
}

async function guardarTurno() {
  const id = document.getElementById('tId').value;
  const payload = {
    nome: document.getElementById('tNome').value.trim(),
    tipo: document.getElementById('tTipo').value,
    hora_entrada: document.getElementById('tEntrada').value || null,
    hora_saida: document.getElementById('tSaida').value || null,
    hora_inicio_intervalo: document.getElementById('tIniInt').value || null,
    hora_fim_intervalo: document.getElementById('tFimInt').value || null
  };
  if (!payload.nome) { toast('O nome é obrigatório.','err'); return; }
  try {
    const res = id ? await api('PUT', `/api/turnos/${id}`, payload) : await api('POST', '/api/turnos', payload);
    if (res.erro) throw new Error(res.mensagem);
    toast(res.mensagem || 'Turno guardado','ok');
    fecharModal('modalTurno'); carregarTurnos();
  } catch(e) { toast(e.message,'err'); }
}

async function eliminarTurno(id) {
  if (!confirm('Desactivar este turno?')) return;
  try {
    const res = await api('DELETE', `/api/turnos/${id}`);
    if (res.erro) throw new Error(res.mensagem);
    toast(res.mensagem,'ok'); carregarTurnos();
  } catch(e) { toast(e.message,'err'); }
}

// ── ESCALAS ──
let _escalas = [];
let _escalaActual = null;
async function carregarEscalas() {
  const el = document.getElementById('escalasTable');
  if (!el) return;
  el.innerHTML = '<div class="empty"><span class="spinner"></span></div>';
  try {
    const res = await api('GET', '/api/escalas');
    _escalas = res.dados || [];
    const rows = _escalas.map(e => `<tr>
      <td><strong>${e.nome}</strong></td>
      <td>${e.departamento_nome || '—'}</td>
      <td>${e.tamanho_ciclo} dias</td>
      <td>
        <button class="btn btn-sm" onclick="verDetalheEscala(${e.id})">Detalhes</button>
        <button class="btn btn-sm" onclick="abrirModalEscala(${e.id})">Editar</button>
        <button class="btn btn-sm btn-danger" onclick="eliminarEscala(${e.id})">Eliminar</button>
      </td>
    </tr>`).join('');
    el.innerHTML = `<table class="tbl">
      <thead><tr><th>Nome</th><th>Departamento</th><th>Ciclo</th><th>Acções</th></tr></thead>
      <tbody>${rows||'<tr><td colspan="4" class="empty">Sem escalas configuradas.</td></tr>'}</tbody>
    </table>`;
  } catch(e) { el.innerHTML = '<div class="empty">Erro ao carregar escalas.</div>'; }
}

async function abrirModalEscala(id = null) {
  const e = id ? _escalas.find(x => x.id === id) : null;
  document.getElementById('modalEscalaTitle').textContent = id ? 'Editar escala' : 'Nova escala';
  document.getElementById('eId').value = id || '';
  document.getElementById('eNome').value = e ? e.nome : '';
  document.getElementById('eDesc').value = e ? (e.descricao || '') : '';
  document.getElementById('eCiclo').value = e ? e.tamanho_ciclo : '7';

  // Popular departamentos
  const deps = await api('GET', '/api/departamentos');
  document.getElementById('eDep').innerHTML = '<option value="">Nenhum</option>' +
    (deps.dados||[]).map(d => `<option value="${d.id}" ${e && e.departamento_id == d.id ? 'selected' : ''}>${d.nome}</option>`).join('');

  document.getElementById('modalEscala').classList.add('open');
}

async function guardarEscala() {
  const id = document.getElementById('eId').value;
  const payload = {
    nome: document.getElementById('eNome').value.trim(),
    descricao: document.getElementById('eDesc').value.trim(),
    departamento_id: document.getElementById('eDep').value || null,
    tamanho_ciclo: parseInt(document.getElementById('eCiclo').value)
  };
  if (!payload.nome || !payload.tamanho_ciclo) { toast('Nome e tamanho do ciclo são obrigatórios.','err'); return; }
  try {
    const res = id ? await api('PUT', `/api/escalas/${id}`, payload) : await api('POST', '/api/escalas', payload);
    if (res.erro) throw new Error(res.mensagem);
    toast(res.mensagem || 'Escala guardada','ok');
    fecharModal('modalEscala'); carregarEscalas();
  } catch(e) { toast(e.message,'err'); }
}

async function verDetalheEscala(id) {
  const res = await api('GET', `/api/escalas/${id}`);
  if (res.erro) { toast(res.mensagem,'err'); return; }
  _escalaActual = res.dados;
  document.getElementById('escalaDetalheNome').textContent = _escalaActual.nome;
  document.getElementById('escalaDetalhe').style.display = 'block';

  # Renderizar grid de turnos
  let turnosHtml = '<div style="display:flex; gap:8px; overflow-x:auto; padding:20px; border-bottom:1px solid var(--border)">';
  for (let i = 1; i <= _escalaActual.tamanho_ciclo; i++) {
    const t = _escalaActual.turnos.find(x => x.posicao == i);
    turnosHtml += `
      <div class="card" style="min-width:140px; flex-shrink:0; margin:0; text-align:center; padding:12px; border-color:${t?'var(--border)':'dashed var(--muted)'}">
        <div style="font-size:10px; color:var(--muted2); margin-bottom:8px">Dia ${i}</div>
        ${t ? `<strong>${t.nome}</strong><br><small>${t.hora_entrada||'—'}-${t.hora_saida||'—'}</small><br>
           <button class="btn btn-sm" style="margin-top:8px" onclick="removerTurnoEscala(${i})">✕</button>`
          : `<button class="btn btn-sm btn-accent" onclick="abrirEscolhaTurno(${i})">+ Turno</button>`}
      </div>`;
  }
  turnosHtml += '</div>';
  document.getElementById('escalaTurnosLista').innerHTML = turnosHtml;

  # Atribuições
  const atrib = await api('GET', `/api/escalas/${id}/atribuicoes`);
  const atribRows = (atrib.dados||[]).map(a => `<tr>
    <td><strong>${a.funcionario_nome}</strong> (${a.numero_funcionario})</td>
    <td>${a.data_inicio} → ${a.data_fim||'—'}</td>
    <td>Posição ${a.posicao_inicial}</td>
    <td><button class="btn btn-sm btn-danger" onclick="removerAtribuicao(${a.funcionario_id})">Remover</button></td>
  </tr>`).join('');
  document.getElementById('escalaAtribuicoes').innerHTML = `<div class="card-body">
    <div class="card-title" style="margin-bottom:16px">Funcionários Atribuídos</div>
    <table class="tbl"><thead><tr><th>Funcionário</th><th>Período</th><th>Início Ciclo</th><th>Accões</th></tr></thead>
    <tbody>${atribRows||'<tr><td colspan="4" class="empty">Sem funcionários atribuídos.</td></tr>'}</tbody></table></div>`;

  window.scrollTo({ top: document.getElementById('escalaDetalhe').offsetTop - 20, behavior: 'smooth' });
}

function abrirEscolhaTurno(posicao) {
  const tid = prompt('ID do Turno (ou use a UI para listar turnos e copiar o ID):');
  if (tid) adicionarTurnoEscala(posicao, tid);
}

async function adicionarTurnoEscala(posicao, turno_id) {
  try {
    const res = await api('POST', `/api/escalas/${_escalaActual.id}/turnos`, { posicao, turno_id });
    if (res.erro) throw new Error(res.mensagem);
    verDetalheEscala(_escalaActual.id);
  } catch(e) { toast(e.message,'err'); }
}

async function removerTurnoEscala(posicao) {
  if (!confirm('Remover este turno da escala?')) return;
  try {
    const res = await api('DELETE', `/api/escalas/${_escalaActual.id}/turnos/${posicao}`);
    if (res.erro) throw new Error(res.mensagem);
    verDetalheEscala(_escalaActual.id);
  } catch(e) { toast(e.message,'err'); }
}

async function abrirModalAtribuicao() {
  document.getElementById('aEscalaId').value = _escalaActual.id;
  document.getElementById('aDataInicio').value = new Date().toISOString().slice(0,10);
  document.getElementById('aDataFim').value = '';
  document.getElementById('aPosicao').value = '1';

  # Popular funcionários
  const funcs = await api('GET', '/api/funcionarios?estado=activo');
  document.getElementById('aFunc').innerHTML = (funcs.dados||[]).map(f => `<option value="${f.id}">${f.nome_completo} (${f.numero_funcionario})</option>`).join('');

  document.getElementById('modalAtribuicao').classList.add('open');
}

async function guardarAtribuicao() {
  const eid = document.getElementById('aEscalaId').value;
  const payload = {
    funcionario_id: parseInt(document.getElementById('aFunc').value),
    data_inicio: document.getElementById('aDataInicio').value,
    data_fim: document.getElementById('aDataFim').value || null,
    posicao_inicial: parseInt(document.getElementById('aPosicao').value)
  };
  try {
    const res = await api('POST', `/api/escalas/${eid}/atribuicoes`, payload);
    if (res.erro) throw new Error(res.mensagem);
    toast(res.mensagem,'ok');
    fecharModal('modalAtribuicao'); verDetalheEscala(eid);
  } catch(e) { toast(e.message,'err'); }
}

async function removerAtribuicao(fid) {
  if (!confirm('Remover atribuição deste funcionário?')) return;
  try {
    const res = await api('DELETE', `/api/escalas/${_escalaActual.id}/atribuicoes/${fid}`);
    if (res.erro) throw new Error(res.mensagem);
    toast(res.mensagem,'ok'); verDetalheEscala(_escalaActual.id);
  } catch(e) { toast(e.message,'err'); }
}

async function eliminarEscala(id) {
  if (!confirm('Eliminar esta escala?')) return;
  try {
    const res = await api('DELETE', `/api/escalas/${id}`);
    if (res.erro) throw new Error(res.mensagem);
    toast(res.mensagem,'ok'); carregarEscalas();
    if (_escalaActual && _escalaActual.id == id) document.getElementById('escalaDetalhe').style.display = 'none';
  } catch(e) { toast(e.message,'err'); }
}'''

with open('public/app.html', 'r', encoding='utf-8') as f:
    content = f.read()

# Pages
new_pages = '''    <!-- TURNOS -->
    <div class="page" id="page-turnos">
      <div class="page-header">
        <div>
          <div class="page-title">Turnos</div>
          <div class="page-sub">Templates de horário reutilizáveis em escalas</div>
        </div>
        <button class="btn btn-accent" id="btnNovoTurno" onclick="abrirModalTurno()">+ Novo turno</button>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Turnos configurados</div></div>
        <div id="turnosTable"><div class="empty"><span class="spinner"></span></div></div>
      </div>
    </div>

    <div class="page" id="page-escalas">
      <div class="page-header">
        <div>
          <div class="page-title">Escalas</div>
          <div class="page-sub">Padrões de turnos · Atribuição de funcionários</div>
        </div>
        <button class="btn btn-accent" id="btnNovaEscala" onclick="abrirModalEscala()">+ Nova escala</button>
      </div>
      <div class="card">
        <div class="card-head"><div class="card-title">Escalas configuradas</div></div>
        <div id="escalasTable"><div class="empty"><span class="spinner"></span></div></div>
      </div>
      <div class="card" id="escalaDetalhe" style="display:none">
        <div class="card-head">
          <div class="card-title" id="escalaDetalheNome">—</div>
          <button class="btn" onclick="abrirModalAtribuicao()">+ Atribuir funcionário</button>
        </div>
        <div id="escalaTurnosLista"></div>
        <div id="escalaAtribuicoes"></div>
      </div>
    </div>'''

# Modals
new_modals = '''<div class="overlay" id="modalTurno">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modalTurnoTitle">Novo turno</h3>
      <button class="modal-close" onclick="fecharModal('modalTurno')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="tId">
      <div class="form-group"><label>Nome *</label><input id="tNome" placeholder="Ex: Diurno 8h-17h"></div>
      <div class="form-group">
        <label>Tipo *</label>
        <select id="tTipo" onchange="toggleCamposTurno()">
          <option value="trabalho">Trabalho</option>
          <option value="folga">Folga</option>
          <option value="compensatorio">Compensatório</option>
        </select>
      </div>
      <div id="camposHorarioTurno">
        <div class="form-row">
          <div class="form-group"><label>Hora entrada</label><input type="time" id="tEntrada"></div>
          <div class="form-group"><label>Hora saída</label><input type="time" id="tSaida"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Início intervalo</label><input type="time" id="tIniInt"></div>
          <div class="form-group"><label>Fim intervalo</label><input type="time" id="tFimInt"></div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="fecharModal('modalTurno')">Cancelar</button>
      <button class="btn btn-accent" onclick="guardarTurno()">Guardar</button>
    </div>
  </div>
</div>

<div class="overlay" id="modalEscala">
  <div class="modal">
    <div class="modal-head">
      <h3 id="modalEscalaTitle">Nova escala</h3>
      <button class="modal-close" onclick="fecharModal('modalEscala')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="eId">
      <div class="form-group"><label>Nome *</label><input id="eNome" placeholder="Ex: Escritório 5/2"></div>
      <div class="form-group"><label>Descrição</label><textarea id="eDesc" rows="2"></textarea></div>
      <div class="form-group"><label>Departamento (opcional)</label><select id="eDep"><option value="">Nenhum</option></select></div>
      <div class="form-group">
        <label>Tamanho do ciclo *</label>
        <input type="number" id="eCiclo" min="1" max="60" placeholder="Ex: 7 para 5/2, 5 para call center">
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="fecharModal('modalEscala')">Cancelar</button>
      <button class="btn btn-accent" onclick="guardarEscala()">Guardar</button>
    </div>
  </div>
</div>

<div class="overlay" id="modalAtribuicao">
  <div class="modal">
    <div class="modal-head">
      <h3>Atribuir funcionário</h3>
      <button class="modal-close" onclick="fecharModal('modalAtribuicao')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="aEscalaId">
      <div class="form-group"><label>Funcionário *</label><select id="aFunc"></select></div>
      <div class="form-group"><label>Data início *</label><input type="date" id="aDataInicio"></div>
      <div class="form-group"><label>Data fim (opcional)</label><input type="date" id="aDataFim"></div>
      <div class="form-group">
        <label>Posição inicial no ciclo *</label>
        <input type="number" id="aPosicao" min="1" placeholder="1">
        <small style="color:var(--muted2)">Posição que este funcionário ocupa no primeiro dia do ciclo</small>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="fecharModal('modalAtribuicao')">Cancelar</button>
      <button class="btn btn-accent" onclick="guardarAtribuicao()">Atribuir</button>
    </div>
  </div>
</div>'''

content = re.sub(r'<!-- ROTA\u00c7\u00d5ES -->.*?<div class=\"page\" id=\"page-rotacoes\">.*?</div>\s+</div>', new_pages, content, flags=re.DOTALL)
content = re.sub(r'<!-- MODAL ROTA\u00c7\u00c1O -->.*?<div class=\"overlay\" id=\"modalRotacao\">.*?</div>\s+</div>', new_modals, content, flags=re.DOTALL)
content = re.sub(r'// \u2500\u2500 ROTA\u00c7\u00d5ES \u2500\u2500.*?// \u2500\u2500 EXPORTA\u00c7\u00c1O \u2500\u2500', new_js + '\\n\\n// \u2500\u2500 EXPORTA\u00c7\u00c1O \u2500\u2500', content, flags=re.DOTALL)

with open('public/app.html', 'w', encoding='utf-8') as f:
    f.write(content)
