# OnePage R14 — enquadramento físico estrito do ledger sintético (NO-GO)

## Pré-verificação em 22/11/2026
- Sites [#617](https://github.com/rafhael100/imagine-sites/pull/617), HEAD imutável `a39250efd50f5abd146b89f3ab145c4eba8d2256`, workflows da mesma SHA 45042682490 / 45042682554 / 45042682496 / 45042682551: todos SUCCESS antes da escrita; não existiam PR ou branch sucessores R14.
- Sites #605 (PG18), Sites #580 / Portal #1088 (Landing) e Agents #490 permanecem intocados; Sites #541/#542 são apenas históricos. Portal [#976](https://github.com/rafhael100/imagine-portal/pull/976) permanece default-deny, sem origem oficial de aprovação.
- Separação OnePage/Landing e ausência de merge, deploy, servidor externo ou permissão operacional.

## Defeito específico reproduzível
Na R13 a leitura de `receipts.jsonl` usava `FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES`: eliminava silenciosamente registros vazios e aceitava JSON válido sem LF final. O replay de recibo existente retornaria HTTP 200 mesmo com histórico fisicamente adulterado, e uma próxima gravação poderia concatenar dois registros.

## Alteração e ensaio
A leitura sob lock exclusivo agora usa os bytes íntegros, recusa final sem LF e qualquer linha vazia com 503, depois valida todos os registros (R12/R13) antes de responder. Dois casos novos Chromium: (1) remover apenas LF final do recibo E; (2) inserir linha vazia no histórico. Cada replay deve responder HTTP 503 sem alterar bytes; o arquivo original é restaurado em finally e conferido. CI própria seleciona SHA exata, quatro arquivos e Chromium isolado; não modifica o contrato nem a regra operacional.

**Fronteiras:** apenas fixture de teste, sem autenticador Portal, aceite jurídico, fonte imutável oficial, declaração de direitos, testemunha de revisor, worker OpenAI provisionado ou preview externo. Sites #38 **NO-GO**. Próxima dependência: origem oficial Portal #976 ou gateway privado do worker OnePage (identidade, modelo, egress e credencial específica) antes de API real.
