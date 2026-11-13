# F1-271 — Alteração de FAQ expansível exige nova revisão

Foi criada uma operação editorial separada para ativar ou desativar o modo de FAQ expansível em uma versão já revisada internamente. Alterações de apresentação não são ajustes normais de texto e **não podem herdar um aceite existente**.

- Exige FAQ pré-existente, estado `awaiting_portal_acceptance`, revisão exata, identidade sintética de papel `imagine_reviewer` e release de destino diferente.
- Recalcula o HTML original e confere conteúdo idêntico, digest SHA-256 e QA de segurança antes de aceitar a proposta.
- Constrói novo `SiteBrief` preservando produto, textos, tema, paleta, SEO, ordem, CTA e todas as demais opções, modificando exclusivamente o booleano editorial de disclosure.
- Produz outro HTML e hash, valida o novo bundle privado e transita a revisão para `drafting` com `artifactDigest=null`.
- Recusa ausência de FAQ, opção idêntica, release idêntica, aprovação antiga falsificada, papel de agente e revisão defasada.
- O resultado nunca aprova cliente, integra CRM ou publica; os roles ainda não são identidades autenticadas.

Testes sintéticos cobrem dois produtos, três estilos e as duas direções de troca, incluindo casos negativos. Os dez gates de produção permanecem `BLOCKED`.