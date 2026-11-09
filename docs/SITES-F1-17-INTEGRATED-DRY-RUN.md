# SITES-F1-17 — Jornada integrada sintética do Imagine Sites

**Escopo:** todos os passos executados *no processo de testes* com objetos fictícios; sem integração por rede, credenciais reais ou dados pessoais. Esse recorte **não** entrega um produto operacional, nem comprova segurança completa da arquitetura.

## Jornada percorrida

1. Uma fixture de pedido do Portal é convertida no `CreateProject v1`, assinada por HMAC falso e validada com janela de tempo/nonce sintético, sem replay.
2. Uma fonte comercial **mockada** autoriza somente tenant/contrato/revisão/produto esperados; a fonte padrão continua negando.
3. Um registry só em memória deduplica pedido; não é idempotência durável, nem banco da aplicação.
4. `StaticSiteRenderer` gera HTML Onepage fictício com hash SHA-256 dos bytes.
5. Uma evidência **fictícia** de aceite expresso do cliente aprova somente o digest daquela release; versão N+1 e aceite ausente/revogado permanecem bloqueados.
6. `SyntheticPublishCoordinator` emite ACK fictício; apenas a leitura de bytes de um origin **simulado** permite chegar a `verified`; sem origin a tentativa é inconclusiva, sem rollback.
7. `DeliveryPolicy` informa elegibilidade técnica candidata somente após preflight, origin, health e checklist. **Não registra o evento oficial ENTREGUE**, exclusivo do Portal.
8. `LeadDeadline` verifica marcos T0+72h e T0+432h em dados fictícios; **não** envia lead, não opera CRM ou captação.

## Negativas e limites

O teste impõe negação de replay, operação sem autoridade, tenant incorreto, cliente sem aceite, permissão revogada, versão não aprovada, ACK sem origin, health/checklist ausentes e reenvio após 432h.

**Crítico:** os gates dos steps 2/5 são mocks. O código de teste não comprova autenticação, pagamento, autorização de cliente real, assinatura legal de aceite, SLA, integridade do CDN, privacidade, retenção legal, redundância física, operação de IA ou confirmação CRM. A comunicação entre os dois repositórios ainda não foi exercitada sobre HTTPS/autenticação real; HMAC de fixture não é contrato homologado. Migrations Laravel e operações em VPS não foram executadas.

Os PRs #1–#18 são recortes de laboratório encadeados, e o Portal tem PRs #94/#101/#105, todos sem merge automático. Estes recortes permitem continuar projeto e documentar decisões sem desbloquear P0/RF-PEND/RV-CT ou AGI-11-RB01.

**Próxima trilha:** rever dependências/segurança/custos, fechar contratos com evidências; implementar conectores reais sob controle de acesso antes de qualquer cliente, dados reais ou publicação. 
