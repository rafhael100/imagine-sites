# F1-368 — Recuperação após perda de resposta HTTP, com reinício do receptor

**Escopo:** teste de laboratório, executado apenas no GitHub Actions com duas bases PostgreSQL 18 efêmeras e chaves publicamente fictícias. Não é integração com o Portal operacional nem implantação na VPS.

## Falha exercitada

Mesmo que o Portal grave o evento, a resposta HTTP pode se perder **depois** do COMMIT e **antes** do Sites receber o recibo. Se o Sites interpretasse o HTTP 503 como não gravação e criasse uma segunda entrega independente, haveria risco de duplicação. Se interpretasse o HTTP 503 como confirmação, haveria risco de perda de evento.

Este teste adiciona à fixture do receptor o modo exclusivo de CI `lost_after_commit`: o PostgreSQL do Portal confirma o evento, mas o processo responde 503 sem emitir um recibo assinado. O Sites **não faz ACK**, mantendo o evento com lease aberto.

A matriz de CI então encerra o processo PHP receptor e inicia outro processo ouvindo em `127.0.0.1:18968`. Um script PHP novo consulta somente os dois bancos, sem transferir estado por arquivo, variável transitória ou objeto compartilhado. Identifica o evento armazenado no Portal, mas ainda não confirmado no Sites, expira o lease anterior **somente por administrador fictício da fixture** e faz o trabalhador reivindicar o evento com um token novo. O receptor reiniciado reconhece a chave `tenant_ref + operation_ref` e o digest, responde `already_recorded` com recibo assinado para o **token atual**, sem gravar uma segunda linha. Somente esse recibo habilita o ACK.

## Critérios negativos

- Resposta 503 após COMMIT mantém o registro no banco Portal e não marca o outbox como entregue no Sites
- Identidade e digest da operação são recuperados da persistência, não de estado do processo encerrado
- Após reinício, a mesma operação é idempotente no segundo PostgreSQL
- Token do lease antigo não consegue confirmar o evento
- Confirmação duplicada também é rejeitada
- Nenhuma admissão do Sites fica sem evento outbox
- A execução é recusada se `SITES_F368_RECOVERY_LAB` estiver desabilitada ou fora das credenciais/bancos fictícios de CI

## Limites

O modo de erro é uma **injeção controlada de falha**, não uma interrupção real de rede ou de datacenter. A simulação comprova que o algoritmo se recupera de perda de resposta e reinício do **processo** receptor com persistência preservada, mas **não** comprova failover, restauração de backups, perda de banco, continuidade da autoridade de revogação, disponibilidade do serviço, autorização financeira do Portal, emissão de notas, TLS, IAM/mTLS, LGPD, deploy ou experiência no navegador. A fixture não deve ser convertida em endpoint de produção.

**Gate:** PR em rascunho/NO-GO, dependente dos PRs #444 → #445 → #446. Nenhum merge automático, DNS, VPS, dados reais ou integração de clientes. Teste deve ser aprovado em PHP 8.3 e 8.4 antes de marcar a entrega como validada.
