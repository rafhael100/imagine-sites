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

A fixture de PostgreSQL acrescenta **16 verificações esperadas** de persistência e comparação, incluindo os mesmos campos, bytes, hashes, histórico e isolamento entre empresas. O fluxo HTTP usa os formulários reais, compara duas versões e abre a prévia em sua página própria, preservando seus headers de isolamento. Esses ensaios de banco e navegador permanecem pendentes de CI nesta revisão local.

O workflow dedicado fixa repositório, branch, draft e SHA da base. Executa PHP 8.3/8.4 com PostgreSQL 18 descartável e o fluxo de navegador via HTTPS/FPM/Nginx. Sintaxe YAML e seus 12 blocos shell passaram, assim como o safety guard aplicado sem alterações aos arquivos de fonte. PostgreSQL local não foi iniciado.

## Continuidade

Esta correção resulta da revisão de caminhos existentes e não depende de um briefing oficial novo. O contrato Portal → briefing → geração ainda precisa das definições registradas em [Próxima integração central](SITES-NEXT-INTEGRATION-2026-11-18.md). Isso não impede correções ou interfaces candidatas que já tenham contrato e consumidor concretos.

O workspace conserva `demonstration_only`. Não há migration, alteração de grants, chamada externa, mudança no staging ou autorização de publicação.
