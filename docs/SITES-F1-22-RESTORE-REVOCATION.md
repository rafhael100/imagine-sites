# SITES-F1-22 — Proteção contra revogação ressuscitada por restore

**Teste real com PostgreSQL 18 descartável**, mas todas as informações, empresas e permissões são fictícias.

**Risco:** se a base do Sites for restaurada a partir de um backup anterior a uma suspensão ou revogação, o registro `tenant.status=active` e uma grant antiga também podem voltar, restaurando indevidamente acesso a projetos e dados de clientes.

## Estratégia candidata testada

1. `authority_guard.revocation_floors` simula um ledger autoritativo **separado da área de dados de clientes restaurada**, com `min_revision` monotônica e `allow_active` (regras explícitas de revogação).
2. RLS de `lab_sites.tenants` e de grants exige correspondência com esse ledger e revisão suficientemente recente.
3. Um teste inicia com tenant fictício autorizado, revoga acesso (floor 2), tira e restaura uma cópia antiga da linha `active / revision 1` e da grant antiga.
4. Depois do restore, leitura e gravação da conta revogada continuam NEGADAS.
5. Mesmo quando se altera o ledger para permitir nova autorização, o backup antigo permanece bloqueado até que a revisão do tenant suba para 3 após reautorização explícita.
6. Outra empresa não afetada permanece visível ao seu próprio papel.

## Limite crítico de produção

**O ledger aqui está em outro schema, mas no MESMO servidor PostgreSQL efêmero**. Isso NÃO prova que sobreviveria a um restore integral do servidor. Em produção, o registro autoritativo precisa ficar fora do domínio de restauração do banco de clientes, com monotonicidade, identificação forte de emissores, revalidação do Portal, provas de retenção e restore reais. Nunca executar esses scripts em banco da VPS ou DB existente.

Não há solução final de disaster recovery, backup criptografado, observabilidade de incidentes ou proteção de chaves implementada. Esta é somente evidência sobre a política de RLS e revisão de autoridade em cenário sintético.

**Gates:** P0/RF-PEND/RV-CT, LGPD/AppSec, recursos de contingência, homologação de banco e operação de backup continuam abertos. AGI-11-RB01 desabilitado. PR draft, sem merge/deploy, dados de clientes ou gastos.
