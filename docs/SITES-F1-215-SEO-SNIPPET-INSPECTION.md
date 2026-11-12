# F1-215 — Inspeção de títulos e descrições para SEO antes da publicação

Esta entrega acrescenta uma leitura **determinística e somente local** dos títulos, H1 e descrições HTML já produzidos pelo Imagine Sites. O serviço de domínio `SyntheticSeoSnippetInspectionV1` permite mostrar à equipe editorial como o texto poderia aparecer em uma prévia de resultados de busca, **sem fazer pesquisa, indexar ou simular uma posição no Google**.

A inspeção exige o mesmo HTML exato da renderização do `SiteBrief`, SHA-256 consistente e aprovação da auditoria de HTML inerte. O parser DOM confere idioma `pt-BR`, um `<title>`, uma meta description, um H1, a meta `noindex, nofollow` e ausência de link canonical não autorizado. Se o cartão social OG/Twitter está ligado, as sete tags sociais também precisam corresponder ao mesmo artefato, título e descrição. Qualquer divergência recusa a inspeção em vez de mostrar um texto de outra versão.

O resultado oferece contagens por caracteres Unicode, prévia textual de até 60 caracteres para título e 160 para descrição e avisos de revisão não bloqueantes: `TITLE_SHORT`, `TITLE_MAY_TRUNCATE`, `DESCRIPTION_SHORT`, `DESCRIPTION_MAY_TRUNCATE`. Os limiares são **heurísticas editoriais**, não recomendações de ranqueamento nem limites universais dos mecanismos de busca; truncamento real depende do espaço disponível e do algoritmo do buscador.

Os testes incluídos no gate principal PHP 8.3/8.4 percorrem 24 versões canônicas (Onepage/Landing, temas, paletas, cartão social on/off), verificam UTF-8, HTML noindex, relatórios sem privilégios e rejeitam meta description duplicada, robots alterado, canonical externo e título divergente. Há casos de textos curtos, longos e acentuados.

A próxima integração possível é exibir este relatório na **revisão Laravel privada**, preservando a identidade do arquivo exportado e o aceite humano pendente. Não há tráfego externo, URL pública, cliente, conexão com Google/Imagine SEO, CRM, geração de lead ou deploy. Os gates OP01/OP02/OP04 e 10/10 bloqueios de produção permanecem vigentes.
