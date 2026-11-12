# F1-122 — Prova cross-repo da elegibilidade comercial até a saída do envelope

## Objetivo

A F1-121 comprovou que o emissor Portal F1-13 busca o segredo HMAC e a revisão de chave em fonte independente, com negação padrão, e produz envelope v2 aceito pelo Imagine Sites. Faltava provar a **rechecagem da autorização comercial do próprio Portal** depois de assinar, antes de devolver o envelope, porque um pedido pode ser revogado, revisado ou transferido enquanto uma assinatura é construída.

## Implementação

`tests/f23-cross-repo.php` utiliza as classes reais do Portal F1-14 fixadas no commit público `2be656bdbf5a6d6fa00e563fd5b5e4942b54cd2c`, correspondente ao [Portal PR #267](https://github.com/rafhael100/imagine-portal/pull/267). A suíte mantém o teste da F1-121 de assinatura v2, HMAC e epoch e agora simula alterações na fonte comercial `F23ContractSource` no instante da **segunda leitura de material de assinatura**, pouco antes da leitura final da fonte comercial.

Para cada produto **Onepage** e **Landing Page**, verifica:

- **Revogação do pedido** durante a assinatura → nenhuma emissão;
- **Mudança da revisão do contrato** → nenhuma emissão;
- **Transferência de tenant/empresa** → nenhuma emissão de envelope com escopo antigo;
- **Restabelecimento de um snapshot comercial coerente** → um novo envelope é assinado e verificado pelo Sites com época e assinatura corretas;
- Testes F1-119/F1-121 de unicidade de nonce, versão v2, integridade da assinatura e fonte de material default DENY continuam ativos.

O workflow `sites-f1-23-cross-repo-contract.yml` fixa exatamente o SHA do Portal, verifica as classes e roda o teste em PHP 8.3 e 8.4. Sem servidor externo, banco real ou dados de clientes.

## Limites e bloqueios

Esse teste usa uma fonte comercial **fictícia** que muda em memória e não comprova a existência, integridade ou permissão operacional de um pedido real no Portal. A segunda leitura não é uma transação atômica, e revogações depois dela não são capturadas. O Sites ainda deve consultar sua fonte confiável e revalidar autorização por empresa/contrato imediatamente antes do commit.

Continuam faltando IAM oficial, assinatura de serviço com chave gerenciada, revisão monotônica durável, mTLS/OAuth, auditoria, permissão por tenant real, teste HTTPS de homologação, aceite do cliente e revisão independente AppSec. Não houve merge em dev/main, deploy, publicação, DNS, VPS, PII ou gastos. **10/10 gates operacionais permanecem BLOCKED.**
