# F1-370 — Prova mTLS Portal → Sites em ambiente descartável CI

**Estado:** candidato DRAFT/NO-GO. Não autoriza publicar endpoint, empregar certificados de produção, gerar gastos com serviços externos nem configurar a Leaseweb.

## Objetivo

As F1-365..369 testam HTTP entre processos, idempotência, PostgreSQL, recibos assinados e restauração em um runner temporário. A comunicação entre serviços ainda usava HTTP simples e chaves públicas de laboratório. A F1-370 demonstra um **canal TLS com autenticação mútua**, diferente da assinatura da mensagem, antes de entregar comandos ao adaptador de admissão do Sites.

## Como funciona o experimento

No job PostgreSQL18 PHP8.3/8.4, o script `tests/fixtures/f370-generate-ephemeral-pki.sh` gera uma **CA descartável**, certificado TLS de servidor com DNS `sites-f370.test`, um certificado cliente de laboratório para o Portal e um certificado cliente de terceiro **não confiável**. O script recusa execução fora de `APP_ENV=testing`, `GITHUB_ACTIONS=true` e `SITES_F370_MTLS_LAB=1`.

Um Nginx temporário em contêiner de CI, escutando apenas em `127.0.0.1:18970`, verifica o certificado de cliente contra a CA local e apresenta seu certificado de servidor. O proxy só encaminha tráfego com host `sites-f370.test` ao PHP de laboratório do Sites na porta 18965, com Host interno explicitamente fixado. Nenhum serviço é exposto externamente e nenhuma rota Laravel produtiva é criada.

O teste `tests/f370-mtls-portal-signed-client.php` usa o encoder e signer candidatos pinados do Imagine Portal para enviar pedidos fictícios Onepage e Landing Page por **HTTPS validado**, incluindo verificação de CA, hostname e certificado cliente no cliente cURL. O servidor continua verificando em sequência assinatura do comando, atestações fictícias, nonce durável, login SQL vinculado à empresa, admissão/outbox atômicos e ausência de publicação.

## Negativas

- Conexão sem certificado cliente não pode acessar o endpoint
- Certificado de cliente assinado por CA diferente não pode acessar
- Servidor de nome incorreto falha na validação de hostname do cliente
- Host HTTP divergente não é roteado ao backend (421)
- Conteúdo/JSON inválido, método diferente, corpo excessivo e caminho desconhecido recusados
- Replay do mesmo nonce, assinatura adulterada, revogação fictícia, empresa incompatível e falha no outbox recusados
- Retransmissão com nonce novo não duplica evento, e nenhuma operação aprovada se torna publicável

## Exclusões e riscos pendentes

A CA e todos os certificados são gerados em arquivos temporários do runner e eliminados ao final do teste. **Não armazenar estes arquivos no GitHub, logs, PR ou repositório.** O Nginx e o PHP embutido são apenas fixtures de integração. O teste comprova mTLS técnico usando CA temporária; **não comprova** gestão de confiança na empresa, identidade do Portal em produção, emissão/rotação/revogação de certificados, identidade de workload, secret manager, mTLS entre contêineres Leaseweb, gateway Cloudflare, roteamento real, contrato/pagamento, LGPD ou homologação de rede.

O passo seguinte à CI verde requer desenhar implantação isolada na infraestrutura Imagine e autorização explícita dos respectivos gates de produção/homologação. Sem merge/deploy automático, sem VPS, DNS ou dados de clientes.
