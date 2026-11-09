# SITES-F1-14 — PDO PostgreSQL como replay fence do contrato assinado (somente laboratório)

**Escopo:** primeira ligação do verificador HMAC `SyntheticSignedCommand` (F1-11) a uma tabela PostgreSQL descartável de nonces (F1-12), por meio de um `ReplayStore` concreto. Não é integração de produção.

## Implementação e testes

- `LabPostgresReplayStore` aceita apenas CLI, `SITES_F1_14_PG_LAB=1`, banco `sites_auth_replay_lab` e papel de DB `sites_auth_runtime`, sem privilégios de owner/BYPASSRLS.
- Usa `INSERT ... ON CONFLICT DO NOTHING` atômico; primeira requisição válida retorna sucesso, mesmo nonce em conexão separada é negado.
- A fonte de tempo para a janela de nonces é o PostgreSQL, com validação de TTL na tabela; o verificador HMAC também faz validação temporal em PHP.
- Falhas SQL, privilégio insuficiente, expirado e indisponibilidade retornam DENY, sem conceder autorização de publicação, contrato ou cliente.
- Testes com envelope HMAC fictício verificam assinatura, adulteração não consumindo nonce, replay por segunda conexão, TTL, issuer e desligamento do laboratório.
- CI usa PostgreSQL 18 e PHP 8.3/8.4, sem rede a terceiros, dados pessoais ou chaves reais. Em runner efêmero a conexão começa como schema owner fictício e executa `SET ROLE` antes do adaptador. **Isso não constitui um desenho produtivo de login DB**.

## Fronteira e próximo passo

A aplicação Laravel F1-13 **não injeta este adaptador** e mantém default DENY. Integração com o endpoint real requer revisão de credenciais DB não privilegiadas, autenticação, vault, replay sem sincronização por chave estática, revogação, outbox e checagem oficial de contrato/empresa. Em produção o papel deve ter autenticação e escopos próprios, não usar um superuser ou `SET ROLE` para assumir papel de usuário.

Esse F1-14 não permite pagamento, aceite do cliente, publicação ou ENTREGUE. Sem Cloudflare, CRM, VPS, DNS ou dados reais. Todos os gates P0/RF-PEND/RV-CT permanecem abertos, AGI-11-RB01 desabilitado e PR draft sem merges/deploys automáticos.
