# Imagine Sites F1-299 — admissão HTTP v3 de laboratório com bytes JSON originais

**13/11/2026 | OP01 #33 · DRAFT · Sem tráfego de produção**

## Problema
Os testes HMAC v3 F1-292/F1-293 exercitavam a classe PHP pura, mas não demonstravam que um futuro adaptador Laravel rejeita JSON ambíguo **antes da conversão normalizadora** realizada por `$request->json()->all()`. É possível que uma integração HTTP erre essa fronteira mesmo com o contrato puro correto.

## Entrega
- Nova rota `POST /_lab/sites/v3/create-project`, registrada exclusivamente no contexto PHP CLI + `APP_ENV=testing` + `SITES_SYNTHETIC_HTTP=1`, e habilitada dentro do handler apenas com `SITES_SYNTHETIC_V3_INTAKE=1` e IP efetivo loopback. Não há rota pública de API.
- A rota só aceita `Content-Type: application/json` e o corpo raw de no máximo 4096 bytes; repassa os bytes intactos a `SyntheticCommercialEpochSignedCommand::verifyCanonicalJson`, com segredo de fixture **público** e epochs de teste explicitamente fixados (3 e 7).
- `ReplayStore` e `PortalProjectAuthorityGateway` negam por padrão. Nenhuma inserção, persistência, rede externa, IAM, criação de projeto, entrega CRM ou publicação. Mesmo após injeções ficcionais em testes, o status HTTP 202 significa exclusivamente **contrato verificado e não executado** (`publishable=false`, `officialClientAcceptance=false`).
- O middleware global `SyntheticLabRouteFence` protege agora tanto `_lab/sites/v1/*` quanto `_lab/sites/v3/*` mesmo quando o cache de rotas sobrevive a uma mudança de ambiente.
- A prova adicional F1-234 coloca ambas as rotas em um cache Laravel feito no runner GitHub e exige HTTP 404 em um processo PHP `cli-server` real (não CLI). A matriz F1-237 faz o mesmo em Nginx + PHP-FPM dentro de uma rede Docker interna, inclusive com a flag ficcional v3 habilitada. Nenhuma dessas verificações estabelece prontidão da VPS/Cloudflare.
- Testes Laravel negativos: reserialização/duplicidade/escapes de JSON, MAC adulterada, replay, downgrade v1/v2, mudança de tenant, epochs obsoletos, cabeçalho e IP indevidos, perda da flag ou mudança para produção. Workflow PHP 8.3/8.4 e suíte Laravel completa revalidam.

## O que falta para a API verdadeira
A entrada operacional exige uma identidade serviço-serviço homologada, chaves de cofre com rotação, escopos de tenant/contrato/vigência em fonte atual do Portal, nonce/idempotência **duráveis**, revogação externa monotônica, autorização humana AppSec, rate limits e observabilidade sem PII. Esta rota de laboratório **não é uma autorização de ativar endpoint real** nem um contrato Portal/Sites homologado.

Sem merge em `dev`/`main`, credenciais, Cloudflare, VPS, DNS, cliente, dado privado, despesas ou alteração dos dez gates `BLOCKED`. Issues #33, #281 e #38 permanecem abertas.
