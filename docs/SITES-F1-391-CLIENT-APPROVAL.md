# F1-391 — aceite expresso do cliente para a versão exata (candidato criptográfico)

A publicação permanece **NO-GO**. Esta entrega adiciona um verificador reutilizável para um recibo de aceite assinado pelo Portal; **não** cria autenticação de clientes, token, endpoint operacional, assinatura oficial, autorização de publicação ou chamada para Cloudflare.

## Vínculo de versão e autoria

O recibo fictício `sites.portal-client-acceptance.ed25519.v1` deve ser emitido por `imagine-portal` para audiência `imagine-sites`, com campos canônicos ordenados e assinatura Ed25519. Exige empresa, site, release, SHA-256 exato dos bytes do artefato, revisão editorial, referência de aceite, época monotônica e declaração assinada `actorContext=portal_client_authenticated_mfa` / `decision=accepted_explicitly`. Emissor tem validade de até 60 segundos, recebimento não anterior a 120 segundos e horário explícito de aceite. Todas as comparações usam entrada de release pré-existente, não alegações do navegador.

**Uma declaração assinada pelo Portal não basta:** o adaptador `IndependentClientApprovalWitness` deve consultar uma fonte governada separadamente para garantir que o consentimento e a época continuam atuais, não revogados e não substituídos por revisão/retrabalho. A consulta ocorre em toda verificação, sem cache. Erro, ausência de fonte, inconsistência, assinatura inválida e revogação retornam DENY com motivo sanitizado, sem identificação pessoal.

A interface `PortalClientApprovalReceiptSource` e a testemunha **não têm implementações operacionais**. Os testes usam chave efêmera em memória e objetos fictícios; não há interface HTTP, identidade IAM real ou contrato de cliente assinado.

## Onde esta etapa entra

1. Imagine produz versão imutável de HTML e digest, com revisão interna de editor e revisor em F1-385.
2. Portal deve autenticar o titular real com MFA, exibir exatamente a versão/digest, registrar ação expressa e permitir recusa/revogação; tudo isso ainda precisa ser implementado e homologado.
3. Somente um serviço do Portal autorizado e consultável em tempo real pode emitir recibo e testemunha de aceitação atual.
4. O Sites pode verificar a evidência com esta classe candidata **sem converter o resultado em permissão de publicar**. Ainda faltam autorização comercial e PSP, domínio, checklist de privacidade, segurança, outbox, deploy de assets, observação independente e rollback.
5. A autoridade de entrega oficial ao cliente permanece no Portal.

## Evidência e riscos

GitHub Actions executa PHP 8.3 e 8.4 com `SITES_F391_APPROVAL_LAB=1`, chave Ed25519 apenas em memória, recibo/empresa fictícios. Casos positivos verificam assinatura e testemunha a cada consulta. Casos negativos incluem cross-tenant/site/release, digest novo não aceito, revisão antiga, não aceitação, identidade do editor, expiração, alteração não assinada, fonte indisponível, testemunha negativa ou indisponível, campo extra e ordem adulterada. Um gate positivo de aceite não significa que as condições financeiras ou de publicação foram satisfeitas.

**Ainda sem código de produção autenticando titular e sem ligação a `PublicationPreflightService`, Laravel, PostgreSQL operacional, PSP, Cloudflare ou infraestrutura Leaseweb. DRAFT / NO-GO / NO MERGE / NO DEPLOY.**
