# Imagine Sites F1-288 — Revisão de redirecionamento canônico sem www (offline)

**12/11/2026. Estritamente proposta para auditoria; sem HTTP real, DNS, TLS, Cloudflare, publicações ou gasto.**

## Regra implementada

Com base no F1-287, um site público novo só pode **simular** o destino de uma eventual resposta 308 de https://www.dominio.com.br/ para https://dominio.com.br/ quando a origem constar exatamente como alias na configuração administrada.

- Não utiliza cabeçalhos HTTP Host/Forwarded fornecidos por visitantes e não assina comprovantes de posse do domínio.
- Para caminhos simples e seguros (/, /servicos/, /campanha/), produz somente candidateLocation; nunca envia HTTP 308, DNS, API ou redirect.
- Não transporta query strings, que podem conter PII/tokens e exigem mapeamento SEO individual antes da migração real.
- Bloqueia caminho com dupla barra, percent-encoding, Unicode sem normalização, CRLF, ponto/segmentos ocultos, fragmento, barra invertida, caminho absoluto externo, método diferente de GET/HEAD e tamanhos excessivos.
- Legacy, aplicações/subdomínios e políticas inconsistentes permanecem HOLD, sem qualquer localização candidata.
- Mesmo em cenário permitido, mayExecute=false. O status 308 é uma sugestão documental e nunca uma autorização de publicação.

## Testes

- tests/f288-canonical-redirect-review.php cobre múltiplos caminhos, GET/HEAD, queries descartadas, domínio novo, origem hostil, legado, mudanças indevidas nos flags, loops e injeções.
- .github/workflows/sites-f1-288-redirect-review.yml executa PHP 8.3/8.4, sem permissão de escrita nem segredo.
- Matriz sintética geral inclui teste F1-288. Validar no HEAD do PR; não reutilizar resultados de commits anteriores.

## Para implementar em ambiente real

Os dez gates de config/operational-readiness.json continuam BLOCKED. Pré-requisitos adicionais: posse verificada dos hosts, TLS dos dois lados, mapeamento e revisão das rotas antigas, equivalência SEO/canonical/sitemap, restrições de cookies, privacidade, testes de staging (sem loops, no máximo um salto), rollback manual e aprovação do cliente/titular. Ferramentas de DNS/Cloudflare não foram acionadas.

**NO-GO até o atendimento de todos os controles externos.**
