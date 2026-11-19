# Onepage: handoff privado de revisão comparado ao snapshot armazenado no PostgreSQL 18

**19/11/2026.** Continuação sem merge da [#545](https://github.com/rafhael100/imagine-sites/pull/545), base fixada em `12a581b0733a06dbc8dd4baa0730a600c3f212e8`. Não altera as branches #541–#545 nem o Portal.

## Mudança implementada
O adaptador Laravel `StoredOnepageReviewHandoff` vincula o ponteiro de revisão interna da #545 ao leitor **real** `StoredBriefSnapshotReader` com conexão PostgreSQL de **tenant/role dedicado**. O plano seleciona o snapshot/versão/digest exatos; o leitor retorna os bytes persistidos sob autorização SQL. O adaptador compara manifesto e fonte originais byte a byte **antes** de derivar o ponteiro, executa o renderer canônico de Sites e repete a leitura após a composição para identificar mudanças observadas. Qualquer recusa termina em `ONEPAGE_STORED_REVIEW_DENIED`, sem ecoar conteúdo, identidade do cliente ou detalhe SQL.

O teste opt-in adicional roda **na mesma fixture real do PG18** após o gate #544, contra snapshots já persistidos. Prova replay determinístico, leitor de outro tenant recusado, mudanças de fonte/manifesto mesmo com novo hash, revisionamento/site/tenant divergentes, tentativa de elevar `canPublish` e ausência de mutações na totalidade de linhas/catálogo. CI pinado à branch e SHA desta base, matriz PHP 8.3/8.4 e sem conexão externa de produção.

## Limites
Este adaptador não busca oficialmente um briefing aprovado no Portal: a fixture usa os bytes do produtor de laboratório e sua fonte/revogação sintéticas. Não verifica consentimento, aprovação real, identidade de comprador, pagamento, origem assinada, CAS distribuído, transporte, revisão assinada, publicação efetiva nem aceite final. A dupla leitura não é operação atômica entre Portal e Sites. Nenhuma rota/worker publica ou concede autoridade. Todos os **dez gates seguem BLOCKED**.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO.** Não mexer em main, VPS, Cloudflare, DNS, Asaas, identidade real, credenciais ou recursos de fornecedores.
