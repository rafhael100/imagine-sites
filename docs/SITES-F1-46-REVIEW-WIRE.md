# F1-46 — Contrato JSON de revisão Portal e interoperabilidade PHP ↔ Python

## Objetivo

Formalizar o formato de transporte exclusivamente sintético criado pelo F1-45, sem criar uma API ou atalho de aprovação. O esquema `contracts/portal-sites/v1/review-handoff-envelope.schema.json` usa JSON Schema 2020-12, com **campos permitidos exatos** e versão de contrato fixada. O conjunto inclui empresa, site, workflow, revisão e release opacos, digest SHA-256, validade curta, nonce e HMAC.

O contrato descreve uma **proposta Sites → Portal**: `officialClientAcceptance` permanece **false** e `publishable` permanece **false**. O contrato de comando CreateProject anterior Portal → Sites não é reutilizado nesta direção.

## Evidência cruzada

`tests/f46-review-wire.php` roda somente com `SITES_F46_WIRE=1` em CLI. Gera um único envelope HMAC sintético de F1-45 usando dados e chave totalmente fictícios. O `tests/f46_review_contracts.py` lê os bytes JSON gerados pelo PHP, valida estruturalmente as propriedades canônicas e recalcula **a assinatura HMAC de forma independente em Python** usando somente sua biblioteca padrão.

Testa emissor/destinatário, schema, nonce, ids, hash, versão, falsas alegações de aceite/publicação, campos extras, inversão de ordem e alteração de payload sem resignatura. Também demonstra expressamente que **o JSON Schema não impõe ordenação ou TTL**, sendo essas obrigações verificadas em tempo de execução pelo F1-45. O validador do teste Python implementa só o subconjunto relevante de tipos/const/pattern/object, **não** um motor JSON Schema completo.

## Gate e limites

Os testes não inferem autenticação real da assinatura HMAC: a chave em fixture não deve ser usada fora do laboratório. O Portal ainda exige identidade de serviço confiável, autorização por recurso atual, revogação, replay durável, observabilidade, persistência e assinatura/aceite **expresso do cliente** por canal oficial. Uma proposta recebida **nunca** pode transformar-se em evento ENTREGUE ou autorização de publicação pelo Sites.

Nenhum endpoint, fila, broker, HTTP, dados de cliente, credencial, custo de fornecedor ou deploy é criado. `main`, VPS, DNS, Cloudflare e gates P0/RF-PEND/RV-CT/AppSec/LGPD permanecem inalterados. PR draft depende de F1-45 (#63).
