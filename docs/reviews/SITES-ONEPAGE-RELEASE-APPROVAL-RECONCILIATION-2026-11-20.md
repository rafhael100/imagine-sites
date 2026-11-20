# OnePage — consentimento corrente não equivale a autorização de troca/reversão (laboratório 20/11/2026)

## Por que este recorte não duplica #573/#574

- Sites #573 prova a prévia OnePage e um aceite assinado da release/SHA em pedido fictício, invalidado após retrabalho. Sites #574 comprova bytes HTTP servidos na troca e reversão local (não reproduzido aqui). Ambos são sucessores de Sites #541/#542 históricos, com Portal #976, Portal #1003 e Sites #552 como contratos de referência; Landing Page #569 é outro produto.
- **Lacuna adicional:** #574 considera A "última aprovada" em uma fixture e opera com um PolicyDecision(true) inerte; não exercita se um aceite assinado de A permanece atual depois de B, nem se um aceite novo concede por si só autorização técnica. Este ensaio testa somente essa separação, sem repetir testes de HTTP, parser, paridade S2S ou assinatura adversarial já existentes.

## Código e teste

- Nova classe candidata sem binding de runtime: OnepageReleaseConsentAndPublisherFenceCandidate. Não tem rota, fonte oficial, chave, provider, outbox, DNS ou capacidade de executar publicação.
- Por padrão não consulta sequer o leitor do aceite sem uma fonte técnica independente. Quando ambas estão presentes, exige recibo válido via SignedPortalClientApprovalGate e witness externo corrente; consulta a alçada do publisher separadamente e volta a consultar consentimento e publisher, recusando mudanças observadas. Estas consultas não são transação distribuída nem provam ausência de ABA.
- Ensaio sintético em PHP 8.3/8.4 com uma empresa, um site e duas releases: A aceita, B substitui A com novo digest/revisão/epoch; A perde admissibilidade de rollback; B deixa de ser admitida se o comprador revoga sua prova ou se a alçada técnica muda durante a inspeção. Reconfirmar A ainda não autoriza proposta técnica sem alçada independente; acesso cross-tenant não herda o recibo.
- A função devolve somente PolicyDecision em memória. Não cria aceite real, registro jurídico, revisão editorial, autorização durável, mudança de release, rollback, deploy ou status ENTREGUE. As identidades, a alçada e a assinatura Ed25519 são exclusivamente fixtures; nenhuma comunicação externa ocorre.

## Bloqueio operacional e ação seguinte

Portal #976 ainda não expõe a fonte oficial auditável dos bytes imutáveis e da confirmação de direitos do comprador/revogação do revisor. Portal #1003/Sites #552 S2S não estão homologados; falta ligar o artefato persistido e HTTP privado #486 ao ledger autenticado, publisher autorizado e observador real da release servida. Um CAS/epoch transacional de ponta a ponta é indispensável antes de qualquer binding operacional. O domínio é exigência apenas da fase futura de publicação.

Manter [gate Sites #38](https://github.com/rafhael100/imagine-sites/issues/38) e dez gates operacionais em **NO-GO**. Nada de main/merge/deploy, checkout/Asaas, VPS, Cloudflare/DNS, credenciais, API, Replit/IA ou fornecedor. Esta PR não altera o Portal nem a Landing Page. Conferir a SHA final e o CI antes de atribuir qualquer resultado.
