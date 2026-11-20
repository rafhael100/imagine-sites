# Sites Landing — serialização da HEAD custodiada na leitura privada (PG18)

A segunda entrega isolada continua [PR #568](https://github.com/rafhael100/imagine-sites/pull/568), base fixa `a9660b34bc0eab2c2c6be59186ff1e08cf2d1967`, sem alterar #541/#542 nem branches da Juliana/automações. Portal [#1019](https://github.com/rafhael100/imagine-portal/pull/1019) fixado em `4a5e1e06376188f85c90bee3d10056ab3ce2302a`.

## Problema corrigido

Bloquear `FOR SHARE` as admissões **durante** a consulta que seleciona o artefato mais recente impede revogações após a consulta, mas se o escritor já tiver bloqueado o site para gravar uma nova versão, o snapshot da consulta pode ter sido criado **antes** de esperar o lock. Uma versão antiga poderia ser lida quando a nova versão estivesse prestes a ser concluída.

## Mudança candidata

A função `read_current_review_observation` primeiro bloqueia a linha de admissão `(tenant_ref, site_ref)` com `FOR SHARE` em uma instrução SQL separada. Só depois consulta a HEAD mais recente da custódia e o diário. Como `record_pending` usa `FOR UPDATE` na mesma admissão, leituras e novas versões ficam serializadas no PostgreSQL 18. Isso fornece um snapshot posterior à aquisição do lock quando a transação do leitor usa `READ COMMITTED` (padrão testado), não valida transações externas do Portal.

O teste adversarial acrescenta uma leitura SQL mantida em transação de leitor independente, tenta obter o lock de escrita no mesmo site em outra sessão (`lock_timeout=300ms`), exige bloqueio e sucesso do readback depois do COMMIT. As provas herdadas de #568 incluem 4 disputas de revogação com rollback e nenhuma mutação de diário.

## Estado de homologação

Somente fixture descartável PostgreSQL 18 com identidades e aprovações sintéticas, sem conexão operacional. Não há ator humano autenticado, fonte oficial de versionamento/approval do Portal, configuração de roles produtiva, S2S homologado, revisão formal, publicação, aceite ou cobrança. Os dez gates continuam **BLOCKED**. Sem merge/deploy, main, VPS, produção, Cloudflare/DNS, credenciais ou Asaas.

Workflow específico: `.github/workflows/sites-landing-pg18-readback-head-serialization.yml`. **Não declarar CI aprovado sem evidência da HEAD final.**
