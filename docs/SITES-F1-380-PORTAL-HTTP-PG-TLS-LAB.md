# F1-380 — Portal → Nginx → Laravel → PostgreSQL18, com TLS verificável

**Status:** ensaio integrado exclusivamente no GitHub Actions; **DRAFT/NO-GO**. Não existe autorização operacional para compras, publicação, migrações de produção ou clientes reais.

## Objetivo do teste

As F1-378 e F1-379 haviam passado isoladamente: uma comprovou que as classes de comando e assinatura reais do Portal conseguiam chamar o HTTP Laravel/Sites com dados fictícios, enquanto a outra comprovou que o PostgreSQL18 aceitava somente clientes TLS válidos com uma CA efêmera e `verify-full`.

A F1-380 executa os **dois caminhos na mesma instância do mesmo job**, eliminando o risco de uma combinação de configurações que funcionava isoladamente, mas quebraria a assinatura ou o transporte quando reunidos.

## Execução

O workflow `sites-f1-380-portal-http-pg-tls.yml`:

1. Faz checkout do Sites da PR e do Portal na SHA exata `bf1b2794de4d6000f120ffa2b1e6dd557f34c35e`, sem credenciais de checkout persistidas.
2. Gera nova CA, certificado do PostgreSQL para nome `sites-db` e outra CA errada. Apenas o certificado público é compartilhado com o PHP. Chave privada da CA permanece no runner e é apagada.
3. Sobe PostgreSQL18 isolado com HBA sem TCP plaintext e Nginx/PHP-FPM em redes Docker; host só expõe `127.0.0.1:18081`. O comando `sites:homologation-preflight` comprova sessão PG18/TLS/restrição SQL, mas continua **NO-GO** comercial.
4. Verifica que o endpoint de admissão continua **HTTP 404** antes da habilitação exclusiva do teste. Recria somente PHP-FPM com opt-in de CI, sem habilitar rotas comerciais.
5. Executa o cliente HTTP F1-377 e o cliente F1-378 baseado em `SitesCreateProjectEncoder` / `SyntheticSitesEnvelopeSigner` do Portal. A aplicação persiste nonce e admissão/outbox em uma mesma transação PG18 por conexão com CA validada.
6. Verifica o estado em SQL por conexão separada: exatamente cinco registros de admissão, cinco eventos outbox e nove nonces persistidos; sem empresa estrangeira admitida, evento de falha gravado ou outbox órfão.
7. Destrói todos os contêineres, dados, certificados e chaves do job.

## Limites

O emissor de contratos é um stub **fictício dentro do teste**, não o serviço de contratos oficiais do Portal. O uso de uma CA curta prova a cadeia de confiança do laboratório, mas não configura identidades S2S operacionais, vault ou TLS público. Nenhum pagamento, nota fiscal, aceite de cliente, consentimento, domínio, deploy Leaseweb ou publicação Cloudflare é realizado.

Antes da homologação verdadeira: conexão com fonte comercial autorizada no Portal, pagamento e contrato vinculados ao cliente logado, credenciais S2S em cofres e rotação, RLS com mapeamento real, testemunha externa de revogação, backup independente, auditoria AppSec/DBA/LGPD, TLS/mTLS e observabilidade.

**Sem merge automático nem alteração em VPS, DNS, Cloudflare ou sistemas de clientes.**
