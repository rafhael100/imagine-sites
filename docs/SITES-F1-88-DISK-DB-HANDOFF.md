# F1-88 — Encadeamento real de arquivos locais e evidência PostgreSQL (laboratório)

A proposta informativa ao Portal agora só é produzida após **duas verificações independentes**: o pacote privado reaberto do filesystem (F1-86/87) e a decisão editorial previamente gravada no PostgreSQL 18 com controle por tenant e releitura (F1-79/80/81).

## Implementação

`LabDiskAndDatabaseBoundReviewHandoff::prepare` exige PHP CLI com **duas flags opt-in de laboratório**, `SITES_F79_PG_LAB=1` e `SITES_F88_DISK_DB_LAB=1`. O diretório aceito tem somente o padrão fixo dos rascunhos fictícios Onepage/Landing, tema Classic/Modern/Warm e variantes Basic/Focused; não há HTTP, path do cliente, rede Portal, formulário ou envio de dados.

O método reabre os quatro arquivos, verifica os hashes canônicos, a revisão pendente, tenant, site, workflow, release, checklist editorial e digest de HTML/bundle/dossiê. A F1-86 passou a incluir explicitamente o `workflowRef` na prova de leitura, evitando confundir dois fluxos com o mesmo HTML e release. O método então verifica que a decisão sintética é exatamente a mesma e recusa decisões de retrabalho. Por fim, chama `LabEvidenceBoundReviewProposal::prepare`, que exige uma **decisão persistida no PostgreSQL e relida sob a role restrita do tenant**. Reabre novamente o disco depois da conferência do banco para detectar alterações intermediárias simples.

O resultado contém apenas metadados de auditoria de laboratório, o candidato informativo e `publishable=false`, `officialClientAcceptance=false`, `externalPortalAuthorityRequired=true`. Nenhum código publica, assina como cliente, escreve em serviço real ou assume aceite oficial.

## Testes

O teste `tests/f88-pg-disk-db-review-handoff.php` constrói dois rascunhos fictícios e independentes, materializa os pacotes usando o escritor F1-87 em pastas temporárias privadas, tenta produzir a proposta antes de gravar decisões, grava decisões sob as duas roles PG18, e valida os campos exatos pós-releitura. Testes negativos incluem cross-tenant, administrador do banco, HTML/dossiê/manifesto adulterado depois da decisão, troca de release/flow com os mesmos HTML, retrabalho e ausência da flag opt-in. Limpeza remove as amostras e diretórios; não há persistência fora do runner.

A prova está vinculada ao workflow PostgreSQL descartável existente em matriz PHP 8.3/8.4, logo exercita arquivos, PHP e banco real no mesmo processo sem serviços pagos.

## Limitações e prontidão

O teste não prova exclusão mútua entre serviços reais, atomicidade distribuída, IAM do Portal, consentimento expresso do cliente, assinatura verificável, revogação operacional, resistência a administrador malicioso ou restore externo, publicação/rollback Cloudflare, aceitação LGPD e segurança da VPS. A prova é **sintética** e a proposta **meramente informativa**. Os dez requisitos em `config/operational-readiness.json` continuam `BLOCKED`; nenhum merge ou deploy é autorizado por esta etapa.
