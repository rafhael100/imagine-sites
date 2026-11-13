# Imagine Sites — candidato consolidado F1-239 para revisão de integração em dev

**Data de preparação:** 11/11/2026, sem alteração de `dev`, `main`, serviços, DNS ou VPS.

## Baseline fixada

- Base `dev` observada: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`.
- Origem candidata: [PR #274](https://github.com/rafhael100/imagine-sites/pull/274) no SHA `ba5d5edc40f4820179ffc5533ac30b2af8c841f1`.
- Relação com `dev`: **943 commits à frente, 0 atrás**, de acordo com a API de comparação no momento do preparo.
- Comparação de arquivos do GitHub pode ser truncada para 300 arquivos; **não** assumir que a listagem é exaustiva.
- Este commit agrega **por ancestralidade**, não por cherry-pick aproximado, o candidato original #266 e as correções/entregas #267–#274.
- Esta branch só existe como **candidato draft de revisão**; nenhum código foi mesclado em `dev` ou `main` e nenhum pacote foi implantado.

## Cadeia de evidências para revisar

| PR | Entrega | Evidência anterior no head da própria PR | Risco de revisão |
| --- | --- | --- | --- |
| [#266](https://github.com/rafhael100/imagine-sites/pull/266) | Candidato inicial do motor F1-231 | 83/83 workflows | Grande base histórica; revisão de diff e versões |
| [#267](https://github.com/rafhael100/imagine-sites/pull/267) | F1-232: negação de migrations fora do PG lab efetivo | 9/9 | A flag CI não substitui IAM/isolamento real |
| [#268](https://github.com/rafhael100/imagine-sites/pull/268) | F1-233: gate global contra cache de rotas sintéticas | 15/15 | Proteção de runtime, não autenticação de clientes |
| [#269](https://github.com/rafhael100/imagine-sites/pull/269) | F1-234: servidor HTTP PHP `cli-server` com cache compilado | 5/5 | Não equivale a FPM/proxy em produção |
| [#270](https://github.com/rafhael100/imagine-sites/pull/270) | F1-235: consumidor estrito de envelope editorial Portal DEMO | 7/7 | `approved_demo` nunca vira aceite real |
| [#271](https://github.com/rafhael100/imagine-sites/pull/271) | F1-236: produtor PHP real do Portal F48, SHA fixo | 5/5 | Compatibilidade de contrato não comprova identidade |
| [#272](https://github.com/rafhael100/imagine-sites/pull/272) | F1-237: Nginx ↔ PHP-FPM com rotas cacheadas e rede interna | 5/5 | Compose de CI não é blueprint operacional |
| [#273](https://github.com/rafhael100/imagine-sites/pull/273) | F1-238: 6 HTMLs fictícios, manifesto e SHA256SUMS | 6/6 | Artifact de 7 dias; `noindex`, sem captação |
| [#274](https://github.com/rafhael100/imagine-sites/pull/274) | F1-239: 12 PNGs Chromium desktop/mobile | 5/5 | Captura sintética não é aceite ou acessibilidade formal |

Os números são verificações **individuais das versões anteriores**, e não prova de que todos os jobs da **nova branch integrada** passaram. Conferir os workflows do head deste candidato separadamente antes de tratar como apto para integração.

## Grupos de revisão da integração (sem liberar produção)

1. **PHP/Laravel/Composer:** contrato de roteamento, migrações PG18, classes, testes, serviços, locks; executar testes em ambas as versões 8.3/8.4 e container CI.
2. **Segurança:** respostas 404 de `/_lab/sites/v1/*` em FPM, ausência de endpoints de publicação, ausência de dados de clientes, comparação de tenants/epochs/replays, teste de restauração.
3. **Portal ↔ Sites:** comparação independente com a classe do Portal F48 em SHA imutável, separar metadados DEMO do fluxo `CreateProject` autenticado.
4. **Sites ↔ CRM:** vínculo de captação com consentimento, persistência T0, retries até 432h, nenhuma confirmação inventada, sem duplicar lead.
5. **Visual/SEO:** exibir 6 HTMLs navegáveis, conferir 12 screenshots reais, integridade, noindex e proibição de publicação.
6. **Operação:** AppSec/DBA, banco de produção dedicado, backups/restore, identidade Portal, assinatura, alçadas de aprovação, DNS/Cloudflare e custo — **não demonstrados** pelos laboratórios.

## Gates e decisão

Conferir `config/operational-readiness.json`: `status=BLOCKED`, `non_operational=true`, **10 gates operacionais ainda falsos**. Os valores só podem mudar após evidências e autorização independentes. Nenhum CI, hash ou arquivo HTML atribui identidade ou concede liberação de publicação, entregas reais, IA, n8n, acesso a VPS, Cloudflare, domínio, CRM, aceite comercial ou LGPD.

**Saída desta etapa:** PR draft contra `dev` com CI completo e revisão manual pendente, não merge automático. Se `dev` avançar durante a validação, verificar divergência e atualizar o candidato sob revisão, sem `force-push` de terceiros. Não modificar `AGENTS.md` nem estados de `main`.
