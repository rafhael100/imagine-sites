# F1-123 — Paridade Portal→Sites com revisão independente de autorização comercial

## Problema e prova

A F1-122 validou a rechecagem da fonte comercial do Portal imediatamente antes de devolver um envelope assinado. Contudo, se um pedido passa de elegível → revogado → elegível **com os mesmos dados do contrato** antes dessa última consulta, uma comparação apenas do conteúdo não identifica o evento (problema ABA).

O Portal [PR #270 / F1-15](https://github.com/rafhael100/imagine-portal/pull/270) introduziu uma fonte **independente** de revisão positiva por pedido: `PortalSiteCommercialEpochSource`, padrão `DenyingPortalSiteCommercialEpochSource`. O emissor fixa a época antes da assinatura, revalida o material da chave e o contrato e exige época comercial idêntica antes de devolver o envelope.

## Prova de interoperabilidade

O workflow `.github/workflows/sites-f1-23-cross-repo-contract.yml` fixa o checkout público do Portal no commit `24081214446da198ea551fb18ee35d6948f3da5d` e executa `tests/f23-cross-repo.php` em PHP 8.3 e 8.4.

Para **Onepage e Landing Page**, o teste preserva a comparação HMAC v2 dos dois repositórios e adiciona:
- uma fonte fictícia de época comercial separada do snapshot de contrato e da revisão da chave de assinatura;
- revogação e restauração do **mesmo** pedido/empresa/revisão, acompanhada de incremento da época; o emissor deve recusar;
- fonte de época comercial indisponível; o emissor deve recusar;
- restauração de época atual válida; o novo envelope é aceito pelo verificador do Sites, mantendo o escopo do pedido e sua HMAC.

As verificações existentes de autenticidade, assinatura, expiração, nonce e revogação durante emissão continuam na mesma suíte.

## Limitações

A época dos testes é uma variável em memória, **não** um log de autorização monotônico confiável ou autenticado. O envelope HMAC v2 atual autentica `keyEpoch` da chave, mas **não inclui uma época comercial autenticada pelo Portal**. O Sites não pode tomar essa prova local de emissão como autorização operacional; precisa consultar uma fonte atual e oficial de permissão de recurso/contrato também imediatamente antes do COMMIT.

Uma dupla consulta não impede revogação depois da última leitura. A homologação real depende de IAM, proteção de fonte de chave/época, mTLS/OAuth, auditoria, aceite de cliente, HTTPS de teste, revisão independente AppSec e infraestrutura autorizada.

Nada foi mesclado ou publicado; sem credenciais reais, PII, VPS, DNS, Cloudflare ou gastos. **10/10 gates operacionais BLOCKED.**
