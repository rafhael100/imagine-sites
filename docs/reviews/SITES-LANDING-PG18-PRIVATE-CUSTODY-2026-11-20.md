# Sites Landing — custódia privada PostgreSQL 18, candidata

Base imutável: [Sites #561](https://github.com/rafhael100/imagine-sites/pull/561) HEAD `b7303b8f8c8c7638823c88ae4e5c555fafc274b3`, precedida por #541/#542. Leitor e encoder do Portal [#1019](https://github.com/rafhael100/imagine-portal/pull/1019) fixados na HEAD `4a5e1e06376188f85c90bee3d10056ab3ce2302a`. Branch isolada, PR draft.

## Escopo implementado

O candidato `PortalLandingPagePrivateCustodyCandidate` chama o rascunho editorial e seu readback corrente, registra **bytes privados exatos** com SHA-256 e ponteiro de revisão em transação PostgreSQL 18, revalida o head Portal depois da inserção e antes do commit e desfaz a transação se detectar revogação/alteração. O contrato SQL `sites_landing_custody.record_pending` usa `session_user` de conexão PostgreSQL como identidade independente, vincula a empresa a um cadastro de site/pedido/contrato previamente admitido, impede leitura e escrita direta das roles, preserva registros append-only, verifica campos fechados, hashes de bytes, flags falsas, operação idempotente e rejeita colisões. Produto exclusivo `landing_page`; não reutiliza Onepage.

Os cadastros de operadores e sites, contas de banco e fatos de aprovação usados no CI são **totalmente sintéticos**. O SQL **não os cria nem concede EXECUTE a usuários operacionais**. A única saída da API PHP é um marcador `pending_private_only` e digests sem HTML/briefing. Artefato armazenado NÃO é revisão aprovada.

## Limites e riscos não resolvidos

- O banco descartável prova transação/roles/negação, mas ainda não há migração homologada no Laravel nem integração de armazenamento de produção.
- O catálogo de site/empresa e a aprovação do Portal não são fontes oficiais e autenticadas. Releituras não garantem atomicidade entre bancos nem impedem revogação logo após commit; será obrigatória uma nova verificação antes da revisão, publicação e entrega.
- Faltam ator editor/revisor comprovadamente autenticado, decisão de revisão auditável, aceite expresso do cliente, transporte mTLS homologado, publicação técnica e entrega oficial.
- Nenhuma publicação, autorização ou pagamento é habilitado. **Dez gates BLOCKED.** Sem merge, deploy, VPS, produção, Cloudflare, DNS, Asaas, credenciais ou novos fornecedores.

Os testes são deliberadamente CI-only, em PHP 8.3/8.4, com PostgreSQL 18 descartável; referências de CI devem ser anexadas somente depois de execuções reais.

## CI verificado

Na HEAD `9b9199f059e594e6b37f04ae8daaeee14047c575`, [GitHub Actions 44718436983](https://github.com/rafhael100/imagine-sites/actions/runs/44718436983) concluiu com dois jobs SUCCESS em PHP 8.3 e 8.4: **34 verificações novas de custódia PG18 + 34 regressões editoriais por versão**. As outras quatro execuções globais dessa HEAD também terminaram SUCCESS. Erros SQL de permissões observados nos logs são recusas negativas esperadas. A atualização documental posterior exige nova confirmação de CI para sua própria HEAD.
