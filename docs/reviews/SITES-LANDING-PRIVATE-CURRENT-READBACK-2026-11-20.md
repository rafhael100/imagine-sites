# Sites — leitura corrente privada de briefing aprovado Landing (candidata)

## Origem e limite do recorte

Esta PR deriva da Sites #556 (HEAD imutável `926f43e659d3b9944b3df0e93b497ff18f9157eb`) e fixa o encoder Portal #1012 (`e0f38d8d98c151ffa5743c9c372ac039074b9ea7`). Preserva as PRs #541/#542, a cadeia Onepage e os gates atuais.

A #556 fazia a validação isolada do envelope fornecido pelo chamador. Esta entrega acrescenta um **leitor independente** de head aprovado e atual, negado por padrão. A API recebe expectativas de autorização **obtidas fora do envelope** (tenant, pedido, site, contrato, revisão, versão, aprovação, epoch e digest de conteúdo). Faz:

1. Validação sintática completa de todas as expectativas antes de acionar o provedor.
2. Duas leituras da admissão corrente do Portal (tenant/pedido/site e contrato).
3. Duas leituras privadas do briefing atual; cada uma passa no decoder real Sites #556 e precisa bater com o digest esperado.
4. Comparação byte a byte das leituras privadas.
5. Nova dupla leitura de admissão, recusando revogação ou avanço observado.

Não há cache, escrita de conteúdo, API HTTP ativa, banco de produção, campos de pagamento ou publicação. O DTO continua privado, não serializável. Falhas e exceções são redigidas.

## Evidência e não evidência

O workflow específico executa PHP 8.3 e 8.4 contra o **encoder real Portal #1012 fixado por SHA**, com cenários negativos: outra empresa/site, produto Onepage, versão ou epoch alterados, conteúdo mudado entre leituras, admissão revogada depois das leituras, origem ausente, JSON inválido, permissão falsa, exceção e replay. Reexecuta as três provas precedentes de Landing. Registrar os links dos runs apenas após concluírem.

**Não é autorização operacional.** Os provedores na fixture são sintéticos. Dupla leitura não é transação; ABA e revogação posterior seguem possíveis. Ainda faltam leitor/ledger transacional oficial no Portal com ator autenticado, auditoria, revogação; autenticação S2S/mTLS homologada; fluxo de artefato/revisão, publicação técnica, aceite do cliente e integração de pagamento. Não habilitar writers, publicação ou gates de contrato/pagamento até homologação por caminho independente.

**Draft/NO-MERGE/NO-DEPLOY.** Sem modificar main, VPS, DNS/Cloudflare, produção, credenciais ou Asaas. Os dez gates operacionais continuam bloqueados.
