const fs = require('fs');

let content = fs.readFileSync('public/app.html', 'utf8');

const search = `  if (tipo === 'marcacoes-diarias') url = \`/api/relatorios/marcacoes-diarias/\${funcId}?formato=\${formato}&inicio=\${inicio}&fim=\${fim}\`;`;

const replace = `  if (tipo === 'marcacoes-diarias') {
    const selected = getSelectedFuncs();
    if (selected.length === 0) {
      toast('Seleccione pelo menos um funcionário.', 'err');
      return;
    }
    const ids = selected.map(f => f.id).join(',');
    url = \`/api/relatorios/marcacoes-diarias?funcionario_ids=\${ids}&formato=\${formato}&inicio=\${inicio}&fim=\${fim}\`;
  }`;

content = content.replace(search, replace);
fs.writeFileSync('public/app.html', content);
