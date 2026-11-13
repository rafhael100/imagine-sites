## F1-256 — Evidência imutável de comparação do segundo CTA

- Classe `SyntheticSecondaryCtaChangeEvidenceV1` reconstrói rigorosamente dois drafts fictícios, com CTA desativado/ativado, e registra SHA-256 dos dois HTMLs e da própria evidência.
- Confere mesma empresa/site, conteúdo editorial, tema, SEO, ordem, menu, paleta e metadados sociais; rejeita comparações cruzadas ou adulteradas.
- O revisor Laravel de laboratório apresenta o SHA da evidência e mantém o estado `internal_review`, sem aceite nem autoridade operacional.
- Regressões PHP negativas validam pares invertidos, igualdade indevida, outra empresa e copy alterada; PHPUnit compara evidência nos 24 visuais.
- Workflow dedicado de revisão e matriz PHP global executam novas regressões.

**Fronteiras:** somente exemplos sintéticos; evidência SHA não equivale a assinatura, identidade autenticada ou aceite de cliente. Dez gates operacionais continuam BLOCKED, sem CRM real, publicação, VPS, DNS, Cloudflare, segredos ou custo. Draft não autoriza merge.