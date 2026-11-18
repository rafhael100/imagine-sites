# Imagine Sites Engine

Motor independente de criação, versionamento e preparação técnica de **Onepages e Landing Pages produzidas e administradas pela Imagine**. O cliente contrata, envia briefing, aprova e acompanha pelo Imagine Portal; a edição interna pertence à equipe Imagine.

## Estado de desenvolvimento

Esta branch contém a aplicação Laravel em `apps/sites`, migrations PostgreSQL18, renderer de HTML inerte, workspace privado de demonstração, ciclo editorial e registro de releases. A sequência de desenvolvimento está em PRs draft; o estado atual e as evidências de cada revisão estão no [STATUS-ATUAL.md](STATUS-ATUAL.md) e no [relatório da PR #490](docs/reviews/SITES-RELEASE-READINESS-2026-11-17.md).

O workspace permite gerar versões imutáveis, consultar fila e histórico, buscar projetos por marca ou chamada principal da versão atual, comparar briefings de duas versões e registrar revisão editorial com outra identidade autorizada. Solicitações de ajuste incluem a orientação para o autor, preservada por versão/digest e visível ao consultar o histórico. Os dados de demonstração permanecem separados dos projetos comerciais. O registro técnico associa o artefato à versão editorial e às referências persistidas de pedido/contrato. O CLI privado prepara um pacote de três arquivos e verifica posteriormente seus bytes e a associação corrente. O diário técnico persiste tentativas, ACKs relatados e resultados inconclusivos, sem autorizar envio ou declarar publicação.

O registro privado de artefatos possui plano somente leitura e gravação explícita por hash revisado, usando a API SQL existente. Ele conserva os bytes e a idempotência do registry; a aprovação editorial e a associação da release exigem suas identidades próprias antes de preparar o pacote.

A CLI editorial permite planejar e solicitar uma transição por execução, com revisão esperada, bytes exatos e confirmação explícita do plano. O SQL verifica a função do operador e o estado corrente. O comando não consulta o artefato no registry nem estabelece uma associação; conflitos e respostas perdidas exigem apuração antes de outra decisão, sem retry ou incremento automático da revisão.

A associação e a revogação privadas têm comandos separados e credenciais próprias, com plano somente leitura e controle da revisão esperada. A associação usa o artefato imutável e a aprovação editorial corrente; a revogação preserva o histórico e invalida o vínculo, permitindo que a preparação e a verificação recusem pacotes que deixaram de ser correntes.

A reconciliação privada permite planejar e solicitar a classificação técnica de uma operação conhecida do diário. O plano vincula seletores e runtime; o SQL decide a revisão e o vencimento. Um resultado `inconclusive` pode incluir ACK tardio e não comprova publicação externa. A execução exige confirmação explícita e preserva tentativa e lease, sem reenvio ou reset automático.

Esses componentes não constituem homologação operacional. Contrato, pagamento, identidade entre serviços, revogação, aceite expresso do cliente, backup externo e publicação exigem suas próprias fontes e evidências. Os dez gates em `config/operational-readiness.json` permanecem `BLOCKED`; os padrões de autorização continuam negadores.

## Componentes

| Caminho | Responsabilidade |
| --- | --- |
| `apps/sites` | Aplicação Laravel, workspace interno, migrations e testes da aplicação |
| `src/Production` | Briefing, renderer, auditoria de HTML, versões, fila, histórico e comparação |
| `src/Publication` | Vínculo da release, evidências, pacote privado e diário persistente de tentativas |
| `src/Integration` | Contratos e adaptadores candidatos de integração com o Portal |
| `contracts` | Regras SQL versionadas e fronteiras dos componentes |
| `ops/preview-candidate` | Provisionamento explícito e revisável do workspace privado |
| `ops/release-candidate` | Registro, associação e revogação explícitos, além de `prepare`/`verify` do pacote técnico |
| `ops/editorial-candidate` | Plano e execução explícita de uma transição editorial pela API existente |
| `ops/publication-candidate` | Inspeção somente leitura e reconciliação explícita de operações conhecidas do diário |
| `tests/fixtures` | Materiais inteiramente sintéticos para os ensaios descartáveis de CI |

## Verificação local

O Composer lock da aplicação define as dependências. A suíte geral usa banco desconectado por padrão; os cenários que precisam de PostgreSQL ou de opt-in explícito são executados pelos jobs dedicados, com identidade do banco descartável verificada antes de qualquer escrita.

```sh
composer install --working-dir=apps/sites --no-interaction --no-scripts --no-plugins
php tests/run.php
php tests/private-preview-provisioning-offline.php
php tests/private-preview-upgrade-offline.php
php tests/private-preview-upgrade-cli-offline.php
php tests/release-preparation-offline.php
php tests/release-registration-offline.php
php tests/release-registration-cli-offline.php
php tests/release-binding-offline.php
php tests/release-binding-cli-offline.php
php tests/editorial-transition-offline.php
php tests/editorial-transition-cli-offline.php
python3 tests/fixture-private-material.py
php tests/publication-journal-offline.php
php tests/publication-inspection-offline.php
php tests/publication-inspection-cli-offline.php
php tests/publication-reconciliation-offline.php
php tests/publication-reconciliation-cli-offline.php
php apps/sites/vendor/bin/phpunit -c apps/sites/phpunit.xml --do-not-cache-result
```

Os runners PostgreSQL/HTTP não são comandos de instalação. Eles exigem o contexto explícito de CI e criam recursos próprios descartáveis. Resultados sintéticos não liberam a aplicação de migrations ou grants em um ambiente existente.

## Fronteiras e operação

O Portal controla contratos, aceite expresso e evento oficial `ENTREGUE`; Sites controla artefatos e release técnica; CRM controla a confirmação de leads. A associação de uma release, uma revisão interna ou um pacote exportado não comprova pagamento nem aceite do cliente.

- [Arquitetura e autoridades](docs/SITES-ARQ-02-BOUNDARIES.md)
- [Contratos candidatos Portal ↔ Sites](docs/SITES-CT-01-PORTAL-SITES-v0.1.md)
- [Gates de segurança](docs/SITES-SEC-01-GATES.md)
- [Inventário de migração](docs/SITES-MIG-01-INVENTARIO.md)
- [Provisionamento de prévias](ops/preview-candidate/README.md)
- [Upgrade explícito do preview gerenciado](docs/reviews/SITES-PREVIEW-MANAGED-UPGRADE-2026-11-18.md)
- [Preparação e verificação de pacotes](ops/release-candidate/README.md)
- [Registro privado de artefatos](docs/reviews/SITES-ARTIFACT-REGISTRATION-2026-11-18.md)
- [Associação e revogação de releases](docs/reviews/SITES-RELEASE-BINDING-2026-11-18.md)
- [Transição editorial explícita](docs/reviews/SITES-EDITORIAL-TRANSITION-2026-11-18.md)
- [Manual da CLI editorial](ops/editorial-candidate/README.md)
- [Diário técnico de publicação](contracts/publication-journal/README.md)
- [Inspeção privada do diário](ops/publication-candidate/README.md)
- [Reconciliação explícita do diário](docs/reviews/SITES-PUBLICATION-RECONCILIATION-2026-11-18.md)
- [Próxima integração Portal → briefing → geração](docs/reviews/SITES-NEXT-INTEGRATION-2026-11-18.md)
- [Orientação editorial por versão](docs/reviews/SITES-EDITORIAL-FEEDBACK-2026-11-18.md)
- [Briefing Unicode e validação por campo](docs/reviews/SITES-BRIEF-UNICODE-VALIDATION-2026-11-18.md)
- [Texto literal e comparação de versões](docs/reviews/SITES-INERT-TEXT-COMPARISON-2026-11-18.md)
- [Busca na fila editorial](docs/reviews/SITES-QUEUE-SEARCH-2026-11-18.md)
- [Regras do repositório](AGENTS.md)

Não há merge ou deploy automático. Infraestrutura existente, DNS, Cloudflare, dados reais, credenciais e despesas dependem da autorização correspondente. O código em `src/Domain` continua incluindo regras de laboratório; seus booleanos não substituem identidade ou evidência externa.
