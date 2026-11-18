# Imagine Sites — comparação de versões com texto literal

## Defeito reproduzido

O workspace aceita texto simples nos campos do briefing e o renderer escapa esse conteúdo antes de inseri-lo no HTML. Uma descrição como `Visite https://example.invalid para conhecer a demonstração.` ou `Uma instrução fictícia: onboarding=aberto.` é uma entrada válida. Ainda assim, a auditoria consultada pela comparação de versões tratava esses trechos como endereço ativo ou atributo de evento.

A reprodução local usou o parser da versão persistida: o controle simples passou, enquanto a URL literal e a expressão dentro de `meta description` foram recusadas. A comparação, portanto, podia rejeitar versões que o próprio workspace havia criado corretamente. O pacote sintético também passava pela mesma auditoria.

Branch `fix/sites-inert-text-audit-2026-11-18`, sobre a PR #496 / `1219c49272b8df387aef7b7e12de4a812d1284c5`.

## Correção e limites

O auditor passa a distinguir texto escapado de contextos que podem executar ações ou carregar recursos. As verificações de atributos e protocolos continuam examinando as tags e o CSS. Somente o valor da descrição em uma tag `meta` com a forma canônica do renderer é tratado como texto. Os demais metadados continuam sujeitos à validação exata, incluindo CSP e robots.

Não há normalização, regravação ou nova renderização do artefato. O HTML original continua sendo usado para o digest e para todas as verificações de estrutura e integridade. Os textos podem conter URLs ou exemplos como `onclick=`, mas isso não cria um link, evento ou carregamento externo.

Tags desconhecidas, scripts, formulários, atributos de evento, destinos externos em links, CSS com carregamento de recursos, metadados alterados e markup malformado continuam recusados. A auditoria continua limitada ao dialeto fixo de demonstração; não é um sanitizador HTML genérico. A política própria de `StructuredCopyCandidate` continua separada e inalterada.

## Verificação

A suíte offline passou **115 verificações do auditor F27 e 20 do pacote F24**. São 30 casos de texto literal nos cinco campos e dois produtos, além de oito novas mutações ativas verificadas na auditoria e no pacote com digest recalculado. As mutações anteriores continuam recusadas. Regressões aprovadas: renderer (27), parser de saída estruturada (26), handoff (28), preparação (50), domínio (32) e PHPUnit geral (**134 testes, 1186 assertions e 27 skips explícitos**).

A fixture de PostgreSQL passou **16 verificações adicionais** de persistência e comparação, incluindo os mesmos campos, bytes, hashes, histórico e isolamento entre empresas. O total do workspace passou para **386 checks**. O fluxo HTTP usa os formulários reais, compara duas versões e abre a prévia em sua página própria, preservando seus headers de isolamento: **273 checks aprovados, incluindo 98 novos**.

O workflow dedicado fixa repositório, branch, draft e SHA da base. Executa PHP 8.3/8.4 com PostgreSQL 18 descartável e o fluxo de navegador via HTTPS/FPM/Nginx. Sintaxe YAML e seus 12 blocos shell passaram, assim como o safety guard aplicado sem alterações aos arquivos de fonte. PostgreSQL local não foi iniciado.

**HEAD verificada: `fb97fa465d162c23fa1ced8d1e0092ecc30798b7`, com 15/15 check-runs SUCCESS na conferência.** O [run 44223033260](https://github.com/rafhael100/imagine-sites/actions/runs/44223033260) aprovou [PHP 8.3](https://github.com/rafhael100/imagine-sites/actions/runs/44223033260/job/134529348569), [PHP 8.4](https://github.com/rafhael100/imagine-sites/actions/runs/44223033260/job/134529349299) e o [navegador](https://github.com/rafhael100/imagine-sites/actions/runs/44223033260/job/134529349035). Além dos números acima, o registry persistente passou 402 verificações. Os 27 skips da suíte geral são explícitos; os contratos dedicados de banco executaram seus cenários.

O primeiro ensaio já passava no SQL, mas a fixture do navegador exigia um único valor nos headers de proteção que Laravel e Nginx emitem. A correção da fixture usa a [API de valores completos/repetidos do Playwright](https://playwright.dev/docs/api/class-response#response-header-values) e exige que todos os valores sejam os esperados. Valores conflitantes continuam recusados. Os headers do produto não foram modificados.

Artifact `14390993429`, com 17 arquivos e SHA-256 conferido `890c4da894eaabfab6c863f92f74d6e54111d5fd78a5f80b071e3f2b7f6bfd4d`. As capturas `inert-text-comparison-desktop.png` e `inert-text-comparison-mobile.png` foram revisadas: conteúdo literal legível, campos alterados identificados e ausência de corte/overflow. A inspeção das páginas reais confirmou zero tentativas externas, os metadados exatos e apenas as âncoras internas esperadas.

## Continuidade

Esta correção resulta da revisão de caminhos existentes e não depende de um briefing oficial novo. O contrato Portal → briefing → geração ainda precisa das definições registradas em [Próxima integração central](SITES-NEXT-INTEGRATION-2026-11-18.md). Isso não impede correções ou interfaces candidatas que já tenham contrato e consumidor concretos.

O workspace conserva `demonstration_only`. Não há migration, alteração de grants, chamada externa, mudança no staging ou autorização de publicação.
