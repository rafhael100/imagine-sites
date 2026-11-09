# SITES-F1-14 — Adapter PHP de replay HMAC integrado a PostgreSQL 18 efêmero

**Escopo:** exercício real de código PHP com banco relacional temporário do GitHub Actions, **sem conectar APIs nem clientes**. PR filho F1-13, sem merge/deploy.

Adiciona `LabPdoReplayStore`, implementação de `ReplayStore` que usa PDO PostgreSQL e somente `INSERT .. ON CONFLICT DO NOTHING` para consumir um nonce por issuer sob o schema temporário do F1-12. O adaptador requer PHP CLI, flag `SITES_F1_14_LAB_ENABLED=1`, conta SQL `sites_auth_runtime` e DB `sites_auth_replay_lab`. Rejeita por padrão ambiente/role/DB divergentes e qualquer falha de conexão ou SQL.

No CI, a conta começa como NOLOGIN/NOBYPASSRLS; só no runner é concedido LOGIN com **senha aleatória descartável**, mascarada nos logs e eliminada junto com a base. Não há senha fixa de acesso em código. Os testes verificam consumo único de nonce válido após HMAC, replay posterior rejeitado, adulteração de payload negada antes do insert, nonce ainda disponível após tentativa inválida e falha fechada quando o banco é inacessível.

**Limites:** isto não cria endpoint de produção, nem dá autoridade comercial, nem protege dados reais. Não há truststore de chaves, rotação, mTLS, autenticação de usuário Portal, reconciliação de estados, outbox ou logs operacionais. O signer continua usando chave **fictícia de teste**. `LabPdoReplayStore` não deve ser registrado no container da aplicação fora de CI e não é um adapter aprovado para produção.

Para operar, ainda precisa homologar política de chave/issuer/tenant, persistência/autorização de recurso, PostgreSQL separado, ambiente seguro e P0/RF-PEND. Sem API pública, Cloudflare, CRM, DNS, VPS, deploy ou custos extras. AGI-11-RB01 desabilitado.
