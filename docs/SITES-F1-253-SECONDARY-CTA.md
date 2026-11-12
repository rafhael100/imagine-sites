# F1-253 — CTA secundário de conversão controlado pelo editor

- Um segundo botão opcional foi acrescentado às prévias Onepage e Landing Page; inicia desativado e aponta somente para a âncora local #contato.
- Reutiliza o texto já validado do CTA principal, com CSS fixo, área de toque mínima de 44 px, e não aceita URL/formulário/JS ou decisão da IA.
- Auditoria de HTML inert, QA semântico, manifesto SHA-256 e fluxo de revisão verificam cada novo rascunho.
- Mudanças de estilo, texto, SEO, social e ordem mantêm a escolha do editor. Edições de copy não podem ligá-la nem desligá-la; toda mudança no HTML invalida a revisão anterior.
- Testes de regressão em PHP 8.3/8.4 cobrem 2 produtos × 3 temas, ajustes e negativas.

**Limites:** funcionalidade de laboratório, sem contatos reais, API de publicação, CRM real, VPS, Cloudflare, custos ou autorização de deploy. Dez gates permanecem BLOCKED.