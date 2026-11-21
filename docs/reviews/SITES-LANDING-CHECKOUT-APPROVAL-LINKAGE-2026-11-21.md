# Landing Page — fronteira do aceite exato da compra e briefing do mesmo pedido

**21/11/2026 · somente laboratório · NO-GO.** Candidata isolada filha da Sites #569 (HEAD `8172e5cc14e97951e529c843059c214e05c33db5`). Sites #541/#542 são históricos; #569 contém reader PG18 e revisões, não o contrato legal da compra. Não altera as branches de checkout/Portal/OnePage.

## Lacuna constatada a partir das fontes

- Portal #725 `apps/portal/tools/test_owned_customer_to_sites_pg18.php` gera `OwnedPortalContractFixture` com `contract-ownedp0-<order>` e revisão `1` **calculados pelo harness** depois de ler quatro colunas (order_id, product, payment, briefing_available) do PostgreSQL fictício. Isso não prova apresentação, clique/aceite nem revisão/digest da oferta exata do comprador. O leitor não fornece comprador/site exatos ou SHA do briefing.
- Portal #836 é um precedente *separado*, do fluxo Performance V7: registra recibo SQL de apresentação e aceite explícito em fixture, mas não prova navegador nem writer legal autorizado para Landing. Não é seguro reutilizar sua autorização como se fosse da Landing.
- Portal #1019 lê uma HEAD de briefing Landing fictícia e corrente, sem fonte oficial de aprovação/revogação, comprador autenticado ou contrato exato do checkout.
- Sites #464 junta admissão fictícia e render com copy fixa; Sites #569 prova readback privado PG18 em outra fixture. Não existe a mesma autoridade transacional e mesmo pedido do checkout até um GET autenticado para o comprador.

## Incremento desta candidata

`LandingCheckoutApprovalLinkageCandidate` **não conecta as fixtures** nem concede autorização. Exige quatro fontes independentes e correntes (sessão do comprador, pedido, apresentação/aceite contratual exatos e briefing aprovado). Confere empresa, comprador responsável, pedido, site, produto Landing, contrato, revisão, SHA dos termos apresentados/aceitos, SHA/revisão do briefing e aprovação corrente. Faz uma segunda leitura e nega qualquer drift, revogação ou ausência de fonte; nenhum texto do contrato/briefing é exportado. A forma de quatro colunas da #725 deve falhar. O resultado só produz `candidate_correlated_not_authorized`, com `previewAuthorized=false`, `publishAuthorized=false` e `releaseGate=NO_GO`, mesmo num caso sintético positivo. **Duas leituras não são prova de atomicidade, sessão HTTP ou validade legal.** O leitor oficial deve substituir os adapters sintéticos em outra proposta homologada; nenhum provider de produção é criado.

Testes novos só verificam estrutura/correlação e negações: outro comprador da mesma empresa, outra empresa/site, produto OnePage, contrato/revisão errados, digest editado, ausência de aceite explícito, aprovação revogada e mudanças entre leituras. Não repetem a concorrência real da #569 ou admissão/render da #464. Nenhuma ligação ao PSP/checkout/Asaas/HTTP/IA e nenhum CI com segredo.

## Bloqueio e próximo avanço autorizado

1. O Portal deve fornecer para **um único pedido Landing** o recibo de apresentação no navegador e clique expresso do comprador autenticado, com registro durável de contrato exato, revisão e digest; ler o mesmo aceite antes do PSP fictício. Não construir referência de contrato por concatenação.
2. Deve existir escritor oficial transacional e revogável do briefing aprovado e vínculo comprador/empresa/pedido/site. Sem isso, o reader candidato da #1019 permanece somente laboratório.
3. Só então ligar o fluxo a uma sessão GET privada do próprio comprador, confirmar por HTTP SHA dos bytes servidos e rejeitar outro comprador na mesma empresa. Aceite final, revisão editorial e publicação simulada/entrega são etapas distintas. Lead exige POST e leitura independente do destino fictício.
4. Continuar OnePage com sua própria fonte Portal #976 e worker privadamente autorizado, sem compartilhar identidade/API/autoridade. Gates Sites #38 e demais permanecem bloqueados.

**Não realizou:** testes browser, transação PG18 de aceite Landing, HTTP autenticado, publicação/rollback, geração IA, lead, deploy ou chamada OpenAI.
