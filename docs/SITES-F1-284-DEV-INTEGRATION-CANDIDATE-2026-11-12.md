# Imagine Sites — candidato F1-284 para revisão independente em dev

**DRAFT para revisão, 12/11/2026. Sem merge, deploy, client data ou gastos.**

## Escopo integrado

- Base imutável `dev`: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`; `main`: `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.
- Origem `test/sites-f1-284-skip-keyboard-chrome-2026-11-12`: 1.231 commits à frente de dev e 0 atrás antes deste documento.
- `dev/main` ainda reportavam `protected:false`, issue #281 exige administração independente.

| Fase | PR | Escopo |
| --- | --- | --- |
| F1-276 | #325 | Chrome F1-59 com retry de bootstrap estrito |
| F1-277 | #326 | T0 72h/432h sem reset e backoff em memória |
| F1-278 | #327 | HTTP 202/409 nunca comprovam entrega CRM; Retry-After estrito |
| F1-279 | #328 | Retry-After e backoff com deadline original |
| F1-280 | #329 | Mesmo envio preserva empresa, vínculo, site, formulário e CRM |
| F1-281 | #331 | Revalidação de vínculo corrente/revogação no laboratório |
| F1-282 | #332 | Link opcional de teclado “Pular para o conteúdo” |
| F1-283 | #333 | Ativação/desativação exige novo release e revoga aceite anterior |
| F1-284 | #334 | Chromium real: primeiro Tab, foco visível e Enter para `#principal` em três larguras |

## Evidências anteriores

- Candidato F1-280 #330: **110/110 workflows PASS**, F1-240 721 arquivos, 204 sensíveis, seis pacotes F1-241.
- PR #331: F1-281 **9/9 workflows PASS**.
- PR #332: F1-282 **31/31 workflows PASS**.
- PR #333: F1-283 **10/10 workflows PASS**.
- PR #334: workflow Chromium F1-284 **PASS** na própria branch; conferir toda a matriz novamente neste SHA de integração.

## Validação obrigatória do novo SHA

1. Reexecutar todo CI PHP/Laravel/PG18, Docker, Chromium, Cloudflare Worker lab, workflows de segurança e revisão de frontend.
2. Inventariar **todas** as mudanças com F1-240 em diff Git completo, não API compare limitada a 300 arquivos.
3. Gerar seis pacotes F1-241 com SHA exato e revisões independentes AppSec, DBA, Portal/CRM, UX e operações.
4. Confirmar dez gates `BLOCKED` e branch protection `dev/main` antes de qualquer promoção.

## Proibições

Nada de emissão de documento ao cliente, servidor público, CRM real, captação/PII, assinatura de produção, OAuth/IAM operacional, DNS, Cloudflare deploy, VPS ou gasto. Um teste criptográfico ou booleano de laboratório não é aprovação real. O Portal continua autoridade exclusiva pelo aceite e evento ENTREGUE.

**Nunca mesclar automaticamente** esta PR nem liberar operações sobre `main` ou `dev` antes das alçadas independentes.