# F1-376 — PostgreSQL 18 isolado na mesma topologia de contêineres Laravel

**Não implantar na VPS: é somente um overlay de teste em GitHub Actions.**

O Compose `ops/homologation/compose.ci-pg18.yaml` estende a imagem de homologação F1-373 com um **terceiro contêiner PostgreSQL 18**, sem porta publicada no host. O banco é visível somente ao PHP-FPM pela rede Docker `sites-internal`; o Nginx continua com único bind em `127.0.0.1:18081`. O PHP-FPM também não possui porta pública.

A inicialização importa os mesmos esquemas de laboratório F1-360, F1-361 e F1-363 por `/docker-entrypoint-initdb.d/`, no banco descartável `sites_f360_lab`. O PHP se autentica com o login SQL fictício `sites_f360_b` (sem superuser), e **não** como postgres. As credenciais, empresa, operação, dados e chaves são fictícios, presentes no repositório somente para a prova automatizada. A aplicação real de homologação permanece com banco inexistente no Compose base, e nenhum segredo real é armazenado.

O workflow `sites-f1-376-container-pg18.yml` exige a variável `SITES_F376_CI_LAB=1`, sobe os serviços descartáveis, testa o `/up` HTTP, verifica os contêineres/portas, autentica em PostgreSQL 18 usando PDO **dentro do PHP-FPM**, confirma que SELECT direto nas tabelas de admissão e tentativa cross-tenant são proibidos por SQL, e executa o comando Laravel `sites:homologation-preflight` na própria topologia.

Mesmo com conexão SQL real e role restrita verificados, o preflight **continua bloqueado**: o banco descartável não prova TLS operacional, backup externo, IAM entre Portal e Sites, autorização de contrato/pagamento, aceite de cliente, revogação independente ou licença para publicar. O teste espera explicitamente `postgres_tls=blocked`, `can_accept_real_orders=false` e `can_publish=false`.

A rotina termina com `docker compose down --volumes`, apagando todos os dados descartáveis. Não cria conta Leaseweb, serviço público, DNS, pagamento ou backup operacional. Os guardas do serviço F1-375 continuam restritos ao teste CLI e não se tornam uma API pública pelo simples fato de existir PostgreSQL.

**Gates:** DRAFT/NO-GO, review DBA/AppSec/LGPD, credenciais segregadas, TLS real, bancos persistentes com backups independentes, integração Portal de contrato e event ledger, logs/observabilidade e testes de rollback antes de considerar homologação funcional.
