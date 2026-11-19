# Onepage: readback corrente do ponteiro de revisão privada

**19/11/2026.** Derivado exclusivamente de Sites [#546](https://github.com/rafhael100/imagine-sites/pull/546), SHA 6d0cf764fc86f4aca232360a57a6feb7c47f55e5, sem alterar branches anteriores. `StoredOnepageReviewHandoff::assertCurrent()` primeiro verifica o compromisso e os bytes originais do pacote de revisão, depois reexecuta a composição usando o leitor PostgreSQL 18 de empresa autorizada, com duas leituras do snapshot armazenado. Compara bytes e SHA exatos; falhas são genéricas e sem exposição de SQL, empresa ou fonte.

O teste adicionado à fixture nativa opt-in de PG18 verifica replay, alteração do digest, falsa aprovação com compromisso SHA recalculado, troca de empresa com SHA recalculado, campos de autoridade extras, perda do payload, tipo incorreto, reutilização de release, leitor SQL de outra empresa e fonte alterada. Compara linhas e catálogo intactos a cada grupo. Workflow dedicado PHP 8.3/8.4 herda o cluster PG18/SCRAM/TLS descartável, exportador Portal imutável `89e309b497137cec4960b87f90a49d00bbf92843`, regressões e limpeza sem segredos.

**Limites:** nem o SHA nem o readback são assinatura, testemunha de aprovação, autorização, CAS transacional, aceite ou publicação. A fonte oficial aprovada, o consentimento autenticado, o transporte autenticado e a revisão humana persistida continuam ausentes. O resultado da fixture não equivale à entrega operacional. Os dez gates seguem BLOCKED.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-GO**. Sem main, VPS, Cloudflare, DNS, Asaas, dados reais, pagamento ou novos fornecedores. CI deve ser verificado na HEAD desta PR antes de alegar sucesso.
