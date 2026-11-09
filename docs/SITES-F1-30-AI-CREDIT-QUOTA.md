# F1-30 — Reservas fictícias de créditos de produção de IA (somente memória)

**Motivação:** registrar contratos básicos para limites de produção Imagine Sites, sem ativar IA paga ou supor que uma conta de crédito de laboratório controle gastos reais.

- Cada empresa fictícia recebe uma quota de **créditos abstratos, não monetários**. Existe um teto por operação, com padrão 20 (limite máximo 500 no laboratório).
- Reservas são imutáveis: cada chamada retorna uma cópia do estado, sem alterar o histórico de versões anterior.
- A chave `(tenantRef, operationRef)` deve ser idempotente: mesmo workflow+quantidade não desconta novamente; se mudar, falha. Mesma referência de operação em tenants diferentes é contabilizada separadamente.
- Inexistência de empresa, quotas excedidas, créditos não inteiros, referência insegura e tentativas com operação diferente recebem recusa.
- Testes em `tests/f30-ai-credit-quota.php` cruzam F1-28 e F1-29 com renderização de prévia fictícia e confirmam que créditos não fornecem permissão de aceite ou publicação.

## Limites críticos

Isto **não executa, mede, limita nem contabiliza nenhum provedor real**. Não há persistência, transação, lock concorrente, banco compartilhado, saldos comerciais, cobrança, estimativa de custo, autorização de fornecedor, antifraude ou ledger financeiro. Para IA de produção, exigir quota transacional central, reservas e reconciliação de uso do provedor, alçadas de aprovação, auditoria e limites de cobrança aprovados — especialmente por se tratar de um SaaS multiempresa.

Nada usa Leaseweb/Cloudflare, domínio, agentes, API externa ou cliente real. `main` e os gates de produção ficam intactos. Não fechar OP05/P0/AppSec/LGPD por testes sintéticos.
