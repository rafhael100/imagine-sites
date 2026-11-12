# F1-39 — Checklist técnico de HTML antes da revisão visual

Esta etapa acrescenta à UI experimental F1-38 um resumo de verificações estruturais sobre os bytes **exatos** do renderizador. É uma checagem técnica, não aprovação editorial, certificação WCAG ou autorização de publicação.

Os sete itens são: HTML inerte (F1-27), SHA-256 consistente, idioma português e título, landmark main com H1 único, navegação por âncoras internas com IDs únicos, viewport mobile/noindex e ausência de elementos executáveis.

A análise usa DOMDocument em modo `LIBXML_NONET` no PHP, sem navegador, recursos remotos ou JavaScript. Se qualquer teste falhar, a rota F1-38 retorna 404 em vez de apresentar o conteúdo como apto para revisão. O relatório exibe cada verificação concluída e esclarece que **contraste, uso de teclado, usabilidade, conteúdo e acessibilidade** ainda dependem de avaliação com navegador e equipe humana.

Testes `php tests/f39-qa-report.php` cobrem ambos os produtos e rejeitam idioma errado, ausência de main, H1 duplicado, links quebrados, URL externa, indexação, viewport ausente, tags de script e ID duplicado. A integração Laravel continua limitada a CLI testing+flags+loopback e sem operações.

PR dependente de F1-38. Não realiza deploy, IA real, revisão do cliente, conexão Portal/CRM, DNS, VPS, publicação ou gastos. Gates OP01/OP03/OP05, P0/RF-PEND, AppSec/LGPD e config/operational-readiness.json permanecem BLOCKED.
