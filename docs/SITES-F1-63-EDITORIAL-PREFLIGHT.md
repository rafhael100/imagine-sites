# F1-63 — Pré-revisão editorial no painel privado de Onepage/Landing

Esta etapa fornece recomendações **orientativas** sobre a cópia dos modelos Onepage e Landing Page antes da revisão humana, sem reescrever conteúdo, executar IA, alterar metadados de produção ou conceder aceite do cliente.

`SyntheticEditorialReviewReport::inspect` recebe o `SiteBrief` validado e o `ReleaseArtifact`, comprova que os bytes correspondem ao renderer original e à auditoria estrutural F1-39, e produz seis checagens determinísticas. As cinco orientações de conteúdo consideram extensão aproximada do título, legibilidade da descrição, clareza da CTA, cobertura de benefícios e eventual repetição de benefícios. Os intervalos são **heurísticas editoriais locais**, não limites normativos de buscadores nem garantia de posicionamento em pesquisa.

O relatório retorna só booleanos e mensagens de orientação estáticas; não contém dados do briefing, segredos, aprovação ou campo `publishable`. O painel Laravel CLI/testing apresenta as orientações em uma seção separada, marcada como **revisão humana necessária**, sem adicionar formulários, links externos nem botão de aprovação. A identidade SHA-256, links do painel, seis combinações e o checklist original de sete itens permanecem inalterados.

Testes PHP 8.3/8.4 validam seis variantes, conteúdo curto, benefícios duplicados/normalizados, HTML diferente do briefing, caracteres escapados e ausência de permissões. Os testes Laravel confirmam a seção em todas as prévias e tentativas de endpoint de aprovação negadas.

O conteúdo comercial real, SEO em domínio público, geração de cópia por IA, aprovação de cliente, privacidade, hospedagem, CRM e deploy não fazem parte desta etapa. `main`, `dev`, VPS, Cloudflare e readiness BLOCKED permanecem preservados. PR draft dependente da F1-62 (#83).
