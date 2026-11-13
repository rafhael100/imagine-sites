# F1-124 — Paridade HMAC v3 com revisão comercial autenticada

## Objetivo

A F1-123 detectou alteração A→B→A da autorização comercial durante a emissão usando revisão independente. Ainda faltava autenticar essa revisão no envelope recebido pelo Imagine Sites. A F1-124 acrescenta um verificador sintético **HMAC v3**, que exige `commercialEpoch` autenticado e igual à revisão comercial corrente consultada independentemente pelo receptor.

## Implementação

`SyntheticCommercialEpochSignedCommand` (Imagine Sites) implementa um contrato v3 distinto do v2, com a ordem exata:
`schemaVersion`, `issuer`, `audience`, `issuedAt`, `expiresAt`, `nonce`, `payload`, `keyEpoch`, `commercialEpoch`, `mac`.

Os campos de revisão da chave (`keyEpoch`) e da autorização comercial (`commercialEpoch`) ficam dentro dos bytes autenticados pelo HMAC SHA-256. O verificador exige revisão comercial positiva e igual ao valor esperado, formato e ordem exatos, e integridade da MAC **antes de consumir nonce**. Depois reutiliza a verificação v2 para comando, prazo e replay. O verificador v3 **não aceita v2**.

## Teste efetivo entre repositórios

`tests/f23-cross-repo.php` executa o emissor protegido real do Portal [PR #273 / F1-16](https://github.com/rafhael100/imagine-portal/pull/273) em revisão pública fixa `8d1e20bc5c39dc4106b549c5e979351b5c766615`. O workflow `sites-f1-23-cross-repo-contract.yml` roda a suíte em PHP 8.3 e 8.4 para Onepage e Landing Page.

Com fontes de chave e épocas comerciais exclusivamente fictícias, a suíte verifica:
- equivalência integral do envelope v3 do emissor Portal com o código Sites e aceitação do comando;
- negação de replay do mesmo nonce entre verificações;
- aumento da época comercial após emissão → envelope antigo negado **antes de consumir nonce**;
- alteração manual de `commercialEpoch` sem recalcular MAC → recusa;
- novo envelope emitido com revisão corrente e nonce ainda disponível → aceito;
- v2 enviado ao verificador v3 → recusa, sem downgrade;
- manutenção das verificações existentes F1-119 a F1-123.

## Limitações e gates

A API do verificador recebe revisões **do teste** como argumentos: nenhuma fonte de autorização oficial do Portal está conectada ao Sites. Isto não constitui uma integração de IAM, certificado, OAuth/mTLS, vault, banco com epoch monotônica, aceite de cliente, autorização real de domínio ou publicação. O emissor v3 ainda não é serviço oficial do Portal. Em produção, a revisão comercial deve ser consultada numa fonte confiável e independente imediatamente antes da decisão e da gravação — não recebida do cliente.

Sem deploy, merge em dev/main, VPS, Cloudflare, DNS, dados de cliente ou custos novos. Os **10 gates operacionais permanecem BLOCKED** e qualquer integração real exige revisão AppSec independente.
