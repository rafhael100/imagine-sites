# Imagine Sites P0 — F1-396: duas provas independentes para a versão aprovada

## Integração com o Imagine Portal

O Portal [PR #756](https://github.com/rafhael100/imagine-portal/pull/756) já consegue, **somente em biblioteca e com fontes fictícias de CI**, reunir um atestado comercial vinculado ao pedido e um comprovante editorial vinculado à versão do site.

No Sites, esses comprovantes devem ser verificados de forma independente:
- `IndependentPortalCommercialGateway` (F1-386): assinatura do Portal, condição de liquidação confirmada pelo PSP e revogação de contrato;
- `SignedPortalClientApprovalGate` (F1-391): assinatura do cliente emitida pelo Portal, mesma versão/hash de release e confirmação atual do aceite por testemunha de revogação externa.

Mesmo as duas provas positivas, isoladamente, **não comprovam que o pedido e a versão editorial pertençam à mesma contratação**. A F1-396 define associação imutável, governada pelo próprio Sites: `SiteCommercialReleaseBinding`, com empresa, pedido, contrato/revisão, produto, site/release/digest, revisão editorial e versão de vínculo.

## Novo verificador de evidências

`SiteDualProofEvidenceCheck::verifyEvidence` consulta `SiteCurrentCommercialReleaseBindingSource` para a associação atual; seu adaptador padrão `DenyingSitesCommercialReleaseBindingSource` **sempre nega**. Depois compara todos os campos com o `SiteProjectCommand` e o `ReleaseManifest` informados por autoridade confiável. Somente então delega, separadamente, aos dois verificadores originais existentes. Após ambos positivos, busca novamente a associação, recusando alteração de `bindingRevision` durante a leitura.

Em qualquer erro ou ausência de fonte, retorna um `PolicyDecision(false, razões genéricas)`. Um retorno positivo **significa exclusivamente que a evidência de duas fontes e o vínculo estão coerentes naquele momento**, sem autorização de publicar; o publisher, as políticas de domínio, conteúdo, legal, revisão, concorrência/anti-replay, aceites, IAM/MFA e rollback permanecem gates externos obrigatórios.

## Suíte sintética e restrições

PHP 8.3/8.4 executam o código original dos verificadores F1-386 e F1-391 do próprio Sites; emitem recibos Ed25519 apenas em memória com chaves efêmeras e fontes fictícias, sem PSP, DNS ou APIs. Testam: fonte de vínculo ausente, assinatura inválida, falta de PSP, revogação de contrato ou aceite, troca de empresa, pedido, site, revisão e digest, associação modificada no meio da execução e nova verificação de testemunhas a cada chamada.

Nada é registrado em controller, provider, rotas ou publish. Nenhum artefato da CI deve ser confundido com produção. A conta Asaas Sandbox, a fonte oficial de contratos, o ledger de consentimento real, o trust mTLS, a autoridade de release/tenant e a instalação na Leaseweb ainda exigem homologação operacional.

**DRAFT / NO-GO / NO MERGE / NO DEPLOY / NO BILLING / NO PUBLISH.**
