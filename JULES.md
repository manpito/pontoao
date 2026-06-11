# PontoAO — Briefing para Jules

## Visão Geral do Projecto

PontoAO é um sistema SaaS multi-tenant de controlo de assiduidade para empresas angolanas. Cada empresa (tenant) tem a sua própria base de dados MySQL isolada. O sistema integra com relógios biométricos ZKTeco via protocolo ADMS.

## Stack Técnica

- **Backend:** PHP 8.3 + Slim 4 (micro-framework REST)
- **Frontend:** HTML/CSS/JavaScript vanilla (sem framework)
- **Base de dados:** MySQL 8.0
- **Servidor:** Nginx + PHP-FPM no Hetzner (Ubuntu 24.04)
- **Autenticação:** JWT (firebase/php-jwt)
- **Autoloader:** Composer PSR-4

## Estrutura do Projecto

```
app/
  Config/
    App.php              — Bootstrap da aplicação Slim
    Database.php         — Conexões PDO (master + tenants)
    TenantResolver.php   — Resolve o tenant pelo header X-Tenant ou subdomínio
  Controllers/
    Admin/
      SuperAdminController.php
      TenantController.php
    Auth/
      AuthController.php
      SuperAdminAuthController.php
    ConfiguracaoController.php
    DepartamentoController.php
    ExportacaoController.php
    FeriadoController.php
    FeriasController.php
    FuncionarioController.php
    HorarioController.php
    ImportacaoController.php
    MarcacaoController.php
    NotificacaoController.php
    PeriodoController.php
    RelatorioController.php
    RotacaoController.php
    ZkBridgeController.php
  Middleware/
    AuthMiddleware.php         — Valida JWT e perfil do utilizador
    RateLimitMiddleware.php
    SuperAdminMiddleware.php
    TenantMiddleware.php
    ZkBridgeAuthMiddleware.php
  Services/
    AuthService.php
    TenantManager.php
config/
  routes.php             — Todas as rotas da API
  app.php                — Configuração Slim (legacy, não usado)
  database.php           — Configuração DB (legacy, não usado)
database/
  migrations/
    master/001_master_schema.sql
    tenant/001_tenant_schema.sql
    tenant/002_rotacoes.sql
    tenant/003_smtp_config.sql
  seeders/
    holidays_angola.php
public/
  index.php              — Entry point (Slim bootstrap)
  admin.html             — Painel super-admin
  app.html               — Painel RH (gestor + supervisor)
  ponto.html             — Portal do funcionário (marcação de ponto)
```

## Perfis de Utilizador

### Tabela `utilizadores` (por tenant)
- `super_admin_tenant` — administrador da empresa, acesso total
- `rh_manager` — gestor RH, acesso quase total
- `rh_colaborador` — colaborador RH, alterações mensais sem dados sensíveis  *(A IMPLEMENTAR)*
- `supervisor` — aprovações da sua equipa, visualização limitada
- `funcionario` — apenas `ponto.html`, sem acesso ao `app.html`

### Tabela `super_admins` (master DB)
- `super_admin` — proprietário do SaaS, acesso total ao painel `admin.html`

## Como Funciona o Multi-Tenant

O tenant é identificado pelo header `X-Tenant` (ex: `ftl`) ou pelo subdomínio (ex: `ftl.rh.ftl-angola.net`). O `TenantResolver` resolve o subdomínio e o `Database::tenant($sub)` abre a conexão à DB correcta.

Cada tenant tem a sua própria base de dados MySQL — ex: `tenant_004_ftl`.

## Autenticação

O `AuthMiddleware` valida o JWT e verifica o perfil. O método `AuthMiddleware::role(['perfil1', 'perfil2'])` cria um middleware que só permite os perfis listados.

Exemplo de uso nas rotas:
```php
$group->get('/funcionarios', [FuncionarioController::class, 'index'])
      ->add(AuthMiddleware::role(['super_admin_tenant', 'rh_manager', 'rh_colaborador']));
```

## URLs de Produção

- **Servidor:** `https://rh.ftl-angola.net`
- **Painel super-admin:** `https://rh.ftl-angola.net/admin.html`
- **Painel RH:** `https://rh.ftl-angola.net/app.html?tenant=ftl`
- **Portal funcionário:** `https://rh.ftl-angola.net/ponto.html?tenant=ftl`
- **API:** `https://rh.ftl-angola.net/api/...`

## Deploy Automático

Qualquer commit na branch `main` é automaticamente deployed para o servidor via webhook. O servidor corre `/var/www/saas/deploy.sh` que faz `git pull origin main`.

**IMPORTANTE:** O `vendor/` está no `.gitignore` — não commitar. O servidor já tem o `vendor/` instalado.

---

## TAREFAS PRIORITÁRIAS

### P1 — Restrições de Menu por Perfil no `app.html`

**Contexto:** O `app.html` actualmente mostra o mesmo menu para todos os perfis que conseguem entrar (`super_admin_tenant`, `rh_manager`, `supervisor`). É preciso restringir o menu e as acções por perfil.

**Novo perfil a adicionar:** `rh_colaborador`

**Matriz de permissões:**

| Secção | super_admin_tenant | rh_manager | rh_colaborador | supervisor |
|--------|-------------------|------------|----------------|------------|
| Dashboard | ✅ | ✅ | ✅ | ✅ |
| Presenças hoje | ✅ | ✅ | ✅ | ✅ (só equipa) |
| Marcações | ✅ | ✅ | ✅ | ✅ (só equipa) |
| Funcionários (ver) | ✅ | ✅ | ✅ | ❌ |
| Funcionários (editar/criar) | ✅ | ✅ | ❌ | ❌ |
| Funcionários (salários) | ✅ | ✅ | ❌ | ❌ |
| Acesso Portal (🔑) | ✅ | ✅ | ❌ | ❌ |
| Férias (aprovar) | ✅ | ✅ | ❌ | ✅ (só equipa) |
| Departamentos | ✅ | ✅ | ❌ | ❌ |
| Horários | ✅ | ✅ | ✅ | ❌ |
| Relatórios | ✅ | ✅ | ✅ | ❌ |
| Exportação | ✅ | ✅ | ✅ | ❌ |
| Importação | ✅ | ✅ | ❌ | ❌ |
| Rotações | ✅ | ✅ | ✅ | ❌ |
| Períodos mensais | ✅ | ✅ | ✅ | ❌ |
| Configurações | ✅ | ❌ | ❌ | ❌ |
| Terminais (relógios) | ✅ | ❌ | ❌ | ❌ |

**Implementação no `app.html`:**

1. Após o login, o `USER.perfil` está disponível na variável `USER`
2. Usar `USER.perfil` para mostrar/esconder items do menu sidebar
3. Usar `USER.perfil` para mostrar/esconder botões de acção dentro de cada secção (ex: botão "Novo funcionário", campo de vencimento, botão 🔑)
4. Para `supervisor`, filtrar os dados para mostrar apenas a sua equipa (funcionários com `supervisor_id = USER.funcionario_id`)

**No backend (`AuthController.php` e `AuthMiddleware.php`):**
- Adicionar `rh_colaborador` como perfil válido para login no `app.html`
- Actualizar as rotas em `routes.php` para incluir `rh_colaborador` onde aplicável

**No `FuncionarioController.php`:**
- O `rh_colaborador` pode ver funcionários mas não pode ver `vencimento_base_aoa` — filtrar esse campo na resposta quando o perfil for `rh_colaborador`

---

### P2 — Gestão de Terminais (Relógios ZKTeco)

**Contexto:** Os relógios ZKTeco estão registados na tabela `relogios` de cada tenant. Actualmente o registo é feito directamente na DB. É preciso uma interface no `app.html` para gerir os relógios.

**Funcionalidades necessárias:**

**2.1 — CRUD de Relógios**
- Listar relógios com estado (activo/inactivo), último sync, IP, localização
- Criar novo relógio (nome, localização, IP, porta, modelo)
- Editar relógio existente
- Activar/desactivar relógio

**2.2 — Grupos de Segurança**
- Criar grupos (ex: "Administração", "Segurança", "Armazém")
- Associar relógios a grupos
- Associar funcionários a grupos
- Um funcionário só pode marcar nos relógios do seu grupo
- Se não tiver grupo, pode marcar em qualquer relógio (comportamento actual)

**Tabelas a criar na migration tenant:**
```sql
CREATE TABLE `grupos_terminais` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(80) NOT NULL,
    `descricao` TEXT NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `grupo_terminal_relogios` (
    `grupo_id` INT UNSIGNED NOT NULL,
    `relogio_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`grupo_id`, `relogio_id`),
    FOREIGN KEY (`grupo_id`) REFERENCES `grupos_terminais`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`relogio_id`) REFERENCES `relogios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `grupo_terminal_funcionarios` (
    `grupo_id` INT UNSIGNED NOT NULL,
    `funcionario_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`grupo_id`, `funcionario_id`),
    FOREIGN KEY (`grupo_id`) REFERENCES `grupos_terminais`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

**Novo controller:** `TerminalController.php`
- `GET /api/terminais` — listar relógios
- `POST /api/terminais` — criar relógio
- `PUT /api/terminais/{id}` — editar relógio
- `PUT /api/terminais/{id}/toggle` — activar/desactivar
- `GET /api/terminais/grupos` — listar grupos
- `POST /api/terminais/grupos` — criar grupo
- `PUT /api/terminais/grupos/{id}` — editar grupo
- `DELETE /api/terminais/grupos/{id}` — eliminar grupo
- `POST /api/terminais/grupos/{id}/relogios` — associar relógio ao grupo
- `DELETE /api/terminais/grupos/{id}/relogios/{relogio_id}` — remover relógio do grupo
- `POST /api/terminais/grupos/{id}/funcionarios` — associar funcionário ao grupo
- `DELETE /api/terminais/grupos/{id}/funcionarios/{func_id}` — remover funcionário do grupo

**No `ZkBridgeController.php`:**
Quando chega uma marcação, verificar se o funcionário pertence a um grupo e se o relógio pertence ao mesmo grupo. Se o funcionário tiver grupo e o relógio não pertencer a esse grupo, rejeitar a marcação com log.

**No `app.html`:**
- Adicionar secção "Terminais" no menu (só `super_admin_tenant`)
- Interface para gerir relógios e grupos

---

### P3 — Logs de Segurança

**Contexto:** O sistema precisa de três tipos de logs para auditoria e segurança.

**3.1 — Log de Actividades por Utilizador**

Tabela já existe: `log_auditoria` (por tenant). Verificar se está a ser usada e completar o registo de actividades em todos os controllers que fazem alterações (criar, editar, eliminar).

Campos importantes: `utilizador_id`, `accao`, `tabela_afectada`, `registo_id`, `dados_antes` (JSON), `dados_depois` (JSON), `ip`, `user_agent`, `criado_em`.

**Interface no `app.html`:**
- Secção "Logs de Actividade" acessível a `super_admin_tenant` e `rh_manager`
- Filtrar por utilizador, data, tipo de acção
- Exportar para CSV

**3.2 — Log de Alertas do Sistema**

Tabela a criar:
```sql
CREATE TABLE `log_sistema` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nivel` ENUM('info','aviso','erro','critico') NOT NULL DEFAULT 'info',
    `componente` VARCHAR(60) NOT NULL,
    `mensagem` TEXT NOT NULL,
    `contexto` JSON NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_nivel` (`nivel`),
    INDEX `idx_criado_em` (`criado_em`)
) ENGINE=InnoDB;
```

Registar automaticamente: falhas de conexão à DB, erros nos relógios ZKTeco, falhas de envio de email, marcações rejeitadas por grupo de segurança.

**3.3 — Log de Tentativas Não Autorizadas**

Tabela a criar:
```sql
CREATE TABLE `log_acessos` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tipo` ENUM('login_falhou','token_invalido','perfil_insuficiente','ip_bloqueado','relogio_nao_autorizado') NOT NULL,
    `email` VARCHAR(150) NULL,
    `ip` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `detalhes` JSON NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_tipo` (`tipo`),
    INDEX `idx_ip` (`ip`),
    INDEX `idx_criado_em` (`criado_em`)
) ENGINE=InnoDB;
```

Registar automaticamente: login falhado, token JWT inválido ou expirado, acesso a rota sem permissão, marcação rejeitada por relógio não autorizado.

**Interface no `app.html`:**
- Secção "Segurança" acessível apenas a `super_admin_tenant`
- Ver logs de acessos com filtros por tipo, IP, data
- Alertas visuais para múltiplas tentativas do mesmo IP

---

## Notas Importantes para o Jules

1. **Nunca commitar o `.env`** — está no `.gitignore` e contém credenciais de produção.

2. **O `vendor/` não vai para o Git** — está no `.gitignore`. O servidor já tem o `vendor/` instalado localmente.

3. **Testar sempre a sintaxe PHP** antes de commitar — um erro de sintaxe deita abaixo toda a aplicação.

4. **O deploy é automático** — qualquer commit na `main` vai para produção imediatamente. Usar branches para desenvolvimento e só fazer merge na `main` quando estiver testado.

5. **Variáveis de ambiente** — não hardcodar credenciais. Usar `$_ENV['VAR_NAME']`.

6. **Compatibilidade PHP 8.3** — o servidor corre PHP 8.3. Usar features modernas à vontade (match, named args, readonly properties, etc.).

7. **Padrão dos controllers** — todos os controllers têm um método privado `json(int $status, array $data): ResponseInterface` para retornar JSON. Seguir o mesmo padrão.

8. **Padrão de auditoria** — os controllers que fazem alterações chamam `$this->auditoria($db, $request, 'accao', 'tabela', $id, $antes, $depois)`. Manter este padrão.

9. **Tabelas novas** — quando criares tabelas novas, adicionar também ao ficheiro `database/migrations/tenant/001_tenant_schema.sql` para que novos tenants criados no futuro também as tenham.

10. **O `app.html` tem 1874+ linhas** — é um ficheiro grande com toda a lógica frontend. A variável `USER` contém os dados do utilizador autenticado após o login, incluindo `USER.perfil`.
11. **Tags HTML dentro de template literals JS** — ao gerar HTML dinâmico com `window.open()`, `innerHTML`, ou qualquer template literal que contenha HTML completo, as tags `<style>`, `</style>`, `<script>`, `</script>` DEVEM ser escapadas para evitar que o browser as interprete durante o parse do HTML principal, quebrando toda a aplicação. Usar obrigatoriamente a seguinte notação:
    - `<style>`   → `${'<'}style>`
    - `</style>`  → `${'</'}style>`
    - `<script>`  → `${'<'}script>`
    - `</script>` → `${'</'}script>`
