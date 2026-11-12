# Integração Imagine Sites — candidato consolidado F1-38..239

**Data de referência:** 11/11/2026. **Uso:** revisão técnica para a branch `dev`; não é autorização de merge, deploy, release de clientes ou operação em VPS.

## Origem exata

- `dev` na comparação: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`.
- Fonte para revisar: `ba5d5edc40f4820179ffc5533ac30b2af8c841f1` (F1-239, PR #274).
- Comparação inicial `dev...fonte`: **943 commits à frente, 0 atrás**; API `compare` retornou até 300 arquivos, que não representa o universo completo do diff.
- O antigo PR candidato #266 capturou F1-231 e teve **83/83 workflows aprovados**; este novo candidato adiciona F1-232–239, portanto deve passar novamente pelo CI completo.

## Novas entregas desde a revisão inicial

| Etapa | PR | Resultado no respectivo head | Valor concreto |
| --- | --- | --- | --- |
| F1-232 | [#267](https://github.com/rafhael100/imagine-sites/pull/267) | 9/9 verdes | Bloqueio de migração PG18 para banco ou contexto errado |
| F1-233 | [#268](https://github.com/rafhael100/imagine-sites/pull/268) | 15/15 verdes | Middleware global fail-closed para rotas sintéticas em cache |
| F1-234 | [#269](https://github.com/rafhael100/imagine-sites/pull/269) | 5/5 verdes | Prova HTTP `cli-server` com cache real e 404 |
| F1-235 | [#270](https://github.com/rafhael100/imagine-sites/pull/270) | 7/7 verdes | Validador Sites para envelope editorial real do Portal |
| F1-236 | [#271](https://github.com/rafhael100/imagine-sites/pull/271) | 5/5 verdes | Prova de compatibilidade com a classe real do Portal F48 |
| F1-237 | [#272](https://github.com/rafhael100/imagine-sites/pull/272) | 5/5 verdes | Teste Nginx/PHP-FPM 8.4 isolado com cache compilado |
| F1-238 | [#273](https://github.com/rafhael100/imagine-sites/pull/273) | 6/6 verdes | Galeria baixável de seis HTMLs fictícios, checksum e `noindex` |
| F1-239 | [#274](https://github.com/rafhael100/imagine-sites/pull/274) | 5/5 verdes | 12 capturas reais desktop/mobile em Chromium com hashes |

**Atenção:** contagens acima são específicas dos SHAs individuais; ainda não comprovam a aprovação desta nova branch candidata como um todo.

## Revisão em seis frentes

1. **Identidade e contratos:** comparar Portal F48 e CreateProject F133 com fonte exata; não confundir `approved_demo` com aceite de cliente; verificar rotação e revogação independentes.
2. **Persistência e RLS:** PG18, migrations F1-93/94 com restrição de alvo, SQL, TTL/nonce/outbox, anti-restore; nenhum papel privilegiado na aplicação.
3. **Produto visual:** motor Onepage/Landing, HTML inerte, revisão editorial e SEO/social, prova de QA Chromium e previews que não vazam cópia privada.
4. **Runtime HTTP:** camada global F1-233, verificação de cache F1-234, Nginx/FPM F1-237 sem portas públicas. A imagem FPM é **somente CI**, sem TLS, pin de digest do registry ou observabilidade de produção.
5. **Captação e CRM:** nenhum formulário é publicado ou recebe PII nesta fase; T0 432h, armazenamento independente, confirmação final e idempotência dependem de homologação dual.
6. **Pacotes de demonstração:** F1-238/239 distribuem **somente Aurora Exemplo fictícia** em artifacts GitHub com retenção 7 dias; não transformar material de cliente em workflow artifact público.

## Gates impeditivos do go-live

`config/operational-readiness.json`: `status=BLOCKED`, `non_operational=true`, **10/10 gates false**. OP01–OP05 (#33–#37), preparação de ambiente (#38), P0/RF-PEND/RV-CT/LGPD/AppSec permanecem sem evidência operacional independente.

**Não executar automaticamente:** merge para `dev`/`main`, deploy, DNS/Cloudflare, migração operacional, credenciais, custos externos, material de clientes, captação de leads ou rollback AGI-11-RB01. Após revisão humana do diff e CI completo desta branch, a integração à `dev` precisará de um ato de aprovação de integração específico. O Portal segue dono de contrato/aceite/ENTREGUE; Sites de release técnica; CRM da confirmação do lead.
