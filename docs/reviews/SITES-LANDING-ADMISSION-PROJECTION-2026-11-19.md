# Sites ↔ Portal — projeção de admissão Landing Page (draft)

Este incremento sucede Sites #553 e Portal #1008, derivados de #541/#542, em branch independente. Usa Portal no SHA imutável b5a4bfc10ef18f0c661eb69d04a4b2d3bac83661.

O parser PortalLandingPageAdmissionProjectionCandidate valida JSON canônico de seis campos, tenant/pedido/site esperados, contrato/revisão esperados explicitamente por um leitor local independente (nunca retirados da resposta recebida) e produto landing_page. Esta exigência foi adicionada após um teste adversarial detectar que uma troca de contractRef passaria na versão inicial. Recusa claims extras, JSON duplicado ou reordenado, números não inteiros, tenant externo e produto Onepage. O teste de integração usa o encoder Sites verdadeiro e a admissão Portal verdadeira com fontes sintéticas para verificar paridade e regressão. Nenhum dado real é consultado.

**A resposta NÃO é aprovação de briefing nem autorização:** não contém conteúdo/versionamento real, assinatura do cliente, testemunho de revogação, pagamento, publicação ou aceite. O peer mTLS é sintético e as leituras do Portal não são transacionais. Falta origem oficial aprovada/versionada e auditoria, ACL real por tenant/site, transporte seguro, revisão formal, renderer Landing Page, publicação técnica homologada e aceite. Nenhuma flag de habilitação foi acrescentada; os dez gates comerciais/operacionais permanecem bloqueados.

Execução CI limitada a PR draft, sem merge, deploy, modificação de main, VPS, Cloudflare, DNS, produção, Asaas, credenciais ou despesas. Identidade visual Imagine permanece inalterada.
