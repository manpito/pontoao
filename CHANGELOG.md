# CHANGELOG

## [Unreleased]

### Actualização
- As justificações de ausência registadas pelo gestor de RH passam a ficar aprovadas automaticamente, sem necessidade de aprovação posterior. As registadas por supervisores continuam a aguardar aprovação.
- Corrigida a renovação automática da sessão no browser: o pedido de renovação passa a identificar o cliente, deixando de terminar a sessão ao fim de uma hora.
- A sessão passa a ser guardada por cliente, e terminar sessão passa a invalidar a renovação no servidor.
- Corrigida a renovação automática da sessão no servidor: passa a responder com um erro tratado quando o cliente não é identificado, e o token renovado mantém a identificação do funcionário.
- Corrigido um erro em que, ao expirar a sessão, a aplicação ficava num ciclo de pedidos e impedia voltar a entrar. A sessão expirada passa a levar directamente ao ecrã de login.
- O formulário de Justificar Ausência passa a ter pesquisa por número e nome na lista de funcionários, com contador de seleccionados.
- O código de um novo motivo de justificação passa a ser sugerido automaticamente a partir do nome, continuando editável manualmente.
- O formulário de Justificar Ausência passa a permitir seleccionar vários funcionários de uma vez e a mostrar todos os motivos configurados pelo RH, não só os dois originais.
- Avisos de funcionário desconhecido saem do painel de alertas do Dashboard e passam a um relatório próprio, agrupado por relógio e número, com nome e localização do relógio em vez de só o número de série.
- Corrigido erro que impedia o painel de avisos ADMS de carregar no Dashboard desde a sua criação.
- Novo ecrã para o RH criar e gerir os seus próprios motivos de justificação de ausência, com o comportamento (conta como trabalho, falta remunerada ou não remunerada) configurável por motivo.
- Relatório Individual e Assiduidade passam a reconhecer motivos de justificação configuráveis, não só os dois motivos originais.
- Corrigido o cálculo de horas em dias de serviço externo que caem num dia de folga — deixam de contar como 8h de trabalho.
- Justificação de ausência passa a validar que a data de fim não é anterior à data de início, evitando registos que nunca se aplicam a nenhum dia.
- Corrigido perfil 'Colaborador RH' em falta nos tenants criados antes de 27/05/2026 (FTL, KWD, MTM) — criar acesso com esse perfil já não falha.
- Relatórios deixam de alterar marcações ao serem abertos; corte de saída tardia passa a ser só de visualização, consistente entre Período, Individual e Exportação Primavera.
- **Presença e Dashboard**: Presença e Dashboard deixam de mostrar funcionários como ausentes por engano em tenants de grande volume.
- **Visibilidade de Falhas ADMS**: As marcações perdidas devido a terminais de ponto não configurados (SN Desconhecido) ou funcionários não associados no relógio (Funcionário Desconhecido) passam a ser exibidas de forma visível no Dashboard dos RH, resolvendo o problema de perda silenciosa de dados que ocorria no protocolo de sincronização.
