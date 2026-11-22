# OnePage — recibo final vinculado ao HTML exato (somente laboratório)

**22/11/2026 — candidato draft / NO-GO.** Base imutável Sites #572 `3464403f8bb0eede572a2d1558061b9ad5df791e`, que mantém a confirmação do comprador e aprovação do revisor ligadas à mesma versão de briefing. #541/#542 são referências históricas; #603 corrige outro ensaio em cadeia paralela, sem ser copiado ou reexecutado. Landing Page não recebe nem fornece evidência.

## Lacuna específica

A prévia privada já era renderizada e validada por `OnepageBuyerPrivatePreviewCandidate`, mas isso **não prova aceite final sobre os bytes exatos do HTML**. Uma nova redação editorial pode alterar o HTML sem alterar a fonte do briefing nem a declaração de direitos do comprador. O recibo final anterior deve falhar para o artefato novo.

## Código e ensaio

`OnepageExactFinalAcceptanceReadCandidate` é um **leitor sem binding operacional**, com portas nulas por padrão e negação fechada. Reutiliza a prévia existente (renderizador, revisão imutável, comprador, confirmação e revisor), exige HEAD corrente de release revisada e recibo independente de clique explícito com mesma empresa, pedido, site, comprador, release, época, SHA do HTML, revisão de briefing e versão de origem. Relê ambas as fontes depois do render e nega rotação observada. O retorno jamais autoriza publicação.

O teste com dados fictícios utiliza a cópia editorial sintética A, altera os **bytes reais** da headline para a cópia B e recompõe o renderizador existente, manifest/plan e hash do artefato. Recibo final da A deve ser recusado na B. Somente um recibo sintético novo de B permite o retorno de aceite fictício. Nega também outro comprador da mesma empresa, recibo revogado, clique ausente, SHA divergente, versão e época erradas, aprovação de artefato revogada e mudança concorrente de HEAD. A regressão da prévia existente não é contabilizada como nova entrega.

## Limites e bloqueios de autoridade

A fixture **não é** writer oficial do Portal, autenticação operacional, sessão de navegador, clique comprovado no navegador, revisão editorial transacional, aceite legal nem publicação. Cabe ao Portal fornecer fonte autorizada de aceite final e comprador autenticado, com recibo durável da exata apresentação; ao Sites, HEAD oficial aprovada e bloqueio transacional/epoch após mudança. A repetição de duas leituras não é CAS nem impede uma mudança não observada entre leitura e resposta. O leitor não está ligado a rota, painel, daemon ou produção.

Sem IA real ou simulated Responses duplicados; não há modelo, chamada OpenAI, Replit, saída externa, checkout, PSP, CRM, VPS, DNS, Cloudflare, grant, migração, merge ou deploy. Domínio continua requisito apenas de publicação. A integração comercial, a etapa 1 do painel, IN127 e Sites #38 permanecem **NO-GO**.

**CI**: matriz PHP 8.3/8.4 em GitHub runner descartável; confirmar job, HEAD exata e marcador antes de alegar PASS. Não repetir trabalho da Sites #603 ou Landing #1062.
