# F1-156 — Identificador único de lease preservado após expiração e limpeza

A F1-146 mantinha o UUID da lease apenas na linha corrente do outbox. Quando a lease era substituída, o token anterior deixava de aparecer na coluna com UNIQUE. Com um outbox novo ou após a F1-154 retirar a entrada vencida, aquele UUID poderia ser reutilizado, gerando uma nova classificação técnica com um identificador que já havia sido consumido.

A F1-156 acrescenta `f140_lab.synthetic_lease_nonce_history` com **chave primária no UUID de lease** e um vínculo de metadados à submissão original (sem payload, cliente ou contato). Antes de atualizar uma linha do outbox, a função de laboratório faz `INSERT ... ON CONFLICT DO NOTHING RETURNING lease_id` **na mesma transação**. Se o UUID já existiu em qualquer empresa, retorna `LEASE_NONCE_REUSED` e não altera a lease/contador. A tabela não possui FK que impeça a retirada da linha de outbox vencida: o tombstone permanece mesmo depois da limpeza. O adaptador PHP só reconhece este resultado como **DENY**, jamais entrega/ACK.

O teste PostgreSQL18 PHP8.3/8.4 prova ID antigo rejeitado após lease renovada, reutilização em outra empresa recusada, preservação do tombstone após expirar e retirar outbox, e bloqueio de replay na próxima submissão. Role CI sem SELECT na história, sem PII. Não há JWT, autenticação do Portal, nonce operacional distribuído, CRM, retry real, LGPD aprovada ou trabalhador externo. A política de retenção e exclusão destes tombstones ainda requer análise legal e AppSec.

PR draft, sem merge `dev/main`, sem VPS, DNS, deploy, dados reais ou gastos. OP04 #36 e os 10 gates continuam BLOCKED.
