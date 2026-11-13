# F1-247 — saneamento na entrada editorial de SiteBrief

## Achado técnico

O adaptador de conteúdo estruturado `StructuredCopyCandidate` já rejeitava caracteres ASCII de controle, strings com esquemas de URL e marcação executável. Entretanto, o caminho direto `new SiteBrief(...)` validava, nos campos principais e benefícios, basicamente tamanho e UTF-8. Isso permitia criar objetos de briefing com quebras de linha internas, caracteres invisíveis de redirecionamento bidirecional ou esquemas remotos que só seriam recusados **após** construir HTML — potencialmente gerando feedback ou hashes inconsistentes entre os pontos de entrada.

## Correção de código

`SiteBrief::prohibitedStaticText()` aplica o mesmo bloqueio a campos principais (`brand`, `headline`, `description`, `callToAction`), benefícios, SEO editado, FAQs, serviços e etapas de produção. Rejeita:
- ASCII de controle (`U+0000..001F`) e `DEL`;
- controles bidi invisíveis de override/isolamento (`U+200E`, `U+200F`, `U+202A..202E`, `U+2066..2069`);
- esquemas `http://`, `https://`, `javascript:` e `data:` em texto editorial.

**Compatibilidade:** símbolos normais `<`, `>`, aspas e `&` permanecem aceitos nos campos principais/FAQ/benefícios e continuam escapados em HTML. A política mais estrita de SEO que já existia (sem `<` ou `>`) continua válida. O renderer não é transformado em sanitizador de HTML arbitrário; o pré-requisito continua sendo texto simples estruturado.

O teste `tests/f247-direct-brief-text-safety.php` exercita ambos os produtos, validação do HTML inerte pelo mesmo QA, 15 entradas adversariais em todos os caminhos e preservação dos símbolos exibidos como texto. Entra na matriz principal de PHP 8.3 e 8.4. **Não envolve dados reais nem autorização operacional.**

Permanecem `BLOCKED` os 10 gates de produção, revisão AppSec/DBA e proteção de `dev/main` em [issue #281](https://github.com/rafhael100/imagine-sites/issues/281). Nenhum merge/deploy autorizado.
