# F1-370 — Bloqueio de backup antigo ou divergente entre Sites e Portal

**Estado:** teste de segurança operacional em banco descartável de GitHub CI, sem ativação na VPS nem autorização de pagamentos/produção.

## Risco

Uma base de recibos do Portal pode ser restaurada corretamente do ponto de vista do PostgreSQL, mas o arquivo escolhido pode ser **antigo demais**. O Sites já pode ter confirmado uma operação cujo recibo não está no backup restaurado. Essa restauração não pode ser promovida silenciosamente como íntegra.

## Prova em três bancos PostgreSQL 18

Ao final da F1-368, o Portal fictício possui três recibos. A F1-370 tira um backup real com `pg_dump -Fc` **antes** de a F1-369 enviar a quarta operação. Esse backup antigo é restaurado por `pg_restore` em `portal_f370_stale_lab`, um banco adicional da terceira instância PostgreSQL descartável.

A F1-369 então grava o quarto evento, restaura a base mais recente em outro banco e comprova que o Sites só emite ACK após receber o recibo válido.

A F1-370 executa uma auditoria de paridade, comparando uma referência explícita **de laboratório** contendo os quatro eventos ao outbox `delivery_state='delivered'` do Sites e à base candidata restaurada:

- Restauração completa: quatro recibos e correspondência de empresas, operações, tipos de evento e hashes → apta apenas sob a régua do laboratório
- Backup antigo com três recibos → **bloqueado**, pois falta uma operação já confirmada pelo Sites
- Backup com uma linha alterada no SHA-256 → **bloqueado**, além da operação ausente
- Backup com linha inesperada adicionada → **bloqueado** também
- Conjunto de referência vazio ou ausente → **bloqueado**: nunca indicar paridade por ausência de dados

Todas as divergências são reconhecidas sem alterar o estado das admissões ou do outbox do Sites. A mutação de teste ocorre **exclusivamente** na cópia antiga e descartável. Os arquivos de backup ficam no diretório temporário do GitHub runner e são removidos pelo próprio workflow, inclusive quando etapas anteriores falham.

## O que ainda falta antes da homologação

O conjunto de referência esperado neste teste vem de **outro banco do mesmo job**, que foi propositalmente construído como a versão mais recente. Em produção isso **não seria uma fonte confiável independente**: uma perda coordenada, backup de época errada ou restauração de todos os sistemas poderia esconder eventos. Antes de usar um gate operacional semelhante, é necessário desenvolver e validar uma autoridade externa imutável de entregas/recibos, pontos de corte (watermarks) auditáveis, RPO/RTO medidos, política de retenção, assinatura de evidências e segregação de acessos.

Outros blockers ainda pendentes: identidade real serviço-serviço, contratos e pagamentos oficiais, origem externa de revogação, TLS, AppSec/LGPD, backup fora do domínio de falha, governança de deploy, rollback e observabilidade. Nenhum código nesta PR habilita rota pública, migração produtiva, acesso a clientes, DNS, Cloudflare, Leaseweb ou gasto adicional.

**PR deve continuar DRAFT/NO-GO**, dependente da revisão das F1-360 a F1-369. Nenhum merge ou deploy automático.
