# Imagine Sites — candidato consolidado F1-246 para revisão em dev

**Data:** 11/11/2026. **Estado:** PR draft de integração, sem merge, sem deploy, sem go-live e sem alteração de cliente.

## Origem e versão auditável

- Base `dev` observada: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3` — `protected: false` na API (problema de governança em [issue #281](https://github.com/rafhael100/imagine-sites/issues/281)).
- Origem funcional: [PR #285](https://github.com/rafhael100/imagine-sites/pull/285), SHA `2dc2c0cc8ba8ed9b76061ce70929db21ef668d81`. A comparação da origem contra `dev` apontou **971 commits à frente, zero atrás**.
- O PR draft agregador anterior [#277](https://github.com/rafhael100/imagine-sites/pull/277) passou **87/87 workflows** no próprio head, mas não contém as entregas posteriores.
- A auditoria [F1-240 / PR #278](https://github.com/rafhael100/imagine-sites/pull/278) e os seis pacotes [F1-241 / PR #279](https://github.com/rafhael100/imagine-sites/pull/279) são anteriores às últimas entregas; seus totais de arquivos e revisões não substituem uma nova auditoria do head F1-246.
- A API de diff Github limita a listagem de arquivos a 300: para revisão exaustiva, usar scripts F1-240/F1-241 em checkout completo e inventário ligado à revisão efetiva.

## Avanços acumulados após o candidato F1-239

| Entrega | Código funcional | CI do head próprio |
| --- | --- | --- |
| [#278 — F1-240](https://github.com/rafhael100/imagine-sites/pull/278) | Inventário completo com classificação de risco por caminho Git | 6/6 |
| [#279 — F1-241](https://github.com/rafhael100/imagine-sites/pull/279) | Seis pacotes de revisão de todos os caminhos modificados | 6/6 |
| [#280 — F1-242](https://github.com/rafhael100/imagine-sites/pull/280) | Safety guard examina workflows `.yml` e `.yaml` | 4/4 |
| [#282 — F1-243](https://github.com/rafhael100/imagine-sites/pull/282) | Seis sites navegáveis com serviços, FAQ, etapas, navegação e SEO | 6/6 |
| [#283 — F1-244](https://github.com/rafhael100/imagine-sites/pull/283) | 18 screenshots completos Chromium: desktop, 390px e 320px, links/teclado | 5/5 |
| [#284 — F1-245](https://github.com/rafhael100/imagine-sites/pull/284) | Pipeline real de conteúdo estruturado v4 → draft privado SHA → revisão obrigatória | 6/6 |
| [#285 — F1-246](https://github.com/rafhael100/imagine-sites/pull/285) | Comparação **byte-a-byte** dos manifests e hashes de seis HTMLs em PHP 8.3 vs 8.4 | 5/5 |

**Atenção:** as contagens são somente dos respectivos heads anteriores e **não** demonstram o CI completo deste novo PR agregador. O candidato deve executar novamente os workflows aplicáveis. Não somar contagens de jobs de heads distintos como uma certificação do novo SHA.

## Critérios para análise e decisão posterior

1. Congelar o head de PR e rerodar a suíte global Laravel/PHP, segurança, PG18/RLS, integração com fonte Portal candidata, estáticos, SEO e Chromium, sem ignorar workflows que falharem ou forem interrompidos.
2. Refazer inventário do **diff real completo** e distribuir revisão dos caminhos de risco de workflows, SQL, confiança externa, privacidade, HTML estático, operações e referências de release.
3. Corrigir regressões e receber review independente AppSec, DBA, Portal e CRM com evidência registrada.
4. Confirmar proteção de branches e regras de aprovação obrigatória no GitHub; [issue #281](https://github.com/rafhael100/imagine-sites/issues/281) ainda aberta. Sem uso de credenciais pelo chat ou issue.
5. Se os revisores aprovarem, uma decisão separada de merge na `dev` poderá ser proposta. **Esta etapa não autoriza merge na `main`, migração real, Cloudflare, VPS, DNS ou publicação.**

## Critérios que não podem ser contornados

`config/operational-readiness.json` segue `status=BLOCKED`, `non_operational=true`, dez gates `false`. Persistem: identidades reais do Portal e revogação, banco Sites de produção e backup restore, aceite expresso imutável, IA/n8n homologada, captação física/CRM, validação independente de publicação, segurança/LGPD/custos, DNS/SSL e autorização final de go-live. Referências [SITES-OP01..05 #33–#37](https://github.com/rafhael100/imagine-sites/issues/33), [SITES-GATE #38](https://github.com/rafhael100/imagine-sites/issues/38).

**Todos os dados usados no candidato são sintéticos.** HTMLs e imagens de revisão são armazenados como artifacts temporários do GitHub, não servidos para clientes. `AGI-11-RB01` permanece desativado. `dev` e `main` inalteradas.
