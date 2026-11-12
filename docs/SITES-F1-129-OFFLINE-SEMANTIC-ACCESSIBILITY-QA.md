# F1-129 — Verificações estruturais de acessibilidade para prévias Onepage e Landing Page

## Situação anterior

O `SyntheticPreviewQaReport` analisava offline as prévias HTML fictícias do motor Imagine Sites, confirmando `lang`, título, `main`, um `h1`, destino válido de âncoras, viewport, `noindex` e ausência de elementos executáveis. Ainda assim, dois problemas de navegação poderiam passar pelos testes: uma seção com `aria-labelledby` ausente ou apontando para um título **em outra seção**, e links internos sem texto ou `aria-label` acessível.

## Alteração

A F1-129 acrescenta dois checks conservadores sem modificar o `StaticSiteRenderer`, seu HTML, CSS, CSP ou digests de artefato:

- `section_heading_labels`: toda `section` dentro do `main#principal` precisa usar um identificador simples em `aria-labelledby` que corresponda a **exatamente um** título `h1` ou `h2` com texto não vazio **dentro dessa seção**. Um título em outra seção não satisfaz a regra.
- `link_accessible_names`: cada link `a[href]` do template inerte deve ter texto legível ou um `aria-label` não vazio; âncora com destino correto mas sem nome falha na revisão estrutural.

A quantidade de verificações explícitas do relatório passa de sete para **nove**, sem alterar o contrato `passedStructuralChecks`: ele continua falso se qualquer item falhar.

## Testes

`tests/f39-qa-report.php` valida os dois produtos, **Onepage** e **Landing Page**, e injeta HTML mutado em `ReleaseArtifact` sem acessar rede:
- `aria-labelledby` apontando para ID ausente;
- `aria-labelledby` apontando para o título de outra seção;
- título da seção sem texto;
- texto do CTA removido;
- nome do link de navegação removido.

As mutações anteriores (âncora quebrada, navegação externa, ID duplicado, `h1` duplicado, viewport/robots ausentes e script ativo) continuam sendo rejeitadas. A matriz de testes sintéticos roda em PHP 8.3 e 8.4.

## Limites

Estas são **verificações automáticas de marcação estrutural**, não uma certificação WCAG, auditoria de experiência, inspeção de leitores de tela, avaliação de contraste em navegador nem aprovação humana. O renderer permanece uma prévia sintética sem formulários, analytics, assets externos, dados reais, site público ou publicação. A produção depende de aprovação comercial, IAM Portal, infraestrutura, homologação e revisão independente.

PR draft/empilhado, sem merge em dev/main, deploy, VPS, DNS, Cloudflare, PII ou novos gastos. **10/10 gates operacionais de produção permanecem BLOCKED.**
