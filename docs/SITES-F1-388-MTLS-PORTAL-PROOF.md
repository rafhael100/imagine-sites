# F1-388 — Cliente Sites para atestado atual do Portal com HTTPS autenticado (mTLS)

**Entrega:** adaptador real de transporte HTTPS (cURL) para a interface `PortalEligibilityProofSource` da F1-386. Não é gateway de pagamento nem endpoint de atendimento. **Nenhuma credencial operacional foi disponibilizada ou configurada.**

## Contrato técnico

`src/Integration/PortalProofMtlsHttpSource.php` envia `POST` para o caminho fixo `/internal/sites/v1/entitlements/resolve` com os identificadores opacos de empresa, pedido, contrato e revisão, código de produto e fingerprint do comando. Não aceita URL, hostname ou identidade TLS vindos da requisição do navegador; todos são fornecidos pelo operador via configuração confiável.

O endereço precisa ser HTTPS, apontar para um host esperado, ter caminho exato e não conter usuário, senha, query ou fragmento. Fora do GitHub CI, a classe exige porta 443, host DNS não loopback e nome de host validado. O teste efêmero em `localhost:18443` só funciona com flags de CI explícitas.

A conexão exige **certificado de cliente, chave privada, CA confiável explicitamente configurada, verificação TLS da CA e do hostname**, protocolo HTTPS, TLS mínimo 1.2 e prazo de 0,5 a 5 segundos. Redirecionamentos e proxies são desativados; nenhum identificador de cliente ou valor de certificado é registrado. Respostas HTTP diferentes de 200, corpos malformados e respostas maiores que 4 KiB resultam em **sem prova**, jamais em aprovação.

O retorno JSON permanece **não confiável** até que `IndependentPortalCommercialGateway` verifique a assinatura Ed25519 do Portal, a liquidação independente do PSP e a revogação atual por testemunho separado. HTTPS/mTLS sozinho não comprova elegibilidade ou pagamento, e a classe não fornece um fallback sem TLS.

## Teste de ponta a ponta em GitHub Actions

O workflow `sites-f1-388-portal-mtls.yml` faz checkout exato do emissor Portal [F1-387](https://github.com/rafhael100/imagine-portal/pull/733), em `45778e950316874bdf227da7839caabebbd12e11`. Gera **uma CA, dois pares de certificados e uma identidade TLS não autorizada em cada job**, sem salvar chaves ou segredos no repositório. O código real do Portal assina um atestado fictício Ed25519 com chave efêmera criada na memória, e um servidor HTTPS Python em 127.0.0.1 exige certificado de cliente válido.

A suíte PHP 8.3 e 8.4 executa o caminho completo: emissor real do Portal → servidor HTTPS com mTLS → cliente Sites → verificador Ed25519 Sites → duas fontes fictícias independentes de liquidação e revogação. Também valida troca de tenant, prova adulterada, PSP não confirmado, revogação, erro HTTP, redirecionamento, resposta demasiadamente grande, corpo HTML, CA incorreta, cliente mTLS de outra CA, URL insegura, ausência de certificado e flag de laboratório desabilitada.

O servidor só existe em loopback do runner; os certificados e provas são fictícios, descartados ao encerrar o job. Nenhum DNS, domínio público, contrato ou pagamento real é utilizado.

## Pré-requisitos operacionais

A implementação real do endpoint Portal ainda precisa ser construída com autenticação de certificado por serviço, IAM/ACL, rate limiting, trilha auditável sem dados sensíveis e vínculo ao banco oficial de contratos. O Sites precisa integrar a cadeia de fontes de PSP e revogação, política de KMS/vault e rotação, TLS na infraestrutura real, admissões/nonce/outbox versionadas, aceite do cliente, backup remoto testado e publicador isolado com rollback. Até lá, o adaptador **não é registrado no Laravel e nenhuma rota pública é ativada**.

**PR DRAFT/NO-GO, sem merge, deploy, VPS, DNS, pagamento real ou custo de terceiros.**
