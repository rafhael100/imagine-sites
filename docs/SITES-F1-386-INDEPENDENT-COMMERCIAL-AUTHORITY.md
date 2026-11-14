# F1-386 — Evidência independente para liberar um contrato Portal → Sites

**Objetivo:** eliminar o risco de usar a simples presença de `payment=approved` em um pedido do Portal como autorização comercial do Sites.

## O que foi entregue

A nova classe `src/Integration/IndependentPortalCommercialGateway.php` implementa `PortalProjectAuthorityGateway`, já utilizado pela admissão de projetos. Ela exige **todas** as seguintes provas, independentemente:

1. **Atestado do Portal assinado com Ed25519:** documento de campos estritos, issuer `imagine-portal`, audience `imagine-sites`, validade curta, revisão do contrato/produto e hash semântico exatamente correspondentes ao pedido. A chave pública deverá ser configurada pela equipe Imagine de modo independente. Um token autoafirmado por navegador ou uma assinatura HMAC do laboratório **não serve** para esse gate.
2. **Confirmação de liquidação financeira independente:** interface `IndependentSettlementWitness` que consulta um sistema autorizado de pagamento ou razão financeira. Um texto `paid`/ `approved` no atestado não vale como liquidação. O testemunho deve comprovar referência e SHA-256 da mesma evidência, além de eventual estorno ou cancelamento.
3. **Revogação e elegibilidade atuais:** interface `IndependentRevocationWitness` que consulta uma autoridade independente para saber se a revisão e o epoch revogatório permanecem válidos. O documento assinado não pode ser usado para esconder uma revogação posterior.

`PortalEligibilityProofSource` define uma obtenção *servidor a servidor* do atestado corrente, não um JSON entregue pelo usuário. **Nenhuma implementação operacional das três interfaces foi criada**, pois os contratos, dados e acessos reais à API de pagamento e IAM ainda não estão provisionados.

O verificador recusa ausência de prova, assinatura adulterada, chave inválida, issuer/audience diferente, contrato/produto/tenant/revisão divergente, campos fora da ordem canônica, atributos inesperados, prazo expirado ou demasiado longo, settlement não comprovado e revogação não confirmada. Exceções e indisponibilidades dos três sistemas **negam** o pedido, sem expor dados, assinatura, chave ou texto de falhas de fornecedores.

O gate nunca publica site, não grava outbox, não consome nonce e não gera cobrança. A segurança transacional e contra replay precisa continuar no componente de admissão, além desta validação de elegibilidade.

## Teste sem credenciais reais

`tests/f386-independent-commercial-gate.php` gera um par de chaves **Ed25519 efêmero, na memória do runner**, com assinatura real de uma evidência fictícia. Stubs separados simulam Portal, testemunho de liquidação e revogação. O CI confere a verificação positiva **somente quando os três sinais artificiais concordam**, a reconsulta obrigatória em toda invocação e uma matriz de negativas, falhas, alteração de bytes, expiração, troca de contrato/tenant e revogação.

O workflow `.github/workflows/sites-f1-386-independent-commercial-gate.yml` executa PHP 8.3/8.4 com libsodium e confirma também que **nenhum ServiceProvider ou rota HTTP registra o novo gateway**, e que `SiteProjectAdmission` continua usando `DenyingPortalProjectAuthorityGateway` por padrão. Portanto, a nova classe **não ativa uma contratação**.

## O que falta para ligar em clientes

Para uso real é necessário conectar APIs oficiais do Portal/PSP e fonte de revogação com credenciais isoladas, política de rotação Ed25519, sincronização de tempo, TLS/mTLS quando aplicável, rate limiting, circuit breaker e logs auditáveis sem dados sensíveis. Além disso, o caminho de admissão precisa de nonce durável, idempotência, identidade SQL associada ao pedido, migração operacional e worker controlado. Aceite do cliente, NF, preço/assinatura, faturamento, publisher e restauração externa continuam fora desta entrega.

**PR DRAFT/NO-GO:** não há conexão com PSP, pagamento real, dados pessoais, SDK pago, conta externa, merge ou deploy na VPS. Não tratar os stubs como fornecedores reais.
