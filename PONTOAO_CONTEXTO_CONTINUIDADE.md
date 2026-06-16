# PontoAO — Documento de Continuidade de Desenvolvimento
*Gerado em: 16/06/2026*

---

## 1. VISÃO GERAL DO PROJECTO

**PontoAO** é um SaaS de gestão de assiduidade e RH desenvolvido pela M6 Investments Ltda para o mercado angolano, conforme a Lei Geral do Trabalho (LGT Lei n.º 7/15).

**Tenant activo:** FTL Angola  
**URL produção:** https://rh.ftl-angola.net  
**Portal funcionário:** https://rh.ftl-angola.net/ponto.html?tenant=ftl  
**Servidor:** Hetzner CX22, IP `78.47.242.71`  
**Directório:** `/var/www/saas/`  
**Repositório:** `github.com/manpito/pontoao` (público)  
**Deploy:** automático via webhook GitHub (merge para main → `/var/www/saas/deploy.sh`)

---

## 2. STACK TÉCNICA

- **Backend:** PHP 8.3, Slim 4, PDO/MySQL
- **Frontend:** HTML/CSS/JS vanilla (ficheiro único `app.html` ~200KB, `ponto.html` para funcionários)
- **Base de dados:** MySQL 8, multi-tenant via prefixo `tenant_XXX_slug`
- **Servidor web:** Nginx + PHP-FPM
- **Testes:** PHPUnit 11 (13 testes unitários, sempre devem passar)
- **Autenticação:** JWT em cookies HttpOnly; Secure; SameSite=Strict

---

## 3. METODOLOGIA DE DESENVOLVIMENTO

**Fluxo obrigatório:**
1. Claude escreve briefing preciso → Marco envia ao Jules (Google Labs AI)
2. Jules implementa via GitHub PR
3. Marco traz PR ao Claude para revisão
4. Claude aprova ou solicita correcções
5. Marco mergeia **apenas após aprovação explícita do Claude**

**Validação de PR (4 passos no servidor):**
```bash
git fetch origin
git show --stat origin/BRANCH
git checkout origin/BRANCH -- ficheiros...
vendor/bin/phpunit --testsuite Unit
# verificações específicas do sprint
git reset HEAD . && git checkout -- ficheiros...
# após aprovação:
git pull origin main
git log --oneline -3
```

**Briefings para Jules:**
- Escritos em markdown no corpo do chat, nunca em canvas
- Sem blocos de código aninhados (usar indentação 4 espaços)
- Sempre incluir critérios de aceitação com greps específicos
- Sempre terminar com "Reporte no PR" com outputs esperados
- Nunca incluir `<style>`, `</style>`, `<script>`, `</script>` dentro de template literals JS — usar `${'<'}style>` etc.
- Nunca usar `replace(/'/g, ...)` dentro de atributos `onclick` — usar `split("'").join("&#39;")` ou pré-calcular em variável
- Expressões ternárias em template literals HTML devem ter `}` de fecho explícito

---

## 4. ESTRUTURA DO PROJECTO

```
/var/www/saas/
├── app/
│   ├── Config/
│   │   ├── Database.php          # Multi-tenant DB resolver
│   │   └── TenantResolver.php
│   ├── Controllers/
│   │   ├── Auth/AuthController.php      # Login, logout, refresh, alterar-password
│   │   ├── EscalaController.php
│   │   ├── EscalaExcecoesController.php # Excepções + atribuicao_directa (P18)
│   │   ├── ExportacaoController.php     # Primavera V10 + CSV
│   │   ├── FeriasController.php
│   │   ├── FuncionarioController.php
│   │   ├── MarcacaoFaltaController.php  # Faltas + classificação + horário
│   │   ├── PedidoHorasExtraController.php  # P17a
│   │   ├── PeriodoController.php
│   │   ├── RelatorioController.php      # marcacoesDiarias, horas, porEscala
│   │   ├── TerminalController.php       # Inclui device_id/SN
│   │   └── ZkBridgeController.php       # Protocolo ADMS ZKTeco
│   └── Services/
│       └── EscalaService.php            # calcularTurnoEm() — consulta atribuicao_directa primeiro
├── config/
│   └── routes.php
├── database/
│   └── migrations/tenant/001_tenant_schema.sql
├── public/
│   ├── app.html                  # Painel de gestão RH (~200KB, 1 bloco script)
│   ├── ponto.html                # Portal do funcionário (mobile-friendly)
│   └── assets/                   # Favicons FTL
├── tests/
│   └── bootstrap_db.php          # SQLite in-memory para PHPUnit
├── logs/
│   ├── app-YYYY-MM-DD.log
│   ├── zk_debug.log              # 4.4GB — logs ZKTeco
│   └── deploy.log
├── JULES.md                      # Briefing master para Jules
└── deploy.sh
```

---

## 5. BASE DE DADOS

**Tenant activo:** `tenant_004_ftl`  
**Acesso:** `mysql -u root -p -e "USE tenant_004_ftl; ..."`

### Tabelas principais

| Tabela | Descrição |
|--------|-----------|
| `funcionarios` | Inclui `departamento_id`, `cargo_id`, `horario_id` (via JOIN) |
| `utilizadores` | Inclui `deve_alterar_password TINYINT(1) DEFAULT 1` (P16) |
| `marcacoes` | `origem` ENUM: relogio/web_pin/web_qr/web_face/manual |
| `marcacoes_em_falta` | Estados: pendente/justificada_trabalho/justificada_motivo/justificada_outras/injustificada_meio_dia/injustificada_falta |
| `escalas` | Inclui `regime ENUM('normal','turnos')` (P13) |
| `escala_excepcoes` | Inclui `tipo ENUM('substituicao','atribuicao_directa')` e `funcionario_ausente_id` nullable (P18) |
| `pedidos_horas_extra` | Estados: pendente/aprovado_rh/aprovado/rejeitado; tipo: normal/excepcional (P17a) |
| `configuracoes` | chave/valor; inclui `horas_extra_entrada_antecipada` (P11) e `deve_alterar_password` |
| `funcionario_horario` | JOIN para obter `horario_id` do funcionário |
| `ferias_pedidos` | Pedidos de férias |

### Funcionários activos (27)

| Nº | Nome | Dept |
|----|------|------|
| 1001 | Marco Carneiro | Administrativo |
| 1004 | João Bartolomeu | — |
| 1014 | Adilson Ambriz | Técnico e IT |
| 1021 | Eurico da Silva | Técnico e IT |
| 1024 | Afonso Garcia | Administrativo |
| 1033 | Jerson Luis | — |
| 1034 | Claudio Cunha | Técnico e IT |
| 1036 | Hermenigildo João | Administrativo |
| 1043 | Nector Raimundo | — |
| 1049 | Angelo Silva | Administrativo |
| 1052 | José Cabaça | — |
| 1056 | Emilio Monteiro | Técnico e IT |
| 1063 | Teresa Miguel | Administrativo |
| 1065 | João Cambando | — |
| 2013 | Helder da Conceição | Administrativo |
| 2017 | Osvaldo Correia | Call Centre |
| 2019 | Suamino Jaime | Call Centre |
| 2020 | Juliana José | Call Centre |
| 2024 | Sousa Tomás | — |
| 2026 | Mauro Caetano | Call Centre |
| 2029 | Géssica Lucas | Call Centre |
| 2043 | Esmael Sango | Call Centre (férias 08/06→07/07) |
| 2044 | Paula Chissupa | — |
| 2045 | Evalina Catito | — |

---

## 6. TERMINAIS ZKTECO

| ID | Nome | IP | SN (device_id) | Estado |
|----|------|----|----------------|--------|
| 1 | Entrada Principal | 192.168.100.8 | 5450251100118 | Activo |
| 2 | Call Centre | 192.168.100.84 | 5450251100040 | Activo (sem acesso web) |

**Protocolo:** ADMS (relógio contacta servidor, não o inverso)  
**Problema conhecido:** relógio Call Centre tem UIDs com zeros à esquerda (0024, 0036, 0049, 0065) — precisam ser corrigidos no ecrã táctil do relógio para 1024, 1036, 1049, 1065  
**Log ZKTeco:** `/var/www/saas/logs/zk_debug.log`

---

## 7. ESCALAS E TURNOS

### Escala 5/2 (regime: normal)
- Segunda a Sexta, horário padrão 08:00-17:00

### Rotação 28/28 (regime: normal)
- Base no Soyo

### Rotação CC 4/1 (regime: turnos, tamanho_ciclo: 4)
- **Posições:** 1=Dia CC (07h-18h), 2=Noite CC (18h-07h), 3=Folga, 4=Folga
- **Operadores:** Osvaldo (id=19, pos_ini=3), Mauro (id=22), Géssica (id=23), Esmael (id=24, férias)
- **Suamino:** supervisor, trabalha segunda a sexta durante férias do Esmael
- **Ciclo real:** Dia → Noite → Folga → Folga (5 dias contando saída pós-noite como descanso)

### Regras de horas extra por regime
- **Normal:** fins de semana/feriados com trabalho = extraordinário
- **Turnos:** fins de semana/feriados com turno atribuído = extra normal; com folga mas trabalhou = extraordinário
- **Configuração:** `horas_extra_entrada_antecipada` em `configuracoes` (0=não conta entrada antecipada)

### Excepções de escala (P18)
- `tipo='atribuicao_directa'`: supervisor define turno manual para um funcionário num dia específico
- `tipo='substituicao'`: funcionário A ausente, B substitui
- UI: menu Escalas → "Excepções de turno"
- `EscalaService::calcularTurnoEm()` consulta atribuições directas PRIMEIRO, depois substituições, depois ciclo

---

## 8. SISTEMA DE HORAS EXTRA (P17)

### Fluxo de aprovação
1. Funcionário submete pedido no `ponto.html` (data, minutos, motivo)
2. Limite: 240 minutos máximo absoluto
3. Até 120 min → `tipo='normal'` → Colaborador RH aprova → estado `aprovado`
4. 121-240 min → `tipo='excepcional'` → Colaborador RH pré-aprova → estado `aprovado_rh` → Gestor RH aprova → estado `aprovado`
5. Acima 240 min → bloqueado automaticamente

### Arredondamento automático
- Quando relatório é gerado, se saída > hora_corte do turno E não há aprovação:
- Saída é arredondada para `hora_corte + minutos_aprovados`
- Marcação actualizada na DB com `origem='manual'`, `motivo_edicao='Arredondamento automático - horas extra não aprovadas'`
- `data_hora_original` preservada para rastreabilidade

---

## 9. MENU FALTAS

### Estados de classificação
- `justificada_trabalho` — Trabalho externo (mostra campos de hora)
- `justificada_motivo` — Motivo pessoal
- `justificada_outras` — Outras situações (mostra campos de hora)
- `injustificada_meio_dia`
- `injustificada_falta`

### Lógica de criação de marcação ao classificar
- Falta de entrada (`marcacao_entrada_id IS NULL`) → cria marcação `tipo='entrada'`
- Falta de saída (`marcacao_entrada_id IS NOT NULL`) → cria marcação `tipo='saida'`
- Verificação de duplicados antes de inserir (janela de 60 segundos)

---

## 10. PORTAL DO FUNCIONÁRIO (ponto.html)

### Funcionalidades
- Marcação de ponto (entrada, saída, intervalo)
- Histórico de marcações hoje e semana
- Saldo de férias + pedido de férias
- Pedido de horas extra
- Alteração de password

### Segurança
- `deve_alterar_password = 1` força alteração no primeiro acesso
- Modal de alteração obrigatório — não pode ser fechado no primeiro acesso
- Botão "PASSWORD" no header para alteração voluntária posterior

---

## 11. EXPORTAÇÃO PRIMAVERA V10

- Menu Exportação → selecção múltipla de funcionários (checkboxes com filtro por nome/departamento)
- Endpoint: `GET /api/exportacao/primavera?mes=YYYY-MM&funcionario_ids=1,2,3`
- Formato: `.txt` posicional com códigos F03/F08/F10/F50/F07/H02/H04

---

## 12. SPRINTS CONCLUÍDOS

| Sprint | Descrição |
|--------|-----------|
| P8 | Classificação de falta com registo de marcação manual |
| P9 | Editar classificação + "Outras justificadas" |
| P10 | Filtros por funcionário/departamento + PDF no menu Faltas |
| P11 | Configuração `horas_extra_entrada_antecipada` por tenant |
| P12 | Horas extra no relatório de marcações diárias |
| P13 | Regime de escala (normal vs turnos rotativos) |
| P14 | Fix edição funcionário mantém departamento/cargo/horário |
| P15 | Exportação Primavera com selecção múltipla |
| P16 | Alteração de password no primeiro acesso |
| P17a | Backend pedidos horas extra (tabela + controller + rotas) |
| P17b | UI pedidos horas extra + arredondamento automático |
| P18 | Excepções de escala com atribuição directa de turno |

### Fixes directos (sem PR)
- `device_id`/SN no registo de terminais
- Escape de `<style>`,`</style>`,`<script>`,`</script>` em template literals
- Regex em atributos `onclick` substituída por `split/join`
- Ternário sem `}` de fecho em template literals
- `loadExportacao` adicionado ao mapa de navegação
- `devido_alterar_password` — botão Cancelar no modal
- Regime de escala — fix `fe.activo` → `data_fim IS NULL OR data_fim >= CURDATE()`
- Botões de edição em fins de semana para regime de turnos

---

## 13. REGRAS DO JULES.md (críticas)

1. Nunca commitar `.env`
2. `vendor/` não vai para Git
3. Testar sintaxe PHP antes de commitar
4. Deploy automático — usar branches, merge só após aprovação
5. Não hardcodar credenciais
6. PHP 8.3 — usar features modernas
7. Padrão controllers: método privado `json(int $status, array $data)`
8. Padrão auditoria: `$this->auditoria($db, $request, ...)`
9. Tabelas novas → adicionar a `001_tenant_schema.sql`
10. `app.html` tem 200KB+ — ficheiro único com toda a lógica frontend
11. **Tags HTML em template literals:** `<style>` → `${'<'}style>`, `</style>` → `${'</'}style>`, `<script>` → `${'<'}script>`, `</script>` → `${'</'}script>`
12. **Aspas em `onclick`:** nunca interpolar variáveis com aspas simples directamente — pré-calcular em variável JS separada
13. **Regex em `onclick`:** nunca usar `replace(/pattern/flags, ...)` dentro de atributos HTML — usar `split().join()`

---

## 14. TAREFA PENDENTE — IMPORTAÇÃO DE MARCAÇÕES HISTÓRICAS

### Contexto
O programa anterior (Naldan Communication) gerou um relatório PDF de Junho 2026 com marcações reais de 21/05 a 12/06/2026. O PontoAO só começou a receber marcações reais a partir de ~13/06. As marcações de 01/06 a 12/06 no PontoAO são testes, não dados reais.

### Acção necessária
1. **Eliminar** marcações de 01/06 a 12/06 no PontoAO para todos os funcionários com dados no PDF (excepto Marco 1001)
2. **Inserir** marcações de 21/05 a 12/06 a partir do PDF, apenas entrada e saída reais (ignorar "Missing")

### Funcionários a processar (do PDF)
1004 João Bartolomeu (verificar se tem dados), 1014 Adilson, 1021 Eurico, 1033 Jerson, 1034 Claudio, 1036 Hermenigildo, 1043 Nector, 1049 Angelo, 1052 José Cabaça, 1056 Emilio, 1063 Teresa, 1065 João Cambando, 2013 Helder, 2017 Osvaldo, 2019 Suamino, 2020 Juliana, 2024 Sousa Tomás, 2026 Mauro, 2029 Géssica, 2043 Esmael, 2044 Paula, 2045 Evalina

**Ignorar:** 1001 Marco, 1038 Márcia, 1046 Leonilde (sem dados reais no PDF)

### Regras de importação
- Inserir com `origem='manual'`, `editada=0`
- Marcações com `*` no PDF = marcações ajustadas manualmente no sistema anterior — importar como estão
- Dias com só entrada OU só saída → importar apenas o que existe
- Fins de semana com marcações → importar (Soyo e Call Centre trabalham)
- Nomes no PDF estão em formato SOBRENOME NOME

### IDs internos a confirmar antes de importar
```bash
mysql -u root -p -e "USE tenant_004_ftl; SELECT id, numero_funcionario, nome_completo FROM funcionarios WHERE numero_funcionario IN ('1004','1014','1021','1033','1034','1036','1043','1049','1052','1056','1063','1065','2013','2017','2019','2020','2024','2026','2029','2043','2044','2045') ORDER BY numero_funcionario;"
```

---

## 15. BACKLOG / PENDENTES

### Operacional (antes de 21/06/2026)
- [ ] Corrigir UIDs com zeros no relógio Call Centre (ecrã táctil): 0024→1024, 0036→1036, 0049→1049, 0065→1065
- [ ] Completar importação de marcações históricas (tarefa acima)
- [ ] Registar excepções de turno para período de férias do Esmael (08/06-07/07) se necessário

### Desenvolvimento futuro
- [ ] Feriados 2027 — em Janeiro: `php bin/precarregar-feriados-moveis.php --ano=2027 --tenant=ftl`
- [ ] UI de gestão de excepções de substituição (quando funcionário A cobre turno de B)
- [ ] Configuração `horas_extra_entrada_antecipada` por tenant — já implementada, só falta UI no menu Configurações
- [ ] Relatório limpo para funcionários sem ambiguidade (marcação errada saída→entrada→saída)
- [ ] P2: Terminais/grupos de segurança
- [ ] P3: Logs de auditoria detalhados

---

## 16. PADRÕES DE CÓDIGO

### PHP — Controller
```php
private function json(int $status, array $data): ResponseInterface
{
    $response = new Response($status);
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
}
```

### PHP — Leitura de configuração
```php
$stmtCfg = $db->query("SELECT valor FROM configuracoes WHERE chave = 'chave_config'");
$rowCfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);
$valor = $rowCfg ? $rowCfg['valor'] : 'default';
```

### PHP — Verificar escala activa
```php
WHERE (fe.data_fim IS NULL OR fe.data_fim >= CURDATE())
// NUNCA usar fe.activo — coluna não existe
```

### JS — Variáveis pré-calculadas para onclick
```javascript
const labelFalta = tipoFalta === 'saida' ? 'Falta de saída' : 'Falta de entrada';
const notaFalta = (m.nota_classificacao||'').split("'").join("&#39;");
// depois usar ${labelFalta} e ${notaFalta} no onclick
```

### Python no servidor — edição de ficheiros
```python
# Usar chr(92) para backslash quando necessário
# Usar índices de linha para alterações precisas
# Sempre verificar com cat -A antes de editar
```

---

## 17. COMANDOS ÚTEIS

```bash
# Verificar sintaxe JS
sed -n '1261,4020p' /var/www/saas/public/app.html | grep -v "^</script>" > /tmp/app_script.js
node --check /tmp/app_script.js 2>&1 | head -5

# Ver logs de erro recentes
tail -20 /var/www/saas/logs/app-$(date +%Y-%m-%d).log | grep -i "error\|exception"

# Verificar marcações de um funcionário
mysql -u root -p -e "USE tenant_004_ftl; SELECT id, tipo, data_hora, origem FROM marcacoes WHERE funcionario_id = X AND DATE(data_hora) = 'YYYY-MM-DD' ORDER BY data_hora;"

# Testar cálculo de turno
php -r "
require '/var/www/saas/vendor/autoload.php';
\$db = new PDO('mysql:host=localhost;dbname=tenant_004_ftl;charset=utf8mb4', 'root', '');
\$service = new \App\Services\EscalaService(\$db);
print_r(\$service->calcularTurnoEm(ID_FUNCIONARIO, 'YYYY-MM-DD'));
"

# Deploy manual (se webhook falhar)
cd /var/www/saas && git pull origin main

# PHPUnit
vendor/bin/phpunit --testsuite Unit
```

---

## 18. TEMA FTL

```css
--bg:       #080e1a;
--s1:       #0c1526;
--s2:       #101d33;
--s3:       #152540;
--border:   #1a2f52;
--accent:   #009CBF;
--blue:     #152F70;
--text:     #e8edf5;
--muted:    #6b7a99;
--muted2:   #4a5568;
```

