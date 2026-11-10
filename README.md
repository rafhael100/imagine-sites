# Imagine Sites Engine

Motor independente de **Onepages e Landing Pages criadas e administradas pela Imagine**. O cliente contrata, envia briefing, acompanha e aprova expressamente no **Imagine Portal**; não existe editor livre para o cliente.

## Estado atual — 10/11/2026

**Protótipo técnico de laboratório; NÃO é serviço operacional ou publicável.** A fundação inclui Laravel/PHP 8.3+, HTML estático inerte, testes de revisão e design, scripts com PostgreSQL 18 descartável, dossiês privados e um laboratório Docker/Compose isolado. Nada disso comprova autenticação real de cliente, publicação Cloudflare, disponibilidade de infraestrutura, integração CRM ou conformidade legal.

- [PR #132 — consolidação F1-45 até F1-96](https://github.com/rafhael100/imagine-sites/pull/132): proposta atual para revisão técnica independente em `dev`; **sem merge em `dev`/`main`**. Os agregados #129/#127 são históricos anteriores.
- [F1-90 — prévia sem aprovação interna implícita](https://github.com/rafhael100/imagine-sites/pull/121): abrir o preview mantém o rascunho em `internal_review` e apresenta o dossiê pendente.
- [F1-91 — auditoria de dependências Composer](https://github.com/rafhael100/imagine-sites/pull/123): auditoria de advisories do `composer.lock` completo e do grafo operacional, executada no CI sem atualizar bibliotecas.
- [F1-92 — relógio único da autorização Portal](https://github.com/rafhael100/imagine-sites/pull/125): corrige comparação temporal do envelope/revogação, com testes de expiração.
- [F1-93 — primeira migração Laravel PostgreSQL](https://github.com/rafhael100/imagine-sites/pull/126): cria tabelas de projetos/revisões sob RLS FORCE em PG18 efêmero; **somente teste**, requer `APP_ENV=testing` e `SITES_F93_SCHEMA_LAB=1`. A sessão RLS NÃO autentica um cliente.
- [F1-94 — admissão de projetos com idempotência](https://github.com/rafhael100/imagine-sites/pull/128): segunda migração e serviço transacional para registrar projeto + operação, com gateway de autoridade DENY por padrão e conflitos bloqueados.
- [F1-95 — persistência de revisões verificadas](https://github.com/rafhael100/imagine-sites/pull/130): verifica os quatro arquivos do rascunho privado e grava hashes/versões na tabela Laravel `sites_drafts`, com sequência e bloqueio de duplicidade.
- [F1-96 — leitura da revisão vigente](https://github.com/rafhael100/imagine-sites/pull/131): releitura privada de hashes de banco e arquivos, negando versões antigas, arquivos alterados ou autoridade revogada. **37 testes na suíte PG18 PHP8.3/8.4; sem API ou identidade real.**
- [Prontidão operacional](config/operational-readiness.json): **10/10 gates BLOCKED**. Não autoriza merge em produção, consentimento, cobrança, aceite, publicação ou marcação ENTREGUE.

## Recursos disponíveis somente em laboratório

- Geração de HTML inerte para Onepage/Landing, temas Classic/Modern/Warm, auditorias offline e galeria privada.
- Rascunhos com manifesto SHA-256, estado de revisão interna pendente, dossiê editorial e testes de alteração de conteúdo/tema. Um hash **não é uma assinatura ou aprovação humana**.
- PostgreSQL descartável para reproduzir RLS, replay, concorrência, evidência de revisão e rollback de backup com checkpoint externo ao banco de teste.
- Migração Laravel real em ambiente descartável com `sites_projects` e `sites_drafts`, RLS reforçado, chaves compostas e revisão append-only; **não executável em produção** até revisão/homologação da infraestrutura.
- Adição transacional de revisões privadas com leitura verificada da versão mais recente, sem guardar HTML/briefing no banco e **sem endpoint de cliente**.
- Admissão idempotente persistida em `sites_project_admissions` com chave de operação/empresa/site, entrada autenticável apenas por futuro Portal e recusa por padrão; **GUC de tenant não é autenticação**, e o usuário SQL jamais deve ser disponibilizado aos clientes.
- Laravel e Docker/Compose em modo CLI/testing, sem portas públicas; a conta PostgreSQL do app no laboratório é sem SUPERUSER. O ambiente Leaseweb **não foi homologado**.

## Testes no código

Na raiz do repositório, com PHP 8.3+ e extensões aplicáveis:

```bash
php tests/run.php
```

No aplicativo Laravel, com dependências já instaladas, execute `php artisan test --no-ansi`. O CI também executa matrizes PHP 8.3/8.4, Chromium, PostgreSQL 18 e Compose restrito em runner descartável. A F1-91 acrescenta `composer validate` e `composer audit --locked`, sem substituir a revisão independente AppSec.

Para gerar e **reverificar** uma amostra fictícia (exemplo de shell Linux, sempre `APP_ENV=testing`, não use dados reais):

```bash
cd apps/sites
APP_ENV=testing SITES_SYNTHETIC_DRAFT=1 php artisan sites:assemble-draft-fixture onepage classic basic
APP_ENV=testing SITES_SYNTHETIC_VERIFY=1 php artisan sites:verify-draft-fixture onepage classic basic
```

Os comandos trabalham somente em `storage/app/private/` com seletores fixos, não enviam dados nem concedem permissão de publicar. Não usar esses comandos como fluxo de cliente.

## Autoridades e próximos bloqueios

- Portal: contrato, pagamento, briefing, aprovação expressa, evento oficial **ENTREGUE**.
- Sites: proposta editorial, versão técnica, release e comprovação de publicação **a implementar operacionalmente**.
- CRM: captação e confirmação própria de leads **a homologar com armazenamento independente**.
- AI Agents: execução limitada de tarefas aprovadas; nunca autoaprova publicação.

As issues de homologação [#33–#38](https://github.com/rafhael100/imagine-sites/issues) exigem identidade/revogação Portal, banco e backup/restore real, IA controlada, Cloudflare/DNS/TLS, captação CRM, aceite verificável e revisão AppSec/LGPD. `AGI-11-RB01` continua desabilitado. Nenhum deploy, fornecedor pago ou uso de dados de clientes é autorizado pelos testes.

Documentação: [STATUS-ATUAL.md](STATUS-ATUAL.md), [AGENTS.md](AGENTS.md), [condições de homologação](docs/SITES-HOMOLOGATION-ENTRY-2026-11-09.md), [verificação offline F1-86](docs/SITES-F1-86-OFFLINE-DRAFT-VERIFICATION.md).
