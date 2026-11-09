# F1-51 — Integração isolada das frentes visual e autoridade do Imagine Sites

As etapas F1-38–44 produziram o painel Blade de revisão, exportação local de galeria Onepage/Landing, QA estrutural, Chrome headless, teclado e contraste. A F1-45–50 desenvolveu em branches separadas propostas de revisão Sites→Portal, autenticação HMAC sintética, consumidor candidato no Portal, anti-replay, leitura RLS de revisão atual e revogação.

Este PR fornece uma **branch de integração de laboratório**, sem nenhum merge em `dev` ou `main`. Os arquivos da cadeia visual são adicionados à base F1-50; o workflow `synthetic-php.yml` combina os testes de DOM F1-39 e assinatura F1-45, e o status rastreia as duas frentes.

A nova CI testa no mesmo checkout PHP 8.3/8.4, PHP-Python HMAC F1-46, Laravel com a interface visual e seus testes negativos, e duas implementações PHP reais pinadas do Portal PR #161 e do Sites. Os workflows existentes de PostgreSQL F1-50, Chrome F1-41/42/43/44 e safety guard também continuam aplicáveis.

**Não há deploy, credencial real, cliente, pagamento, aceite expresso, captura, CRM, DNS ou Cloudflare.** A integração só demonstra que as duas branches podem coexistir com os mesmos testes. Os PRs originais #56–#67 e Portal #161 permanecem em draft até revisão formal e gate de promoção. Não é conclusão dos requisitos de identidade, AppSec, LGPD, ambiente Leaseweb ou publicação.
