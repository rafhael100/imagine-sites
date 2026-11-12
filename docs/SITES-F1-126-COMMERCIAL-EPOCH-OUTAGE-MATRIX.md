# F1-126 — Matriz de falhas na fonte de autorização comercial HMAC v3

## Objetivo

A F1-125 integrou o envelope HMAC v3 e a revisão comercial no ledger de projetos, com consumo de nonce em **outro banco PostgreSQL 18 descartável**. A F1-126 exercita indisponibilidade transitória da fonte de autorização em três pontos distintos, para demonstrar o comportamento de falha fechada e as regras de recuperação sem duplicar projetos.

## Evidência executável

Os cenários F1-126 em `tests/f111-pg-durable-signed-intake.php` rodam no CI F1-94 em PHP 8.3/8.4, usando duas bases efêmeras PostgreSQL 18 e conexões independentes:

1. **Indisponibilidade antes da gravação do nonce:** a fonte comercial falha logo após a pré-validação do HMAC v3. A entrada é negada sem alterar a admissão. Depois da recuperação, a **mesma mensagem** ainda pode ser validada e consumida uma vez.
2. **Indisponibilidade após a gravação do nonce e antes da autorização transacional:** a segunda consulta à fonte falha. O banco de projetos não muda; o nonce já foi consumido no banco de replay e não pode ser reutilizado em outra conexão. A recuperação exige **novo nonce assinado**, preservando o replay idempotente.
3. **Indisponibilidade imediatamente antes do COMMIT de um projeto novo:** a terceira consulta falha dentro do fluxo transacional. A inserção de projeto e admissão é revertida integralmente, sem deixar o PDO numa transação pendente. A mensagem assinada original permanece consumida; somente um novo envelope v3, com nonce novo e revisão comercial atual, cria exatamente um projeto.

Os testes conservam os checks de validade de assinatura, revisão comercial, revisão da chave, contrato/empresa, modo v3 exclusivo e consulta final da autorização. Nenhuma alteração de esquema ou banco de dados operacional é realizada.

## Limites

As falhas são simuladas em uma **fixture comercial em memória**; não equivalem à queda de uma autoridade real ou a redes particionadas. O comportamento pressupõe que a fonte oficial seja confiável, atual e monotônica, o que permanece **não implementado**. Não existe commit distribuído entre a base de nonce e a de projetos; em cenários com nonce consumido, um pedido de retry legítimo deve ser **reautorizado, reassinado e receber nonce novo**. Não fazer retry silencioso da mesma mensagem.

O laboratório não fornece IAM, mTLS/OAuth, vault, fonte comercial real, logs de auditoria operacionais, teste HTTPS ou relatório independente AppSec. As contas SQL e a GUC de tenant são apenas de prova de conceito; acesso SQL arbitrário não oferece isolamento IAM. Os **dez gates de produção permanecem BLOCKED**. Nenhum merge em dev/main, cliente, deploy, DNS, VPS, Cloudflare ou nova despesa.
