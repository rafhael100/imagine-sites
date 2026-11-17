# Imagine Sites Engine

Motor independente de criação, versionamento e preparação técnica de **Onepages e Landing Pages produzidas e administradas pela Imagine**. O cliente contrata, envia briefing, aprova e acompanha pelo Imagine Portal; a edição interna pertence à equipe Imagine.

## Estado de desenvolvimento

Esta branch contém a aplicação Laravel em `apps/sites`, migrations PostgreSQL18, renderer de HTML inerte, workspace privado de demonstração, ciclo editorial e registro de releases. A sequência de desenvolvimento está em PRs draft; o estado atual e as evidências de cada revisão estão no [STATUS-ATUAL.md](STATUS-ATUAL.md) e no [relatório da PR #490](docs/reviews/SITES-RELEASE-READINESS-2026-11-17.md).

O workspace permite gerar versões imutáveis, consultar fila e histórico, comparar briefings de duas versões e registrar revisão editorial com outra identidade autorizada. Os dados de demonstração permanecem separados dos projetos comerciais. O registro técnico associa o artefato à versão editorial e às referências persistidas de pedido/contrato. O CLI privado prepara um pacote de três arquivos e verifica posteriormente seus bytes e a associação corrente.

Esses componentes não constituem homologação operacional. Contrato, pagamento, identidade entre serviços, revogação, aceite expresso do cliente, backup externo e publicação exigem suas próprias fontes e evidências. Os dez gates em `config/operational-readiness.json` permanecem `BLOCKED`; os padrões de autorização continuam negadores.

## Componentes

| Caminho | Responsabilidade |
| --- | --- |
| `apps/sites` | Aplicação Laravel, workspace interno, migrations e testes da aplicação |
| `src/Production` | Briefing, renderer, auditoria de HTML, versões, fila, histórico e comparação |
| `src/Publication` | Vínculo atual da release, verificação de evidências, preparação e verificação do pacote privado |
| `src/Integration` | Contratos e adaptadores candidatos de integração com o Portal |
| `contracts` | Regras SQL versionadas e fronteiras dos componentes |
| `ops/preview-candidate` | Provisionamento explícito e revisável do workspace privado |
| `ops/release-candidate` | Comandos `prepare` e `verify` do pacote técnico |
| `tests/fixtures` | Materiais inteiramente sintéticos para os ensaios descartáveis de CI |

## Verificação local

O Composer lock da aplicação define as dependências. A suíte geral usa banco desconectado por padrão; os cenários que precisam de PostgreSQL ou de opt-in explícito são executados pelos jobs dedicados, com identidade do banco descartável verificada antes de qualquer escrita.

```sh
composer install --working-dir=apps/sites --no-interaction --no-scripts --no-plugins
php tests/run.php
php tests/private-preview-provisioning-offline.php
php tests/release-preparation-offline.php
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
- [Preparação e verificação de pacotes](ops/release-candidate/README.md)
- [Regras do repositório](AGENTS.md)

Não há merge ou deploy automático. Infraestrutura existente, DNS, Cloudflare, dados reais, credenciais e despesas dependem da autorização correspondente. O código em `src/Domain` continua incluindo regras de laboratório; seus booleanos não substituem identidade ou evidência externa.
