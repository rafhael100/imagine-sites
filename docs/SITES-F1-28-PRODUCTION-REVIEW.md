# F1-28 — Máquina de estados sintética Imagine Sites (produção e revisão humana)

**Escopo:** candidato de domínio **somente em memória e sem integração de rede**. Prepara o fluxo de produção Imagine para Onepage/Landing; a VPS Leaseweb não é necessária para esta etapa.

## Fluxo de laboratório

`queued` → `drafting` (agente/editor Imagine) → `internal_review` (digest SHA-256 fixado) → `awaiting_portal_acceptance` (revisor Imagine valida os bytes exatos).

Em caso de ajustes internos, o revisor retorna à elaboração, invalidando o digest anterior e contabilizando outra tentativa. Uma opção de retorno também existe quando uma prévia já está esperando aceite no Portal. O limite sintético de novas tentativas é configurado de 1 a 10, com padrão 3. A API do objeto é imutável e exige revisão otimista exata: alterações antigas são recusadas.

`portalReviewCandidate()` cria apenas **uma proposta informativa** com empresa, site, release, revisão e hash, sempre `officialClientAcceptance=false` e `publishable=false`. O Portal é a única autoridade para comprovar identidade do titular, contrato, aceite expresso e emitir eventos oficiais; nenhuma transição de aprovação de cliente ou publicação existe neste módulo.

## Limitações

Papéis `agent`, `imagine_editor` e `imagine_reviewer` são **strings de teste**, NÃO autenticação. O código **não** constitui alçada real, assinatura, auditoria durável, autorização de contrato, prova de direitos de conteúdo, antirreplay, aprovação de cliente, fluxo n8n, serviço Laravel, custo ou execução de modelo IA. Um fluxo real exigirá autenticação, separação de papéis, persistência, eventos/auditoria e conectores homologados.

Validação offline: `php tests/f28-production-flow.php`; a suíte cobre recusas cross-role, versão obsoleta, hash errado, orçamento excedido, aprovação fictícia, tentativa de publicar, retorno/revisão e imutabilidade.

**Gates:** SITES-OP05 e SITES-OP03 não fechados, P0/RF-PEND/RV-CT, AppSec e LGPD continuam pendentes. Sem mudança em `main`, VPS, Cloudflare, DNS, CRM, segredos ou dados reais. `AGI-11-RB01` permanece desabilitado.
