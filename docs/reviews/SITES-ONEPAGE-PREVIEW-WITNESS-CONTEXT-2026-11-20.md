# Onepage — prova humana vinculada ao contrato completo (candidata)

Data: 20/11/2026. Continuação isolada de [Sites #570](https://github.com/rafhael100/imagine-sites/pull/570), na base imutável `c141f0d5603dbffc03ca8c47570969aaa113a73f`.

## Lacuna corrigida

Na candidata de prévia privada Onepage, a HEAD `readCurrentHead` já exigia `contractRef`, `contractRevision` e `productCode=onepage`, mas os **testemunhos de confirmação do comprador e aprovação do revisor** eram confrontados apenas com oito dos onze campos. Uma fonte de testemunhos defeituosa poderia devolver confirmação/aprovação de outro contrato ou produto, mesmo mantendo empresa, pedido, site, versão, revisão e hashes coincidentes.

Agora ambos os testemunhos precisam vincular **todos os onze campos da seleção exata**, inclusive contrato, revisão contratual e produto. Campos ausentes, extras ou diferentes são recusados. O comprador da sessão continua obrigado a ser o responsável exato e a declarar os direitos de uso; o revisor deve ser outra identidade autorizada. Não se substitui o contrato existente de Portal #976, o despacho S2S Portal #1003/Sites #552, parser, plano ou renderer.

## Testes e limites

`php tests/onepage-buyer-private-preview-offline.php` adiciona oito cenários negativos: contrato diferente, revisão contratual obsoleta, produto Landing Page e campo de proveniência ausente, separadamente para comprador e revisor. Mantém os casos positivos de idempotência, prévia sem domínio e ausência de autoridade de aceite/publicação. O workflow dedicado é draft-only, restringe base/HEAD por SHA, executa PHP 8.3 e 8.4 sem segredos ou ações em produção.

A origem oficial **continua não demonstrada** no Portal: D0 sinaliza `briefing_available`, mas não comprova leitura durável dos bytes ricos, revisão imutável, confirmação do comprador responsável, direitos declarados, aprovação/revogação do revisor, CAS transacional e identidade S2S autenticada. Não há serviço, controller, rota, publicação ou adaptador operacional registrados; o construtor padrão nega. Dupla leitura não é atomicidade. Não confundir simulação de aprovação com aprovação humana.

**Próxima ação:** estabelecer e homologar no Portal os dados e testemunhos transacionais oficialmente autorizados, com auditoria, revogação e isolamento por empresa/pedido/comprador; somente então avaliar autenticação S2S e HTTP de prévia privada. Prévia, aceite final e autorização de publicação são etapas distintas; domínio é exigido apenas na publicação.

Landing Page #569 e seus testes PostgreSQL 18 não foram alterados nem reatribuídos à Onepage. Sites #541/#542 são referências históricas. Gate [Sites #38](https://github.com/rafhael100/imagine-sites/issues/38) e os dez gates operacionais permanecem **NO-GO**. Sem merge, main, VPS, Cloudflare/DNS, credenciais, Asaas, checkout, gasto, publicação ou deploy.

Evidência de CI da HEAD desta entrega deve ser anexada à PR apenas depois de verificar runs com SHA correspondente.
