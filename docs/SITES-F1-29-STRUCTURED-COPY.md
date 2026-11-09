# F1-29 — Conversão estrita de texto gerado por IA em briefing de site (offline)

**Somente laboratório.** Nenhuma IA foi invocada. A etapa define um contrato mínimo para um futuro adaptador de geração de texto da Imagine e **não substitui moderação de conteúdo, proteção contra prompt injection ou revisão humana**.

- A saída fictícia de um gerador contém exatamente `schemaVersion=sites.ai-copy.v1`, `headline`, `description`, `callToAction`, `benefits`.
- A **empresa/marca e o produto adquirido** são parâmetros externos controlados por um futuro adaptador autorizado do Portal. Se a resposta do gerador inserir `brand`, `productCode`, `tenantRef`, `publishable` ou `officialClientAcceptance`, o parser recusa toda a proposta.
- Limite explícito para tamanho total da resposta (padrão 4096 bytes, máximo 32768), strings em UTF-8, sem elementos HTML, controles nem esquemas de URL externa nesta primeira versão de teste.
- Os limites conhecidos de `SiteBrief` permanecem aplicáveis. Os bytes são renderizados pelo `StaticSiteRenderer` e submetidos ao `InertPreviewAudit` de F1-27; o resultado é **uma prévia inerte, não publicável**.
- Os testes incluem HTML malicioso, campos extras, tentativas de forjar autoridade, URL, texto grande, UTF-8 inválido, produto incorreto e passagem ao F1-28 até **revisão interna**, sem autoaceite.

Não há modelo, chave API, prompts com dados de clientes, conexão n8n, publicador, frontend real, crédito/cobrança, analytics nem contrato oficial. Uma validação sintática não decide direitos autorais, LGPD, veracidade publicitária ou adequação editorial. Antes de uma integração real, precisamos de origem autenticada e versionada, auditoria segura, cota de uso, revisão editorial e aprovação expressa no Portal.

Execução: `php tests/f29-structured-copy.php`. F1-28, F1-27, OP05/OP03, P0/RF-PEND/RV-CT/AppSec/LGPD continuam com os respectivos bloqueios de produção. `main`, VPS, DNS e Cloudflare não alterados.
