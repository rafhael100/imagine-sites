# OnePage R13 — validade do histórico inteiro antes de resposta idempotente (NO-GO)

## Evidência de preflight — 22/11/2026
- Sites [#616](https://github.com/rafhael100/imagine-sites/pull/616) HEAD fixa `eba892bb745002f81a97fe4ced1ce669615b1714`, com workflows exatamente dessa SHA concluídos SUCCESS: 45037784023, 45037784040, 45037784022 e 45037784027. Busca de PRs e branches não identificou sucessor OnePage R13. A branch R13 foi criada exclusivamente a partir desse commit.
- Sites #605 (OnePage PG18) e Sites #580 / Portal #1088 (Landing) têm escritores próprios e permanecem intocados. Agents #490 é boundary simulado sem tráfego. Sites #541/#542 são referências históricas.
- Portal [#976](https://github.com/rafhael100/imagine-portal/pull/976) define `ApprovedOnepageBriefSnapshotSource` e `CurrentOnepageBriefApprovalWitness`, ambos **default-deny**, sem fonte operacional oficial de briefing nem confirmação/approval da mesma revisão. Portal #1003 / Sites #552 não conferem autoridade.

## Defeito reproduzível na HEAD de #616
No router sintético da R12, o laço `foreach ($previous as $line)` passou a verificar formato canônico e referências duplicadas. Entretanto, ao encontrar `$other['ref'] === $receipt['ref']`, respondia HTTP **200/409 dentro do laço**, sem inspecionar registros posteriores. Logo, um aceite idempotente da release E podia resultar em HTTP 200 mesmo havendo linha de auditoria posterior corrompida, contradizendo a exigência R12 de validar a história inteira.

## Correção pequena e ensaio novo
- Guardar o `matchingReceipt` enquanto percorre **todas** as linhas sob o lock exclusivo R11. Só responder 200/409 **depois** da validação de todas as linhas e da unicidade. Histórico inválido continua com HTTP 503 sem append ou efeito.
- Prova Chromium com origem HTTP loopback: após 12 POSTs concorrentes e exatamente um recibo E íntegro, inserir linha truncada **depois** do recibo E e clicar novamente na mesma sessão observada. Esperado: HTTP 503 e bytes do ledger inalterados; então restaurar os bytes originais e confirmar que os quatro recibos válidos permanecem. Na R12 anterior, esse clique daria 200.
- Workflow específico somente draft/push na branch isolada, checkout da SHA exata, comparação com base `eba892bb745002f81a97fe4ced1ce669615b1714`, quatro arquivos exatos, sintaxe PHP/Node e navegador real. Não duplicar outro mock OnePage Agents.

## Separações e gates
Este é reparo do *harness* sintético, não transação autorizada nem evidência de aceite no Portal. Prévia, aceite final e publicação continuam etapas distintas; não há fonte oficial, identidade real, aprovação/revogação autêntica, transporte worker OnePage atestado ou API OpenAI live. Landing, painel VPS, CRM, Cloudflare, DNS, checkout e Asaas intocados. **Draft / NO-MERGE / NO-DEPLOY / NO-GO Sites #38**.

**Próximo passo útil:** identificar no Portal produtor operacional imutável de briefing/declaração de direitos/revisão aprovada e homologar sua origem, ou atestar gateway privado, modelo, identidade/egress e credencial específica do worker OnePage antes de qualquer chamada API real. Não inventar fonte nem publicar fixture como prova oficial.
