const fs = require('fs');

let content = fs.readFileSync('public/app.html', 'utf8');

const search = `async function downloadApi(path, filename) {
  try {
    const opts = { headers: { 'Authorization': 'Bearer ' + TOKEN } };
    if (TENANT) opts.headers['X-Tenant'] = TENANT;
    const res  = await fetch(path, opts);
    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
  } catch(e) { toast('Erro ao descarregar: ' + e.message,'err'); }
}`;

const replace = `async function downloadApi(path, filename) {
  try {
    const opts = { headers: { 'Authorization': 'Bearer ' + TOKEN } };
    if (TENANT) opts.headers['X-Tenant'] = TENANT;
    const res  = await fetch(path, opts);

    if (!res.ok) {
      let erroMsg = 'Erro na exportação (HTTP ' + res.status + ')';
      try {
        const json = await res.json();
        if (json.mensagem) erroMsg = json.mensagem;
      } catch (e) {
        // Ignora se não for JSON e usa a msg padrão
      }
      throw new Error(erroMsg);
    }

    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
  } catch(e) { toast('Erro ao descarregar: ' + e.message,'err'); }
}`;

content = content.replace(search, replace);
fs.writeFileSync('public/app.html', content);
